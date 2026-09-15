<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Formation extends Model
{
    use HasFactory;

    protected $fillable = [
        'church_id',
        'category_id',
        'title',
        'description',
        'cover_image',
        'file_path',
        'is_free',
        'price',
        'status',
        'published_at',
        'created_by',
    ];

    protected $casts = [
        'is_free' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function church()
    {
        return $this->belongsTo(Church::class);
    }

    public function category()
    {
        return $this->belongsTo(ResourceCategory::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function modules()
    {
        return $this->hasMany(FormationModule::class)->orderBy('order');
    }

    public function purchases()
    {
        return $this->morphMany(Purchase::class, 'purchasable');
    }

    public function enrollments()
    {
        return $this->hasMany(Enrollment::class);
    }
}
