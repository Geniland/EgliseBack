<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class FinancialAccount extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'type',
        'initial_balance',
        'currency',
        'status',
        'description',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'initial_balance' => 'decimal:2',
        'status' => 'boolean',
    ];

    public static function types(): array
    {
        return [
            'cash' => 'Caisse',
            'bank' => 'Compte bancaire',
            'other' => 'Autre',
        ];
    }

    public static function currencies(): array
    {
        return [
            'XOF' => 'Franc CFA (XOF)',
            'EUR' => 'Euro (EUR)',
            'USD' => 'Dollar US (USD)',
            'GNF' => 'Franc Guinéen (GNF)',
        ];
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'account_id');
    }

    public function outgoingTransfers()
    {
        return $this->hasMany(Transaction::class, 'from_account_id');
    }

    public function incomingTransfers()
    {
        return $this->hasMany(Transaction::class, 'to_account_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('status', false);
    }

    public function scopeCash($query)
    {
        return $query->where('type', 'cash');
    }

    public function scopeBank($query)
    {
        return $query->where('type', 'bank');
    }

    public function calculateBalance(): string
    {
        $approvedTransactions = $this->transactions()
            ->where('status', 'approved')
            ->selectRaw(
                "SUM(CASE WHEN direction = 'in' THEN amount ELSE - amount END) as net"
            )
            ->value('net');

        $net = (float) ($approvedTransactions ?? 0);
        return bcadd((string) $this->initial_balance, (string) $net, 2);
    }

    public function getCurrentBalanceAttribute(): string
    {
        return $this->calculateBalance();
    }

    public function formattedAmount(float|int|string $amount): string
    {
        $currency = $this->currency ?: 'XOF';
        return number_format((float)$amount, 0, ',', ' ') . ' ' . $currency;
    }

    public function getTypeLabelAttribute(): string
    {
        $types = self::types();
        return $types[$this->type] ?? $this->type;
    }

    public function getFormattedInitialBalanceAttribute(): string
    {
        return $this->formattedAmount($this->initial_balance);
    }

    public function getFormattedCurrentBalanceAttribute(): string
    {
        return $this->formattedAmount($this->current_balance);
    }

    public function hasSufficientBalance(float|int|string $amount): bool
    {
        return (float) $this->current_balance >= (float) $amount;
    }
}
