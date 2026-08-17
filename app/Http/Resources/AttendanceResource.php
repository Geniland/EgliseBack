<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'session_id' => $this->session_id,
            'member_id' => $this->member_id,
            'status' => $this->status,
            'status_label' => match ($this->status) {
                'present' => 'Présent',
                'absent' => 'Absent',
                'absent_excuse' => 'Absent excusé',
                'retard' => 'En retard',
                default => $this->status,
            },
            'arrival_time' => $this->arrival_time?->format('d/m/Y H:i'),
            'arrival_time_iso' => $this->arrival_time?->toDateTimeString(),
            'absence_reason_id' => $this->absence_reason_id,
            'absence_note' => $this->absence_note,
            'comment' => $this->comment,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'gps_verified' => (bool) $this->gps_verified,
            'scan_method' => $this->scan_method,
            'session' => new AttendanceSessionResource($this->whenLoaded('session')),
            'member' => new MemberResource($this->whenLoaded('member')),
            'absence_reason' => $this->whenLoaded('absenceReason'),
            'created_by' => $this->whenLoaded('creator'),
            'updated_by' => $this->whenLoaded('updater'),
            'created_at' => $this->created_at?->format('d/m/Y H:i'),
            'updated_at' => $this->updated_at?->format('d/m/Y H:i'),
        ];
    }
}
