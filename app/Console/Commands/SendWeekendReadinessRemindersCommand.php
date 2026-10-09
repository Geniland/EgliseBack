<?php

namespace App\Console\Commands;

use App\Models\ChatMessage;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendWeekendReadinessRemindersCommand extends Command
{
    protected $signature = 'church:send-weekend-readiness-reminders';

    protected $description = 'Envoyer un rappel varié aux fidèles chaque samedi et dimanche matin.';

    public function handle(): int
    {
        $now = now();
        if (!$now->isSaturday() && !$now->isSunday()) {
            $this->comment('Aucun rappel : cette commande ne traite que le samedi et le dimanche.');
            return self::SUCCESS;
        }

        $faithfulRoleId = Role::query()->where('name', 'Fidèle')->value('id');
        if (!$faithfulRoleId) {
            $this->error('Le rôle « Fidèle » est introuvable.');
            return self::FAILURE;
        }

        $membersByChurch = Member::query()
            ->where('status', true)
            ->whereNotNull('church_id')
            ->whereNotNull('user_id')
            ->whereHas('user', function ($query) use ($faithfulRoleId) {
                $query->where('status', true)->where('role_id', $faithfulRoleId);
            })
            ->with('user:id,name,status,role_id,church_id')
            ->get(['id', 'church_id', 'user_id', 'first_name'])
            ->unique('user_id')
            ->groupBy('church_id');

        $sent = 0;
        $skipped = 0;
        $failed = 0;
        $automationKey = 'weekend-readiness:' . $now->toDateString();

        foreach ($membersByChurch as $churchId => $members) {
            $sender = User::query()
                ->where('church_id', $churchId)
                ->where('status', true)
                ->whereHas('role', function ($query) {
                    $query->whereIn('name', ['Administrateur', 'Secrétaire', 'Responsable']);
                })
                ->orderBy('id')
                ->first();

            if (!$sender) {
                $sender = User::query()
                    ->where('status', true)
                    ->whereHas('role', fn ($query) => $query->where('name', 'Super Admin'))
                    ->orderBy('id')
                    ->first();
            }

            if (!$sender) {
                $skipped += $members->count();
                Log::warning('Rappel du week-end ignoré : aucun compte émetteur actif trouvé.', [
                    'church_id' => $churchId,
                ]);
                continue;
            }

            foreach ($members as $member) {
                $recipient = $member->user;
                if (!$recipient || (int) $recipient->id === (int) $sender->id) {
                    $skipped++;
                    continue;
                }

                $alreadySent = ChatMessage::query()
                    ->where('church_id', $churchId)
                    ->where('recipient_id', $recipient->id)
                    ->where('automation_key', $automationKey)
                    ->exists();

                if ($alreadySent) {
                    $skipped++;
                    continue;
                }

                try {
                    ChatMessage::create([
                        'church_id' => $churchId,
                        'sender_id' => $sender->id,
                        'recipient_id' => $recipient->id,
                        'automation_key' => $automationKey,
                        'contenu' => $this->makeMessage($member->first_name, $recipient->name, $now->isSaturday()),
                        'lu' => false,
                        'expires_at' => $now->copy()->addDays(7),
                    ]);
                    $sent++;
                } catch (\Throwable $exception) {
                    // A second scheduler worker may have created this recipient's reminder first.
                    if (ChatMessage::query()
                        ->where('church_id', $churchId)
                        ->where('recipient_id', $recipient->id)
                        ->where('automation_key', $automationKey)
                        ->exists()) {
                        $skipped++;
                        continue;
                    }

                    $failed++;
                    Log::warning('Échec de création du rappel du week-end.', [
                        'church_id' => $churchId,
                        'recipient_id' => $recipient->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }

        $this->info("Rappels envoyés : {$sent} | déjà envoyés ou ignorés : {$skipped} | erreurs : {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function makeMessage(?string $memberFirstName, ?string $userName, bool $isSaturday): string
    {
        $name = trim((string) $memberFirstName);
        if ($name === '') {
            $name = trim(explode(' ', (string) $userName)[0] ?? '');
        }
        $name = $name !== '' ? ucfirst(mb_strtolower($name)) : 'cher(e) fidèle';

        $saturdayMessages = [
            'Bonjour %s 🙏 Le culte de demain approche ! Es-tu prêt(e) à nous rejoindre ? As-tu déjà préparé et repassé tes habits ? Nous avons hâte de partager ce moment avec toi.',
            'Coucou %s 😊 Petit rappel du samedi : demain, c’est le culte ! Ta tenue est-elle prête et repassée ? Nous espérons te voir parmi nous.',
            'Bonjour %s 🌿 Comment vas-tu ? Es-tu prêt(e) pour dimanche ? Si ce n’est pas déjà fait, pense à préparer tes habits pour le culte de demain.',
            'La famille de l’église pense à toi, %s 💛 Le dimanche arrive : as-tu préparé tes affaires et repassé ta tenue ? Nous serons heureux de t’accueillir demain.',
        ];

        $sundayMessages = [
            'Bonjour %s 🙏 C’est dimanche ! Es-tu prêt(e) pour le culte ? Tes habits sont-ils prêts et repassés ? Nous nous réjouissons de te retrouver.',
            'Très bon dimanche, %s 🌞 Ta tenue est-elle prête ? Nous espérons te voir au culte aujourd’hui pour partager ce beau moment ensemble.',
            'Bonjour %s 💛 Le culte de ce dimanche est arrivé ! Es-tu prêt(e) à nous rejoindre ? Ta tenue est-elle prête ? Que ta journée soit remplie de paix et de joie.',
            'Joyeux dimanche, %s 🌿 N’oublie pas le culte d’aujourd’hui ! Si tes habits sont prêts, nous t’attendons avec joie.',
        ];

        $messages = $isSaturday ? $saturdayMessages : $sundayMessages;

        return sprintf($messages[random_int(0, count($messages) - 1)], $name);
    }
}
