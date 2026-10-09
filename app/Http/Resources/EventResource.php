<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'type' => $this->type,
            'type_label' => $this->type_label,
            'event_date' => $this->event_date?->toDateString(),
            'start_time' => $this->start_time?->format('H:i'),
            'end_time' => $this->end_time?->format('H:i'),
            'location' => $this->location,
            'address' => $this->address,
            'image_path' => $this->image_path,
            'notes' => $this->notes,
            'status' => $this->status,
            'status_label' => $this->status_label,
            'is_featured' => (bool) $this->is_featured,
            'max_attendees' => $this->max_attendees,
            'organizer' => $this->organizer,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'formatted_date' => $this->formatted_date,
            'formatted_time' => $this->formatted_time,
            'is_upcoming' => $this->isUpcoming(),
            'is_past' => $this->isPast(),
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'creator' => $this->whenLoaded('creator', function () {
                return $this->creator ? [
                    'id' => $this->creator->id,
                    'name' => $this->creator->name,
                ] : null;
            }),
            'updater' => $this->whenLoaded('updater', function () {
                return $this->updater ? [
                    'id' => $this->updater->id,
                    'name' => $this->updater->name,
                ] : null;
            }),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
            'deleted_at' => $this->deleted_at?->toDateTimeString(),
        ];
    }
}
