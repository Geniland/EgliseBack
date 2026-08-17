<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class Transaction extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'transaction_code',
        'type',
        'direction',
        'account_id',
        'category_id',
        'amount',
        'description',
        'transaction_date',
        'reference',
        'payment_method',
        'status',
        'rejection_reason',
        'parent_transaction_id',
        'transfer_group_code',
        'from_account_id',
        'to_account_id',
        'created_by',
        'approved_by',
        'approved_at',
        'updated_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'date',
        'approved_at' => 'datetime',
    ];

    public static function types(): array
    {
        return [
            'income' => 'Recette',
            'expense' => 'Dépense',
            'transfer' => 'Transfert',
        ];
    }

    public static function statuses(): array
    {
        return [
            'pending' => 'En attente',
            'approved' => 'Approuvée',
            'rejected' => 'Rejetée',
        ];
    }

    public static function directions(): array
    {
        return [
            'in' => 'Entrée',
            'out' => 'Sortie',
        ];
    }

    public static function paymentMethods(): array
    {
        return [
            'cash' => 'Espèces',
            'bank' => 'Virement bancaire',
            'check' => 'Chèque',
            'transfer' => 'Transfert interne',
            'other' => 'Autre',
        ];
    }

    public static function generateTransactionCode(): string
    {
        $last = DB::table('transactions')
            ->whereNotNull('transaction_code')
            ->latest('id')
            ->value('transaction_code');

        $n = 1;
        if ($last && preg_match('/^TRX-(\d{6})$/', $last, $m)) {
            $n = ((int) $m[1]) + 1;
        }
        return 'TRX-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    public static function generateTransferGroupCode(): string
    {
        $last = DB::table('transactions')
            ->whereNotNull('transfer_group_code')
            ->latest('id')
            ->value('transfer_group_code');

        $n = 1;
        if ($last && preg_match('/^TRF-(\d{6})$/', $last, $m)) {
            $n = ((int) $m[1]) + 1;
        }
        return 'TRF-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    protected static function booted(): void
    {
        static::creating(function (self $t) {
            if (empty($t->transaction_code)) {
                $t->transaction_code = self::generateTransactionCode();
            }
            if (empty($t->transaction_date)) {
                $t->transaction_date = now()->toDateString();
            }
        });
    }

    public function account()
    {
        return $this->belongsTo(FinancialAccount::class, 'account_id');
    }

    public function category()
    {
        return $this->belongsTo(FinancialCategory::class, 'category_id');
    }

    public function fromAccount()
    {
        return $this->belongsTo(FinancialAccount::class, 'from_account_id');
    }

    public function toAccount()
    {
        return $this->belongsTo(FinancialAccount::class, 'to_account_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function parentTransaction()
    {
        return $this->belongsTo(self::class, 'parent_transaction_id');
    }

    public function reverseTransactions()
    {
        return $this->hasMany(self::class, 'parent_transaction_id');
    }

    public function transferPair()
    {
        if (!$this->transfer_group_code) return null;
        return $this->hasOne(self::class, 'transfer_group_code', 'transfer_group_code')
            ->where('id', '!=', $this->id);
    }

    public function attachments()
    {
        return $this->hasMany(TransactionAttachment::class, 'transaction_id');
    }

    public function scopeIncome($query)
    {
        return $query->where('type', 'income');
    }

    public function scopeExpense($query)
    {
        return $query->where('type', 'expense');
    }

    public function scopeTransfer($query)
    {
        return $query->where('type', 'transfer');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopeFromAccount($query, int $accountId)
    {
        return $query->where('account_id', $accountId);
    }

    public function scopeInPeriod($query, string $from, string $to)
    {
        return $query->whereBetween('transaction_date', [$from, $to]);
    }

    public function scopeFromMonth($query, ?int $year = null, ?int $month = null)
    {
        $year ??= now()->year;
        $month ??= now()->month;
        return $query->whereYear('transaction_date', $year)
                     ->whereMonth('transaction_date', $month);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isTransfer(): bool
    {
        return $this->type === 'transfer';
    }

    public function canBeEdited(): bool
    {
        return !$this->isApproved();
    }

    public function canBeDeleted(): bool
    {
        return !$this->isApproved();
    }

    public function canBeApprovedBy(?User $user = null): bool
    {
        $user ??= auth()->user();
        if (!$user) return false;
        if (!$this->isPending()) return false;
        if ((int) $this->created_by === (int) $user->id) {
            return false;
        }
        return true;
    }

    public function canBeRejectedBy(?User $user = null): bool
    {
        return $this->canBeApprovedBy($user);
    }

    public function getTypeLabelAttribute(): string
    {
        $types = self::types();
        return $types[$this->type] ?? $this->type;
    }

    public function getStatusLabelAttribute(): string
    {
        $statuses = self::statuses();
        return $statuses[$this->status] ?? $this->status;
    }

    public function getDirectionLabelAttribute(): string
    {
        $d = self::directions();
        return $d[$this->direction] ?? $this->direction;
    }

    public function getPaymentMethodLabelAttribute(): ?string
    {
        if (!$this->payment_method) return null;
        $m = self::paymentMethods();
        return $m[$this->payment_method] ?? $this->payment_method;
    }

    public function getSignedAmountAttribute(): string
    {
        $sign = $this->direction === 'in' ? '+' : '-';
        return $sign . rtrim(rtrim(number_format((float)$this->amount, 2, '.', ''), '0'), '.');
    }

    public function getFormattedAmountAttribute(): string
    {
        $currency = optional($this->account)->currency ?: 'XOF';
        return number_format((float)$this->amount, 0, ',', ' ') . ' ' . $currency;
    }

    public function getFormattedSignedAmountAttribute(): string
    {
        $currency = optional($this->account)->currency ?: 'XOF';
        $sign = $this->direction === 'in' ? '+' : '-';
        return $sign . number_format((float)$this->amount, 0, ',', ' ') . ' ' . $currency;
    }

    public function getFormattedDateAttribute(): string
    {
        if (!$this->transaction_date) return '';
        return $this->transaction_date->isoFormat('dddd D MMMM YYYY');
    }
}
