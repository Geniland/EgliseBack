<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResourceCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'church_id',
        'name',
        'description',
        'type',
    ];

    public function church()
    {
        return $this->belongsTo(Church::class);
    }

    public function resources()
    {
        return $this->hasMany(Resource::class, 'category_id');
    }

    public function formations()
    {
        return $this->hasMany(Formation::class, 'category_id');
    }
}
