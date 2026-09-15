<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LiveStreamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'provider_live_input_id' => $this->provider_live_input_id,
            'playback_url' => $this->playback_url,
            'hls_url' => $this->hls_url,
            'scheduled_at' => $this->scheduled_at,
            'started_at' => $this->started_at,
            'ended_at' => $this->ended_at,
            'recording_url' => $this->recording_url,
            
            // Only include event if loaded
            'event' => $this->whenLoaded('event'),
            
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
