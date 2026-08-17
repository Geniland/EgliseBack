<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Role;
use App\Models\Church;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\Transaction;
use App\Support\ScopeHelper;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

echo "=== TEST E2E COMPLET : ÉGLISES + CRÉATION UTILISATEURS + SWITCH CONTEXTE TOPBAR ===\n\n";
$pass = 0; $fail = 0;

function it(string $label, callable $fn, &$pass, &$fail): void {
    echo "→ {$label} : ";
    try {
        $fn();
        echo "✅ OK\n";
        $pass++;
    } catch (\Throwable $e) {
        echo "❌ ÉCHEC\n   " . get_class($e) . " : " . $e->getMessage() . "\n   " . $e->getFile() . " L:" . $e->getLine() . "\n";
        $fail++;
    }
}
function assertEq($exp, $act, string $msg = '') {
    if ($exp != $act) {
        $e = var_export($exp, true);
        $a = var_export($act, true);
        throw new \RuntimeException("expected={$e} actual={$a} . {$msg}");
    }
}
function assertTrue(bool $b, string $msg = '') { if (!$b) throw new \RuntimeException("TRUE attendu : {$msg}"); }
function assertFalse(bool $b, string $msg = '') { if ($b) throw new \RuntimeException("FALSE attendu : {$msg}"); }

function simulateHeaderChurchContext($ctxIdOrAll, callable $fn) {
    $origServer = $_SERVER;
    $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', ScopeHelper::HEADER_CHURCH_CONTEXT))] = is_string($ctxIdOrAll) ? $ctxIdOrAll : (string)$ctxIdOrAll;
    try {
        // Re-resolve request with new header
        $app = \Illuminate\Container\Container::getInstance();
        $req = $app->make('request');
        $req->headers->set(ScopeHelper::HEADER_CHURCH_CONTEXT, is_string($ctxIdOrAll) ? $ctxIdOrAll : (string)$ctxIdOrAll);
        return $fn();
    } finally {
        $_SERVER = $origServer;
    }
}

