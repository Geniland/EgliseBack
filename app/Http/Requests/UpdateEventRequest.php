<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Event;

class UpdateEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'sometimes|required|string|max:200',
            'description' => 'nullable|string',
            'type' => 'sometimes|required|string|in:' . implode(',', array_keys(Event::types())),
            'event_date' => 'sometimes|required|date',
            'start_time' => 'sometimes|required|date_format:H:i,H:i:s',
            'end_time' => 'nullable|date_format:H:i,H:i:s|after:start_time',
            'location' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'image_path' => 'nullable|string|max:500',
            'notes' => 'nullable|string',
            'status' => 'sometimes|in:' . implode(',', array_keys(Event::statuses())),
            'is_featured' => 'nullable|boolean',
            'max_attendees' => 'nullable|integer|min:1',
            'organizer' => 'nullable|string|max:150',
            'contact_email' => 'nullable|email|max:150',
            'contact_phone' => 'nullable|string|max:30',
        ];
    }
}
