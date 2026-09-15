<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssistantMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'assistant_conversation_id',
        'expediteur',
        'contenu',
    ];

    public function conversation()
    {
        return $this->belongsTo(AssistantConversation::class, 'assistant_conversation_id');
    }
}
