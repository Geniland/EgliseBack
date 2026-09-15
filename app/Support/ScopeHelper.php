<?php

namespace App\Support;

use App\Models\User;
use App\Models\Church;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class ScopeHelper
{
    public const HEADER_CHURCH_CONTEXT = 'X-Church-Context';
    public const CONTEXT_ALL = 'all';

    public static function isSuperAdmin(): bool
    {
        if (!auth()->check()) {
            return false;
        }
        $user = auth()->user();
        return (int) $user->role_id === 1 || optional($user->role)->name === 'Super Admin';
    }

    /**
     * Retourne l'ID d'église demandé dans le contexte courant (header X-Church-Context).
     *  - null / absent / "all" = toutes mes églises (équipe entière)
     *  - int id = contexte église cible
     * Vérifie systématiquement que l'église cible m'appartient (je l'ai créée).
     */
    public static function getRequestedChurchContext(): ?int
    {
        try {
            $request = App::has('request') ? App::make('request') : (function_exists('request') ? request() : null);
            if (!$request) {
                return null;
            }
            if ($request instanceof Request) {
                $raw = $request->header(self::HEADER_CHURCH_CONTEXT, $request->input('_church_context'));
            } else {
                $raw = null;
            }
        } catch (\Throwable) {
            $raw = null;
        }

        if ($raw === null || $raw === '' || $raw === self::CONTEXT_ALL) {
            return null;
        }

        $churchId = (int) $raw;
        if ($churchId <= 0) {
            return null;
        }

        if (self::isSuperAdmin()) {
            return $churchId;
        }

        $ownedChurch = Church::query()
            ->whereKey($churchId)
            ->where(function ($q) use ($churchId) {
                $userId = auth()->id() ?: 0;
                $q->where('created_by', $userId);
                // ou l'église est une sous-église d'une de mes églises (created_by = moi indirect)
                $myTopLevelChurches = Church::query()
                    ->where('created_by', $userId)
                    ->pluck('id')
                    ->all();
                if ($myTopLevelChurches) {
                    foreach ($myTopLevelChurches as $top) {
                        $topChurch = Church::find($top);
                        if ($topChurch) {
                            $tree = $topChurch->team_church_ids;
                            if (in_array($churchId, $tree, true)) {
                                $q->orWhereRaw('1=1');
                                break;
                            }
                        }
                    }
                }
            })
            ->exists();

        return $ownedChurch ? $churchId : null;
    }

    /**
     * Retourne la liste des IDs églises qui m'appartiennent (créées par moi + tous descendants).
     * Super Admin => [] (vide = toutes).
     * Non connecté => [0].
     */
    public static function getMyChurchIds(?int $userId = null): array
    {
        if ($userId === null) {
            if (self::isSuperAdmin()) {
                return [];
            }
            $userId = auth()->id();
        } else {
            $user = ($userId === auth()->id() && auth()->check()) ? auth()->user() : User::find($userId);
            if ($user && ((int) $user->role_id === 1 || optional($user->role)->name === 'Super Admin')) {
                return [];
            }
        }

        if (!$userId) {
            return [0];
        }

        try {
            $user = ($userId === auth()->id() && auth()->check()) ? auth()->user() : User::find($userId);
            $topLevelChurches = Church::query()
                ->where('created_by', $userId)
                ->get();
            $ids = [];
            foreach ($topLevelChurches as $c) {
                $ids = array_merge($ids, $c->team_church_ids);
            }
            // Ajouter aussi l'église de l'utilisateur lui-même (si l'admin a été créé par Super Admin sur une église)
            $selfChurchId = (int) (optional($user)->church_id ?? 0);
            if ($selfChurchId > 0 && !in_array($selfChurchId, $ids, true)) {
                $ids[] = $selfChurchId;
                $selfChurch = Church::find($selfChurchId);
                if ($selfChurch) {
                    $ids = array_merge($ids, $selfChurch->team_church_ids);
                }
            }
            $ids = array_values(array_unique(array_map('intval', $ids)));
            return $ids ?: [0];
        } catch (\Throwable) {
            return [(int)$userId];
        }
    }

    /**
     * Retourne les IDs utilisateurs à inclure dans le filtre :
     *  - Super Admin => [] (aucun filtre)
     *  - Non connecté => [0] (bloque)
     *  - Contexte église donné => IDs des users de CETTE arborescence d'église (m'appartenant)
     *  - Sinon => équipe standard (moi + subordonnés hiérarchiques)
     */
    public static function getTeamUserIds(?int $userId = null): array
    {
        if (self::isSuperAdmin()) {
            return [];
        }

        if ($userId === null) {
            $userId = auth()->id();
        }

        if (!$userId) {
            return [0];
        }

        $contextChurchId = self::getRequestedChurchContext();
        if ($contextChurchId !== null && $contextChurchId > 0) {
            try {
                $church = Church::find($contextChurchId);
                if (!$church) {
                    return [0];
                }
                $ids = $church->getUserIdsOfChurchTree();
                if (!$ids || $ids === [0]) {
                    return [0];
                }
                return array_values(array_unique(array_map('intval', $ids)));
            } catch (\Throwable) {
                // Fallback
            }
        }

        try {
            $user = User::query()->find($userId);
            if (!$user) {
                return [0];
            }
            return $user->team_user_ids;
        } catch (\Throwable) {
            return [(int) $userId];
        }
    }

    /**
     * Applique le filtrage équipe :
     *  - Super Admin -> pas de filtre
     *  - Non connecté -> WHERE 0=1
     *  - Sinon -> colonne IN (IDs membres équipe + contexte église si défini)
     */
    public static function applyOwnedByScope(Builder $query, string $column = 'created_by'): Builder
    {
        if (self::isSuperAdmin()) {
            return $query;
        }

        $teamIds = self::getTeamUserIds();

        if (empty($teamIds) || (count($teamIds) === 1 && $teamIds[0] === 0)) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where(function ($q) use ($column, $teamIds) {
            $q->whereIn($column, $teamIds)
              ->orWhereNull($column);
        });
    }

    /**
     * Récupère une ressource par son ID en s'assurant qu'elle appartient à l'équipe courante.
     * Super Admin voit tout.
     *
     * @param class-string<\Illuminate\Database\Eloquent\Model> $modelClass
     * @param mixed $id
     * @return \Illuminate\Database\Eloquent\Model
     * @throws ModelNotFoundException
     */
    public static function findOwnedOrFail(string $modelClass, mixed $id, string $column = 'created_by')
    {
        $query = $modelClass::query();
        if (!self::isSuperAdmin()) {
            if ($column === 'church_id') {
                $myChurchIds = self::getMyChurchIds();
                $userChurchId = auth()->user()?->church_id;
                if ($userChurchId) {
                    $myChurchIds[] = (int) $userChurchId;
                    $church = Church::find($userChurchId);
                    if ($church && $church->parent_church_id) {
                        $myChurchIds[] = (int) $church->parent_church_id;
                    }
                }
                $myChurchIds = array_values(array_unique(array_filter(array_map('intval', $myChurchIds))));
                $userId = auth()->id() ?: 0;

                $query->where(function ($q) use ($myChurchIds, $userId) {
                    if (!empty($myChurchIds)) {
                        $q->whereIn('church_id', $myChurchIds);
                    }
                    if ($userId) {
                        $q->orWhere('created_by', $userId);
                    }
                    $q->orWhereNull('church_id');
                });
            } else {
                $teamIds = self::getTeamUserIds();
                if (empty($teamIds) || (count($teamIds) === 1 && (int) $teamIds[0] === 0)) {
                    throw (new ModelNotFoundException())->setModel($modelClass, is_array($id) ? $id : [$id]);
                }
                $query->where(function ($q) use ($column, $teamIds) {
                    $q->whereIn($column, $teamIds)
                      ->orWhereNull($column);
                });
            }
        }
        if (method_exists($modelClass, 'bootSoftDeletes')) {
            // Let the model handle its own soft-delete resolution via resolveSoftDeletableRouteBinding
        }
        if (is_array($id)) {
            return $query->whereKey($id)->firstOrFail();
        }
        return $query->findOrFail($id);
    }

    /**
     * Vérifie qu'un enregistrement (par son id) appartient bien à l'équipe courante.
     * Utilisable dans les Form Request en Closure custom.
     */
    public static function recordBelongsToTeam(string $modelClass, mixed $id, string $column = 'created_by'): bool
    {
        if (self::isSuperAdmin()) {
            return true;
        }
        if ($column === 'church_id') {
            $myChurchIds = self::getMyChurchIds();
            $userChurchId = auth()->user()?->church_id;
            if ($userChurchId) {
                $myChurchIds[] = (int) $userChurchId;
            }
            $myChurchIds = array_values(array_unique(array_filter(array_map('intval', $myChurchIds))));
            $userId = auth()->id() ?: 0;

            return $modelClass::query()
                ->whereKey($id)
                ->where(function ($q) use ($myChurchIds, $userId) {
                    if (!empty($myChurchIds)) {
                        $q->whereIn('church_id', $myChurchIds);
                    }
                    if ($userId) {
                        $q->orWhere('created_by', $userId);
                    }
                    $q->orWhereNull('church_id');
                })
                ->exists();
        }

        $teamIds = self::getTeamUserIds();
        if (empty($teamIds) || (count($teamIds) === 1 && (int) $teamIds[0] === 0)) {
            return false;
        }
        try {
            return $modelClass::query()
                ->whereKey($id)
                ->where(function ($q) use ($column, $teamIds) {
                    $q->whereIn($column, $teamIds)
                      ->orWhereNull($column);
                })
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    public static function listMyChurches(): array
    {
        $q = Church::with(['parent:id,name,code'])->orderBy('name');
        if (!self::isSuperAdmin()) {
            $myIds = self::getMyChurchIds();
            if (empty($myIds) || (count($myIds) === 1 && (int)$myIds[0] === 0)) {
                return [];
            }
            $q->whereIn('id', $myIds);
        }
        return $q->active()->get()
            ->map(fn($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'code' => $c->code,
                'city' => $c->city,
                'address' => $c->address,
                'phone' => $c->phone,
                'email' => $c->email,
                'parent_church_id' => $c->parent_church_id,
                'parent_name' => optional($c->parent)->name,
                'members_count' => (int) ($c->users_count ?? 0),
            ])
            ->all();
    }

    /**
     * Applique le filtrage par église pour les ressources et formations.
     * Une ressource est visible par son église créatrice et toutes ses sous-églises.
     * Pour un fidèle, cela signifie qu'il voit les ressources de son église + celles de son église mère.
     */
    public static function applyChurchScope(Builder $query): Builder
    {
        if (self::isSuperAdmin()) {
            return $query;
        }

        $userId = auth()->id();
        if (!$userId) {
            return $query->whereRaw('0 = 1');
        }

        $myIds = self::getMyChurchIds(); 
        
        $userChurchId = auth()->user()->church_id;
        if ($userChurchId) {
            $church = Church::find($userChurchId);
            if ($church && $church->parent_church_id) {
                $myIds[] = $church->parent_church_id;
                // Si l'église mère a elle-même une mère (grand-mère)
                $parent = Church::find($church->parent_church_id);
                if ($parent && $parent->parent_church_id) {
                    $myIds[] = $parent->parent_church_id;
                }
            }
        }
        
        $myIds = array_unique(array_filter(array_map('intval', $myIds)));
        
        if (empty($myIds)) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereIn('church_id', $myIds);
    }

    /**
     * Applique le filtrage strict des membres :
     * - Super Admin (1) : voit tous les membres de toutes les églises.
     * - Administrateur (2) : voit tous les membres de son église et de ses sous-églises (ou du contexte sélectionné).
     * - Responsable (5) : voit UNIQUEMENT les membres créés par lui OU dont le code d'église a été utilisé (église du responsable).
     * - Autre personnel église : restreint à leur église d'affectation ou aux membres qu'ils ont créés.
     */
    public static function applyMemberScope(Builder $query, ?User $user = null): Builder
    {
        if (self::isSuperAdmin()) {
            return $query;
        }

        if (!$user && auth()->check()) {
            $user = auth()->user();
        }

        if (!$user) {
            return $query->whereRaw('0 = 1');
        }

        $roleId = (int) $user->role_id;

        // Super Admin
        if ($roleId === 1) {
            return $query;
        }

        // Administrateur (Role 2)
        if ($roleId === 2) {
            $contextChurchId = self::getRequestedChurchContext();
            $adminChurchIds = $contextChurchId ? [$contextChurchId] : self::getMyChurchIds($user->id);
            $teamUserIds = self::getTeamUserIds($user->id);

            return $query->where(function ($q) use ($adminChurchIds, $teamUserIds) {
                $hasChurches = !empty($adminChurchIds) && !(count($adminChurchIds) === 1 && (int)$adminChurchIds[0] === 0);
                $hasTeam = !empty($teamUserIds) && !(count($teamUserIds) === 1 && (int)$teamUserIds[0] === 0);

                if ($hasChurches) {
                    $q->whereIn('members.church_id', $adminChurchIds)
                      ->orWhereHas('user', fn($uq) => $uq->whereIn('church_id', $adminChurchIds));
                }

                if ($hasTeam) {
                    if ($hasChurches) {
                        $q->orWhereIn('members.created_by', $teamUserIds);
                    } else {
                        $q->whereIn('members.created_by', $teamUserIds);
                    }
                }

                if (!$hasChurches && !$hasTeam) {
                    $q->whereRaw('0 = 1');
                }
            });
        }

        // Responsable d'une église (Role 5) ou personnel affecté à une église
        $churchIds = [];
        if ($user->church_id) {
            $churchIds[] = (int) $user->church_id;
        }
        $createdChurchIds = Church::where('created_by', $user->id)->pluck('id')->all();
        $churchIds = array_values(array_unique(array_filter(array_merge($churchIds, $createdChurchIds))));

        return $query->where(function ($q) use ($churchIds, $user) {
            if (!empty($churchIds)) {
                $q->whereIn('members.church_id', $churchIds)
                  ->orWhereHas('user', fn($uq) => $uq->whereIn('church_id', $churchIds))
                  ->orWhere('members.created_by', $user->id);
            } else {
                $q->where('members.created_by', $user->id);
            }
        });
    }

    /**
     * Vérifie si l'utilisateur courant ou spécifié a le droit d'accéder au membre donné.
     */
    public static function canAccessMember(\App\Models\Member $member, ?User $user = null): bool
    {
        if (self::isSuperAdmin()) {
            return true;
        }

        if (!$user && auth()->check()) {
            $user = auth()->user();
        }

        if (!$user) {
            return false;
        }

        $roleId = (int) $user->role_id;
        if ($roleId === 1) {
            return true;
        }

        // Si créé par l'utilisateur lui-même
        if ($member->created_by && (int) $member->created_by === (int) $user->id) {
            return true;
        }

        $memberChurchId = (int) ($member->church_id ?: (optional($member->user)->church_id ?: 0));

        // Administrateur (Role 2)
        if ($roleId === 2) {
            $adminChurchIds = self::getMyChurchIds($user->id);
            if ($memberChurchId > 0 && in_array($memberChurchId, $adminChurchIds, true)) {
                return true;
            }
            $teamUserIds = self::getTeamUserIds($user->id);
            if ($member->created_by && in_array((int) $member->created_by, $teamUserIds, true)) {
                return true;
            }
            return false;
        }

        // Responsable (Role 5) ou autre
        $churchIds = [];
        if ($user->church_id) {
            $churchIds[] = (int) $user->church_id;
        }
        $createdChurchIds = Church::where('created_by', $user->id)->pluck('id')->all();
        $churchIds = array_values(array_unique(array_filter(array_merge($churchIds, $createdChurchIds))));

        if ($memberChurchId > 0 && in_array($memberChurchId, $churchIds, true)) {
            return true;
        }

        return false;
    }
}
