<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'user1_id',
        'user2_id',
        'duree',
    ];

    public static function getSettingBetween(int $userId1, int $userId2): ?self
    {
        $u1 = min($userId1, $userId2);
        $u2 = max($userId1, $userId2);

        return self::where('user1_id', $u1)->where('user2_id', $u2)->first();
    }

    public static function setDurationBetween(int $userId1, int $userId2, ?string $duree): self
    {
        $u1 = min($userId1, $userId2);
        $u2 = max($userId1, $userId2);

        return self::updateOrCreate(
            ['user1_id' => $u1, 'user2_id' => $u2],
            ['duree' => $duree]
        );
    }
}