DB::beginTransaction();
try {
    $roleAdmin = (int) Role::where('name', 'Administrateur')->firstOrFail()->id;
    $roleResp = (int) Role::where('name', 'Responsable')->firstOrFail()->id;
    $roleCompta = (int) Role::where('name', 'Comptable')->firstOrFail()->id;
    $roleSecr = (int) Role::where('name', 'Secrétaire')->firstOrFail()->id;
    $roleSA = (int) Role::where('name', 'Super Admin')->firstOrFail()->id;

    $makeUser = function (string $n, string $e, int $rid, ?int $pid = null, ?int $cid = null, ?string $church = null) {
        return User::create([
            'name' => $n, 'email' => $e,
            'password' => Hash::make('P@ssw0rd!123'),
            'phone' => '99' . rand(100000, 999999),
            'address' => null,
            'gender' => 'Homme',
            'role_id' => $rid,
            'parent_user_id' => $pid,
            'church_id' => $cid,
            'church_name' => $church,
            'status' => true,
            'email_verified_at' => now(),
        ]);
    };

    $t = time();
    $admin1 = $makeUser("Admin Églises A_{$t}", "e2e_a_{$t}@yafin.xx", $roleAdmin, null, null, 'Église A (par défaut)');
    $admin2 = $makeUser("Admin Église B_{$t}", "e2e_b_{$t}@yafin.xx", $roleAdmin, null, null, 'Autre Église B (isolée)');
    $super = $makeUser("SA_{$t}", "e2e_sa_{$t}@yafin.xx", $roleSA);

    // ================ TEST : Administrateur CRUD ses propres églises =================
    Auth::setUser($admin1->fresh());
    $egliseMere = Church::create([
        'name' => "[E2E] Église Source de Vie MÈRE (Admin1)",
        'city' => 'Lomé',
        'phone' => '90 00 00 00',
        'email' => 'mere@a.xx',
        'status' => true,
        'created_by' => $admin1->id,
    ]);
    $egliseKara = Church::create([
        'name' => "[E2E] Source de Vie KARA (sous-A)",
        'parent_church_id' => $egliseMere->id,
        'city' => 'Kara',
        'status' => true,
        'created_by' => $admin1->id,
    ]);
    $egliseSokode = Church::create([
        'name' => "[E2E] Source de Vie SOKODÉ (sous-A)",
        'parent_church_id' => $egliseMere->id,
        'city' => 'Sokodé',
        'status' => true,
        'created_by' => $admin1->id,
    ]);

    it("Admin1 voit bien ses 3 églises via ScopeHelper::listMyChurches", function() use (&$pass,&$fail,$egliseMere,$egliseKara,$egliseSokode) {
        $list = ScopeHelper::listMyChurches();
        $ids = array_column($list, 'id');
        assertEq(3, count($ids), "3 églises Admin1");
        assertTrue(in_array($egliseMere->id, $ids), "Église mère");
        assertTrue(in_array($egliseKara->id, $ids), "Kara");
        assertTrue(in_array($egliseSokode->id, $ids), "Sokodé");
    }, $pass, $fail);

    it("Admin2 voit 0 église d'Admin1 (isolation églises)", function() use (&$pass,&$fail,$admin2) {
        Auth::setUser($admin2->fresh());
        $list = ScopeHelper::listMyChurches();
        assertEq(0, count($list));
    }, $pass, $fail);

    // ================ TEST : Administrateur CRÉE des utilisateurs dans une église (Staff) =================
    Auth::setUser($admin1->fresh());

    $respKara = $makeUser("Resp Kara_{$t}", "resp_kara_{$t}@yafin.xx", $roleResp, $admin1->id, $egliseKara->id, $egliseKara->name);
    $secrKara = $makeUser("Secrétaire Kara_{$t}", "sec_kara_{$t}@yafin.xx", $roleSecr, $respKara->id, $egliseKara->id, $egliseKara->name);
    $comptaKara = $makeUser("Compta Kara_{$t}", "cmp_kara_{$t}@yafin.xx", $roleCompta, $respKara->id, $egliseKara->id, $egliseKara->name);

    $respSokode = $makeUser("Resp Sokodé_{$t}", "resp_soko_{$t}@yafin.xx", $roleResp, $admin1->id, $egliseSokode->id, $egliseSokode->name);
    $comptaSokode = $makeUser("Compta Sokodé_{$t}", "cmp_soko_{$t}@yafin.xx", $roleCompta, $respSokode->id, $egliseSokode->id, $egliseSokode->name);

    it("Peut-on vérifier les créations utilisateur ? Admin1 a 5 subordonnés directs", function() use (&$pass,&$fail,$admin1,$respKara,$respSokode) {
        $fresh = $admin1->fresh();
        $direct = $fresh->children;
        $ok = $direct->whereIn('id', [$respKara->id, $respSokode->id])->count() === 2;
        assertTrue($ok, "2 subordonnés directs Admin1");
    }, $pass, $fail);

    it("Création des comptes subordonnés : team_user_ids Admin1 contient 6 personnes (lui-même + 5 staff)", function() use (&$pass,&$fail,$admin1) {
        $team = $admin1->fresh()->team_user_ids;
        assertEq(6, count($team));
    }, $pass, $fail);

    // ================ TEST : DONNÉES FINANCIÈRES PAR ÉGLISE + SWITCH CONTEXTE via header =================
    Auth::setUser($admin1->fresh());
    $catInc = FinancialCategory::income()->active()->first()
        ?? FinancialCategory::create(['name'=>'[E2E] Inc','type'=>'income','status'=>true,'created_by'=>$admin1->id,'updated_by'=>$admin1->id]);
    $catDep = FinancialCategory::expense()->active()->first()
        ?? FinancialCategory::create(['name'=>'[E2E] Dep','type'=>'expense','status'=>true,'created_by'=>$admin1->id,'updated_by'=>$admin1->id]);

    $accMere = FinancialAccount::create(['name'=>'[E2E] Caisse MÈRE A','type'=>'cash','initial_balance'=>1000000,'currency'=>'XOF','status'=>true,'created_by'=>$admin1->id,'updated_by'=>$admin1->id]);
    $accKara = FinancialAccount::create(['name'=>'[E2E] Caisse KARA','type'=>'cash','initial_balance'=>300000,'currency'=>'XOF','status'=>true,'created_by'=>$respKara->id,'updated_by'=>$respKara->id]);
    $accSoko = FinancialAccount::create(['name'=>'[E2E] Caisse SOKODÉ','type'=>'bank','initial_balance'=>200000,'currency'=>'XOF','status'=>true,'created_by'=>$respSokode->id,'updated_by'=>$respSokode->id]);
    $accSokoCmp = FinancialAccount::create(['name'=>'[E2E] Petite Caisse SOKODÉ (Compta)','type'=>'cash','initial_balance'=>50000,'currency'=>'XOF','status'=>true,'created_by'=>$comptaSokode->id,'updated_by'=>$comptaSokode->id]);
    $accAdmin2 = FinancialAccount::create(['name'=>'[E2E] Caisse ÉGLISE B (autre admin)','type'=>'cash','initial_balance'=>999999,'currency'=>'XOF','status'=>true,'created_by'=>$admin2->id,'updated_by'=>$admin2->id]);

    Transaction::create(['type'=>'income','direction'=>'in','account_id'=>$accMere->id,'category_id'=>$catInc->id,'amount'=>250000,'description'=>'Don Église Mère A','transaction_date'=>now()->toDateString(),'status'=>'pending','payment_method'=>'cash','created_by'=>$admin1->id,'updated_by'=>$admin1->id]);
    Transaction::create(['type'=>'expense','direction'=>'out','account_id'=>$accKara->id,'category_id'=>$catDep->id,'amount'=>80000,'description'=>'Loyer KARA','transaction_date'=>now()->toDateString(),'status'=>'pending','payment_method'=>'cash','created_by'=>$respKara->id,'updated_by'=>$respKara->id]);
    Transaction::create(['type'=>'expense','direction'=>'out','account_id'=>$accSoko->id,'category_id'=>$catDep->id,'amount'=>50000,'description'=>'Électricité SOKODÉ','transaction_date'=>now()->toDateString(),'status'=>'pending','payment_method'=>'cash','created_by'=>$comptaSokode->id,'updated_by'=>$comptaSokode->id]);
    Transaction::create(['type'=>'income','direction'=>'in','account_id'=>$accAdmin2->id,'category_id'=>$catInc->id,'amount'=>99999,'description'=>'Don ÉGLISE B (autre admin)','transaction_date'=>now()->toDateString(),'status'=>'pending','payment_method'=>'cash','created_by'=>$admin2->id,'updated_by'=>$admin2->id]);

    // Contexte "ALL" (default) : Admin1 voit TOUTES ses données (4 comptes + 3 transactions)
    Auth::setUser($admin1->fresh());
    it("ADMIN1 — contexte ALL — voit 4 comptes (Mère + Kara + Sokodé + Compta S.)", function() use (&$pass,&$fail) {
        $c = FinancialAccount::query()->tap(fn($q)=>ScopeHelper::applyOwnedByScope($q))->count();
        assertEq(4, $c, "4 comptes ALL");
    }, $pass, $fail);
    it("ADMIN1 — contexte ALL — voit 3 transactions", function() use (&$pass,&$fail) {
        $c = Transaction::query()->tap(fn($q)=>ScopeHelper::applyOwnedByScope($q))->count();
        assertEq(3, $c);
    }, $pass, $fail);

    // Switch contexte -> CHURCH KARA via header X-Church-Context
    simulateHeaderChurchContext($egliseKara->id, function() use ($admin1, &$pass, &$fail, $egliseKara, $egliseMere, $egliseSokode, $accMere, $accKara, $accSoko, $catInc) {
        Auth::setUser($admin1->fresh());
        it("ADMIN1 — contexte KARA — getRequestedChurchContext = Kara", function() use (&$pass,&$fail,$egliseKara) {
            $id = ScopeHelper::getRequestedChurchContext();
            assertEq($egliseKara->id, $id);
        }, $pass, $fail);
        it("ADMIN1 — contexte KARA — voit SEULEMENT 1 compte (Caisse KARA : respKara et comptaKara créateurs)", function() use (&$pass,&$fail) {
            $c = FinancialAccount::query()->tap(fn($q)=>ScopeHelper::applyOwnedByScope($q))->count();
            assertEq(1, $c, "1 compte uniquement dans Kara");
        }, $pass, $fail);
        it("ADMIN1 — contexte KARA — voit 1 transaction (Loyer)", function() use (&$pass,&$fail) {
            $c = Transaction::query()->tap(fn($q)=>ScopeHelper::applyOwnedByScope($q))->count();
            assertEq(1, $c, "1 tx loyer kara");
        }, $pass, $fail);
        it("ADMIN1 — contexte KARA — ne VOIT PAS compte Sokodé via findOwnedOrFail (404 attendu)", function() use (&$pass,&$fail,$accSoko) {
            $thrown = false;
            try { ScopeHelper::findOwnedOrFail(FinancialAccount::class, $accSoko->id); }
            catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) { $thrown = true; }
            assertTrue($thrown, "findOwnedOrFail Sokodé KO");
        }, $pass, $fail);
    });

    // Switch contexte -> SOKODÉ
    simulateHeaderChurchContext($egliseSokode->id, function() use ($admin1, &$pass, &$fail, $accKara, $accSoko) {
        Auth::setUser($admin1->fresh());
        it("ADMIN1 — contexte SOKODÉ — 2 comptes (Sokodé Resp + Sokodé Compta)", function() use (&$pass,&$fail) {
            $c = FinancialAccount::query()->tap(fn($q)=>ScopeHelper::applyOwnedByScope($q))->count();
            assertEq(2, $c);
        }, $pass, $fail);
        it("ADMIN1 — contexte SOKODÉ — 1 tx électricité", function() use (&$pass,&$fail) {
            $c = Transaction::query()->tap(fn($q)=>ScopeHelper::applyOwnedByScope($q))->count();
            assertEq(1, $c);
        }, $pass, $fail);
        it("ADMIN1 — contexte SOKODÉ — findOwnedOrFail Caisse KARA = 404", function() use (&$pass,&$fail,$accKara) {
            $th = false;
            try { ScopeHelper::findOwnedOrFail(FinancialAccount::class, $accKara->id); }
            catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) { $th = true; }
            assertTrue($th, "Kara invisible ds contexte Sokode");
        }, $pass, $fail);
    });

    // Retour CONTEXTE ALL (header "all")
    simulateHeaderChurchContext('all', function() use ($admin1, &$pass, &$fail) {
        Auth::setUser($admin1->fresh());
        it("ADMIN1 — header ALL explicit — 4 comptes", function() use (&$pass,&$fail) {
            assertEq(4, FinancialAccount::query()->tap(fn($q)=>ScopeHelper::applyOwnedByScope($q))->count());
        }, $pass, $fail);
        it("ADMIN1 — header ALL explicit — 3 transactions", function() use (&$pass,&$fail) {
            assertEq(3, Transaction::query()->tap(fn($q)=>ScopeHelper::applyOwnedByScope($q))->count());
        }, $pass, $fail);
    });

    // ================ TEST : Isolation Admin2 ne voit rien d'Admin1 ni ses églises =================
    Auth::setUser($admin2->fresh());
    it("ADMIN2 (autre église) — contexte ALL — 1 compte (SEULEMENT le sien)", function() use (&$pass,&$fail) {
        assertEq(1, FinancialAccount::query()->tap(fn($q)=>ScopeHelper::applyOwnedByScope($q))->count());
    }, $pass, $fail);
    it("ADMIN2 — 1 tx (seulement la sienne)", function() use (&$pass,&$fail) {
        assertEq(1, Transaction::query()->tap(fn($q)=>ScopeHelper::applyOwnedByScope($q))->count());
    }, $pass, $fail);
    it("ADMIN2 — liste églises 0", function() use (&$pass,&$fail) {
        assertEq(0, count(ScopeHelper::listMyChurches()));
    }, $pass, $fail);

    // ================ TEST : Super Admin voit TOUT =================
    Auth::setUser($super->fresh());
    it("SA voit >= 5 comptes (> Admin1 4 + Admin2 1)", function() use (&$pass,&$fail) {
        $c = FinancialAccount::query()->tap(fn($q)=>ScopeHelper::applyOwnedByScope($q))->count();
        assertTrue($c >= 5, "SA >=5 comptes count=$c");
    }, $pass, $fail);
    it("SA voit >= 4 tx", function() use (&$pass,&$fail) {
        $c = Transaction::query()->tap(fn($q)=>ScopeHelper::applyOwnedByScope($q))->count();
        assertTrue($c >= 4, "SA tx count=$c");
    }, $pass, $fail);
    it("SA listMyChurches() >= 3 (Admin1 a 3)", function() use (&$pass,&$fail) {
        $c = count(ScopeHelper::listMyChurches());
        assertTrue($c >= 3, "SA églises count=$c");
    }, $pass, $fail);

    // ================ TEST : Staff Resp Kara SEUL ne voit QUE données KARA (pas église mère ni SOKODÉ) =================
    Auth::setUser($respKara->fresh());
    it("Resp Kara (contexte ALL défaut) — 1 compte (seulement Kara: lui-même+secrétaire+compta)", function() use (&$pass,&$fail) {
        $c = FinancialAccount::query()->tap(fn($q)=>ScopeHelper::applyOwnedByScope($q))->count();
        assertEq(1, $c);
    }, $pass, $fail);
    it("Resp Kara — voit 1 tx (Loyer)", function() use (&$pass,&$fail) {
        assertEq(1, Transaction::query()->tap(fn($q)=>ScopeHelper::applyOwnedByScope($q))->count());
    }, $pass, $fail);
    it("Resp Kara — listMyChurches = 1 (seulement l'église KARA où il est rattaché)", function() use (&$pass,&$fail,$egliseKara) {
        $ch = ScopeHelper::listMyChurches();
        assertEq(1, count($ch), "respkara 1");
        assertEq($egliseKara->id, $ch[0]['id']);
    }, $pass, $fail);

    // ================ TEST : Compta Sokode isolé (sous Responsable Sokodé) =================
    Auth::setUser($comptaSokode->fresh());
    it("Compta SOKODÉ — 1 compte", function() use (&$pass,&$fail) {
        assertEq(1, FinancialAccount::query()->tap(fn($q)=>ScopeHelper::applyOwnedByScope($q))->count());
    }, $pass, $fail);
    it("Compta SOKODÉ — 1 tx (électricité lui-même)", function() use (&$pass,&$fail) {
        assertEq(1, Transaction::query()->tap(fn($q)=>ScopeHelper::applyOwnedByScope($q))->count());
    }, $pass, $fail);

    echo "\n=========================================\n";
    echo "RÉSULTATS TESTS ÉGLISE COMPLET : {$pass} OK / {$fail} ÉCHECS\n";
    $exit = $fail === 0 ? 0 : 1;
    DB::rollBack();
    echo "(Rollback BD effectué — aucune donnée persistée)\n";
    exit($exit);
} catch (\Throwable $e) {
    DB::rollBack();
    echo "\n💥 ERREUR FATALE (rollback) : " . get_class($e) . " : " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
