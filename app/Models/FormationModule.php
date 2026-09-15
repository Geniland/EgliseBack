<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FormationModule extends Model
{
    use HasFactory;

    protected $fillable = [
        'formation_id',
        'title',
        'description',
        'order',
    ];

    public function formation()
    {
        return $this->belongsTo(Formation::class);
    }

    public function contents()
    {
        return $this->hasMany(Content::class, 'module_id')->orderBy('order');
    }
}
