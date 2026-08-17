<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Event;

class StoreEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:200',
            'description' => 'nullable|string',
            'type' => 'required|string|in:' . implode(',', array_keys(Event::types())),
            'event_date' => 'required|date',
            'start_time' => 'required|date_format:H:i,H:i:s',
            'end_time' => 'nullable|date_format:H:i,H:i:s|after:start_time',
            'location' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'image_path' => 'nullable|string|max:500',
            'notes' => 'nullable|string',
            'status' => 'nullable|in:' . implode(',', array_keys(Event::statuses())),
            'is_featured' => 'nullable|boolean',
            'max_attendees' => 'nullable|integer|min:1',
            'organizer' => 'nullable|string|max:150',
            'contact_email' => 'nullable|email|max:150',
            'contact_phone' => 'nullable|string|max:30',
        ];
    }
}
