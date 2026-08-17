<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AbsenceReason extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'description',
        'requires_proof',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'requires_proof' => 'boolean',
        'status' => 'boolean',
    ];

    public function attendances()
    {
        return $this->hasMany(Attendance::class, 'absence_reason_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive($q)
    {
        return $q->where('status', true);
    }
}
