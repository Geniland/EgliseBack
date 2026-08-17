<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'member_code' => $this->member_code,

            'first_name' => $this->first_name,

            'last_name' => $this->last_name,

            'full_name' => $this->first_name . ' ' . $this->last_name,

            'gender' => $this->gender,

            'birth_date' => $this->birth_date,

            'birth_place' => $this->birth_place,

            'phone' => $this->phone,

            'email' => $this->email,

            'address' => $this->address,

            'city' => $this->city,

            'country' => $this->country,

            'profession' => $this->profession,

            'marital_status' => $this->marital_status,

            'spouse_name' => $this->spouse_name,

            'member_type' => $this->member_type,

            'conversion_date' => $this->conversion_date,

            'baptism_date' => $this->baptism_date,

            'membership_date' => $this->membership_date,

            'photo' => $this->photo,

            'emergency_contact' => $this->emergency_contact,

            'emergency_phone' => $this->emergency_phone,

            'status' => $this->status,

            'family' => $this->whenLoaded('family'),

            'ministries' => $this->whenLoaded('ministries'),

            'created_by' => $this->whenLoaded('creator'),

            'updated_by' => $this->whenLoaded('updater'),

            'created_at' => $this->created_at?->format('d/m/Y H:i'),

            'updated_at' => $this->updated_at?->format('d/m/Y H:i'),

        ];
    }
}