<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionAttachmentResource extends JsonResource
{

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transaction_id' => $this->transaction_id,
            'original_name' => $this->original_name,
            'file_path' => $this->file_path,
            'file_url' => $this->file_url,
            'mime_type' => $this->mime_type,
            'file_size' => (int) $this->file_size,
            'formatted_size' => $this->formatted_size,
            'extension' => $this->extension,
            'is_image' => $this->is_image,
            'is_pdf' => $this->is_pdf,
            'uploaded_by' => $this->uploaded_by,
            'uploader' => $this->whenLoaded('uploadedBy', function () {
                return $this->uploadedBy ? [
                    'id' => $this->uploadedBy->id,
                    'name' => $this->uploadedBy->name,
                    'email' => $this->uploadedBy->email,
                ] : null;
            }),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
