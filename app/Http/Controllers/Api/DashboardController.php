<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Member;
use App\Models\Ministry;
use App\Models\User;
use App\Models\Event;
use App\Models\Transaction;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\Attendance;
use App\Models\AttendanceSession;
use App\Models\LiveStream;
use App\Models\AssistantConversation;
use App\Models\Church;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Support\ScopeHelper;

class DashboardController extends Controller
{
    /**
     * Statistiques globales du tableau de bord (KPI cards).
     * 100% calculé à partir de la base de données selon l'utilisateur connecté.
     */
    public function stats(Request $request)
    {
        $now = Carbon::now();
        $teamIds = ScopeHelper::isSuperAdmin() ? null : ScopeHelper::getTeamUserIds();
        $isBlocked = $teamIds && (count($teamIds) === 1 && (int) $teamIds[0] === 0);

        // 1. TOTAL MEMBRES (avec scoping de rôle et église)
        $membersQuery = Member::query();
        ScopeHelper::applyMemberScope($membersQuery);

        $totalMembers = (clone $membersQuery)->count();
        $newMembersThisMonth = (clone $membersQuery)
            ->whereMonth('created_at', $now->month)
            ->whereYear('created_at', $now->year)
            ->count();

        $lastMonthMembers = (clone $membersQuery)
            ->whereMonth('created_at', $now->copy()->subMonth()->month)
            ->whereYear('created_at', $now->copy()->subMonth()->year)
            ->count();

        $membersGrowth = $lastMonthMembers > 0
            ? round((($newMembersThisMonth - $lastMonthMembers) / $lastMonthMembers) * 100, 1)
            : ($newMembersThisMonth > 0 ? 100 : 0);

        // Sparkline membres : 12 derniers mois réels
        $memberSparkline = [];
        for ($i = 11; $i >= 0; $i--) {
            $m = $now->copy()->subMonths($i);
            $memberSparkline[] = (clone $membersQuery)
                ->whereYear('created_at', $m->year)
                ->whereMonth('created_at', $m->month)
                ->count();
        }

        // 2. PRÉSENCES DU MOIS
        $attendancesQuery = Attendance::where('attendances.status', 'present');
        if (!ScopeHelper::isSuperAdmin()) {
            if ($isBlocked) {
                $attendancesQuery->whereRaw('0 = 1');
            } else {
                $attendancesQuery->where(function ($q) use ($teamIds) {
                    $q->whereHas('session', fn($sq) => ScopeHelper::applyOwnedByScope($sq))
                      ->orWhereHas('member', fn($mq) => ScopeHelper::applyMemberScope($mq));
                });
            }
        }

        $presencesThisMonth = (clone $attendancesQuery)
            ->whereMonth('attendances.created_at', $now->month)
            ->whereYear('attendances.created_at', $now->year)
            ->count();

        $presencesLastMonth = (clone $attendancesQuery)
            ->whereMonth('attendances.created_at', $now->copy()->subMonth()->month)
            ->whereYear('attendances.created_at', $now->copy()->subMonth()->year)
            ->count();

        $presencesGrowth = $presencesLastMonth > 0
            ? round((($presencesThisMonth - $presencesLastMonth) / $presencesLastMonth) * 100, 1)
            : ($presencesThisMonth > 0 ? 100 : 0);

        // Sessions ce mois-ci
        $sessionsQuery = AttendanceSession::query();
        ScopeHelper::applyOwnedByScope($sessionsQuery);
        $sessionsThisMonthCount = (clone $sessionsQuery)
            ->whereMonth('session_date', $now->month)
            ->whereYear('session_date', $now->year)
            ->count();

        $expectedAttendance = $totalMembers * max(1, $sessionsThisMonthCount);
        $attendanceRate = ($expectedAttendance > 0 && $presencesThisMonth > 0)
            ? round(min(100, ($presencesThisMonth / $expectedAttendance) * 100), 1)
            : 0;

        // Sparkline présences : 12 derniers mois réels
        $presenceSparkline = [];
        for ($i = 11; $i >= 0; $i--) {
            $m = $now->copy()->subMonths($i);
            $presenceSparkline[] = (clone $attendancesQuery)
                ->whereYear('attendances.created_at', $m->year)
                ->whereMonth('attendances.created_at', $m->month)
                ->count();
        }

        // 3. RECETTES / DONS DU MOIS
        $donationsQuery = Transaction::approved()->income();
        if (!ScopeHelper::isSuperAdmin()) {
            if ($isBlocked) {
                $donationsQuery->whereRaw('0 = 1');
            } elseif ($teamIds !== null) {
                $donationsQuery->whereIn('created_by', $teamIds);
            }
        }

        $donationsThisMonth = (float) (clone $donationsQuery)
            ->whereMonth('transaction_date', $now->month)
            ->whereYear('transaction_date', $now->year)
            ->sum('amount');

        $donationsLastMonth = (float) (clone $donationsQuery)
            ->whereMonth('transaction_date', $now->copy()->subMonth()->month)
            ->whereYear('transaction_date', $now->copy()->subMonth()->year)
            ->sum('amount');

        $donationsGrowth = $donationsLastMonth > 0
            ? round((($donationsThisMonth - $donationsLastMonth) / $donationsLastMonth) * 100, 1)
            : ($donationsThisMonth > 0 ? 100 : 0);

        // Devise du compte principal de l'église
        $accountsQuery = FinancialAccount::active();
        ScopeHelper::applyOwnedByScope($accountsQuery);
        $currency = optional($accountsQuery->first())->currency ?: 'FCFA';

        // Sparkline dons : 12 derniers mois réels
        $donationsSparkline = [];
        for ($i = 11; $i >= 0; $i--) {
            $m = $now->copy()->subMonths($i);
            $donationsSparkline[] = (float) (clone $donationsQuery)
                ->whereYear('transaction_date', $m->year)
                ->whereMonth('transaction_date', $m->month)
                ->sum('amount');
        }

        // 4. ÉVÉNEMENTS (Actifs / À venir ou ce mois-ci)
        $eventsQuery = Event::query();
        ScopeHelper::applyOwnedByScope($eventsQuery);

        $totalUpcomingEvents = (clone $eventsQuery)
            ->whereDate('event_date', '>=', $now->toDateString())
            ->count();

        $eventsThisMonth = (clone $eventsQuery)
            ->whereMonth('event_date', $now->month)
            ->whereYear('event_date', $now->year)
            ->count();

        $eventsLastMonth = (clone $eventsQuery)
            ->whereMonth('event_date', $now->copy()->subMonth()->month)
            ->whereYear('event_date', $now->copy()->subMonth()->year)
            ->count();

        $eventsGrowth = $eventsLastMonth > 0
            ? round((($eventsThisMonth - $eventsLastMonth) / $eventsLastMonth) * 100, 1)
            : ($eventsThisMonth > 0 ? 100 : 0);

        $eventsSparkline = [];
        for ($i = 11; $i >= 0; $i--) {
            $m = $now->copy()->subMonths($i);
            $eventsSparkline[] = (clone $eventsQuery)
                ->whereYear('event_date', $m->year)
                ->whereMonth('event_date', $m->month)
                ->count();
        }

        // 5. TRANSACTIONS / DEMANDES EN ATTENTE
        $pendingQuery = Transaction::pending();
        if (!ScopeHelper::isSuperAdmin()) {
            if ($isBlocked) {
                $pendingQuery->whereRaw('0 = 1');
            } elseif ($teamIds !== null) {
                $pendingQuery->whereIn('created_by', $teamIds);
            }
        }
        $pendingRequests = (clone $pendingQuery)->count();

        $pendingSparkline = [];
        for ($i = 11; $i >= 0; $i--) {
            $m = $now->copy()->subMonths($i);
            $pendingSparkline[] = (clone $pendingQuery)
                ->whereYear('transaction_date', $m->year)
                ->whereMonth('transaction_date', $m->month)
                ->count();
        }

        $user = $request->user();

        return response()->json(array_filter([
            'members_total' => $user->hasPermission('members.view') ? [
                'value' => $totalMembers,
                'growth' => $membersGrowth,
                'new_this_month' => $newMembersThisMonth,
                'sparkline' => $memberSparkline,
            ] : null,
            'presences_month' => $user->hasPermission('attendance.view') ? [
                'value' => $presencesThisMonth,
                'growth' => $presencesGrowth,
                'attendance_rate' => $attendanceRate,
                'sparkline' => $presenceSparkline,
            ] : null,
            'donations' => $user->hasPermission('finance.view') ? [
                'value' => $donationsThisMonth,
                'currency' => $currency,
                'growth' => $donationsGrowth,
                'sparkline' => $donationsSparkline,
            ] : null,
            'events' => $user->hasPermission('events.view') ? [
                'value' => $totalUpcomingEvents,
                'growth' => $eventsGrowth,
                'sparkline' => $eventsSparkline,
            ] : null,
            'pending_requests' => $user->hasPermission('finance.view') ? [
                'value' => $pendingRequests,
                'growth' => 0,
                'sparkline' => $pendingSparkline,
            ] : null,
        ], fn ($value) => $value !== null));
    }

