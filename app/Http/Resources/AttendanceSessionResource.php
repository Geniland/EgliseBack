<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Support\ScopeHelper;

class AttendanceSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $attendanceRows = $this->relationLoaded('attendances')
            ? $this->getRelation('attendances')
            : null;
        $scopedAttendances = $this->attendances()->whereHas('member', fn ($query) => ScopeHelper::applyMemberScope($query));
        $presentCount = $attendanceRows
            ? $attendanceRows->count()
            : $scopedAttendances->count();
        $present = $attendanceRows
            ? $attendanceRows->where('status', 'present')->count()
            : (clone $scopedAttendances)->present()->count();
        $absent = $attendanceRows
            ? $attendanceRows->whereIn('status', ['absent', 'absent_excuse'])->count()
            : (clone $scopedAttendances)->absent()->count();
        $late = $attendanceRows
            ? $attendanceRows->where('status', 'retard')->count()
            : (clone $scopedAttendances)->late()->count();
        $canManageQr = $request->user()?->hasPermission('attendance.create|attendance.scan|attendance.update') ?? false;
        $totalExpected = $present + $absent + $late;
        $rate = $totalExpected > 0 ? round(($present / $totalExpected) * 100, 1) : 0;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'session_date' => $this->session_date?->format('d/m/Y'),
            'session_date_iso' => $this->session_date?->toDateString(),
            'start_time' => $this->start_time instanceof \DateTimeInterface ? $this->start_time->format('H:i') : $this->start_time,
            'end_time' => $this->end_time instanceof \DateTimeInterface ? $this->end_time->format('H:i') : $this->end_time,
            'type' => $this->type,
            'description' => $this->description,
            'location' => $this->location,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'gps_radius_meters' => $this->gps_radius_meters,
            'gps_required' => (bool) $this->gps_required,
            'status' => (bool) $this->status,
            'qr_token' => $this->when($canManageQr, $this->qr_token),
            'qr_expires_at' => $this->when($canManageQr, $this->qr_expires_at?->format('d/m/Y H:i')),
            'qr_valid' => $this->when($canManageQr, $this->isQrValid()),
            'stats' => [
                'total' => $totalExpected,
                'present' => $present,
                'absent' => $absent,
                'late' => $late,
                'attendance_rate' => $rate,
            ],
            'attendances_count' => $presentCount,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null),
            'updated_by' => $this->whenLoaded('updater', fn () => $this->updater ? [
                'id' => $this->updater->id,
                'name' => $this->updater->name,
            ] : null),
            'attendances' => AttendanceResource::collection($this->whenLoaded('attendances')),
            'created_at' => $this->created_at?->format('d/m/Y H:i'),
            'updated_at' => $this->updated_at?->format('d/m/Y H:i'),
        ];
    }
}
