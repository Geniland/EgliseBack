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
    public static function getMyChurchIds(): array
    {
        if (self::isSuperAdmin()) {
            return [];
        }
        $userId = auth()->id();
        if (!$userId) {
            return [0];
        }
        try {
            $topLevelChurches = Church::query()
                ->where('created_by', $userId)
                ->get();
            $ids = [];
            foreach ($topLevelChurches as $c) {
                $ids = array_merge($ids, $c->team_church_ids);
            }
            // Ajouter aussi l'église de l'utilisateur lui-même (si l'admin a été créé par Super Admin sur une église)
            $selfChurchId = (int) (auth()->user()->church_id ?? 0);
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

        return $query->whereIn($column, $teamIds);
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
            $teamIds = self::getTeamUserIds();
            if (empty($teamIds) || (count($teamIds) === 1 && (int) $teamIds[0] === 0)) {
                throw (new ModelNotFoundException())->setModel($modelClass, is_array($id) ? $id : [$id]);
            }
            $query->whereIn($column, $teamIds);
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
        $teamIds = self::getTeamUserIds();
        if (empty($teamIds) || (count($teamIds) === 1 && (int) $teamIds[0] === 0)) {
            return false;
        }
        try {
            return $modelClass::query()
                ->whereKey($id)
                ->whereIn($column, $teamIds)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Liste de mes églises (pour le dropdown Select Church de la topbar).
     * Super Admin retourne toutes les églises actives.
     * Sinon mes églises (created_by = moi + église où je suis affecté + descendants).
     */
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
}
