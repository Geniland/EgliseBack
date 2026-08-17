<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransactionAttachmentRequest;
use App\Http\Resources\TransactionAttachmentResource;
use App\Models\Transaction;
use App\Models\TransactionAttachment;
use App\Support\ScopeHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TransactionAttachmentController extends Controller
{

    public function index(int $id)
    {
        $transaction = ScopeHelper::findOwnedOrFail(Transaction::class, $id);
        $items = $transaction->attachments()
            ->with('uploadedBy')
            ->latest()
            ->get();
        return TransactionAttachmentResource::collection($items);
    }

    public function store(StoreTransactionAttachmentRequest $request)
    {
        $transaction = ScopeHelper::findOwnedOrFail(Transaction::class, $request->transaction_id);

        $file = $request->file('file');
        $allowed = TransactionAttachment::allowedExtensions();
        $ext = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, $allowed, true)) {
            return response()->json([
                'message' => "Type de fichier interdit : .{$ext}. Extensions autorisées : " . implode(', ', $allowed),
            ], 422);
        }
        $mimes = TransactionAttachment::allowedMimeTypes();
        if (!in_array($file->getMimeType(), $mimes, true)) {
            return response()->json([
                'message' => "Type MIME interdit : " . $file->getMimeType(),
            ], 422);
        }
        $maxKb = TransactionAttachment::maxFileSizeKb();
        if ($file->getSize() > $maxKb * 1024) {
            return response()->json([
                'message' => "Fichier trop volumineux (max {$maxKb} Ko).",
            ], 422);
        }

        $year = now()->year;
        $month = now()->format('m');
        $directory = "finance/{$year}/{$month}/transaction_{$transaction->id}";
        $storedPath = $file->store($directory, [
            'disk' => config('filesystems.default', 'public'),
        ]);
        if (!$storedPath) {
            return response()->json(['message' => 'Erreur d\'enregistrement du fichier.'], 500);
        }

        $attachment = TransactionAttachment::create([
            'transaction_id' => $transaction->id,
            'original_name' => $file->getClientOriginalName(),
            'file_path' => $storedPath,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'uploaded_by' => auth()->id(),
        ]);
        $attachment->load('uploadedBy');

        return response()->json([
            'message' => 'Justificatif ajouté',
            'attachment' => new TransactionAttachmentResource($attachment),
        ], 201);
    }

    public function show(int $id)
    {
        $attachment = TransactionAttachment::with('uploadedBy')->findOrFail($id);
        $txOwned = ScopeHelper::recordBelongsToTeam(Transaction::class, $attachment->transaction_id);
        if (!$txOwned && !ScopeHelper::isSuperAdmin()) {
            abort(403, 'Pièce jointe non accessible.');
        }
        return new TransactionAttachmentResource($attachment);
    }

    public function destroy(int $id)
    {
        $attachment = TransactionAttachment::findOrFail($id);
        $txOwned = ScopeHelper::recordBelongsToTeam(Transaction::class, $attachment->transaction_id);
        if (!$txOwned && !ScopeHelper::isSuperAdmin()) {
            abort(403, 'Pièce jointe non accessible.');
        }
        $attachment->delete();
        return response()->json(['message' => 'Justificatif supprimé']);
    }

    public function download(int $id)
    {
        $attachment = TransactionAttachment::findOrFail($id);
        $txOwned = ScopeHelper::recordBelongsToTeam(Transaction::class, $attachment->transaction_id);
        if (!$txOwned && !ScopeHelper::isSuperAdmin()) {
            abort(403, 'Pièce jointe non accessible.');
        }
        if (!$attachment->file_path || !Storage::exists($attachment->file_path)) {
            abort(404, 'Fichier introuvable');
        }
        return Storage::download($attachment->file_path, $attachment->original_name ?? 'piece_jointe');
    }
}
