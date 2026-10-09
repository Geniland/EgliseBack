<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\Role;
use App\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Ajouter les colonnes manquantes sur attendance_sessions
        Schema::table('attendance_sessions', function (Blueprint $table) {
            if (!Schema::hasColumn('attendance_sessions', 'church_id')) {
                $table->foreignId('church_id')->nullable()->after('id')->constrained('churches')->nullOnDelete();
            }
            if (!Schema::hasColumn('attendance_sessions', 'auto_absences_processed')) {
                $table->boolean('auto_absences_processed')->default(false)->after('qr_expires_at');
            }
            if (!Schema::hasColumn('attendance_sessions', 'auto_absences_at')) {
                $table->dateTime('auto_absences_at')->nullable()->after('auto_absences_processed');
            }
        });

        // 2. Rétro-remplissage du church_id pour les sessions existantes à partir du créateur
        try {
            $userChurchIds = DB::table('users')
                ->whereNotNull('church_id')
                ->pluck('church_id', 'id');

            foreach (DB::table('attendance_sessions')->whereNull('church_id')->whereNotNull('created_by')->get(['id', 'created_by']) as $session) {
                $churchId = $userChurchIds[$session->created_by] ?? null;
                if ($churchId) {
                    DB::table('attendance_sessions')->where('id', $session->id)->update(['church_id' => $churchId]);
                }
            }
        } catch (\Throwable $e) {
            // Ignorer si les tables ou leurs relations ne sont pas encore prêtes.
        }

        // 3. Assurer l'existence de toutes les permissions de présences
        $attendancePerms = [
            'attendance.view' => ['description' => 'Voir les présences', 'module' => 'attendance'],
            'attendance.create' => ['description' => 'Enregistrer une présence ou créer une session', 'module' => 'attendance'],
            'attendance.update' => ['description' => 'Modifier une présence ou une session', 'module' => 'attendance'],
            'attendance.delete' => ['description' => 'Supprimer une présence ou une session', 'module' => 'attendance'],
            'attendance.scan' => ['description' => 'Scanner un QR code de présence', 'module' => 'attendance'],
        ];

        foreach ($attendancePerms as $name => $meta) {
            $perm = Permission::where('name', $name)->first();
            if ($perm) {
                // Mettre à jour module si nécessaire
                $perm->update(['module' => 'attendance', 'status' => true]);
            } else {
                Permission::create([
                    'name' => $name,
                    'description' => $meta['description'],
                    'module' => $meta['module'],
                    'status' => true,
                ]);
            }
        }

        // 4. Assigner les permissions aux rôles appropriés
        $permIds = Permission::whereIn('name', array_keys($attendancePerms))->pluck('id')->toArray();
        $adminPermIds = Permission::whereIn('name', [
            'attendance.view', 'attendance.create', 'attendance.update', 'attendance.delete', 'attendance.scan'
        ])->pluck('id')->toArray();
        $respoPermIds = Permission::whereIn('name', [
            'attendance.view', 'attendance.create', 'attendance.update', 'attendance.delete', 'attendance.scan'
        ])->pluck('id')->toArray();
        $secretairePermIds = Permission::whereIn('name', [
            'attendance.view', 'attendance.create', 'attendance.update', 'attendance.scan'
        ])->pluck('id')->toArray();

        // Super Admin (toutes les permissions)
        $superAdmin = Role::where('name', 'Super Admin')->first();
        if ($superAdmin) {
            $superAdmin->permissions()->syncWithoutDetaching(Permission::pluck('id')->toArray());
        }

        // Administrateur
        $admin = Role::where('name', 'Administrateur')->first();
        if ($admin) {
            $admin->permissions()->syncWithoutDetaching($adminPermIds);
        }

        // Responsable
        $responsable = Role::where('name', 'Responsable')->first();
        if ($responsable) {
            $responsable->permissions()->syncWithoutDetaching($respoPermIds);
        }

        // Secrétaire
        $secretaire = Role::where('name', 'Secrétaire')->first();
        if ($secretaire) {
            $secretaire->permissions()->syncWithoutDetaching($secretairePermIds);
        }
    }

    public function down(): void
    {
        Schema::table('attendance_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('attendance_sessions', 'church_id')) {
                $table->dropConstrainedForeignId('church_id');
            }
            if (Schema::hasColumn('attendance_sessions', 'auto_absences_processed')) {
                $table->dropColumn('auto_absences_processed');
            }
            if (Schema::hasColumn('attendance_sessions', 'auto_absences_at')) {
                $table->dropColumn('auto_absences_at');
            }
        });
    }
};
