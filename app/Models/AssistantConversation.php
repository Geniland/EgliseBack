<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssistantConversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'church_id',
        'titre',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function church()
    {
        return $this->belongsTo(Church::class);
    }

    public function messages()
    {
        return $this->hasMany(AssistantMessage::class, 'assistant_conversation_id');
    }
}
