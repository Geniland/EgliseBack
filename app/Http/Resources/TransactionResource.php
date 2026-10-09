<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transaction_code' => $this->transaction_code,
            'type' => $this->type,
            'type_label' => $this->type_label,
            'direction' => $this->direction,
            'direction_label' => $this->direction_label,
            'signed_amount' => $this->signed_amount,
            'amount' => (float) $this->amount,
            'formatted_amount' => $this->formatted_amount,
            'formatted_signed_amount' => $this->formatted_signed_amount,
            'account_id' => $this->account_id,
            'category_id' => $this->category_id,
            'description' => $this->description,
            'transaction_date' => $this->transaction_date?->toDateString(),
            'formatted_date' => $this->formatted_date,
            'reference' => $this->reference,
            'payment_method' => $this->payment_method,
            'payment_method_label' => $this->payment_method_label,
            'status' => $this->status,
            'status_label' => $this->status_label,
            'rejection_reason' => $this->rejection_reason,
            'parent_transaction_id' => $this->parent_transaction_id,
            'transfer_group_code' => $this->transfer_group_code,
            'from_account_id' => $this->from_account_id,
            'to_account_id' => $this->to_account_id,
            'can_be_edited' => $this->canBeEdited(),
            'can_be_deleted' => $this->canBeDeleted(),
            'can_current_user_approve' => $this->canBeApprovedBy(),
            'can_current_user_reject' => $this->canBeRejectedBy(),

            'account' => $this->whenLoaded('account', function () {
                return $this->account ? [
                    'id' => $this->account->id,
                    'name' => $this->account->name,
                    'type' => $this->account->type,
                    'type_label' => $this->account->type_label,
                    'currency' => $this->account->currency,
                ] : null;
            }),
            'category' => $this->whenLoaded('category', function () {
                return $this->category ? [
                    'id' => $this->category->id,
                    'name' => $this->category->name,
                    'type' => $this->category->type,
                    'type_label' => $this->category->type_label,
                ] : null;
            }),
            'from_account' => $this->whenLoaded('fromAccount', function () {
                return $this->fromAccount ? [
                    'id' => $this->fromAccount->id,
                    'name' => $this->fromAccount->name,
                ] : null;
            }),
            'to_account' => $this->whenLoaded('toAccount', function () {
                return $this->toAccount ? [
                    'id' => $this->toAccount->id,
                    'name' => $this->toAccount->name,
                ] : null;
            }),
            'parent_transaction' => $this->whenLoaded('parentTransaction', function () {
                return $this->parentTransaction ? [
                    'id' => $this->parentTransaction->id,
                    'transaction_code' => $this->parentTransaction->transaction_code,
                ] : null;
            }),
            'transfer_pair' => $this->whenLoaded('transferPair', function () {
                return $this->transferPair ? [
                    'id' => $this->transferPair->id,
                    'transaction_code' => $this->transferPair->transaction_code,
                    'account_id' => $this->transferPair->account_id,
                    'account_name' => optional($this->transferPair->account)->name,
                ] : null;
            }),

            'attachments_count' => $this->when(isset($this->attachments_count), (int) $this->attachments_count),
            'attachments' => $this->whenLoaded('attachments', function () {
                return TransactionAttachmentResource::collection($this->attachments);
            }),

            'created_by' => $this->created_by,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toDateTimeString(),
            'formatted_approved_at' => $this->formatted_approved_at,
            'updated_by' => $this->updated_by,

            'creator' => $this->whenLoaded('creator', function () {
                return $this->creator ? [
                    'id' => $this->creator->id,
                    'name' => $this->creator->name,
                ] : null;
            }),
            'approver' => $this->relationLoaded('approver')
                ? ($this->approver ? [
                    'id' => $this->approver->id,
                    'name' => $this->approver->name,
                ] : null)
                : $this->whenLoaded('approver', function () {
                    return $this->approver ? [
                        'id' => $this->approver->id,
                        'name' => $this->approver->name,
                    ] : null;
                }),
            'updater' => $this->whenLoaded('updater', function () {
                return $this->updater ? [
                    'id' => $this->updater->id,
                    'name' => $this->updater->name,
                ] : null;
            }),

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
            'deleted_at' => $this->deleted_at?->toDateTimeString(),
        ];
    }
}
