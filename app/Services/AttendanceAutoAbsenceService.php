<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceSession;
use App\Models\ChatMessage;
use App\Models\Member;
use App\Models\User;
use App\Support\ScopeHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AttendanceAutoAbsenceService
{
    public function processExpired(int $max = 50): array
    {
        $totalSessions = 0;
        $totalAbsences = 0;
        $totalMessages = 0;
        $errors = 0;

        $sessions = AttendanceSession::query()
            ->where('auto_absences_processed', false)
            ->whereNotNull('qr_expires_at')
            ->where('qr_expires_at', '<=', now())
            ->orderBy('qr_expires_at', 'ASC')
            ->limit($max)
            ->get();

        foreach ($sessions as $session) {
            try {
                $report = $this->processSession($session);
                $totalSessions++;
                $totalAbsences += $report['absences_created'] ?? 0;
                $totalMessages += $report['messages_created'] ?? 0;
            } catch (\Throwable $e) {
                $errors++;
                try { Log::warning('AutoAbsenceService failed session ' . $session->id, ['error' => $e->getMessage()]); } catch (\Throwable) {}
            }
        }

        return [
            'sessions_processed' => $totalSessions,
            'absences_created' => $totalAbsences,
            'messages_created' => $totalMessages,
            'errors' => $errors,
            'processed_at' => now()->toDateTimeString(),
        ];
    }

    public function processSession(AttendanceSession $session): array
    {
        if ($session->auto_absences_processed) {
            return [
                'session_id' => $session->id,
                'already_processed' => true,
                'absences_created' => 0,
                'messages_created' => 0,
                'processed_at' => $session->auto_absences_at?->toDateTimeString(),
            ];
        }

        DB::beginTransaction();
        try {
            $creatorId = $session->created_by;
            $churchId = $session->church_id;

            $membersQuery = Member::query();
            if ($churchId) {
                $membersQuery->where('church_id', $churchId);
            }
            if ($creatorId) {
                $membersQuery->tap(function ($q) use ($creatorId) {
                    $saved = auth()->id();
                    try {
                        if (!$saved) {
                            $tmpUser = User::find($creatorId);
                            if ($tmpUser) auth()->setUser($tmpUser);
                        }
                        ScopeHelper::applyMemberScope($q, $tmpUser ?? null);
                    } finally {
                        if (!$saved) {
                            try { auth()->logout(); } catch (\Throwable) {}
                        }
                    }
                });
            }

            $members = $membersQuery->where('status', true)
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get(['id', 'first_name', 'last_name', 'user_id', 'church_id']);

            $existing = Attendance::where('session_id', $session->id)
                ->pluck('member_id')
                ->all();

            $absencesCreated = 0;
            $messagesCreated = 0;
            $msgCreatorId = $creatorId;
            if ($msgCreatorId === null) {
                $superUser = User::where('role_id', 1)->first();
                $msgCreatorId = $superUser?->id;
            }

            $dateFr = $session->session_date ? $session->session_date->format('d/m/Y') : date('d/m/Y');

            foreach ($members as $member) {
                if (in_array($member->id, $existing, true)) continue;

                try {
                    Attendance::create([
                        'session_id' => $session->id,
                        'member_id' => $member->id,
                        'status' => 'absent',
                        'arrival_time' => null,
                        'scan_method' => 'auto',
                        'gps_verified' => false,
                        'created_by' => $creatorId,
                        'updated_by' => $creatorId,
                    ]);
                    $absencesCreated++;
                } catch (\Throwable) { /* duplicate ignore */ }

                $userId = $member->user_id;
                if ($userId && $msgCreatorId && (int)$userId !== (int)$msgCreatorId) {
                    try {
                        $alreadySent = ChatMessage::where([
                            ['sender_id', '=', $msgCreatorId],
                            ['recipient_id', '=', $userId],
                            ['church_id', '=', $churchId],
                        ])
                        ->whereRaw('DATE(created_at) = ?', [now()->toDateString()])
                        ->whereRaw('LOWER(contenu) LIKE ?', ['%absence%session%' . mb_strtolower($session->title) . '%'])
                        ->exists();
                        if (!$alreadySent) {
                            $prenom = trim(ucfirst(mb_strtolower($member->first_name)));
                            if (!$prenom) $prenom = 'cher(e) fidèle';
                            $message = sprintf(
                                'Bonjour %s, nous avons remarqué votre absence lors de la session « %s » du %s. Nous espérons que tout va bien pour vous et votre famille. N\'hésitez pas à nous écrire si vous traversez un moment difficile ou si vous avez besoin de soutien.',
                                $prenom,
                                $session->title,
                                $dateFr
                            );
                            ChatMessage::create([
                                'church_id' => $churchId,
                                'sender_id' => $msgCreatorId,
                                'recipient_id' => $userId,
                                'contenu' => $message,
                                'lu' => false,
                                'expires_at' => now()->addDays(60),
                            ]);
                            $messagesCreated++;
                        }
                    } catch (\Throwable $e) { /* ignore message failure */ }
                }
            }

            $session->auto_absences_processed = true;
            $session->auto_absences_at = now();
            $session->save();

            DB::commit();

            return [
                'session_id' => $session->id,
                'already_processed' => false,
                'members_scoped' => $members->count(),
                'existing_attendances' => count($existing),
                'absences_created' => $absencesCreated,
                'messages_created' => $messagesCreated,
                'processed_at' => $session->auto_absences_at->toDateTimeString(),
            ];
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
