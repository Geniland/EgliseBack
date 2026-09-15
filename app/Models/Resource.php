<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Resource extends Model
{
    use HasFactory;

    protected $fillable = [
        'church_id',
        'category_id',
        'title',
        'description',
        'cover_image',
        'type',
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

    public function purchases()
    {
        return $this->morphMany(Purchase::class, 'purchasable');
    }
}
