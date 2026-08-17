<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Role;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\Transaction;
use App\Support\ScopeHelper;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

echo "=== TEST ISOLATION HIÉRARCHIQUE FINANCIÈRE MULTI-ÉGLISES ===\n\n";
$pass = 0; $fail = 0;

function it(string $label, callable $fn, &$pass, &$fail): void {
    echo "→ {$label} : ";
    try {
        $fn();
        echo "OK\n";
        $pass++;
    } catch (\Throwable $e) {
        echo "❌ ÉCHEC\n   " . get_class($e) . " : " . $e->getMessage() . "\n   File:" . $e->getFile() . " L:" . $e->getLine() . "\n";
        $fail++;
    }
}

function assertEq($expected, $actual, string $msg = ''): void {
    if ($expected != $actual) {
        $expStr = var_export($expected, true);
        $actStr = var_export($actual, true);
        throw new \RuntimeException("Assertion failed: expected={$expStr} actual={$actStr} " . $msg);
    }
}

function assertTrue(bool $cond, string $msg = ''): void {
    if (!$cond) throw new \RuntimeException("Assertion failed: TRUE attendu. " . $msg);
}

function assertFalse(bool $cond, string $msg = ''): void {
    if ($cond) throw new \RuntimeException("Assertion failed: FALSE attendu. " . $msg);
}

