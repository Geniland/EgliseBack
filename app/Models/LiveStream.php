<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LiveStream extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'event_id',
        'title',
        'description',
        'provider',
        'provider_live_input_id',
        'playback_url',
        'hls_url',
        'status',
        'scheduled_at',
        'started_at',
        'ended_at',
        'recording_video_id',
        'recording_url',
        'created_by',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
