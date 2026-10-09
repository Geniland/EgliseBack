<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAttendanceSessionRequest;
use App\Http\Requests\UpdateAttendanceSessionRequest;
use App\Http\Resources\AttendanceSessionResource;
use App\Models\Attendance;
use App\Models\AttendanceSession;
use App\Models\Member;
use App\Support\ScopeHelper;
use App\Services\AttendanceAutoAbsenceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceSessionController extends Controller
{
    protected function canUserActOnSession(AttendanceSession $session, string $action = 'view'): bool
    {
        $u = auth()->user();
        if (!$u) return false;
        if (ScopeHelper::isSuperAdmin()) return true;

        $isInScope = AttendanceSession::query()
            ->whereKey($session->id)
            ->tap(fn ($query) => ScopeHelper::applyOwnedByScope($query))
            ->exists();
        if (!$isInScope) return false;

        $permMatch = match ($action) {
            'update' => $u->hasPermission('attendance.update'),
            'delete' => $u->hasPermission('attendance.delete'),
            default => $u->hasPermission('attendance.view'),
        };

        return $permMatch;
    }

    protected function processExpiredSessions(AttendanceAutoAbsenceService $service, int $max = 5): void
    {
        try {
            $service->processExpired($max);
        } catch (\Throwable $e) { /* Ignorer silencieusement en requête Web */ }
    }

    public function index(Request $request, AttendanceAutoAbsenceService $autoAbsence)
    {
        $this->processExpiredSessions($autoAbsence, 3);

        $sessions = AttendanceSession::with(['creator'])
            ->when($request->search, function ($q) use ($request) {
                $q->where('title', 'LIKE', "%{$request->search}%");
            })
            ->when($request->type, fn($q) => $q->where('type', $request->type))
            ->when($request->status !== null, fn($q) => $q->where('status', (bool)$request->status))
            ->when($request->date_from, fn($q) => $q->whereDate('session_date', '>=', $request->date_from))
            ->when($request->date_to, fn($q) => $q->whereDate('session_date', '<=', $request->date_to))
            ->tap(fn($q) => ScopeHelper::applyOwnedByScope($q))
            ->latest('session_date')
            ->latest('start_time')
            ->paginate($request->per_page ?? 20);

        return AttendanceSessionResource::collection($sessions);
    }

    public function show(Request $request, AttendanceSession $attendanceSession, AttendanceAutoAbsenceService $autoAbsence)
    {
        abort_unless($this->canUserActOnSession($attendanceSession), 404);
        $this->processExpiredSessions($autoAbsence, 3);
        $attendanceSession->load([
            'creator',
            'updater',
            'attendances' => function ($query) {
                $query->whereHas('member', fn ($members) => ScopeHelper::applyMemberScope($members));
            },
            'attendances.member',
        ]);
        return new AttendanceSessionResource($attendanceSession);
    }

    public function store(StoreAttendanceSessionRequest $request)
    {
        $data = $request->validated();
        $u = $request->user();
        $data['created_by'] = $u->id;
        $data['updated_by'] = $u->id;
        $data['status'] = $data['status'] ?? true;
        if (empty($data['church_id']) && !empty($u->church_id)) {
            $data['church_id'] = $u->church_id;
        }
        if (!empty($data['church_id']) && !ScopeHelper::isSuperAdmin()) {
            $allowedChurchIds = ScopeHelper::getMyChurchIds();
            if ($u->church_id) $allowedChurchIds[] = (int) $u->church_id;
            abort_unless(in_array((int) $data['church_id'], array_map('intval', $allowedChurchIds), true), 403);
        }

        $session = AttendanceSession::create($data);
        $session->load(['creator']);

        return response()->json([
            'message' => 'Session de présence créée avec succès',
            'session' => new AttendanceSessionResource($session),
        ], 201);
    }

    public function update(UpdateAttendanceSessionRequest $request, AttendanceSession $attendanceSession)
    {
        abort_unless($this->canUserActOnSession($attendanceSession, 'update'), 404);
        if (!$this->canUserActOnSession($attendanceSession, 'update')) {
            return response()->json(['message' => 'Vous n\'avez pas la permission nécessaire pour modifier cette session'], 403);
        }

        $data = $request->validated();
        $data['updated_by'] = auth()->id();
        $attendanceSession->update($data);
        $attendanceSession->load(['creator', 'updater']);

        return response()->json([
            'message' => 'Session mise à jour',
            'session' => new AttendanceSessionResource($attendanceSession),
        ]);
    }

    public function destroy(AttendanceSession $attendanceSession)
    {
        abort_unless($this->canUserActOnSession($attendanceSession, 'delete'), 404);
        if (!$this->canUserActOnSession($attendanceSession, 'delete')) {
            return response()->json(['message' => 'Vous n\'avez pas la permission nécessaire pour supprimer cette session'], 403);
        }
        $attendanceSession->delete();
        return response()->json(['message' => 'Session supprimée']);
    }

    public function stats(Request $request)
    {
        $base = AttendanceSession::query()->tap(fn($q) => ScopeHelper::applyOwnedByScope($q));

        $sessions = (clone $base)->count();
        $active = (clone $base)->where('status', true)->count();

        $attBase = Attendance::query()
            ->whereHas('session', function ($q) {
                ScopeHelper::applyOwnedByScope($q);
            })
            ->whereHas('member', function ($q) {
                ScopeHelper::applyMemberScope($q);
            });

        $present = (clone $attBase)->present()->count();
        $absent = (clone $attBase)->absent()->count();
        $late = (clone $attBase)->late()->count();
        $total = $present + $absent + $late;

        $rate = $total > 0 ? round(($present / $total) * 100, 1) : 0;

        $periodStart = $request->period === 'week' ? now()->subDays(6)
            : ($request->period === 'year' ? now()->subYear()
            : now()->subMonth());

        $trend = [];
        for ($i = 0; $i < ($request->period === 'year' ? 12 : 6); $i++) {
            $end = now()->subWeeks($i * ($request->period === 'year' ? 4 : 1));
            $start = (clone $end)->subWeek();
            $p = (clone $attBase)->whereBetween('created_at', [$start, $end])->present()->count();
            $a = (clone $attBase)->whereBetween('created_at', [$start, $end])->absent()->count();
            $tot = max(1, $p + $a);
            $trend[] = [
                'label' => $end->format('d/m'),
                'present' => $p,
                'absent' => $a,
                'rate' => round(($p / $tot) * 100, 1),
            ];
        }

        $repeated = (clone $attBase)
            ->select('member_id', DB::raw('COUNT(*) as cnt'))
            ->whereIn('status', ['absent', 'absent_excuse'])
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('member_id')
            ->havingRaw('COUNT(*) >= ?', [3])
            ->orderByDesc('cnt')
            ->limit(10)
            ->get()
            ->map(function ($r) {
                $m = Member::find($r->member_id);
                return [
                    'member_id' => $r->member_id,
                    'count' => $r->cnt,
                    'full_name' => $m ? ($m->first_name . ' ' . $m->last_name) : null,
                    'member_code' => $m?->member_code,
                ];
            })->filter(fn($x) => $x['full_name'] !== null)->values();

        return response()->json([
            'sessions' => ['total' => $sessions, 'active' => $active],
            'attendances' => [
                'total' => $total,
                'present' => $present,
                'absent' => $absent,
                'late' => $late,
                'attendance_rate' => $rate,
            ],
            'trend' => array_reverse($trend),
            'repeated_absences' => $repeated,
        ]);
    }

    public function generateQr(Request $request, AttendanceSession $attendanceSession)
    {
        $attendanceSession = ScopeHelper::findOwnedOrFail(AttendanceSession::class, $attendanceSession->id);
        $validity = (int) ($request->validity_minutes ?? 180);
        $validity = max(5, min(4320, $validity));

        $token = $attendanceSession->generateQrToken($validity);

        $payload = [
            'token' => $token,
            'expires_at' => $attendanceSession->qr_expires_at->toDateTimeString(),
            'validity_minutes' => $validity,
            'session_id' => $attendanceSession->id,
            'title' => $attendanceSession->title,
            'session_date' => $attendanceSession->session_date?->toDateString(),
            'qr_code_data' => json_encode([
                't' => $token,
                's' => $attendanceSession->id,
                'd' => $attendanceSession->session_date?->toDateString(),
                'x' => $attendanceSession->qr_expires_at->timestamp,
            ], JSON_UNESCAPED_SLASHES),
        ];

        return response()->json([
            'message' => 'QR Code généré',
            'qr' => $payload,
            'session' => new AttendanceSessionResource($attendanceSession->fresh()),
        ]);
    }

    public function invalidateQr(Request $request, AttendanceSession $attendanceSession, AttendanceAutoAbsenceService $autoAbsence)
    {
        abort_unless($this->canUserActOnSession($attendanceSession, 'update'), 404);
        $attendanceSession->invalidateQr();

        $processAbsences = (bool) $request->input('process_absences', false);
        $report = null;
        if ($processAbsences || $attendanceSession->auto_absences_processed === false) {
            try {
                $report = $autoAbsence->processSession($attendanceSession->fresh());
            } catch (\Throwable $e) { /* Ignorer */ }
        }

        return response()->json([
            'message' => 'QR Code invalide' . ($report ? ' et absences traitées, messages pastoraux envoyés.' : '.'),
            'report' => $report,
        ]);
    }

    public function markAllAbsent(Request $request, AttendanceSession $attendanceSession)
    {
        $attendanceSession = ScopeHelper::findOwnedOrFail(AttendanceSession::class, $attendanceSession->id);
        $override = (bool) $request->input('override', false);
        $createdBy = auth()->id();

        $members = Member::query()->tap(fn($q) => ScopeHelper::applyMemberScope($q))
            ->where('status', true)
            ->pluck('id');

        $inserted = 0;
        foreach ($members as $mid) {
            try {
                Attendance::create([
                    'session_id' => $attendanceSession->id,
                    'member_id' => $mid,
                    'status' => 'absent',
                    'created_by' => $createdBy,
                    'updated_by' => $createdBy,
                    'scan_method' => $override ? 'bulk' : 'bulk',
                ]);
                $inserted++;
            } catch (\Throwable $e) {
                if ($override) {
                    DB::table('attendances')
                        ->where('session_id', $attendanceSession->id)
                        ->where('member_id', $mid)
                        ->update([
                            'status' => 'absent',
                            'updated_by' => $createdBy,
                            'updated_at' => now(),
                        ]);
                }
            }
        }

        return response()->json([
            'message' => "Absences initialisées : {$inserted} membres",
        ]);
    }
}
