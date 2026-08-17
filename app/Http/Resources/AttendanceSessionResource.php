<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $presentCount = $this->whenCounted('attendances')
            ? ($this->attendances_count ?? 0)
            : $this->attendances()->count();

        $present = $this->attendances()->present()->count();
        $absent = $this->attendances()->absent()->count();
        $late = $this->attendances()->late()->count();
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
            'qr_token' => $this->qr_token,
            'qr_expires_at' => $this->qr_expires_at?->format('d/m/Y H:i'),
            'qr_valid' => $this->isQrValid(),
            'stats' => [
                'total' => $totalExpected,
                'present' => $present,
                'absent' => $absent,
                'late' => $late,
                'attendance_rate' => $rate,
            ],
            'attendances_count' => $presentCount,
            'created_by' => $this->whenLoaded('creator'),
            'updated_by' => $this->whenLoaded('updater'),
            'attendances' => AttendanceResource::collection($this->whenLoaded('attendances')),
            'created_at' => $this->created_at?->format('d/m/Y H:i'),
            'updated_at' => $this->updated_at?->format('d/m/Y H:i'),
        ];
    }
}
