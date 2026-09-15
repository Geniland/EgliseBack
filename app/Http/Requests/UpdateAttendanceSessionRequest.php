<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAttendanceSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'sometimes|required|string|max:255',
            'session_date' => 'sometimes|required|date',
            'start_time' => 'sometimes|required|date_format:H:i,H:i:s',
            'end_time' => 'sometimes|required|date_format:H:i,H:i:s',
            'type' => ['nullable', Rule::in([
                'Culte dominical', 'Réunion de prière', 'Étude biblique', 
                'Réunion des jeunes', 'Culte des enfants', 'Mariage', 
                'Baptême', 'Conférence', 'Atelier de formation', 'Autre'
            ])],
            'description' => 'sometimes|nullable|string|max:1000',
            'location' => 'sometimes|nullable|string|max:255',
            'latitude' => 'sometimes|nullable|numeric|between:-90,90',
            'longitude' => 'sometimes|nullable|numeric|between:-180,180',
            'gps_radius_meters' => 'sometimes|nullable|integer|min:10|max:50000',
            'gps_required' => 'sometimes|nullable|boolean',
            'status' => 'sometimes|nullable|boolean',
        ];
    }
}
