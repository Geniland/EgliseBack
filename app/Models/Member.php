<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Member extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [

        'member_code',
        'church_id',
        'user_id',

        'first_name',
        'last_name',

        'gender',

        'birth_date',
        'birth_place',

        'phone',
        'email',

        'address',
        'city',
        'country',

        'profession',

        'marital_status',
        'spouse_name',

        'conversion_date',
        'baptism_date',
        'membership_date',

        'photo',

        'emergency_contact',
        'emergency_phone',

        'status',

        'created_by',
        'updated_by',

        'family_id',
        'member_type',

    ];

    protected $casts = [

        'birth_date' => 'date',
        'conversion_date' => 'date',
        'baptism_date' => 'date',
        'membership_date' => 'date',

        'status' => 'boolean',

    ];

    /**
     * Église à laquelle appartient le membre.
     */
    public function church()
    {
        return $this->belongsTo(Church::class, 'church_id');
    }

    /**
     * Scope pour filtrer les membres selon l'église et les droits de l'utilisateur.
     */
    public function scopeForUser($query, ?User $user = null)
    {
        return \App\Support\ScopeHelper::applyMemberScope($query, $user);
    }

    /**
     * Utilisateur ayant créé le membre.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Utilisateur ayant modifié le membre.
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Famille du membre.
     */
    public function family()
    {
        return $this->belongsTo(Family::class, 'family_id');
    }

    /**
     * Ministères auxquels appartient le membre.
     */
    public function ministries()
    {
        return $this->belongsToMany(Ministry::class);
    }

    /**
     * Utilisateur lié à ce membre (pour la connexion de l'app mobile).
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}