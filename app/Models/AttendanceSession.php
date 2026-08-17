<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Carbon\Carbon;

class AttendanceSession extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title',
        'session_date',
        'start_time',
        'end_time',
        'type',
        'description',
        'location',
        'latitude',
        'longitude',
        'gps_radius_meters',
        'gps_required',
        'status',
        'qr_token',
        'qr_expires_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'session_date' => 'date',
        'start_time' => 'datetime:H:i:s',
        'end_time' => 'datetime:H:i:s',
        'gps_required' => 'boolean',
        'status' => 'boolean',
        'qr_expires_at' => 'datetime',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'gps_radius_meters' => 'integer',
    ];

    public function attendances()
    {
        return $this->hasMany(Attendance::class, 'session_id');
    }

    public function presentMembers()
    {
        return $this->belongsToMany(Member::class, 'attendances', 'session_id', 'member_id')
            ->wherePivot('status', 'present')
            ->withTimestamps();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeUpcoming($query)
    {
        return $query->where('session_date', '>=', now()->toDateString());
    }

    public function generateQrToken(int $validityMinutes = 180): string
    {
        $this->qr_token = Str::random(60) . '_' . time();
        $this->qr_expires_at = Carbon::now()->addMinutes($validityMinutes);
        $this->save();
        return $this->qr_token;
    }

    public function isQrValid(): bool
    {
        if (!$this->qr_token || !$this->qr_expires_at) {
            return false;
        }
        return $this->status && Carbon::now()->lte($this->qr_expires_at);
    }

    public function invalidateQr(): void
    {
        $this->qr_token = null;
        $this->qr_expires_at = null;
        $this->save();
    }

    public function isWithinGps(?float $lat, ?float $lng): bool
    {
        if (!$this->gps_required) {
            return true;
        }
        if ($lat === null || $lng === null || $this->latitude === null || $this->longitude === null) {
            return !$this->gps_required;
        }
        $earthRadius = 6371000;
        $dLat = deg2rad($lat - $this->latitude);
        $dLng = deg2rad($lng - $this->longitude);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($this->latitude)) * cos(deg2rad($lat))
            * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $distanceMeters = $earthRadius * $c;
        return $distanceMeters <= (int) $this->gps_radius_meters;
    }
}
