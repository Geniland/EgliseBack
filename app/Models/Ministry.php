<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ministry extends Model
{
    use HasFactory;

    protected $fillable = [

        'name',

        'description',

        'status',

        'created_by',
        'updated_by',

    ];


    protected $casts = [

        'status' => 'boolean',

    ];


    /**
     * Membres appartenant à ce ministère.
     */
    public function members()
    {
        return $this->belongsToMany(Member::class)
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
}