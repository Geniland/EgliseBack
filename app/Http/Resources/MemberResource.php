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

            'qr_token' => $this->qr_token,

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

            'photo_url' => $this->photo ? (str_starts_with($this->photo, 'http') ? $this->photo : asset('storage/' . $this->photo)) : null,

            'emergency_contact' => $this->emergency_contact,

            'emergency_phone' => $this->emergency_phone,

            'status' => $this->status,

            'church_id' => $this->church_id,

            'church' => $this->church ? [
                'id' => $this->church->id,
                'name' => $this->church->name,
                'code' => $this->church->code,
            ] : ($this->user && $this->user->church ? [
                'id' => $this->user->church->id,
                'name' => $this->user->church->name,
                'code' => $this->user->church->code,
            ] : null),

            'family' => $this->whenLoaded('family'),

            'ministries' => $this->whenLoaded('ministries'),

            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null),

            'updated_by' => $this->whenLoaded('updater', fn () => $this->updater ? [
                'id' => $this->updater->id,
                'name' => $this->updater->name,
            ] : null),

            'created_at' => $this->created_at?->format('d/m/Y H:i'),

            'updated_at' => $this->updated_at?->format('d/m/Y H:i'),

        ];
    }
}