DB::beginTransaction();
try {
    $roleSuper = (int) Role::where('name', 'Super Admin')->firstOrFail()->id;
    $roleAdmin = (int) Role::where('name', 'Administrateur')->firstOrFail()->id;
    $roleResp  = (int) Role::where('name', 'Responsable')->firstOrFail()->id;
    $roleCompta = (int) Role::where('name', 'Comptable')->firstOrFail()->id;
    echo "✅ Rôles trouvés : Super=$roleSuper Admin=$roleAdmin Resp=$roleResp Compta=$roleCompta\n\n";

    $makeUser = function (string $n, string $e, int $rid, ?int $pid = null, ?string $church = null) {
        return User::create([
            'name' => $n, 'email' => $e, 'password' => Hash::make('S1234!ab'),
            'phone' => '99' . rand(100000, 999999), 'gender' => 'Homme', 'role_id' => $rid,
            'parent_user_id' => $pid, 'church_name' => $church,
            'status' => true, 'email_verified_at' => now(),
        ]);
    };

    $admin1 = $makeUser('ISO_Admin1', 'iso_a1_' . time() . '@test.xx', $roleAdmin, null, 'ÉGLISE PRINCIPALE A');
    $resp1  = $makeUser('ISO_RespA1',  'iso_r1_' . time() . '@test.xx', $roleResp,  $admin1->id, 'SOUS-ÉGLISE A-1');
    $resp2  = $makeUser('ISO_RespA2',  'iso_r2_' . time() . '@test.xx', $roleResp,  $admin1->id, 'SOUS-ÉGLISE A-2');
    $compta1 = $makeUser('ISO_ComptaA1','iso_c1_' . time() . '@test.xx', $roleCompta,$resp1->id,  'SOUS-ÉGLISE A-1');

    $admin2 = $makeUser('ISO_Admin2', 'iso_a2_' . time() . '@test.xx', $roleAdmin, null, 'ÉGLISE PRINCIPALE B');
    $respB  = $makeUser('ISO_RespB1',  'iso_rb_' . time() . '@test.xx', $roleResp,  $admin2->id, 'SOUS-ÉGLISE B-1');

    $super  = $makeUser('ISO_Super',  'iso_sa_' . time() . '@test.xx', $roleSuper, null, 'SYS');

    // ===== TEST 1 : team_user_ids hiérarchique =====
    it("Team Admin1 inclut lui-même + RespA1 + RespA2 + ComptaA1 (4 users)", function() use ($admin1,$resp1,$resp2,$compta1,&$pass,&$fail){
        $t = $admin1->fresh()->team_user_ids;
        sort($t);
        $exp = [$admin1->id,$resp1->id,$resp2->id,$compta1->id];
        sort($exp);
        assertEq(4, count($t), "taille équipe admin1");
        assertEq($exp, $t);
    }, $pass, $fail);

    it("Team RespA1 inclut RespA1 + ComptaA1 (2)", function() use ($resp1,$compta1,&$pass,&$fail){
        $t = $resp1->fresh()->team_user_ids;
        sort($t);
        $exp = [$resp1->id, $compta1->id];
        sort($exp);
        assertEq($exp, $t);
    }, $pass, $fail);

    it("Team Admin2 n'inclut que lui-même + RespB1 (2)", function() use ($admin2,$respB,&$pass,&$fail){
        $t = $admin2->fresh()->team_user_ids;
        sort($t);
        assertEq([$admin2->id, $respB->id], $t);
    }, $pass, $fail);

    // ===== TEST 2 : Création des données financières =====
    $catInc = FinancialCategory::where('type', 'income')->active()->first()
        ?? FinancialCategory::create(['name'=>'[ISO] Test Inc','type'=>'income','status'=>true,'created_by'=>$admin1->id,'updated_by'=>$admin1->id]);
    $catDep = FinancialCategory::where('type', 'expense')->active()->first()
        ?? FinancialCategory::create(['name'=>'[ISO] Test Dep','type'=>'expense','status'=>true,'created_by'=>$admin1->id,'updated_by'=>$admin1->id]);

    $accA1 = FinancialAccount::create(['name'=>'[ISO] Caisse Admin1','type'=>'cash','initial_balance'=>100000,'currency'=>'XOF','status'=>true,'created_by'=>$admin1->id,'updated_by'=>$admin1->id]);
    $accAR1 = FinancialAccount::create(['name'=>'[ISO] Caisse RespA1','type'=>'bank','initial_balance'=>50000,'currency'=>'XOF','status'=>true,'created_by'=>$resp1->id,'updated_by'=>$resp1->id]);
    $accAC1 = FinancialAccount::create(['name'=>'[ISO] Caisse ComptaA1','type'=>'cash','initial_balance'=>10000,'currency'=>'XOF','status'=>true,'created_by'=>$compta1->id,'updated_by'=>$compta1->id]);
    $accB  = FinancialAccount::create(['name'=>'[ISO] Caisse Admin2','type'=>'cash','initial_balance'=>25000,'currency'=>'XOF','status'=>true,'created_by'=>$admin2->id,'updated_by'=>$admin2->id]);

    Transaction::create(['type'=>'income','direction'=>'in','account_id'=>$accA1->id,'category_id'=>$catInc->id,'amount'=>50000,'description'=>'Don Admin1','transaction_date'=>now()->toDateString(),'status'=>'pending','payment_method'=>'cash','created_by'=>$admin1->id,'updated_by'=>$admin1->id]);
    Transaction::create(['type'=>'expense','direction'=>'out','account_id'=>$accAR1->id,'category_id'=>$catDep->id,'amount'=>15000,'description'=>'Loyer A1','transaction_date'=>now()->toDateString(),'status'=>'pending','payment_method'=>'cash','created_by'=>$resp1->id,'updated_by'=>$resp1->id]);
    Transaction::create(['type'=>'income','direction'=>'in','account_id'=>$accAC1->id,'category_id'=>$catInc->id,'amount'=>5000,'description'=>'Dime Compta A1','transaction_date'=>now()->toDateString(),'status'=>'pending','payment_method'=>'cash','created_by'=>$compta1->id,'updated_by'=>$compta1->id]);
    Transaction::create(['type'=>'income','direction'=>'in','account_id'=>$accB->id,'category_id'=>$catInc->id,'amount'=>9999,'description'=>'Don AUTRE équipe B','transaction_date'=>now()->toDateString(),'status'=>'pending','payment_method'=>'cash','created_by'=>$admin2->id,'updated_by'=>$admin2->id]);

    echo "\n=== DONNÉES CRÉÉES ===\n";
    echo "  Comptes  : ÉquipeA = 3 (Admin1 + RespA1 + ComptaA1), ÉquipeB = 1 (Admin2)\n";
    echo "  Transacs : ÉquipeA = 3, ÉquipeB = 1\n\n";

    // ===== TEST 3 : Isolation Admin1 =====
    Auth::setUser($admin1->fresh());
    it("Admin1 is NOT SuperAdmin", fn() => assertFalse(ScopeHelper::isSuperAdmin()), $pass, $fail);
    it("Admin1 voit 3 comptes (équipeA)", function() use ($admin1) {
        $c = FinancialAccount::query()->tap(fn($q)=>ScopeHelper::applyOwnedByScope($q))->count();
        assertEq(3, $c);
    }, $pass, $fail);
    it("Admin1 voit 3 transactions (équipeA)", function() {
        $c = Transaction::query()->tap(fn($q)=>ScopeHelper::applyOwnedByScope($q))->count();
        assertEq(3, $c);
    }, $pass, $fail);
    it("Admin1 findOwnedOrFail accA1 OK", function() use ($accA1) {
        $f = ScopeHelper::findOwnedOrFail(FinancialAccount::class, $accA1->id);
        assertEq($accA1->id, $f->id);
    }, $pass, $fail);
    it("Admin1 recordBelongsToTeam sur son compte = TRUE", function() use ($accA1) {
        assertTrue(ScopeHelper::recordBelongsToTeam(FinancialAccount::class, $accA1->id));
    }, $pass, $fail);
    it("Admin1 recordBelongsToTeam sur compte équipe B = FALSE", function() use ($accB) {
        assertFalse(ScopeHelper::recordBelongsToTeam(FinancialAccount::class, $accB->id));
    }, $pass, $fail);
    it("Admin1 findOwnedOrFail sur compte équipe B = 404", function() use ($accB) {
        $thrown = false;
        try { ScopeHelper::findOwnedOrFail(FinancialAccount::class, $accB->id); }
        catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) { $thrown = true; }
        assertTrue($thrown, "ModelNotFoundException attendue");
    }, $pass, $fail);

    // ===== TEST 4 : Isolation Admin2 =====
    Auth::setUser($admin2->fresh());
    it("Admin2 voit 1 compte (seulement lui-même)", function() {
        $c = FinancialAccount::query()->tap(fn($q)=>ScopeHelper::applyOwnedByScope($q))->count();
        assertEq(1, $c);
    }, $pass, $fail);
    it("Admin2 voit 1 transaction", function() {
        $c = Transaction::query()->tap(fn($q)=>ScopeHelper::applyOwnedByScope($q))->count();
        assertEq(1, $c);
    }, $pass, $fail);
    it("Admin2 ne voit PAS le compte d'Admin1", function() use ($accA1) {
        assertFalse(ScopeHelper::recordBelongsToTeam(FinancialAccount::class, $accA1->id));
    }, $pass, $fail);
    it("Admin2 ne voit PAS la transaction d'Admin1", function() use ($accA1) {
        $tx = Transaction::where('account_id', $accA1->id)->first();
        assertFalse(ScopeHelper::recordBelongsToTeam(Transaction::class, $tx?->id ?? 0));
    }, $pass, $fail);

    // ===== TEST 5 : Isolation Compta (subordonné) =====
    Auth::setUser($compta1->fresh());
    it("ComptaA1 voit 1 compte (le sien uniquement)", function() {
        $c = FinancialAccount::query()->tap(fn($q)=>ScopeHelper::applyOwnedByScope($q))->count();
        assertEq(1, $c);
    }, $pass, $fail);
    it("ComptaA1 voit 1 transaction (la sienne)", function() {
        $c = Transaction::query()->tap(fn($q)=>ScopeHelper::applyOwnedByScope($q))->count();
        assertEq(1, $c);
    }, $pass, $fail);

    // ===== TEST 6 : Super Admin (bypass) =====
    Auth::setUser($super->fresh());
    it("SuperAdmin IS SuperAdmin", fn() => assertTrue(ScopeHelper::isSuperAdmin()), $pass, $fail);
    it("SuperAdmin getTeamUserIds = [] (bypass)", function() {
        $t = ScopeHelper::getTeamUserIds();
        assertEq([], $t);
    }, $pass, $fail);
    it("SuperAdmin voit TOUS les comptes (>= 4)", function() {
        $c = FinancialAccount::query()->tap(fn($q)=>ScopeHelper::applyOwnedByScope($q))->count();
        assertTrue($c >= 4, "count=$c");
    }, $pass, $fail);
    it("SuperAdmin voit TOUTES les transactions (>= 4)", function() {
        $c = Transaction::query()->tap(fn($q)=>ScopeHelper::applyOwnedByScope($q))->count();
        assertTrue($c >= 4, "count=$c");
    }, $pass, $fail);
    it("SuperAdmin findOwnedOrFail OK sur compte équipe B", function() use ($accB) {
        $f = ScopeHelper::findOwnedOrFail(FinancialAccount::class, $accB->id);
        assertEq($accB->id, $f->id);
    }, $pass, $fail);

    // ===== TEST 7 : Non connecté =====
    Auth::logout();
    it("Non connecté getTeamUserIds = [0] (bloquant)", function() {
        $t = ScopeHelper::getTeamUserIds();
        assertEq([0], $t);
    }, $pass, $fail);
    it("Non connecté applyOwnedByScope = WHERE 0=1 => 0 ligne", function() {
        $c = FinancialAccount::query()->tap(fn($q)=>ScopeHelper::applyOwnedByScope($q))->count();
        assertEq(0, $c);
    }, $pass, $fail);
    it("Non connecté findOwnedOrFail = 404", function() use ($accA1) {
        $thrown = false;
        try { ScopeHelper::findOwnedOrFail(FinancialAccount::class, $accA1->id); }
        catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) { $thrown = true; }
        assertTrue($thrown);
    }, $pass, $fail);

    echo "\n=========================================\n";
    echo "RÉSULTATS : {$pass} OK / {$fail} ÉCHECS\n";
    $exit = $fail === 0 ? 0 : 1;
    DB::rollBack();
    echo "(Rollback BD effectué)\n";
    exit($exit);
} catch (\Throwable $e) {
    DB::rollBack();
    echo "\n\n💥 ERREUR FATALE (rollback fait) : " . get_class($e) . " : " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
