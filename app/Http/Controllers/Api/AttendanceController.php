<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ScanAttendanceRequest;
use App\Http\Requests\StoreAttendanceRequest;
use App\Http\Requests\UpdateAttendanceRequest;
use App\Http\Resources\AttendanceResource;
use App\Models\Attendance;
use App\Models\AttendanceSession;
use App\Models\Member;
use App\Support\ScopeHelper;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        $q = Attendance::with(['member', 'session', 'absenceReason', 'creator'])
            ->when($request->session_id, fn($qq) => $qq->where('session_id', $request->session_id))
            ->when($request->member_id, fn($qq) => $qq->where('member_id', $request->member_id))
            ->when($request->status, fn($qq) => $qq->where('status', $request->status))
            ->when($request->date, fn($qq) => $qq->whereHas('session', fn($s) => $s->whereDate('session_date', $request->date)));

        $q->whereHas('session', function ($sq) {
            ScopeHelper::applyOwnedByScope($sq);
        });
        $q->orWhereDoesntHave('session')
            ->tap(fn($qq) => ScopeHelper::applyOwnedByScope($qq, 'created_by'));

        $attendances = $q->latest()->paginate($request->per_page ?? 30);

        return AttendanceResource::collection($attendances);
    }

    public function show(Attendance $attendance)
    {
        $attendance->load(['member', 'session', 'absenceReason', 'creator', 'updater']);
        return new AttendanceResource($attendance);
    }

    public function store(StoreAttendanceRequest $request)
    {
        DB::beginTransaction();
        try {
            $data = $request->validated();
            $data['created_by'] = auth()->id();
            $data['updated_by'] = auth()->id();
            $data['scan_method'] = $data['scan_method'] ?? 'manual';
            $data['gps_verified'] = false;

            if (isset($data['status']) && in_array($data['status'], ['present', 'retard']) && empty($data['arrival_time'])) {
                $data['arrival_time'] = now();
            }

            $session = AttendanceSession::find($data['session_id']);
            if ($session) {
                $lat = $data['latitude'] ?? null;
                $lng = $data['longitude'] ?? null;
                $okGps = $session->isWithinGps($lat, $lng);
                if ($session->gps_required && !$okGps) {
                    return response()->json([
                        'message' => 'Vérification GPS échouée : vous êtes trop éloigné du lieu de la session.',
                        'gps_required' => true,
                        'gps_verified' => false,
                    ], 400);
                }
                $data['gps_verified'] = $okGps && ($lat !== null && $lng !== null);
            }

            $att = Attendance::create($data);
            $att->load(['member', 'session', 'absenceReason']);

            DB::commit();
            return response()->json([
                'message' => 'Présence enregistrée',
                'attendance' => new AttendanceResource($att),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function bulkStore(Request $request)
    {
        $valid = $request->validate([
            'session_id' => 'required|exists:attendance_sessions,id',
            'entries' => 'required|array|min:1|max:500',
            'entries.*.member_id' => 'required|exists:members,id',
            'entries.*.status' => ['nullable', 'in:present,absent,absent_excuse,retard'],
            'entries.*.arrival_time' => 'nullable|date',
            'entries.*.absence_reason_id' => 'nullable|exists:absence_reasons,id',
            'entries.*.comment' => 'nullable|string|max:500',
        ]);

        $session = AttendanceSession::find($valid['session_id']);
        $created = 0;
        $updated = 0;
        $errors = [];
        $uid = auth()->id();

        DB::beginTransaction();
        try {
            foreach ($valid['entries'] as $i => $e) {
                try {
                    $existing = Attendance::where('session_id', $session->id)
                        ->where('member_id', $e['member_id'])
                        ->first();
                    if ($existing) {
                        $existing->update([
                            'status' => $e['status'] ?? $existing->status,
                            'arrival_time' => $e['arrival_time'] ?? $existing->arrival_time,
                            'absence_reason_id' => $e['absence_reason_id'] ?? $existing->absence_reason_id,
                            'comment' => $e['comment'] ?? $existing->comment,
                            'updated_by' => $uid,
                            'scan_method' => 'bulk',
                        ]);
                        $updated++;
                    } else {
                        Attendance::create([
                            'session_id' => $session->id,
                            'member_id' => $e['member_id'],
                            'status' => $e['status'] ?? 'present',
                            'arrival_time' => $e['arrival_time'] ?? (in_array(($e['status'] ?? 'present'), ['present', 'retard']) ? now() : null),
                            'absence_reason_id' => $e['absence_reason_id'] ?? null,
                            'comment' => $e['comment'] ?? null,
                            'created_by' => $uid,
                            'updated_by' => $uid,
                            'scan_method' => 'bulk',
                        ]);
                        $created++;
                    }
                } catch (\Throwable $err) {
                    $errors[] = ['index' => $i, 'error' => $err->getMessage()];
                }
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return response()->json([
            'message' => "Traitement terminé : {$created} créés, {$updated} modifiés",
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors,
        ], 207);
    }

    public function update(UpdateAttendanceRequest $request, Attendance $attendance)
    {
        $data = $request->validated();
        $data['updated_by'] = auth()->id();

        if (isset($data['status']) && in_array($data['status'], ['present', 'retard'])
            && empty($data['arrival_time']) && !$attendance->arrival_time) {
            $data['arrival_time'] = now();
        }

        if ($attendance->session) {
            $lat = $data['latitude'] ?? $attendance->latitude;
            $lng = $data['longitude'] ?? $attendance->longitude;
            $ok = $attendance->session->isWithinGps($lat, $lng);
            if ($attendance->session->gps_required && !$ok && ($attendance->session->latitude !== null)) {
                return response()->json([
                    'message' => 'GPS hors zone',
                ], 400);
            }
            if ($lat !== null && $lng !== null) {
                $data['gps_verified'] = $ok;
            }
        }

        $attendance->update($data);
        $attendance->load(['member', 'session', 'absenceReason', 'updater']);

        return response()->json([
            'message' => 'Présence mise à jour',
            'attendance' => new AttendanceResource($attendance),
        ]);
    }

    public function destroy(Attendance $attendance)
    {
        $attendance->delete();
        return response()->json(['message' => 'Présence supprimée']);
    }

    public function scan(ScanAttendanceRequest $request)
    {
        DB::beginTransaction();
        try {
            $data = $request->validated();

            $session = AttendanceSession::where('qr_token', $data['qr_token'])->first();
            if (!$session) {
                return response()->json(['message' => 'QR Code invalide'], 404);
            }
            if (!$session->isQrValid()) {
                return response()->json([
                    'message' => 'QR Code expiré ou session inactive. Demandez un nouveau QR Code.',
                    'expired' => true,
                ], 410);
            }

            if (!empty($data['member_code'])) {
                $member = Member::where('member_code', $data['member_code'])->first();
            } else {
                $member = Member::find($data['member_id']);
            }
            if (!$member) {
                return response()->json(['message' => 'Membre introuvable'], 404);
            }

            $lat = $data['latitude'] ?? null;
            $lng = $data['longitude'] ?? null;

            $gpsOk = $session->isWithinGps($lat, $lng);
            if ($session->gps_required && !$gpsOk) {
                return response()->json([
                    'message' => 'Vérification GPS échouée : trop éloigné du lieu de culte.',
                    'session_gps_required' => true,
                    'gps_verified' => false,
                    'member' => [
                        'id' => $member->id,
                        'full_name' => $member->first_name . ' ' . $member->last_name,
                    ],
                ], 400);
            }

            $status = 'present';
            $start = Carbon::parse($session->session_date->toDateString() . ' ' . ($session->start_time instanceof \DateTimeInterface ? $session->start_time->format('H:i:s') : $session->start_time));
            if (Carbon::now()->greaterThan($start->addMinutes(15))) {
                $status = 'retard';
            }

            $existing = Attendance::where('session_id', $session->id)
                ->where('member_id', $member->id)
                ->first();

            if ($existing) {
                DB::commit();
                return response()->json([
                    'message' => 'Présence déjà enregistrée pour cette session.',
                    'already_registered' => true,
                    'gps_verified' => (bool)($lat !== null && $lng !== null && $gpsOk),
                    'attendance' => new AttendanceResource($existing->load(['member', 'session'])),
                ], 409);
            }

            $att = Attendance::create([
                'session_id' => $session->id,
                'member_id' => $member->id,
                'status' => $status,
                'arrival_time' => now(),
                'latitude' => $lat,
                'longitude' => $lng,
                'gps_verified' => (bool)($lat !== null && $lng !== null && $gpsOk),
                'scan_method' => 'qr',
                'created_by' => auth()->check() ? auth()->id() : null,
                'updated_by' => auth()->check() ? auth()->id() : null,
            ]);
            $att->load(['member', 'session']);

            DB::commit();
            return response()->json([
                'message' => "Présence enregistrée : {$member->first_name} {$member->last_name} ({$status})",
                'status' => $status,
                'already_registered' => false,
                'gps_verified' => (bool)$att->gps_verified,
                'attendance' => new AttendanceResource($att),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function memberHistory(Request $request, $memberId)
    {
        $member = Member::findOrFail($memberId);

        $attendances = Attendance::with(['session', 'absenceReason'])
            ->where('member_id', $member->id)
            ->whereHas('session', function ($q) {
                ScopeHelper::applyOwnedByScope($q);
            })
            ->latest('created_at')
            ->paginate($request->per_page ?? 20);

        $total = Attendance::where('member_id', $member->id)
            ->whereHas('session', function ($q) {
                ScopeHelper::applyOwnedByScope($q);
            })->count();
        $present = Attendance::where('member_id', $member->id)->where('status', 'present')
            ->whereHas('session', fn($q) => ScopeHelper::applyOwnedByScope($q))->count();
        $absent = Attendance::where('member_id', $member->id)->whereIn('status', ['absent','absent_excuse'])
            ->whereHas('session', fn($q) => ScopeHelper::applyOwnedByScope($q))->count();
        $late = Attendance::where('member_id', $member->id)->where('status', 'retard')
            ->whereHas('session', fn($q) => ScopeHelper::applyOwnedByScope($q))->count();
        $rate = $total > 0 ? round(($present / $total) * 100, 1) : 0;

        return response()->json([
            'member' => [
                'id' => $member->id,
                'full_name' => $member->first_name . ' ' . $member->last_name,
                'member_code' => $member->member_code,
            ],
            'summary' => compact('total', 'present', 'absent', 'late', 'rate'),
            'attendances' => AttendanceResource::collection($attendances),
        ]);
    }
}
