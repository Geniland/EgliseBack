<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Transaction;
use App\Models\TransactionAttachment;
use App\Support\ScopeHelper;

class StoreTransactionAttachmentRequest extends FormRequest
{

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxKb = TransactionAttachment::maxFileSizeKb();
        $ext = implode(',', TransactionAttachment::allowedExtensions());
        $mimes = implode(',', TransactionAttachment::allowedExtensions());

        return [
            'transaction_id' => [
                'required',
                'integer',
                'exists:transactions,id',
                function ($attr, $val, $fail) {
                    if (!ScopeHelper::recordBelongsToTeam(Transaction::class, $val)) {
                        $fail('La transaction sélectionnée n\'appartient pas à votre équipe.');
                    }
                },
            ],
            'file' => [
                'required',
                'file',
                'max:' . $maxKb,
                'mimes:' . $mimes,
            ],
            'description' => 'nullable|string|max:255',
        ];
    }
}
