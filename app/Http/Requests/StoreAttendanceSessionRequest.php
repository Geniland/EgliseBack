<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAttendanceSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'session_date' => 'required|date',
            'start_time' => 'required|date_format:H:i,H:i:s',
            'end_time' => 'required|date_format:H:i,H:i:s|after:start_time',
            'type' => ['required', Rule::in([
                'Culte dominical', 'Réunion de prière', 'Étude biblique', 
                'Réunion des jeunes', 'Culte des enfants', 'Mariage', 
                'Baptême', 'Conférence', 'Atelier de formation', 'Autre'
            ])],
            'description' => 'nullable|string|max:1000',
            'location' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'gps_radius_meters' => 'nullable|integer|min:10|max:50000',
            'gps_required' => 'nullable|boolean',
            'status' => 'nullable|boolean',
        ];
    }
}
