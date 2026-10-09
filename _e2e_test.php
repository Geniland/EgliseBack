<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

$base = 'http://127.0.0.1:8000';
$pass = 'Password123!';
$rand = substr(Str::lower(Str::random(8)), 0, 7);
$email = "fidele.{$rand}@example.com";
$firstName = 'Test_' . ucfirst($rand);
$lastName = 'E2E_QR';
$churchCode = 'EG-perc1245';

echo "=== ÉTAPE 1 : Inscription publique ===\n";
$resp = Http::withHeaders(['Accept' => 'application/json', 'X-Church-Context' => '16'])
    ->post("{$base}/api/register", [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'gender' => 'Homme',
        'church_code' => $churchCode,
        'email' => $email,
        'password' => $pass,
    ]);
echo "HTTP {$resp->status()}\n";
$regData = $resp->json();
if (!$resp->successful()) {
    echo "ÉCHEC inscription: ";
    print_r($regData);
    exit(1);
}
$userToken = $regData['token'];
$memberId = $regData['member_id'];
$qrToken = $regData['qr_code']['qr_token'] ?? null;
$qrPayload = $regData['qr_code']['payload'] ?? null;
echo "✅ Inscription OK | user_id={$regData['user']['id']} member_id={$memberId}\n";
echo "   qr_token = {$qrToken}\n";
echo "   payload keys: " . ($qrPayload ? implode(',', array_keys($qrPayload)) : 'null') . "\n";
echo "   qr_data_url length: " . strlen($regData['qr_code']['qr_data_url'] ?? '') . "\n";

echo "\n=== ÉTAPE 2 : Vérifier que le message QR est bien dans chat_messages ===\n";
$chatRow = \App\Models\ChatMessage::where('recipient_id', $regData['user']['id'])
    ->where('contenu', 'like', '%---QR_CODE_IMAGE---%')
    ->latest('id')
    ->first();
if ($chatRow) {
    $hasQrImg = str_contains($chatRow->contenu, '---QR_CODE_IMAGE---') && str_contains($chatRow->contenu, 'data:image');
    $hasQrData = str_contains($chatRow->contenu, '---QR_CODE_DATA---');
    echo "✅ Chat message trouvé (id={$chatRow->id}) sender_id={$chatRow->sender_id} recipient_id={$chatRow->recipient_id}\n";
    echo "   has QR_IMAGE? " . ($hasQrImg ? 'OUI' : 'NON') . "\n";
    echo "   has QR_DATA? " . ($hasQrData ? 'OUI' : 'NON') . "\n";
    echo "   msg length = " . strlen($chatRow->contenu) . " chars\n";
} else {
    echo "❌ AUCUN chat message bienvenue avec marqueurs QR n'a été créé pour recipient_id={$regData['user']['id']}\n";
}

