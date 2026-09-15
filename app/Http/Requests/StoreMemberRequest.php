<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMemberRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules.
     */
    public function rules(): array
    {
        return [

            'member_code' => 'nullable|string|max:50|unique:members,member_code',

            'first_name' => 'required|string|max:100',

            'last_name' => 'required|string|max:100',

            'gender' => 'required|in:Homme,Femme',

            'birth_date' => 'nullable|date',

            'birth_place' => 'nullable|string|max:255',

            'phone' => 'nullable|string|max:30',

            'email' => 'nullable|email|unique:members,email',

            'address' => 'nullable|string|max:255',

            'city' => 'nullable|string|max:100',

            'country' => 'nullable|string|max:100',

            'profession' => 'nullable|string|max:150',

            'marital_status' => 'nullable|in:Célibataire,Marié,Divorcé,Veuf',

            'spouse_name' => 'nullable|string|max:150',

            'conversion_date' => 'nullable|date',

            'baptism_date' => 'nullable|date',

            'membership_date' => 'nullable|date',

            'photo' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',

            'family_id' => 'nullable|exists:families,id',

            'member_type' => 'required',

            'ministries' => 'nullable|array',

            'ministries.*' => 'exists:ministries,id',

            'emergency_contact' => 'nullable|string|max:150',

            'emergency_phone' => 'nullable|string|max:30',

            'status' => 'nullable|boolean',

            'church_id' => 'nullable|exists:churches,id',

        ];
    }
}