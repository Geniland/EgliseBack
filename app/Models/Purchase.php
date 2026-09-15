<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Purchase extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'church_id',
        'purchasable_type',
        'purchasable_id',
        'payment_id',
        'amount_paid',
        'status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function church()
    {
        return $this->belongsTo(Church::class);
    }

    public function purchasable()
    {
        return $this->morphTo();
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
}
