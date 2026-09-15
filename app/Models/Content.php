<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Content extends Model
{
    use HasFactory;

    protected $fillable = [
        'module_id',
        'title',
        'type',
        'content_data',
        'duration',
        'is_preview',
        'order',
    ];

    protected $casts = [
        'is_preview' => 'boolean',
    ];

    public function module()
    {
        return $this->belongsTo(FormationModule::class, 'module_id');
    }
}
