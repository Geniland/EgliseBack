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
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceSessionController extends Controller
{
    public function index(Request $request)
    {
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

    public function show(AttendanceSession $attendanceSession)
    {
        $attendanceSession->load(['creator', 'updater', 'attendances.member']);
        return new AttendanceSessionResource($attendanceSession);
    }

    public function store(StoreAttendanceSessionRequest $request)
    {
        $data = $request->validated();
        $data['created_by'] = auth()->id();
        $data['updated_by'] = auth()->id();
        $data['status'] = $data['status'] ?? true;

        $session = AttendanceSession::create($data);
        $session->load(['creator']);

        return response()->json([
            'message' => 'Session de présence créée avec succès',
            'session' => new AttendanceSessionResource($session),
        ], 201);
    }

    public function update(UpdateAttendanceSessionRequest $request, AttendanceSession $attendanceSession)
    {
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

        $repeated = DB::table('attendances')
            ->select('member_id', DB::raw('COUNT(*) as cnt'))
            ->whereIn('status', ['absent', 'absent_excuse'])
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('member_id')
            ->having('cnt', '>=', 3)
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

    public function invalidateQr(AttendanceSession $attendanceSession)
    {
        $attendanceSession->invalidateQr();
        return response()->json(['message' => 'QR Code invalide']);
    }

    public function markAllAbsent(Request $request, AttendanceSession $attendanceSession)
    {
        $override = (bool) $request->input('override', false);
        $createdBy = auth()->id();

        $members = Member::query()->tap(fn($q) => ScopeHelper::applyOwnedByScope($q))
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
