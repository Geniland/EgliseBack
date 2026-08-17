<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AttendanceSessionController;
use App\Http\Controllers\Api\AbsenceReasonController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\FinancialAccountController;
use App\Http\Controllers\Api\FinancialCategoryController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\TransactionAttachmentController;
use App\Http\Controllers\Api\ChurchController;
use App\Http\Controllers\Api\ChurchStaffController;



Route::get('/user', function (Request $request) {
    return $request->user()->load(['role', 'fonction']);
})->middleware('auth:sanctum');




Route::post('/register',[AuthController::class,'register']);
Route::post('/login',[AuthController::class,'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
});

// Dashboard
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('/dashboard/ministry-distribution', [DashboardController::class, 'ministryDistribution']);
    Route::get('/dashboard/presence-donation-chart', [DashboardController::class, 'presenceDonationChart']);
    Route::get('/dashboard/financial-summary', [DashboardController::class, 'financialSummary']);
    Route::get('/dashboard/upcoming-events', [DashboardController::class, 'upcomingEvents']);
    Route::get('/dashboard/recent-activities', [DashboardController::class, 'recentActivities']);
    Route::get('/dashboard/devices-status', [DashboardController::class, 'devicesStatus']);
});

// Super Admin - Gestion des administrateurs d'églises
Route::middleware(['auth:sanctum', 'super_admin'])->group(function () {
    Route::get('/super-admin/users', [\App\Http\Controllers\Api\SuperAdminController::class, 'index']);
    Route::get('/super-admin/users/{id}', [\App\Http\Controllers\Api\SuperAdminController::class, 'show']);
    Route::post('/super-admin/users', [\App\Http\Controllers\Api\SuperAdminController::class, 'store']);
    Route::put('/super-admin/users/{id}', [\App\Http\Controllers\Api\SuperAdminController::class, 'update']);
    Route::patch('/super-admin/users/{id}/toggle-status', [\App\Http\Controllers\Api\SuperAdminController::class, 'toggleStatus']);
    Route::delete('/super-admin/users/{id}', [\App\Http\Controllers\Api\SuperAdminController::class, 'destroy']);
    Route::get('/super-admin/roles-and-fonctions', [\App\Http\Controllers\Api\SuperAdminController::class, 'roles']);
});







Route::middleware([
    'auth:sanctum',
    'permission:users.view'
])
->group(function(){


    Route::get('/users',
        [UserController::class,'index']
    );


    Route::get('/users/{user}',
        [UserController::class,'show']
    );


});



Route::middleware([
    'auth:sanctum',
    'permission:users.create'
])
->post('/users',
    [UserController::class,'store']
);



Route::middleware([
    'auth:sanctum',
    'permission:users.update'
])
->put('/users/{user}',
    [UserController::class,'update']
);



Route::middleware([
    'auth:sanctum',
    'permission:users.delete'
])
->delete('/users/{user}',
    [UserController::class,'destroy']
);




Route::middleware([
    'auth:sanctum',
    'permission:members.view'
])->get('/members', [MemberController::class, 'index']);

Route::middleware([
    'auth:sanctum',
    'permission:members.create'
])->post('/members', [MemberController::class, 'store']);

Route::middleware([
    'auth:sanctum',
    'permission:members.view'
])->get('/members/{member}', [MemberController::class, 'show']);

Route::middleware([
    'auth:sanctum',
    'permission:members.update'
])->put('/members/{member}', [MemberController::class, 'update']);

Route::middleware([
    'auth:sanctum',
    'permission:members.delete'
])->delete('/members/{member}', [MemberController::class, 'destroy']);


