<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    /**
     * Les utilisateurs ayant ce rôle.
     */
    public function users()
    {
        return $this->hasMany(User::class);
    }

    /**
 * Permissions du rôle
 */
    public function permissions()
    {
        return $this->belongsToMany(Permission::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('status', false);
    }

}