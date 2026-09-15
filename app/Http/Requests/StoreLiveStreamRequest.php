<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLiveStreamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorizations are handled in the controller
    }

    public function rules(): array
    {
        return [
            'event_id' => 'nullable|exists:events,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'scheduled_at' => 'nullable|date',
            'status' => 'nullable|string|in:draft,scheduled',
            'youtube_url' => 'nullable|url',
        ];
    }
}
