<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContentProgress extends Model
{
    use HasFactory;
    
    protected $table = 'content_progress';

    protected $fillable = [
        'enrollment_id',
        'content_id',
        'is_completed',
        'last_position',
        'completed_at',
    ];

    protected $casts = [
        'is_completed' => 'boolean',
        'completed_at' => 'datetime',
    ];

    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function content()
    {
        return $this->belongsTo(Content::class);
    }
}