// Référentiels (accessibles à tous les utilisateurs connectés)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/referentiels/ministries', function () {
        $q = App\Models\Ministry::where('status', true);
        App\Support\ScopeHelper::applyOwnedByScope($q);
        return response()->json($q->orderBy('name')->get(['id', 'name', 'description']));
    });

    Route::get('/referentiels/families', function () {
        $q = App\Models\Family::where('status', true);
        App\Support\ScopeHelper::applyOwnedByScope($q);
        return response()->json($q->orderBy('family_name')->get(['id', 'family_code', 'family_name', 'phone', 'address']));
    });

    Route::post('/referentiels/families', function (Illuminate\Http\Request $request) {
        $validated = $request->validate([
            'family_name' => 'required|string|max:150',
            'phone' => 'nullable|string|max:30',
            'address' => 'nullable|string|max:255',
        ]);
        $year = date('Y');
        $q = App\Models\Family::whereYear('created_at', $year);
        App\Support\ScopeHelper::applyOwnedByScope($q);
        $last = $q->orderBy('id', 'desc')->first();
        if ($last && preg_match('/-(\d{6})$/', $last->family_code, $m)) {
            $n = str_pad(((int)$m[1]) + 1, 6, '0', STR_PAD_LEFT);
        } else {
            $n = '000001';
        }
        $validated['family_code'] = 'FAM-' . $year . $n;
        $validated['status'] = true;
        $validated['created_by'] = auth()->id();
        $validated['updated_by'] = auth()->id();
        $fam = App\Models\Family::create($validated);
        return response()->json(['message' => 'Famille créée', 'family' => $fam], 201);
    });

    Route::get('/referentiels/member-static', function () {
        return response()->json([
            'genders' => ['Homme', 'Femme'],
            'marital_statuses' => ['Célibataire', 'Marié', 'Divorcé', 'Veuf'],
            'member_types' => ['Visiteur', 'Catéchumène', 'Membre', 'Responsable', 'Pasteur'],
        ]);
    });
});


// === MODULE PRÉSENCES - QR Scan public (sans authentification) ===
Route::post('/attendance/scan-public', [AttendanceController::class, 'scan']);

// === MODULE PRÉSENCES - Sessions de présence ===
Route::middleware([
    'auth:sanctum',
    'permission:attendance.view'
])->group(function () {
    Route::get('/attendance/sessions', [AttendanceSessionController::class, 'index']);
    Route::get('/attendance/sessions/{attendanceSession}', [AttendanceSessionController::class, 'show']);
    Route::get('/attendance/sessions-stats', [AttendanceSessionController::class, 'stats']);
});

Route::middleware([
    'auth:sanctum',
    'permission:attendance.create'
])->group(function () {
    Route::post('/attendance/sessions', [AttendanceSessionController::class, 'store']);
    Route::post('/attendance/sessions/{attendanceSession}/generate-qr', [AttendanceSessionController::class, 'generateQr']);
    Route::post('/attendance/sessions/{attendanceSession}/mark-all-absent', [AttendanceSessionController::class, 'markAllAbsent']);
});

Route::middleware([
    'auth:sanctum',
    'permission:attendance.update'
])->group(function () {
    Route::put('/attendance/sessions/{attendanceSession}', [AttendanceSessionController::class, 'update']);
    Route::post('/attendance/sessions/{attendanceSession}/invalidate-qr', [AttendanceSessionController::class, 'invalidateQr']);
});

Route::middleware([
    'auth:sanctum',
    'permission:attendance.delete'
])->delete('/attendance/sessions/{attendanceSession}', [AttendanceSessionController::class, 'destroy']);

// === MODULE PRÉSENCES - Enregistrements attendance ===
Route::middleware([
    'auth:sanctum',
    'permission:attendance.view'
])->group(function () {
    Route::get('/attendance', [AttendanceController::class, 'index']);
    Route::get('/attendance/{attendance}', [AttendanceController::class, 'show']);
    Route::get('/attendance/members/{memberId}/history', [AttendanceController::class, 'memberHistory']);
});

Route::middleware([
    'auth:sanctum',
    'permission:attendance.create'
])->group(function () {
    Route::post('/attendance', [AttendanceController::class, 'store']);
    Route::post('/attendance/bulk', [AttendanceController::class, 'bulkStore']);
});

Route::middleware([
    'auth:sanctum',
    'permission:attendance.update'
])->put('/attendance/{attendance}', [AttendanceController::class, 'update']);

Route::middleware([
    'auth:sanctum',
    'permission:attendance.delete'
])->delete('/attendance/{attendance}', [AttendanceController::class, 'destroy']);

Route::middleware([
    'auth:sanctum',
    'permission:attendance.scan'
])->post('/attendance/scan', [AttendanceController::class, 'scan']);

// === MODULE PRÉSENCES - Motifs d'absence ===
Route::middleware([
    'auth:sanctum',
    'permission:attendance.view'
])->get('/attendance/reasons', [AbsenceReasonController::class, 'index']);

Route::middleware([
    'auth:sanctum',
    'permission:attendance.create'
])->post('/attendance/reasons', [AbsenceReasonController::class, 'store']);

Route::middleware([
    'auth:sanctum',
    'permission:attendance.update'
])->put('/attendance/reasons/{absenceReason}', [AbsenceReasonController::class, 'update']);

Route::middleware([
    'auth:sanctum',
    'permission:attendance.delete'
])->delete('/attendance/reasons/{absenceReason}', [AbsenceReasonController::class, 'destroy']);



