<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;

class Member extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [

        'member_code',
        'qr_token',
        'church_id',
        'user_id',

        'first_name',
        'last_name',

        'gender',

        'birth_date',
        'birth_place',

        'phone',
        'email',

        'address',
        'city',
        'country',

        'profession',

        'marital_status',
        'spouse_name',

        'conversion_date',
        'baptism_date',
        'membership_date',

        'photo',

        'emergency_contact',
        'emergency_phone',

        'status',

        'created_by',
        'updated_by',

        'family_id',
        'member_type',

    ];

    protected $casts = [

        'birth_date' => 'date',
        'conversion_date' => 'date',
        'baptism_date' => 'date',
        'membership_date' => 'date',

        'status' => 'boolean',

    ];

    /**
     * Église à laquelle appartient le membre.
     */
    public function church()
    {
        return $this->belongsTo(Church::class, 'church_id');
    }

    /**
     * Scope pour filtrer les membres selon l'église et les droits de l'utilisateur.
     */
    public function scopeForUser($query, ?User $user = null)
    {
        return \App\Support\ScopeHelper::applyMemberScope($query, $user);
    }

    /**
     * Utilisateur ayant créé le membre.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Utilisateur ayant modifié le membre.
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Famille du membre.
     */
    public function family()
    {
        return $this->belongsTo(Family::class, 'family_id');
    }

    /**
     * Ministères auxquels appartient le membre.
     */
    public function ministries()
    {
        return $this->belongsToMany(Ministry::class);
    }

    /**
     * Utilisateur lié à ce membre (pour la connexion de l'app mobile).
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Génère un token QR unique pour le membre s'il n'en a pas déjà un.
     */
    public function ensureQrToken(): string
    {
        if (!empty($this->qr_token)) {
            return $this->qr_token;
        }
        do {
            $token = 'MQR-' . Str::random(32);
        } while (self::where('qr_token', $token)->exists());

        $this->qr_token = $token;
        $this->save();

        return $token;
    }

    /**
     * Retourne le payload JSON embarqué dans le QR code.
     */
    public function getQrPayload(): array
    {
        return [
            'type' => 'member',
            't' => $this->ensureQrToken(),
            'mid' => $this->id,
            'code' => $this->member_code,
            'church_id' => $this->church_id,
            'fn' => $this->first_name,
            'ln' => $this->last_name,
            'v' => 1,
        ];
    }

    /**
     * Retourne les données du QR code sous forme de chaîne JSON sérialisée.
     */
    public function getQrCodeDataString(): string
    {
        return json_encode($this->getQrPayload(), JSON_UNESCAPED_SLASHES);
    }

    /**
     * Génère l'image QR code (PNG en base64 data URL) pour affichage côté client.
     */
    public function getQrCodeDataUrl(int $size = 280): string
    {
        $data = $this->getQrCodeDataString();

        $qrCode = new QrCode(
            data: $data,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $size,
            margin: 6,
        );

        $writer = new PngWriter();
        $result = $writer->write($qrCode);
        return 'data:image/png;base64,' . base64_encode($result->getString());
    }

    /**
     * Scope permettant de retrouver un membre via son QR token.
     */
    public function scopeByQrToken($query, string $token)
    {
        return $query->where('qr_token', $token);
    }
}