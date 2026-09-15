<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'church_id',
        'user_id',
        'amount',
        'currency',
        'payment_method',
        'transaction_reference',
        'status',
        'paid_at',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
    ];

    public function church()
    {
        return $this->belongsTo(Church::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
