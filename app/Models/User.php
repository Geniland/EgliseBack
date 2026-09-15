<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;


class User extends Authenticatable
{
    use HasApiTokens, Notifiable;


    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'address',
        'role_id',
        'fonction_id',
        'status',
        'parent_user_id',
        'church_id',
        'church_name',
    ];


    protected $casts = [
        'email_verified_at' => 'datetime',
        'status' => 'boolean',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];


    /**
     * Relation avec Role
     */
    public function role()
    {
        return $this->belongsTo(Role::class);
    }


    /**
     * Relation avec Fonction
     */
    public function fonction()
    {
        return $this->belongsTo(Fonction::class);
    }

    /**
     * Vérifie si l'utilisateur peut agir sur un autre utilisateur (modifier/supprimer)
     * Règle: on ne peut pas agir sur un utilisateur ayant un rôle supérieur ou égal au sien
     * (sauf Super Admin qui peut tout faire)
     */
    public function canActOnUser(User $targetUser): bool
    {
        $actorRoleId = $this->role_id;
        $targetRoleId = $targetUser->role_id;

        // Super Admin peut faire tout
        if ($actorRoleId === 1) {
            return true;
        }

        // Personne ne peut agir sur un Super Admin sauf un Super Admin
        if ($targetRoleId === 1) {
            return false;
        }

        // Administrateur ne peut pas agir sur un Administrateur
        if ($actorRoleId === 2 && $targetRoleId === 2) {
            return false;
        }

        // Responsable ne peut pas agir sur un Administrateur
        if ($actorRoleId === 5 && in_array($targetRoleId, [1, 2])) {
            return false;
        }

        // Pour tous les cas restants: on agit sur un rôle inférieur ou égal autorisé
        // Si c'est le même rôle, on refuse (sauf SA qui est déjà traité)
        if ($actorRoleId === $targetRoleId) {
            return false;
        }

        return true;
    }

    /**
     * Vérifie si l'utilisateur peut créer un rôle cible
     * Règles:
     * - Super Admin (1) peut créer: tous les rôles
     * - Administrateur (2) peut créer: 3,4,5,6 (sauf 1 et 2)
     * - Responsable (5) peut créer: 3,4,5,6 (sauf 1 et 2)
     */
    public function canCreateRole(int $targetRoleId): bool
    {
        $creatorRoleId = $this->role_id;

        // Super Admin peut créer absolument tous les rôles
        if ($creatorRoleId === 1) {
            return true;
        }

        // Administrateur ne peut pas créer Super Admin (1) ni Administrateur (2)
        if ($creatorRoleId === 2) {
            return !in_array($targetRoleId, [1, 2]);
        }

        // Responsable ne peut pas créer Super Admin (1) ni Administrateur (2)
        if ($creatorRoleId === 5) {
            return !in_array($targetRoleId, [1, 2]);
        }

        // Tous les autres rôles ne peuvent pas créer d'utilisateurs
        return false;
    }

    /**
     * Supérieur hiérarchique direct (parent)
     */
    public function parent()
    {
        return $this->belongsTo(User::class, 'parent_user_id');
    }

    /**
     * Subordonnés directs (enfants hiérarchiques)
     */
    public function children()
    {
        return $this->hasMany(User::class, 'parent_user_id');
    }

    /**
     * Église d'affectation de l'utilisateur
     */
    public function church()
    {
        return $this->belongsTo(Church::class, 'church_id');
    }

    /**
     * Tous les subordonnés récursivement : enfants + petits-enfants + ...
     */
    public function allDescendants()
    {
        $descendants = collect();
        $stack = $this->children()->get();
        while ($stack->isNotEmpty()) {
            $current = $stack->shift();
            $descendants->push($current);
            foreach ($current->children()->get() as $c) {
                $stack->push($c);
            }
        }
        return $descendants;
    }

    /**
     * Retourne la liste plate des IDs : l'utilisateur lui-même + TOUS ses subordonnés récursifs
     */
    public function getTeamUserIdsAttribute(): array
    {
        $ids = [$this->id];
        foreach ($this->allDescendants() as $u) {
            $ids[] = $u->id;
        }
        return array_values(array_unique($ids));
    }

    /**
     * Vérifie si un autre user est dans mon équipe (moi ou subordonné)
     */
    public function isInMyTeam(int|User $user): bool
    {
        $id = $user instanceof User ? $user->id : (int) $user;
        return in_array($id, $this->team_user_ids, true);
    }

    /**
     * Le profil membre associé à cet utilisateur.
     */
    public function member()
    {
        return $this->hasOne(Member::class, 'user_id');
    }

    /**
     * Accesseur pour les initiales de l'utilisateur (avatar).
     */
    public function getInitialesAttribute(): string
    {
        $mots = explode(' ', trim($this->name ?? ''));
        $initiales = '';
        foreach (array_slice($mots, 0, 2) as $mot) {
            $initiales .= strtoupper(substr($mot, 0, 1));
        }
        return $initiales ?: 'U';
    }

    public function sentMessages()
    {
        return $this->hasMany(ChatMessage::class, 'sender_id');
    }

    public function receivedMessages()
    {
        return $this->hasMany(ChatMessage::class, 'recipient_id');
    }

    public function assistantConversations()
    {
        return $this->hasMany(AssistantConversation::class, 'user_id');
    }
}