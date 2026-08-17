<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Attendance extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'session_id',
        'member_id',
        'status',
        'arrival_time',
        'absence_reason_id',
        'absence_note',
        'comment',
        'latitude',
        'longitude',
        'gps_verified',
        'scan_method',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'arrival_time' => 'datetime',
        'gps_verified' => 'boolean',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    protected static function booted(): void
    {
        static::creating(function (Attendance $att) {
            if (!$att->arrival_time && $att->status === 'present') {
                $att->arrival_time = now();
            }
        });
    }

    public function session()
    {
        return $this->belongsTo(AttendanceSession::class, 'session_id');
    }

    public function member()
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function absenceReason()
    {
        return $this->belongsTo(AbsenceReason::class, 'absence_reason_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopePresent($q) { return $q->where('status', 'present'); }
    public function scopeAbsent($q) { return $q->whereIn('status', ['absent', 'absent_excuse']); }
    public function scopeLate($q) { return $q->where('status', 'retard'); }
}
