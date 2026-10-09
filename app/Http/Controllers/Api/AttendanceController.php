<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ScanAttendanceRequest;
use App\Http\Requests\ScanMemberQrRequest;
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
    private function findVisibleAttendance(Attendance $attendance): Attendance
    {
        $visible = Attendance::with('member')
            ->whereKey($attendance->id)
            ->where(function ($query) {
                $query->whereHas('session', fn ($session) => ScopeHelper::applyOwnedByScope($session))
                    ->orWhere(function ($ownAttendance) {
                        ScopeHelper::applyOwnedByScope($ownAttendance);
                    });
            })
            ->firstOrFail();

        abort_unless(!$visible->member || ScopeHelper::canAccessMember($visible->member), 404);

        return $visible;
    }

    protected function resolveGpsFromRequest(Request $request): ?array
    {
        $all = $request->all();

        // payload JSON encapsulé
        foreach (['qr_payload', 'payload', 'data'] as $key) {
            if (is_string($all[$key] ?? null) && ($all[$key][0] ?? '') === '{') {
                try {
                    $decoded = json_decode($all[$key], true, 3);
                    if (is_array($decoded)) $all = array_merge($all, $decoded);
                } catch (\Throwable) { /* ignore */ }
            }
        }

        $coords = $all['coords'] ?? null;
        if (is_array($coords)) {
            $all['latitude'] ??= $coords['latitude'] ?? $coords['lat'] ?? null;
            $all['longitude'] ??= $coords['longitude'] ?? $coords['lng'] ?? $coords['lon'] ?? $coords['long'] ?? null;
            $all['accuracy'] ??= $coords['accuracy'] ?? null;
        }

        $all['latitude'] ??= $all['lat'] ?? null;
        $all['longitude'] ??= $all['lng'] ?? $all['long'] ?? $all['lon'] ?? null;
        foreach (['position', 'gps', 'location'] as $k) {
            $sub = $all[$k] ?? null;
            if (is_array($sub)) {
                $all['latitude'] ??= $sub['latitude'] ?? $sub['lat'] ?? null;
                $all['longitude'] ??= $sub['longitude'] ?? $sub['lng'] ?? $sub['lon'] ?? $sub['long'] ?? null;
                $all['accuracy'] ??= $sub['accuracy'] ?? null;
            }
        }

        $lat = $all['latitude'] ?? null;
        $lng = $all['longitude'] ?? null;
        if ($lat === null || $lng === null || $lat === '' || $lng === '') return null;

        if (!is_numeric($lat) || !is_numeric($lng)) return null;
        if ($lat < -90 || $lat > 90) return null;
        if ($lng < -180 || $lng > 180) return null;

        $accuracy = isset($all['accuracy']) && is_numeric($all['accuracy']) ? (float)$all['accuracy'] : null;
        return [
            'lat' => (float)$lat,
            'lng' => (float)$lng,
            'accuracy' => $accuracy,
        ];
    }

    public function index(Request $request)
    {
        $q = Attendance::with(['member', 'session', 'absenceReason', 'creator'])
            ->when($request->session_id, fn($qq) => $qq->where('session_id', $request->session_id))
            ->when($request->member_id, fn($qq) => $qq->where('member_id', $request->member_id))
            ->when($request->status, fn($qq) => $qq->where('status', $request->status))
            ->when($request->date, fn($qq) => $qq->whereHas('session', fn($s) => $s->whereDate('session_date', $request->date)))
            ->where(function ($scope) {
                $scope->whereHas('session', fn ($session) => ScopeHelper::applyOwnedByScope($session))
                    ->orWhere(function ($noSession) {
                        $noSession->whereDoesntHave('session');
                        ScopeHelper::applyOwnedByScope($noSession, 'attendances.created_by');
                    });
            })
            ->whereHas('member', fn ($member) => ScopeHelper::applyMemberScope($member));

        $attendances = $q->latest()->paginate($request->per_page ?? 30);

        return AttendanceResource::collection($attendances);
    }

    public function show(Attendance $attendance)
    {
        $attendance = $this->findVisibleAttendance($attendance);
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

            $session = ScopeHelper::findOwnedOrFail(AttendanceSession::class, $data['session_id']);
            abort_unless(ScopeHelper::canAccessMember(Member::findOrFail($data['member_id'])), 403);
            if ($session) {
                $gps = $this->resolveGpsFromRequest($request);
                if ($gps === null && !empty($data['latitude']) && !empty($data['longitude'])) {
                    $gps = [
                        'lat' => (float)$data['latitude'],
                        'lng' => (float)$data['longitude'],
                        'accuracy' => null,
                    ];
                }
                $lat = $gps ? $gps['lat'] : null;
                $lng = $gps ? $gps['lng'] : null;
                $acc = $gps ? ($gps['accuracy'] ?? null) : null;

                if ($session->gps_required) {
                    if ($session->latitude === null || $session->longitude === null) {
                        DB::rollBack();
                        return response()->json([
                            'message' => 'Session mal configurée : les coordonnées GPS de l\'église n\'ont pas été renseignées.',
                            'gps_required' => true, 'gps_verified' => false, 'diagnostic' => 'missing_session_coords',
                        ], 400);
                    }
                    if ($lat === null || $lng === null) {
                        DB::rollBack();
                        return response()->json([
                            'message' => 'Coordonnées GPS non transmises par votre appareil. Veuillez autoriser la géolocalisation.',
                            'gps_required' => true, 'gps_verified' => false, 'diagnostic' => 'missing_mobile_coords',
                        ], 400);
                    }
                    $distance = $session->calculateDistanceMeters($lat, $lng);
                    $okGps = $session->isWithinGps($lat, $lng, $acc);
                    if (!$okGps) {
                        $effRadius = max((int)$session->gps_radius_meters, 150) + ($acc ? min((float)$acc, 100.0) : 0.0);
                        DB::rollBack();
                        return response()->json([
                            'message' => sprintf(
                                'Vérification GPS échouée : vous êtes à %s m du lieu autorisé (rayon autorisé : %s m, imprécision mobile : %s m). Approchez-vous du point de culte.',
                                $distance !== null ? (int)$distance : '?',
                                (int)$effRadius,
                                $acc !== null ? (int)$acc : 'inconnue'
                            ),
                            'gps_required' => true,
                            'gps_verified' => false,
                            'diagnostic' => [
                                'distance_meters' => $distance,
                                'authorized_radius_meters' => $effRadius,
                                'session' => ['lat' => $session->latitude, 'lng' => $session->longitude, 'radius' => $session->gps_radius_meters],
                                'device' => ['lat' => $lat, 'lng' => $lng, 'accuracy' => $acc],
                            ],
                        ], 400);
                    }
                    $data['latitude'] = $lat;
                    $data['longitude'] = $lng;
                    $data['gps_verified'] = true;
                } else {
                    if ($lat !== null && $lng !== null) {
                        $data['latitude'] = $lat;
                        $data['longitude'] = $lng;
                        $data['gps_verified'] = true;
                    }
                }
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

        $session = ScopeHelper::findOwnedOrFail(AttendanceSession::class, $valid['session_id']);
        foreach ($valid['entries'] as $entry) {
            abort_unless(ScopeHelper::canAccessMember(Member::findOrFail($entry['member_id'])), 403);
        }
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
        $attendance = $this->findVisibleAttendance($attendance);
        $data = $request->validated();
        $data['updated_by'] = auth()->id();
        if (isset($data['session_id'])) {
            ScopeHelper::findOwnedOrFail(AttendanceSession::class, $data['session_id']);
        }
        if (isset($data['member_id'])) {
            abort_unless(ScopeHelper::canAccessMember(Member::findOrFail($data['member_id'])), 403);
        }

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
        $attendance = $this->findVisibleAttendance($attendance);
        $attendance->delete();
        return response()->json(['message' => 'Présence supprimée']);
    }

    public function scan(ScanAttendanceRequest $request)
    {
        DB::beginTransaction();
        try {
            $data = $request->validated();
            $gps = $request->getNormalizedGps() ?? $this->resolveGpsFromRequest($request);

            $session = AttendanceSession::where('qr_token', $data['qr_token'])->first();
            if (!$session) {
                return response()->json(['message' => 'QR Code invalide'], 404);
            }
            $session = ScopeHelper::findOwnedOrFail(AttendanceSession::class, $session->id);
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
            abort_unless(ScopeHelper::canAccessMember($member), 404);

            $lat = $gps['lat'] ?? null;
            $lng = $gps['lng'] ?? null;
            $acc = $gps['accuracy'] ?? null;

            $gpsOk = true;
            if ($session->gps_required) {
                if ($session->latitude === null || $session->longitude === null) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Session mal configurée : les coordonnées GPS de l\'église n\'ont pas été renseignées.',
                        'session_gps_required' => true,
                        'gps_verified' => false,
                        'diagnostic' => 'missing_session_coords',
                        'member' => [
                            'id' => $member->id,
                            'full_name' => $member->first_name . ' ' . $member->last_name,
                        ],
                    ], 400);
                }
                if ($lat === null || $lng === null) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Coordonnées GPS non transmises par votre appareil. Veuillez autoriser la géolocalisation.',
                        'session_gps_required' => true,
                        'gps_verified' => false,
                        'diagnostic' => 'missing_mobile_coords',
                        'member' => [
                            'id' => $member->id,
                            'full_name' => $member->first_name . ' ' . $member->last_name,
                        ],
                    ], 400);
                }
                $distance = $session->calculateDistanceMeters($lat, $lng);
                $gpsOk = $session->isWithinGps($lat, $lng, $acc);
                if (!$gpsOk) {
                    $effRadius = max((int)$session->gps_radius_meters, 150) + ($acc ? min((float)$acc, 100.0) : 0.0);
                    DB::rollBack();
                    return response()->json([
                        'message' => sprintf(
                            'Vérification GPS échouée : vous êtes à %s m du lieu autorisé (rayon : %s m, imprécision mobile : %s m). Approchez-vous du point de culte.',
                            $distance !== null ? (int)$distance : '?',
                            (int)$effRadius,
                            $acc !== null ? (int)$acc : 'inconnue'
                        ),
                        'session_gps_required' => true,
                        'gps_verified' => false,
                        'diagnostic' => [
                            'distance_meters' => $distance,
                            'authorized_radius_meters' => $effRadius,
                            'session' => ['lat' => $session->latitude, 'lng' => $session->longitude, 'radius' => $session->gps_radius_meters],
                            'device' => ['lat' => $lat, 'lng' => $lng, 'accuracy' => $acc],
                        ],
                        'member' => [
                            'id' => $member->id,
                            'full_name' => $member->first_name . ' ' . $member->last_name,
                        ],
                    ], 400);
                }
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
        abort_unless(ScopeHelper::canAccessMember($member), 404);

        $attendances = Attendance::with(['session', 'absenceReason'])
            ->where('member_id', $member->id)
            ->whereHas('member', fn ($q) => ScopeHelper::applyMemberScope($q))
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

    public function scanMember(ScanMemberQrRequest $request)
    {
        DB::beginTransaction();
        try {
            $data = $request->validated();
            $gps = $request->getNormalizedGps() ?? $this->resolveGpsFromRequest($request);

            $session = AttendanceSession::find($data['session_id']);
            if (!$session) {
                return response()->json(['message' => 'Session introuvable'], 404);
            }
            if ($session->status === false) {
                return response()->json(['message' => 'Session clôturée'], 400);
            }
            if (ScopeHelper::isSuperAdmin() === false) {
                $myChurchIds = ScopeHelper::getMyChurchIds();
                if (auth()->user() && auth()->user()->church_id) {
                    $myChurchIds[] = (int)auth()->user()->church_id;
                }
                $myChurchIds = array_values(array_unique(array_filter($myChurchIds)));
                $ok = in_array((int)$session->church_id, $myChurchIds, true)
                    || (int)($session->created_by ?? 0) === (int)auth()->id();
                if (!$ok) {
                    return response()->json(['message' => 'Accès non autorisé à cette session'], 403);
                }
            }

            if (!empty($data['member_qr_token'])) {
                $member = Member::byQrToken($data['member_qr_token'])->first();
            } elseif (!empty($data['member_code'])) {
                $member = Member::where('member_code', $data['member_code'])->first();
            } else {
                $member = Member::find($data['member_id']);
            }
            if (!$member) {
                return response()->json(['message' => 'Membre introuvable - QR Code invalide'], 404);
            }
            if (!$member->status) {
                return response()->json(['message' => 'Ce membre est désactivé'], 400);
            }

            abort_unless(ScopeHelper::canAccessMember($member), 404);

            $lat = $gps['lat'] ?? null;
            $lng = $gps['lng'] ?? null;
            $acc = $gps['accuracy'] ?? null;

            $gpsOk = true;
            if ($session->gps_required) {
                if ($session->latitude === null || $session->longitude === null) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Session mal configurée : coordonnées GPS église manquantes.',
                        'gps_verified' => false,
                        'diagnostic' => 'missing_session_coords',
                        'member' => [
                            'id' => $member->id,
                            'full_name' => $member->first_name . ' ' . $member->last_name,
                            'member_code' => $member->member_code,
                        ],
                    ], 400);
                }
                if ($lat === null || $lng === null) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Coordonnées GPS manquantes. Autorisez la géolocalisation.',
                        'gps_verified' => false,
                        'diagnostic' => 'missing_mobile_coords',
                        'member' => [
                            'id' => $member->id,
                            'full_name' => $member->first_name . ' ' . $member->last_name,
                            'member_code' => $member->member_code,
                        ],
                    ], 400);
                }
                $distance = $session->calculateDistanceMeters($lat, $lng);
                $gpsOk = $session->isWithinGps($lat, $lng, $acc);
                if (!$gpsOk) {
                    $effRadius = max((int)$session->gps_radius_meters, 150) + ($acc ? min((float)$acc, 100.0) : 0.0);
                    DB::rollBack();
                    return response()->json([
                        'message' => sprintf(
                            'GPS hors zone : vous êtes à %s m (rayon autorisé %s m, imprécision %s m).',
                            $distance !== null ? (int)$distance : '?',
                            (int)$effRadius,
                            $acc !== null ? (int)$acc : 'inconnue'
                        ),
                        'gps_verified' => false,
                        'diagnostic' => [
                            'distance_meters' => $distance,
                            'authorized_radius_meters' => $effRadius,
                        ],
                        'member' => [
                            'id' => $member->id,
                            'full_name' => $member->first_name . ' ' . $member->last_name,
                            'member_code' => $member->member_code,
                        ],
                    ], 400);
                }
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
                    'message' => 'Présence déjà enregistrée : ' . $member->first_name . ' ' . $member->last_name,
                    'already_registered' => true,
                    'gps_verified' => (bool)($lat !== null && $lng !== null && $gpsOk),
                    'member' => [
                        'id' => $member->id,
                        'full_name' => $member->first_name . ' ' . $member->last_name,
                        'member_code' => $member->member_code,
                    ],
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
                'scan_method' => 'member_qr',
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
                'member' => [
                    'id' => $member->id,
                    'full_name' => $member->first_name . ' ' . $member->last_name,
                    'member_code' => $member->member_code,
                    'photo' => $member->photo,
                ],
                'attendance' => new AttendanceResource($att),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
