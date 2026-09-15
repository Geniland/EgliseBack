<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class Event extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title',
        'description',
        'type',
        'event_date',
        'start_time',
        'end_time',
        'location',
        'address',
        'image_path',
        'notes',
        'status',
        'is_featured',
        'max_attendees',
        'organizer',
        'contact_email',
        'contact_phone',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'event_date' => 'date',
        'start_time' => 'datetime:H:i:s',
        'end_time' => 'datetime:H:i:s',
        'is_featured' => 'boolean',
        'max_attendees' => 'integer',
    ];

    public static function types(): array
    {
        return [
            'special_service' => 'Culte spécial',
            'conference' => 'Conférence',
            'seminar' => 'Séminaire / Formation',
            'meeting' => 'Réunion',
            'retreat' => 'Retraite spirituelle',
            'evangelism' => 'Campagne d\'évangélisation',
            'youth' => 'Activité de jeunesse',
            'women' => 'Activité des femmes',
            'men' => 'Activité des hommes',
            'concert' => 'Concert / Activité culturelle',
            'other' => 'Autre activité',
        ];
    }

    public static function statuses(): array
    {
        return [
            'draft' => 'Brouillon',
            'published' => 'Publié',
            'cancelled' => 'Annulé',
            'completed' => 'Terminé',
        ];
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeUpcoming($query)
    {
        return $query->whereDate('event_date', '>=', now()->toDateString());
    }

    public function scopePast($query)
    {
        return $query->whereDate('event_date', '<', now()->toDateString());
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function isUpcoming(): bool
    {
        return $this->event_date && $this->event_date->gte(now()->toDateString());
    }

    public function isPast(): bool
    {
        return $this->event_date && $this->event_date->lt(now()->toDateString());
    }

    public function getFormattedDateAttribute(): string
    {
        if (!$this->event_date) return '';
        return $this->event_date->isoFormat('dddd D MMMM YYYY');
    }

    public function getFormattedTimeAttribute(): string
    {
        $parts = [];
        if ($this->start_time) {
            $parts[] = Carbon::parse($this->start_time)->format('H:i');
        }
        if ($this->end_time) {
            $parts[] = Carbon::parse($this->end_time)->format('H:i');
        }
        return implode(' - ', $parts);
    }

    public function getTypeLabelAttribute(): string
    {
        $types = self::types();
        return $types[$this->type] ?? $this->type;
    }

    public function getStatusLabelAttribute(): string
    {
        $statuses = self::statuses();
        return $statuses[$this->status] ?? $this->status;
    }

    public function liveStream()
    {
        return $this->hasOne(LiveStream::class);
    }
}
