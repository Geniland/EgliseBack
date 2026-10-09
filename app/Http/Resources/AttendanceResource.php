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
            'member' => $this->whenLoaded('member', function () use ($request) {
                if (!$this->member) return null;

                if ($request->user()?->hasPermission('members.view')) {
                    return (new MemberResource($this->member))->resolve($request);
                }

                return [
                    'id' => $this->member->id,
                    'member_code' => $this->member->member_code,
                    'first_name' => $this->member->first_name,
                    'last_name' => $this->member->last_name,
                    'full_name' => trim($this->member->first_name . ' ' . $this->member->last_name),
                ];
            }),
            'absence_reason' => $this->whenLoaded('absenceReason'),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null),
            'updated_by' => $this->whenLoaded('updater', fn () => $this->updater ? [
                'id' => $this->updater->id,
                'name' => $this->updater->name,
            ] : null),
            'created_at' => $this->created_at?->format('d/m/Y H:i'),
            'updated_at' => $this->updated_at?->format('d/m/Y H:i'),
        ];
    }
}
