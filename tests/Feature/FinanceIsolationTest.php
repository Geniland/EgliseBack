<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\Transaction;
use App\Support\ScopeHelper;
use Illuminate\Support\Facades\Hash;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class FinanceIsolationTest extends TestCase
{
    use DatabaseTransactions;

    private static bool $seedsInited = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::$seedsInited) {
            try { \Artisan::call('db:seed', ['--class' => 'RoleSeeder', '--force' => true]); } catch (\Throwable $e) {}
            try { \Artisan::call('db:seed', ['--class' => 'PermissionSeeder', '--force' => true]); } catch (\Throwable $e) {}
            try { \Artisan::call('db:seed', ['--class' => 'RolePermissionSeeder', '--force' => true]); } catch (\Throwable $e) {}
            self::$seedsInited = true;
        }
    }

    private function createUser(string $name, string $email, int $roleId, ?int $parentUserId = null, ?string $church = null): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('Secret123!'),
            'phone' => '0000000' . rand(100, 999),
            'gender' => 'Homme',
            'role_id' => $roleId,
            'parent_user_id' => $parentUserId,
            'church_name' => $church,
            'status' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function findRoleId(string $name): int
    {
        return (int) Role::where('name', $name)->firstOrFail()->id;
    }

    public function test_team_user_ids_includes_descendants(): void
    {
        $roleAdmin = $this->findRoleId('Administrateur');
        $roleResp = $this->findRoleId('Responsable');
        $roleCompta = $this->findRoleId('Comptable');

        $admin1 = $this->createUser('Admin1', 'a1.isolation@test.com', $roleAdmin, null, 'Église Mère A');
        $resp1 = $this->createUser('Resp Sous1', 'r1.isolation@test.com', $roleResp, $admin1->id, 'Sous-Église A.1');
        $compta1 = $this->createUser('Compta Sous1', 'c1.isolation@test.com', $roleCompta, $resp1->id, 'Sous-Église A.1');

        $admin2 = $this->createUser('Admin2', 'a2.isolation@test.com', $roleAdmin, null, 'Église Mère B');

        $teamAdmin1 = $admin1->fresh()->team_user_ids;
        sort($teamAdmin1);
        $this->assertEquals([$admin1->id, $resp1->id, $compta1->id], $teamAdmin1);

        $teamResp1 = $resp1->fresh()->team_user_ids;
        sort($teamResp1);
        $this->assertEquals([$resp1->id, $compta1->id], $teamResp1);

        $teamAdmin2 = $admin2->fresh()->team_user_ids;
        $this->assertEquals([$admin2->id], $teamAdmin2);
    }

    public function test_finance_data_strictly_isolated_between_teams(): void
    {
        $roleAdmin = $this->findRoleId('Administrateur');
        $roleResp = $this->findRoleId('Responsable');
        $roleSuper = $this->findRoleId('Super Admin');

        $admin1 = $this->createUser('AdminTest1', 'finiso_a1@test.com', $roleAdmin, null, 'Église Mère 1');
        $resp1  = $this->createUser('RespTest1', 'finiso_r1@test.com', $roleResp, $admin1->id, 'Sous-Église 1.1');
        $admin2 = $this->createUser('AdminTest2', 'finiso_a2@test.com', $roleAdmin, null, 'Église Mère 2');
        $super  = $this->createUser('SuperTest', 'finiso_sa@test.com', $roleSuper, null, 'SYS');

        $catIncomeId = FinancialCategory::income()->active()->value('id')
            ?? FinancialCategory::create([
                'name' => '[TEST] Dîmes',
                'type' => 'income',
                'status' => true,
                'created_by' => $admin1->id,
                'updated_by' => $admin1->id,
            ])->id;
        $catExpenseId = FinancialCategory::expense()->active()->value('id')
            ?? FinancialCategory::create([
                'name' => '[TEST] Loyer',
                'type' => 'expense',
                'status' => true,
                'created_by' => $admin2->id,
                'updated_by' => $admin2->id,
            ])->id;

        $accA = FinancialAccount::create([
            'name' => '[TEST] Caisse Principale A',
            'type' => 'cash',
            'initial_balance' => 500000,
            'currency' => 'XOF',
            'status' => true,
            'created_by' => $admin1->id,
            'updated_by' => $admin1->id,
        ]);
        $accB = FinancialAccount::create([
            'name' => '[TEST] Caisse Principale B',
            'type' => 'bank',
            'initial_balance' => 200000,
            'currency' => 'XOF',
            'status' => true,
            'created_by' => $resp1->id,
            'updated_by' => $resp1->id,
        ]);
        $accC = FinancialAccount::create([
            'name' => '[TEST] Caisse Autre Équipe',
            'type' => 'cash',
            'initial_balance' => 150000,
            'currency' => 'XOF',
            'status' => true,
            'created_by' => $admin2->id,
            'updated_by' => $admin2->id,
        ]);

        Transaction::create([
            'type' => 'income', 'direction' => 'in',
            'account_id' => $accA->id, 'category_id' => $catIncomeId,
            'amount' => 100000,
            'description' => 'Dîme équipe Admin1',
            'transaction_date' => now()->toDateString(),
            'status' => 'pending',
            'payment_method' => 'cash',
            'created_by' => $admin1->id, 'updated_by' => $admin1->id,
        ]);
        Transaction::create([
            'type' => 'expense', 'direction' => 'out',
            'account_id' => $accB->id, 'category_id' => $catExpenseId,
            'amount' => 50000,
            'description' => 'Dépense sous-équipe Resp1',
            'transaction_date' => now()->toDateString(),
            'status' => 'pending',
            'payment_method' => 'cash',
            'created_by' => $resp1->id, 'updated_by' => $resp1->id,
        ]);
        Transaction::create([
            'type' => 'income', 'direction' => 'in',
            'account_id' => $accC->id, 'category_id' => $catIncomeId,
            'amount' => 99999,
            'description' => 'Don Équipe Admin2 (autre église)',
            'transaction_date' => now()->toDateString(),
            'status' => 'pending',
            'payment_method' => 'cash',
            'created_by' => $admin2->id, 'updated_by' => $admin2->id,
        ]);

        \Auth::setUser($admin1->fresh());
        $this->assertFalse(ScopeHelper::isSuperAdmin());
        $team = ScopeHelper::getTeamUserIds();
        sort($team);
        $exp = [$admin1->id, $resp1->id];
        sort($exp);
        $this->assertEquals($exp, $team);

        $countAccA = FinancialAccount::query()->tap(fn($q) => ScopeHelper::applyOwnedByScope($q))->count();
        $countTxA = Transaction::query()->tap(fn($q) => ScopeHelper::applyOwnedByScope($q))->count();
        $this->assertEquals(2, $countAccA, 'Admin1 doit voir 2 comptes (le sien + celui de Resp1)');
        $this->assertEquals(2, $countTxA, 'Admin1 doit voir 2 transactions (la sienne + celle de Resp1)');

        \Auth::setUser($admin2->fresh());
        $countAccB = FinancialAccount::query()->tap(fn($q) => ScopeHelper::applyOwnedByScope($q))->count();
        $countTxB = Transaction::query()->tap(fn($q) => ScopeHelper::applyOwnedByScope($q))->count();
        $this->assertEquals(1, $countAccB, 'Admin2 doit voir 1 compte seulement (le sien)');
        $this->assertEquals(1, $countTxB, 'Admin2 doit voir 1 transaction seulement (la sienne)');

        $this->assertFalse(
            ScopeHelper::recordBelongsToTeam(FinancialAccount::class, $accA->id),
            'Admin2 ne doit PAS avoir accès au compte de Admin1'
        );
        $this->assertFalse(
            ScopeHelper::recordBelongsToTeam(Transaction::class, 1),
            'Admin2 ne doit pas accéder aux transactions des autres via recordBelongsToTeam'
        );

        \Auth::setUser($super->fresh());
        $this->assertTrue(ScopeHelper::isSuperAdmin());
        $countAccS = FinancialAccount::query()->tap(fn($q) => ScopeHelper::applyOwnedByScope($q))->count();
        $countTxS = Transaction::query()->tap(fn($q) => ScopeHelper::applyOwnedByScope($q))->count();
        $this->assertGreaterThanOrEqual(3, $countAccS, 'Super Admin doit voir les 3 comptes minimum');
        $this->assertGreaterThanOrEqual(3, $countTxS, 'Super Admin doit voir les 3 transactions minimum');
        $this->assertEmpty(ScopeHelper::getTeamUserIds(), 'Super Admin bypass: team ids vide = aucun filtre');

        \Auth::logout();
    }

    public function test_find_owned_or_fail_blocks_outside_team(): void
    {
        $roleAdmin = $this->findRoleId('Administrateur');

        $admin1 = $this->createUser('FindA', 'find_a@test.com', $roleAdmin, null, 'X');
        $admin2 = $this->createUser('FindB', 'find_b@test.com', $roleAdmin, null, 'Y');

        $acc = FinancialAccount::create([
            'name' => '[TEST] FindOwned',
            'type' => 'cash',
            'initial_balance' => 0,
            'currency' => 'XOF',
            'status' => true,
            'created_by' => $admin1->id,
            'updated_by' => $admin1->id,
        ]);

        \Auth::setUser($admin1->fresh());
        $found = ScopeHelper::findOwnedOrFail(FinancialAccount::class, $acc->id);
        $this->assertEquals($acc->id, $found->id);

        \Auth::setUser($admin2->fresh());
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        ScopeHelper::findOwnedOrFail(FinancialAccount::class, $acc->id);

        \Auth::logout();
    }
}
