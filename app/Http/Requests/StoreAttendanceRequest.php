<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'session_id' => [
                'required',
                'exists:attendance_sessions,id',
                Rule::unique('attendances')->where(function ($q) {
                    return $q->where('member_id', $this->input('member_id'))
                             ->whereNull('deleted_at');
                })
            ],
            'member_id' => 'required|exists:members,id',
            'status' => ['sometimes', 'required', Rule::in(['present', 'absent', 'absent_excuse', 'retard'])],
            'arrival_time' => 'nullable|date',
            'absence_reason_id' => 'nullable|exists:absence_reasons,id',
            'absence_note' => 'nullable|string|max:1000',
            'comment' => 'nullable|string|max:1000',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'scan_method' => ['sometimes', Rule::in(['manual', 'qr', 'auto', 'bulk'])],
        ];
    }

    public function messages(): array
    {
        return [
            'session_id.unique' => 'Ce membre a déjà une présence enregistrée pour cette session.',
        ];
    }
}
