<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'church_id',
        'sender_id',
        'recipient_id',
        'automation_key',
        'contenu',
        'lu',
        'expires_at',
    ];

    protected $casts = [
        'lu' => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function church()
    {
        return $this->belongsTo(Church::class, 'church_id');
    }
}
