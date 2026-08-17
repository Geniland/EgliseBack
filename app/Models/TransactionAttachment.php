<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class TransactionAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'original_name',
        'file_path',
        'mime_type',
        'file_size',
        'uploaded_by',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    public static function allowedMimeTypes(): array
    {
        return [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain',
        ];
    }

    public static function allowedExtensions(): array
    {
        return [
            'jpg', 'jpeg', 'png', 'gif', 'webp',
            'pdf',
            'doc', 'docx',
            'xls', 'xlsx',
            'txt',
        ];
    }

    public static function maxFileSizeKb(): int
    {
        return 10240;
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function scopeImage($query)
    {
        return $query->where('mime_type', 'LIKE', 'image/%');
    }

    public function scopePdf($query)
    {
        return $query->where('mime_type', 'application/pdf');
    }

    public function getIsImageAttribute(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }

    public function getIsPdfAttribute(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    public function getFormattedSizeAttribute(): string
    {
        $size = (int) $this->file_size;
        if ($size < 1024) return $size . ' octets';
        if ($size < 1024 * 1024) return number_format($size / 1024, 1, ',', ' ') . ' Ko';
        return number_format($size / (1024 * 1024), 2, ',', ' ') . ' Mo';
    }

    public function getFileUrlAttribute(): ?string
    {
        if (!$this->file_path) return null;
        try {
            return Storage::url($this->file_path);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function getExtensionAttribute(): ?string
    {
        if (!$this->original_name) return null;
        return strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION));
    }

    protected static function booted(): void
    {
        static::deleted(function (self $a) {
            if ($a->file_path) {
                try {
                    Storage::delete($a->file_path);
                } catch (\Throwable $e) {}
            }
        });
    }
}
