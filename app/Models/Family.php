<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Family extends Model
{
    use HasFactory;

    protected $fillable = [

        'family_code',

        'family_name',

        'phone',

        'address',

        'status',

        'created_by',
        'updated_by',

    ];


    protected $casts = [

        'status' => 'boolean',

    ];


    /**
     * Membres appartenant à cette famille.
     */
    public function members()
    {
        return $this->hasMany(Member::class);
    }


    /**
     * Nombre de membres de la famille.
     */
    public function getMembersCountAttribute()
    {
        return $this->members()->count();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}