    /**
     * Graphique comparatif Présences & Dons.
     * 100% calculé à partir de la base de données.
     */
    public function presenceDonationChart(Request $request)
    {
        $period = $request->input('period', 'monthly');
        $teamIds = ScopeHelper::isSuperAdmin() ? null : ScopeHelper::getTeamUserIds();
        $isBlocked = $teamIds && (count($teamIds) === 1 && (int) $teamIds[0] === 0);

        $labels = [];
        $presences = [];
        $donations = [];

        if ($period === 'weekly') {
            // 7 derniers jours réels
            for ($i = 6; $i >= 0; $i--) {
                $date = Carbon::now()->subDays($i);
                $dayStr = $date->toDateString();
                $labels[] = $date->locale('fr')->isoFormat('ddd D');

                $attQ = Attendance::where('attendances.status', 'present')
                    ->whereDate('attendances.created_at', $dayStr);
                if (!ScopeHelper::isSuperAdmin()) {
                    if ($isBlocked) {
                        $attQ->whereRaw('0 = 1');
                    } else {
                        $attQ->where(function ($q) use ($teamIds) {
                            $q->whereHas('session', fn($sq) => ScopeHelper::applyOwnedByScope($sq))
                              ->orWhereHas('member', fn($mq) => ScopeHelper::applyMemberScope($mq));
                        });
                    }
                }
                $presences[] = $attQ->count();

                $donQ = Transaction::approved()->income()->whereDate('transaction_date', $dayStr);
                if (!ScopeHelper::isSuperAdmin()) {
                    if ($isBlocked) {
                        $donQ->whereRaw('0 = 1');
                    } elseif ($teamIds !== null) {
                        $donQ->whereIn('created_by', $teamIds);
                    }
                }
                $donations[] = (float) $donQ->sum('amount');
            }
        } else {
            // 6 derniers mois réels
            for ($i = 5; $i >= 0; $i--) {
                $monthDate = Carbon::now()->subMonths($i);
                $labels[] = ucfirst($monthDate->locale('fr')->isoFormat('MMM YYYY'));

                $attQ = Attendance::where('attendances.status', 'present')
                    ->whereYear('attendances.created_at', $monthDate->year)
                    ->whereMonth('attendances.created_at', $monthDate->month);
                if (!ScopeHelper::isSuperAdmin()) {
                    if ($isBlocked) {
                        $attQ->whereRaw('0 = 1');
                    } else {
                        $attQ->where(function ($q) use ($teamIds) {
                            $q->whereHas('session', fn($sq) => ScopeHelper::applyOwnedByScope($sq))
                              ->orWhereHas('member', fn($mq) => ScopeHelper::applyMemberScope($mq));
                        });
                    }
                }
                $presences[] = $attQ->count();

                $donQ = Transaction::approved()->income()
                    ->whereYear('transaction_date', $monthDate->year)
                    ->whereMonth('transaction_date', $monthDate->month);
                if (!ScopeHelper::isSuperAdmin()) {
                    if ($isBlocked) {
                        $donQ->whereRaw('0 = 1');
                    } elseif ($teamIds !== null) {
                        $donQ->whereIn('created_by', $teamIds);
                    }
                }
                $donations[] = (float) $donQ->sum('amount');
            }
        }

        $payload = [
            'period' => $period,
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Présences',
                    'data' => $presences,
                    'borderColor' => '#3B82F6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                ],
                [
                    'label' => 'Dons (FCFA)',
                    'data' => $donations,
                    'borderColor' => '#10B981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'yAxisID' => 'y1',
                ],
            ],
        ];
        if (!$request->user()->hasPermission('attendance.view')) {
            $payload['datasets'] = array_values(array_filter($payload['datasets'], fn ($dataset) => $dataset['borderColor'] !== '#3B82F6'));
        }
        if (!$request->user()->hasPermission('finance.view')) {
            $payload['datasets'] = array_values(array_filter($payload['datasets'], fn ($dataset) => $dataset['borderColor'] !== '#10B981'));
        }

