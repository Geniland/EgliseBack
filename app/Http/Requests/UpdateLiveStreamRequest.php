<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLiveStreamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_id' => 'nullable|exists:events,id',
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'scheduled_at' => 'nullable|date',
            'status' => 'sometimes|string|in:draft,scheduled,ready,live,ending,processing,ended,cancelled,error',
            'youtube_url' => 'nullable|url',
        ];
    }
}
