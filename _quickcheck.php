<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo 'Churches count: ' . \App\Models\Church::count() . PHP_EOL;
\App\Models\Church::select('id','code','name')
    ->limit(5)
    ->get()
    ->each(function($c) {
        echo "  id={$c->id} code={$c->code} name={$c->name}" . PHP_EOL;
    });
echo 'Users count: ' . \App\Models\User::count() . PHP_EOL;
echo 'Members with user_id count: ' . \App\Models\Member::whereNotNull('user_id')->count() . PHP_EOL;
echo 'Members qr_token non-null: ' . \App\Models\Member::whereNotNull('qr_token')->count() . PHP_EOL;
echo 'Attendance sessions count: ' . \App\Models\AttendanceSession::count() . PHP_EOL;
\App\Models\AttendanceSession::select('id','title','session_date','status')
    ->orderBy('id','desc')
    ->limit(3)
    ->get()
    ->each(function($s) {
        echo "  session id={$s->id} title={$s->title} date={$s->session_date} status=" . ($s->status ? '1' : '0') . PHP_EOL;
    });