/*
|--------------------------------------------------------------------------
| MODULE FINANCE
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'permission:finance.view'])->group(function () {

    Route::get('finance/dashboard', [TransactionController::class, 'dashboard']);
    Route::get('finance/statistics', [TransactionController::class, 'statistics']);

    Route::get('financial-accounts/all-active', [FinancialAccountController::class, 'allActive']);
    Route::get('financial-accounts', [FinancialAccountController::class, 'index']);
    Route::get('financial-accounts/{id}', [FinancialAccountController::class, 'show']);
    Route::get('financial-accounts/{id}/balance', [FinancialAccountController::class, 'balance']);
    Route::get('financial-accounts/{id}/transactions', [FinancialAccountController::class, 'transactions']);

    Route::get('financial-categories/all-active', [FinancialCategoryController::class, 'allActive']);
    Route::get('financial-categories', [FinancialCategoryController::class, 'index']);
    Route::get('financial-categories/{id}', [FinancialCategoryController::class, 'show']);

    Route::get('transactions', [TransactionController::class, 'index']);
    Route::get('transactions/{id}', [TransactionController::class, 'show']);

    Route::get('transactions/{id}/attachments', [TransactionAttachmentController::class, 'index']);
    Route::get('transaction-attachments/{id}', [TransactionAttachmentController::class, 'show']);
    Route::get('transaction-attachments/{id}/download', [TransactionAttachmentController::class, 'download']);
});

Route::middleware(['auth:sanctum', 'permission:finance.manage_accounts'])->group(function () {
    Route::post('financial-accounts', [FinancialAccountController::class, 'store']);
    Route::match(['PUT', 'PATCH'], 'financial-accounts/{id}', [FinancialAccountController::class, 'update']);
    Route::patch('financial-accounts/{id}/toggle-status', [FinancialAccountController::class, 'toggleStatus']);
    Route::delete('financial-accounts/{id}', [FinancialAccountController::class, 'destroy']);
});

Route::middleware(['auth:sanctum', 'permission:finance.manage_categories'])->group(function () {
    Route::post('financial-categories', [FinancialCategoryController::class, 'store']);
    Route::match(['PUT', 'PATCH'], 'financial-categories/{id}', [FinancialCategoryController::class, 'update']);
    Route::patch('financial-categories/{id}/toggle-status', [FinancialCategoryController::class, 'toggleStatus']);
    Route::delete('financial-categories/{id}', [FinancialCategoryController::class, 'destroy']);
});

Route::middleware(['auth:sanctum', 'permission:finance.create'])->group(function () {
    Route::post('transactions', [TransactionController::class, 'store']);
});

Route::middleware(['auth:sanctum', 'permission:finance.update'])->group(function () {
    Route::match(['PUT', 'PATCH'], 'transactions/{id}', [TransactionController::class, 'update']);
    Route::post('transactions/{id}/reverse', [TransactionController::class, 'reverse']);
});

Route::middleware(['auth:sanctum', 'permission:finance.delete'])->group(function () {
    Route::delete('transactions/{id}', [TransactionController::class, 'destroy']);
});

Route::middleware(['auth:sanctum', 'permission:finance.approve'])->group(function () {
    Route::post('transactions/{id}/approve', [TransactionController::class, 'approve']);
});

Route::middleware(['auth:sanctum', 'permission:finance.reject'])->group(function () {
    Route::post('transactions/{id}/reject', [TransactionController::class, 'reject']);
});

Route::middleware(['auth:sanctum', 'permission:finance.transfer'])->group(function () {
    Route::post('financial-transfers', [TransactionController::class, 'transfer']);
});

Route::middleware(['auth:sanctum', 'permission:finance.attachments'])->group(function () {
    Route::post('transaction-attachments', [TransactionAttachmentController::class, 'store']);
    Route::delete('transaction-attachments/{id}', [TransactionAttachmentController::class, 'destroy']);
});


/*
|--------------------------------------------------------------------------
| RÉFÉRENTIELS FINANCIERS
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum'])->group(function () {

    Route::get('/referentiels/financial-categories', function () {
        $qIncome = \App\Models\FinancialCategory::active()->income();
        \App\Support\ScopeHelper::applyOwnedByScope($qIncome);
        $incomes = $qIncome->orderBy('name')->get(['id', 'name', 'type', 'parent_id', 'description']);

        $qExpense = \App\Models\FinancialCategory::active()->expense();
        \App\Support\ScopeHelper::applyOwnedByScope($qExpense);
        $expenses = $qExpense->orderBy('name')->get(['id', 'name', 'type', 'parent_id', 'description']);

        return response()->json([
            'types' => \App\Models\FinancialCategory::types(),
            'income' => $incomes,
            'expense' => $expenses,
        ]);
    });

    Route::get('/referentiels/transaction-meta', function () {
        return response()->json([
            'transaction_types' => \App\Models\Transaction::types(),
            'transaction_statuses' => \App\Models\Transaction::statuses(),
            'directions' => \App\Models\Transaction::directions(),
            'payment_methods' => \App\Models\Transaction::paymentMethods(),
            'account_types' => \App\Models\FinancialAccount::types(),
            'currencies' => \App\Models\FinancialAccount::currencies(),
            'attachments' => [
                'max_size_kb' => \App\Models\TransactionAttachment::maxFileSizeKb(),
                'extensions' => \App\Models\TransactionAttachment::allowedExtensions(),
                'mime_types' => \App\Models\TransactionAttachment::allowedMimeTypes(),
            ],
        ]);
    });

    Route::get('/referentiels/financial-accounts', function () {
        $q = \App\Models\FinancialAccount::active();
        \App\Support\ScopeHelper::applyOwnedByScope($q);
        $accounts = $q->orderBy('name')->get(['id', 'name', 'type', 'currency']);
        return response()->json($accounts->map(fn($a) => [
            'id' => $a->id,
            'name' => $a->name,
            'type' => $a->type,
            'type_label' => $a->type_label,
            'currency' => $a->currency,
            'current_balance' => (float)$a->current_balance,
            'formatted_current_balance' => $a->formatted_current_balance,
        ]));
    });
});


// === MODULE ÉGLISES (CRUD) + GESTION DU PERSONNEL PAR ÉGLISE ===
Route::middleware(['auth:sanctum'])->group(function () {

    Route::get('me/churches', [ChurchController::class, 'myChurches'])->name('my.churches');
    Route::get('churches/select-options', [ChurchController::class, 'myChurches']);

    Route::middleware(['church_manager'])->group(function () {

        Route::apiResource('churches', ChurchController::class)->except(['create', 'edit']);
        Route::patch('churches/{id}/toggle-status', [ChurchController::class, 'toggleStatus']);

        Route::get('churches/{id}/staff', [ChurchStaffController::class, 'index']);
        Route::post('churches/{id}/staff', [ChurchStaffController::class, 'store']);
        Route::get('church-staff-roles', [ChurchStaffController::class, 'roles']);
        Route::match(['PUT', 'PATCH'], 'churches/{id}/staff/{userId}', [ChurchStaffController::class, 'update']);
        Route::delete('churches/{id}/staff/{userId}', [ChurchStaffController::class, 'destroy']);
        Route::patch('churches/{id}/staff/{userId}/toggle-status', [ChurchStaffController::class, 'toggleStatus']);
    });
});


// === MODULE ÉVÉNEMENTS ===
Route::middleware([
    'auth:sanctum',
    'permission:events.view'
])->group(function () {
    Route::get('/events', [EventController::class, 'index']);
    Route::get('/events/{event}', [EventController::class, 'show']);
    Route::get('/events-stats', [EventController::class, 'stats']);
    Route::get('/events-calendar', [EventController::class, 'calendar']);
});
Route::middleware([
    'auth:sanctum',
    'permission:events.create'
])->group(function () {
    Route::post('/events', [EventController::class, 'store']);
});

Route::middleware([
    'auth:sanctum',
    'permission:events.update'
])->group(function () {
    Route::put('/events/{event}', [EventController::class, 'update']);
    Route::patch('/events/{event}/publish', [EventController::class, 'publish']);
    Route::patch('/events/{event}/cancel', [EventController::class, 'cancel']);
    Route::patch('/events/{event}/complete', [EventController::class, 'complete']);
    Route::patch('/events/{event}/toggle-featured', [EventController::class, 'toggleFeatured']);
});

Route::middleware([
    'auth:sanctum',
    'permission:events.delete'
])->delete('/events/{event}', [EventController::class, 'destroy']);

// Référentiel - types d'événements
Route::middleware('auth:sanctum')->get('/referentiels/event-types', function () {
    return response()->json([
        'types' => \App\Models\Event::types(),
        'statuses' => \App\Models\Event::statuses(),
    ]);
});


// Route::post(
//     '/members',
//     [MemberController::class,'store']
// );

// Route::post('/test', function () {
//     return response()->json([
//         'message' => 'La route fonctionne'
//     ]);
// });


// Route::get('/test-json', function () {
//     return response()->json(['message' => 'Hello API!']);
// });