echo "\n=== ÉTAPE 3 : Endpoint GET /me/qr-code (avec le token du nouveau fidèle) ===\n";
$resp = Http::withHeaders(['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $userToken, 'X-Church-Context' => '16'])
    ->get("{$base}/api/me/qr-code", ['size' => 200]);
echo "HTTP {$resp->status()}\n";
$qrData = $resp->json();
if ($resp->successful() && !empty($qrData['data']['qr_data_url'])) {
    echo "✅ Endpoint /me/qr-code OK | qr_token={$qrData['data']['qr_token']} image=" . strlen($qrData['data']['qr_data_url']) . " chars\n";
    echo "   payload type=" . ($qrData['data']['payload']['type'] ?? 'null') . " version=" . ($qrData['data']['payload']['v'] ?? 'null') . "\n";
} else {
    echo "❌ Échec /me/qr-code : " . json_encode($qrData, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== ÉTAPE 4 : Récupérer un token ADMIN/STAFF qui a la permission attendance.scan ===\n";
$staff = \App\Models\User::where('church_id', 16)
    ->whereIn('role_id', [1, 2, 3, 5])
    ->orderBy('role_id', 'asc')
    ->first();
if (!$staff) {
    echo "❌ Aucun staff/admin trouvé dans l'église 16. Skip scan-member auth test with permissions.\n";
    $staffToken = null;
} else {
    $staffToken = $staff->createToken('staff_e2e')->plainTextToken;
    echo "✅ Utilisateur staff trouvé : id={$staff->id} role_id={$staff->role_id} email={$staff->email}\n";
    // Check if permission exists
    $hasPerm = DB::table('permission_role as pr')
        ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
        ->where('p.name', 'attendance.scan')
        ->where('pr.role_id', $staff->role_id)
        ->exists();
    echo "   permission attendance.scan liée au rôle {$staff->role_id} ? " . ($hasPerm ? 'OUI' : 'NON — tentative quand même via scope super_admin/church') . "\n";
}

echo "\n=== ÉTAPE 5 : Endpoint POST /attendance/scan-member (scan QR membre → marquage présence) ===\n";
$sessionId = 10;
if ($staffToken) {
    $payload = [
        'session_id' => $sessionId,
        'member_qr_token' => $qrToken,
    ];
    $resp = Http::withHeaders(['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $staffToken, 'X-Church-Context' => '16'])
        ->post("{$base}/api/attendance/scan-member", $payload);
    echo "HTTP {$resp->status()}\n";
    $scanRes = $resp->json();
    if ($resp->successful()) {
        echo "✅ Scan-member OK (1er appel) | statut=" . ($scanRes['status'] ?? 'null') . " already_registered=" . ($scanRes['already_registered'] ? '1' : '0') . "\n";
        if (!empty($scanRes['member'])) echo "   membre: {$scanRes['member']['first_name']} {$scanRes['member']['last_name']} ({$scanRes['member']['member_code']})\n";
        if (!empty($scanRes['attendance_id'])) echo "   attendance_id = {$scanRes['attendance_id']} scan_method=" . ($scanRes['scan_method'] ?? 'null') . "\n";
    } else {
        echo "⚠️ 1er appel HTTP non 2xx : " . json_encode($scanRes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }

    echo "\n--- Rejouer scan membre pour tester le DOUBLON 409 (already_registered) ---\n";
    $resp2 = Http::withHeaders(['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $staffToken, 'X-Church-Context' => '16'])
        ->post("{$base}/api/attendance/scan-member", $payload);
    $scan2 = $resp2->json();
    echo "HTTP {$resp2->status()} | already_registered = " . ($scan2['already_registered'] ?? 'null') . "\n";
    if (!empty($scan2['already_registered'])) {
        echo "✅ Contrainte unicité session_id+member_id RESPECTÉE (doublon bien bloqué)\n";
    } else {
        echo "⚠️ Non bloqué — réponse: " . json_encode($scan2, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
}

echo "\n=== ÉTAPE 6 : Vérifier dans la base que l'enregistrement présence existe ===\n";
$att = \App\Models\Attendance::where('session_id', $sessionId)
    ->where('member_id', $memberId)
    ->first();
if ($att) {
    echo "✅ Présence trouvée en BD : id={$att->id} status={$att->status} scan_method={$att->scan_method} arrival_time={$att->arrival_time}\n";
    echo "   member_id={$att->member_id} session_id={$att->session_id} created_at={$att->created_at}\n";
} else {
    echo "❌ Aucun enregistrement attendance trouvé pour session_id={$sessionId} + member_id={$memberId}\n";
}

echo "\n=== ÉTAPE 7 : Test GET /api/members/{id}/qr-code (admin qui lit le QR d'un membre) ===\n";
if ($staffToken) {
    $resp = Http::withHeaders(['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $staffToken, 'X-Church-Context' => '16'])
        ->get("{$base}/api/members/{$memberId}/qr-code");
    echo "HTTP {$resp->status()}\n";
    $d = $resp->json();
    if ($resp->successful() && !empty($d['data']['qr_data_url'])) {
        echo "✅ Endpoint admin GET /api/members/{$memberId}/qr-code OK | qr_token={$d['data']['qr_token']} len=" . strlen($d['data']['qr_data_url']) . "\n";
    } else {
        echo "⚠️ Réponse: " . json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
}

echo "\n=== ÉTAPE 8 : Génération QR via JSON payload membre → décodeur (simule scan caméra JS) ===\n";
$payloadMember = \App\Models\Member::find($memberId);
if ($payloadMember) {
    $json = $payloadMember->getQrCodeDataString();
    echo "Payload JSON membre: " . $json . "\n";
    $parsed = json_decode($json, true);
    if (is_array($parsed) && ($parsed['t'] ?? null) === $qrToken && $parsed['type'] === 'member') {
        echo "✅ Payload JSON conforme (type=member, t=token, mid, code, church_id, fn, ln, v=1)\n";
        echo "   champs: " . implode(', ', array_keys($parsed)) . "\n";
    } else {
        echo "❌ Payload JSON NON conforme\n";
    }
}

echo "\n====================================\n";
echo "Tous les tests end-to-end terminés.\n";
