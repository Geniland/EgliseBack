<?php

namespace App\Models;

use App\Support\ScopeHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Church extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'address',
        'city',
        'phone',
        'email',
        'description',
        'parent_church_id',
        'created_by',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $church) {
            if (!$church->code) {
                $next = (int) self::withTrashed()->max('id') + 1;
                $church->code = 'EGL-' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
            }
            if (!$church->created_by && auth()->check()) {
                $church->created_by = auth()->id();
            }
        });
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_church_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_church_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function users()
    {
        return $this->hasMany(User::class, 'church_id');
    }

    public function allDescendants()
    {
        $descendants = collect();
        $stack = $this->children()->get();
        while ($stack->isNotEmpty()) {
            $cur = $stack->shift();
            $descendants->push($cur);
            foreach ($cur->children as $c) {
                $stack->push($c);
            }
        }
        return $descendants;
    }

    public function getTeamChurchIdsAttribute(): array
    {
        $ids = [$this->id];
        foreach ($this->allDescendants() as $c) {
            $ids[] = $c->id;
        }
        return array_values(array_unique($ids));
    }

    public function getUserIdsOfChurchTree(): array
    {
        $churchIds = $this->team_church_ids;
        if (!$churchIds) {
            return [0];
        }
        return (array) User::query()
            ->whereIn('church_id', $churchIds)
            ->pluck('id')
            ->all();
    }

    public function scopeActive($q)
    {
        return $q->where('status', true);
    }

    public function scopeOwned($q)
    {
        return ScopeHelper::applyOwnedByScope($q, 'created_by');
    }
}
