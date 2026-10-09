<?php

namespace App\Console\Commands;

use App\Models\Member;
use Illuminate\Console\Command;

class EnsureMemberQrTokensCommand extends Command
{
    protected $signature = 'members:ensure-qr-tokens {--church-id= : Limiter à une église spécifique}';
    protected $description = 'Génére un token QR permanent pour tous les membres qui n\'en ont pas encore';

    public function handle(): int
    {
        $churchId = $this->option('church-id');

        $query = Member::query();
        if ($churchId) {
            $query->where('church_id', (int) $churchId);
        }

        $total = (clone $query)->count();
        $toProcess = (clone $query)->whereNull('qr_token')->count();
        $this->info("Membres totaux : {$total}, sans QR token : {$toProcess}");

        if (!$toProcess) {
            $this->info("Aucun membre à traiter.");
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($toProcess);
        $bar->start();

        $updated = 0;
        $errors = 0;

        (clone $query)
            ->whereNull('qr_token')
            ->orderBy('id')
            ->chunk(200, function ($members) use ($bar, &$updated, &$errors) {
                foreach ($members as $member) {
                    try {
                        $member->ensureQrToken();
                        $updated++;
                    } catch (\Throwable) {
                        $errors++;
                    }
                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine(2);

        $this->info("Terminé : {$updated} tokens générés, {$errors} erreurs.");

        return self::SUCCESS;
    }
}
