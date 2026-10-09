<?php

namespace App\Console\Commands;

use App\Services\AttendanceAutoAbsenceService;
use Illuminate\Console\Command;

class ProcessExpiredAttendanceSessionsCommand extends Command
{
    protected $signature = 'attendance:process-expired {--max=50 : Nombre max de sessions à traiter}';

    protected $description = 'Traiter les sessions QR expirées : marquer absences automatiquement et envoyer messages pastoraux aux absents.';

    public function handle(AttendanceAutoAbsenceService $service): int
    {
        $max = (int) $this->option('max');
        $max = max(1, min($max, 5000));

        $this->line('Traitement sessions expirées… (max=' . $max . ')');

        try {
            $report = $service->processExpired($max);
        } catch (\Throwable $e) {
            $this->error('ERREUR FATALE : ' . $e->getMessage());
            return 1;
        }

        $this->info(' Sessions traitées     : ' . $report['sessions_processed']);
        $this->info(' Absences créées       : ' . $report['absences_created']);
        $this->info(' Messages pastoraux    : ' . $report['messages_created']);
        if ($report['errors'] > 0) $this->warn(' Erreurs ignorées      : ' . $report['errors']);
        $this->line(' Effectué le           : ' . $report['processed_at']);

        return 0;
    }
}
