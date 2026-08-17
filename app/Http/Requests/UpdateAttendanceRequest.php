<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $attendanceId = optional($this->route('attendance'))->id ?? 'NULL';

        return [
            'session_id' => [
                'sometimes',
                'required',
                'exists:attendance_sessions,id',
                Rule::unique('attendances')->where(function ($q) {
                    return $q->where('member_id', $this->input('member_id'))
                             ->whereNull('deleted_at');
                })->ignore($attendanceId)
            ],
            'member_id' => 'sometimes|required|exists:members,id',
            'status' => ['sometimes', 'required', Rule::in(['present', 'absent', 'absent_excuse', 'retard'])],
            'arrival_time' => 'sometimes|nullable|date',
            'absence_reason_id' => 'sometimes|nullable|exists:absence_reasons,id',
            'absence_note' => 'sometimes|nullable|string|max:1000',
            'comment' => 'sometimes|nullable|string|max:1000',
            'latitude' => 'sometimes|nullable|numeric|between:-90,90',
            'longitude' => 'sometimes|nullable|numeric|between:-180,180',
            'scan_method' => ['sometimes', Rule::in(['manual', 'qr', 'auto', 'bulk'])],
        ];
    }
}