        return response()->json($payload);
    }

    /**
     * Répartition des membres par ministère.
     * 100% calculé à partir des tables ministries et member_ministry.
     */
    public function ministryDistribution(Request $request)
    {
        $membersQuery = Member::query();
        ScopeHelper::applyMemberScope($membersQuery);
        $totalMembers = (clone $membersQuery)->count();

        $ministryBase = Ministry::where('status', true);
        ScopeHelper::applyOwnedByScope($ministryBase);

        $ministries = $ministryBase
            ->withCount(['members' => function ($q) {
                ScopeHelper::applyMemberScope($q);
            }])
            ->get()
            ->map(function ($ministry) use ($totalMembers) {
                $count = (int) $ministry->members_count;
                $percentage = $totalMembers > 0 ? round(($count / $totalMembers) * 100, 1) : 0;
                return [
                    'id' => $ministry->id,
                    'name' => $ministry->name,
                    'count' => $count,
                    'percentage' => $percentage,
                ];
            });

        $assignedCount = $ministries->sum('count');
        $othersCount = max(0, $totalMembers - $assignedCount);
        $othersPercentage = $totalMembers > 0 ? round(($othersCount / $totalMembers) * 100, 1) : 0;

        if ($othersCount > 0) {
            $ministries->push([
                'id' => null,
                'name' => 'Sans ministère',
                'count' => $othersCount,
                'percentage' => $othersPercentage,
            ]);
        }

        $colors = ['#4F46E5', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#06B6D4', '#6B7280'];
        $ministries = $ministries->map(function ($item, $index) use ($colors) {
            $arr = is_array($item) ? $item : $item->toArray();
            $arr['color'] = $colors[$index % count($colors)];
            return $arr;
        });

        return response()->json([
            'total' => $totalMembers,
            'ministries' => $ministries->values(),
        ]);
    }

    /**
     * Bilan financier mensuel réel (recettes, dépenses, solde net et ventilation par catégorie).
     */
    public function financialSummary(Request $request)
    {
        $now = Carbon::now();
        $teamIds = ScopeHelper::isSuperAdmin() ? null : ScopeHelper::getTeamUserIds();
        $isBlocked = $teamIds && (count($teamIds) === 1 && (int) $teamIds[0] === 0);

        $scopeTeam = function ($q) use ($teamIds, $isBlocked) {
            if ($isBlocked) {
                $q->whereRaw('0 = 1');
            } elseif ($teamIds !== null) {
                $q->whereIn('created_by', $teamIds);
            }
        };

        // Devise
        $accountsQuery = FinancialAccount::active();
        ScopeHelper::applyOwnedByScope($accountsQuery);
        $currency = optional($accountsQuery->first())->currency ?: 'FCFA';

        // Recettes ce mois-ci
        $incomeQuery = Transaction::approved()->income()
            ->tap($scopeTeam)
            ->whereMonth('transaction_date', $now->month)
            ->whereYear('transaction_date', $now->year);
        $totalReceipts = (float) (clone $incomeQuery)->sum('amount');

        // Recettes mois précédent
        $lastMonthIncome = (float) Transaction::approved()->income()
            ->tap($scopeTeam)
            ->whereMonth('transaction_date', $now->copy()->subMonth()->month)
            ->whereYear('transaction_date', $now->copy()->subMonth()->year)
            ->sum('amount');
        $receiptsGrowth = $lastMonthIncome > 0
            ? round((($totalReceipts - $lastMonthIncome) / $lastMonthIncome) * 100, 1)
            : ($totalReceipts > 0 ? 100 : 0);

        // Dépenses ce mois-ci
        $expenseQuery = Transaction::approved()->expense()
            ->tap($scopeTeam)
            ->whereMonth('transaction_date', $now->month)
            ->whereYear('transaction_date', $now->year);
        $totalExpenses = (float) (clone $expenseQuery)->sum('amount');

        // Dépenses mois précédent
        $lastMonthExpense = (float) Transaction::approved()->expense()
            ->tap($scopeTeam)
            ->whereMonth('transaction_date', $now->copy()->subMonth()->month)
            ->whereYear('transaction_date', $now->copy()->subMonth()->year)
            ->sum('amount');
        $expenseGrowth = $lastMonthExpense > 0
            ? round((($totalExpenses - $lastMonthExpense) / $lastMonthExpense) * 100, 1)
            : ($totalExpenses > 0 ? 100 : 0);

        $netBalance = $totalReceipts - $totalExpenses;
        $lastNetBalance = $lastMonthIncome - $lastMonthExpense;
        $netGrowth = $lastNetBalance != 0
            ? round((($netBalance - $lastNetBalance) / abs($lastNetBalance)) * 100, 1)
            : 0;

        // Ventilation des recettes par catégorie réelle
        $receiptBreakdown = [];
        if ($totalReceipts > 0) {
            $receiptBreakdown = DB::table('transactions')
                ->leftJoin('financial_categories', 'transactions.category_id', '=', 'financial_categories.id')
                ->where('transactions.status', 'approved')
                ->where('transactions.type', 'income')
                ->whereMonth('transactions.transaction_date', $now->month)
                ->whereYear('transactions.transaction_date', $now->year)
                ->when(!$isBlocked && $teamIds !== null && !ScopeHelper::isSuperAdmin(), function ($q) use ($teamIds) {
                    $q->whereIn('transactions.created_by', $teamIds);
                })
                ->when($isBlocked, function ($q) {
                    $q->whereRaw('0 = 1');
                })
                ->select(
                    DB::raw('COALESCE(financial_categories.name, "Autre") as label'),
                    DB::raw('SUM(transactions.amount) as total')
                )
                ->groupBy('label')
                ->orderByDesc('total')
                ->get()
                ->map(function ($item) use ($totalReceipts) {
                    $val = (float) $item->total;
                    return [
                        'label' => $item->label,
                        'value' => $val,
                        'percentage' => $totalReceipts > 0 ? round(($val / $totalReceipts) * 100, 1) : 0,
                    ];
                })
                ->values()
                ->toArray();
        }

        // Ventilation des dépenses par catégorie réelle
        $expenseBreakdown = [];
        if ($totalExpenses > 0) {
            $expenseBreakdown = DB::table('transactions')
                ->leftJoin('financial_categories', 'transactions.category_id', '=', 'financial_categories.id')
                ->where('transactions.status', 'approved')
                ->where('transactions.type', 'expense')
                ->whereMonth('transactions.transaction_date', $now->month)
                ->whereYear('transactions.transaction_date', $now->year)
                ->when(!$isBlocked && $teamIds !== null && !ScopeHelper::isSuperAdmin(), function ($q) use ($teamIds) {
                    $q->whereIn('transactions.created_by', $teamIds);
                })
                ->when($isBlocked, function ($q) {
                    $q->whereRaw('0 = 1');
                })
                ->select(
                    DB::raw('COALESCE(financial_categories.name, "Autre") as label'),
                    DB::raw('SUM(transactions.amount) as total')
                )
                ->groupBy('label')
                ->orderByDesc('total')
                ->get()
                ->map(function ($item) use ($totalExpenses) {
                    $val = (float) $item->total;
                    return [
                        'label' => $item->label,
                        'value' => $val,
                        'percentage' => $totalExpenses > 0 ? round(($val / $totalExpenses) * 100, 1) : 0,
                    ];
                })
                ->values()
                ->toArray();
        }

        return response()->json([
            'period' => 'Mensuel',
            'receipts' => [
                'total' => $totalReceipts,
                'growth' => $receiptsGrowth,
                'currency' => $currency,
                'breakdown' => $receiptBreakdown,
            ],
            'expenses' => [
                'total' => $totalExpenses,
                'growth' => $expenseGrowth,
                'currency' => $currency,
                'breakdown' => $expenseBreakdown,
            ],
            'net_balance' => [
                'total' => $netBalance,
                'growth' => $netGrowth,
                'currency' => $currency,
            ],
        ]);
    }

    /**
     * Événements à venir réels depuis la table events.
     */
    public function upcomingEvents(Request $request)
    {
        $eventsQuery = Event::query()
            ->whereDate('event_date', '>=', Carbon::today());

        ScopeHelper::applyOwnedByScope($eventsQuery);

        $events = $eventsQuery
            ->orderBy('event_date', 'asc')
            ->orderBy('start_time', 'asc')
            ->limit(5)
            ->get()
            ->map(function ($evt) {
                $date = Carbon::parse($evt->event_date);
                $startTime = $evt->start_time ? Carbon::parse($evt->start_time)->format('H:i') : '';
                $endTime = $evt->end_time ? Carbon::parse($evt->end_time)->format('H:i') : '';
                $timeStr = $startTime ? ($endTime ? "{$startTime} - {$endTime}" : $startTime) : 'Horaire à confirmer';

                return [
                    'id' => $evt->id,
                    'title' => $evt->title,
                    'location' => $evt->location ?: ($evt->address ?: 'Temple principal'),
                    'start_date' => $evt->event_date . ($evt->start_time ? ' ' . $evt->start_time : ''),
                    'end_date' => $evt->event_date . ($evt->end_time ? ' ' . $evt->end_time : ''),
                    'day' => $date->format('d'),
                    'month' => strtoupper($date->locale('fr')->isoFormat('MMM')),
                    'time' => $timeStr,
                    'status' => $evt->status === 'cancelled' ? 'Annulé' : 'À venir',
                ];
            });

        return response()->json($events->values());
    }

    /**
     * Activités récentes réelles assemblées depuis les tables membres, transactions, événements, présences et diffusions.
     */
    public function recentActivities(Request $request)
    {
        $limit = (int) $request->input('limit', 10);
        $teamIds = ScopeHelper::isSuperAdmin() ? null : ScopeHelper::getTeamUserIds();
        $isBlocked = $teamIds && (count($teamIds) === 1 && (int) $teamIds[0] === 0);

        $activities = [];

        // 1. Nouveaux membres
        $membersQuery = Member::query();
        ScopeHelper::applyMemberScope($membersQuery);
        $recentMembers = (clone $membersQuery)
            ->latest('created_at')
            ->limit(5)
            ->get();

        foreach ($recentMembers as $m) {
            $activities[] = [
                'id' => 'mem-' . $m->id,
                'type' => 'new_member',
                'icon' => '👤',
                'color' => '#8B5CF6',
                'title' => 'Nouveau membre inscrit',
                'description' => trim($m->first_name . ' ' . strtoupper($m->last_name)),
                'created_at' => $m->created_at ? $m->created_at->toDateTimeString() : now()->toDateTimeString(),
                'time_ago' => $m->created_at ? $m->created_at->locale('fr')->diffForHumans() : 'Récemment',
            ];
        }

        // 2. Dernières transactions
        $txQuery = Transaction::with(['category']);
        if (!ScopeHelper::isSuperAdmin()) {
            if ($isBlocked) {
                $txQuery->whereRaw('0 = 1');
            } elseif ($teamIds !== null) {
                $txQuery->whereIn('created_by', $teamIds);
            }
        }
        $recentTxs = (clone $txQuery)
            ->latest('created_at')
            ->limit(5)
            ->get();

        foreach ($recentTxs as $tx) {
            $typeLabel = $tx->type === 'income' ? 'Don / Recette reçu' : 'Dépense enregistrée';
            $color = $tx->type === 'income' ? '#10B981' : '#EF4444';
            $icon = $tx->type === 'income' ? '💰' : '💳';
            $cat = optional($tx->category)->name ?: 'Transaction';
            $formattedAmount = number_format($tx->amount, 0, ',', ' ') . ' FCFA';

            $activities[] = [
                'id' => 'tx-' . $tx->id,
                'type' => 'donation',
                'icon' => $icon,
                'color' => $color,
                'title' => $typeLabel,
                'description' => "{$cat} — {$formattedAmount} ({$tx->status})",
                'created_at' => $tx->created_at ? $tx->created_at->toDateTimeString() : now()->toDateTimeString(),
                'time_ago' => $tx->created_at ? $tx->created_at->locale('fr')->diffForHumans() : 'Récemment',
            ];
        }

        // 3. Derniers événements
        $eventsQuery = Event::query();
        ScopeHelper::applyOwnedByScope($eventsQuery);
        $recentEvents = (clone $eventsQuery)
            ->latest('created_at')
            ->limit(4)
            ->get();

        foreach ($recentEvents as $e) {
            $activities[] = [
                'id' => 'evt-' . $e->id,
                'type' => 'event',
                'icon' => '📅',
                'color' => '#4F46E5',
                'title' => 'Événement programmé',
                'description' => $e->title . ($e->location ? ' (' . $e->location . ')' : ''),
                'created_at' => $e->created_at ? $e->created_at->toDateTimeString() : now()->toDateTimeString(),
                'time_ago' => $e->created_at ? $e->created_at->locale('fr')->diffForHumans() : 'Récemment',
            ];
        }

        // 4. Dernières diffusions live
        $livesQuery = LiveStream::query();
        if (!ScopeHelper::isSuperAdmin()) {
            if ($isBlocked) {
                $livesQuery->whereRaw('0 = 1');
            } elseif ($teamIds !== null) {
                $livesQuery->whereIn('created_by', $teamIds);
            }
        }
        $recentLives = (clone $livesQuery)
            ->latest('created_at')
            ->limit(3)
            ->get();

        foreach ($recentLives as $l) {
            $activities[] = [
                'id' => 'live-' . $l->id,
                'type' => 'live',
                'icon' => '▶️',
                'color' => '#DC2626',
                'title' => 'Diffusion en direct',
                'description' => $l->title . " [{$l->status}]",
                'created_at' => $l->created_at ? $l->created_at->toDateTimeString() : now()->toDateTimeString(),
                'time_ago' => $l->created_at ? $l->created_at->locale('fr')->diffForHumans() : 'Récemment',
            ];
        }

        // Tri chronologique réel
        usort($activities, function ($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });

        $user = $request->user();
        $activities = array_values(array_filter($activities, function ($activity) use ($user) {
            $permission = match ($activity['type']) {
                'new_member' => 'members.view',
                'donation' => 'finance.view',
                'event' => 'events.view',
                'live' => 'live_streams.view',
                default => null,
            };

            return $permission && $user->hasPermission($permission);
        }));

        return response()->json(array_slice($activities, 0, max(1, min($limit, 50))));
    }

    /**
     * Indicateurs numériques réels des services et équipements de l'église.
     */
    public function devicesStatus(Request $request)
    {
        $teamIds = ScopeHelper::isSuperAdmin() ? null : ScopeHelper::getTeamUserIds();
        $isBlocked = $teamIds && (count($teamIds) === 1 && (int) $teamIds[0] === 0);

        // 1. Membres avec Cartes / Badges (code membre attribué)
        $membersQuery = Member::query();
        ScopeHelper::applyMemberScope($membersQuery);
        $cardsCount = (clone $membersQuery)->whereNotNull('member_code')->count();

        // 2. Scans QR Code ce mois-ci
        $qrQuery = Attendance::where('attendances.scan_method', 'qr');
        if (!ScopeHelper::isSuperAdmin()) {
            if ($isBlocked) {
                $qrQuery->whereRaw('0 = 1');
            } else {
                $qrQuery->where(function ($q) use ($teamIds) {
                    $q->whereHas('session', fn($sq) => ScopeHelper::applyOwnedByScope($sq))
                      ->orWhereHas('member', fn($mq) => ScopeHelper::applyMemberScope($mq));
                });
            }
        }
        $qrScansMonth = $qrQuery->whereMonth('created_at', Carbon::now()->month)->count();

        // 3. Diffusions Live enregistrées
        $livesQuery = LiveStream::query();
        if (!ScopeHelper::isSuperAdmin()) {
            if ($isBlocked) {
                $livesQuery->whereRaw('0 = 1');
            } elseif ($teamIds !== null) {
                $livesQuery->whereIn('created_by', $teamIds);
            }
        }
        $activeLive = (clone $livesQuery)->where('status', 'live')->exists();
        $totalLives = (clone $livesQuery)->count();

        // 4. Membres avec téléphone joignable (SMS / WhatsApp)
        $phoneMembersCount = (clone $membersQuery)->whereNotNull('phone')->where('phone', '!=', '')->count();

        // 5. Assistant Virtuel IA (Conversations créées)
        $assistantConvQuery = AssistantConversation::query()->where('user_id', $request->user()->id);
        $aiConversationsCount = $assistantConvQuery->count();

        $payload = [
            'smart_terminals' => [
                'label' => 'Régie & Diffusions',
                'status' => $activeLive ? 'En direct' : ($totalLives > 0 ? 'Disponible' : 'Non configuré'),
                'value' => $activeLive ? 'Flux ON AIR en cours' : ($totalLives . ' diffusion(s) enregistrée(s)'),
                'color' => $activeLive ? '#EF4444' : '#10B981',
            ],
            'rfid_cards' => [
                'label' => 'Badges & Cartes',
                'status' => $cardsCount > 0 ? 'Actives' : 'En attente',
                'value' => $cardsCount . ' membre(s) badgé(s)',
                'color' => '#8B5CF6',
            ],
            'qr_code' => [
                'label' => 'Scans QR Présence',
                'status' => $qrScansMonth > 0 ? 'En usage' : 'Actif',
                'value' => $qrScansMonth . ' scan(s) ce mois',
                'color' => '#6366f1',
            ],
            'telephone' => [
                'label' => 'Fidèles Joignables',
                'status' => $phoneMembersCount > 0 ? 'Actif' : 'Non renseigné',
                'value' => $phoneMembersCount . ' numéro(s) enregistré(s)',
                'color' => '#3B82F6',
            ],
            'voice_assistant' => [
                'label' => 'Assistant Vocal (IA)',
                'status' => 'Opérationnel',
                'value' => $aiConversationsCount . ' échange(s) pastoral(aux)',
                'color' => '#8B5CF6',
            ],
        ];
        $user = $request->user();
        if (!$user->hasPermission('live_streams.view')) {
            unset($payload['smart_terminals']);
        }
        if (!$user->hasPermission('members.view')) {
            unset($payload['rfid_cards'], $payload['telephone']);
        }
        if (!$user->hasPermission('attendance.view')) {
            unset($payload['qr_code']);
        }

        return response()->json($payload);
    }
}
