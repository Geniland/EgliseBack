<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Member;
use App\Models\Ministry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Support\ScopeHelper;

class DashboardController extends Controller
{
    public function stats(Request $request)
    {
        $membersQuery = Member::query();
        ScopeHelper::applyOwnedByScope($membersQuery);

        $totalMembers = (clone $membersQuery)->count();
        $newMembersThisMonth = (clone $membersQuery)
            ->whereMonth('created_at', Carbon::now()->month)
            ->whereYear('created_at', Carbon::now()->year)
            ->count();

        $lastMonthMembers = (clone $membersQuery)
            ->whereMonth('created_at', Carbon::now()->subMonth()->month)
            ->whereYear('created_at', Carbon::now()->subMonth()->year)
            ->count();

        $membersGrowth = $lastMonthMembers > 0
            ? round((($newMembersThisMonth - $lastMonthMembers) / $lastMonthMembers) * 100, 1)
            : ($newMembersThisMonth > 0 ? 100 : 12.5);

        $ministriesQuery = Ministry::where('status', true);
        ScopeHelper::applyOwnedByScope($ministriesQuery);
        $ministries = (clone $ministriesQuery)->count();

        $totalUsers = ScopeHelper::isSuperAdmin()
            ? User::count()
            : 1;

        return response()->json([
            'members_total' => [
                'value' => $totalMembers,
                'growth' => $membersGrowth,
                'new_this_month' => $newMembersThisMonth,
                'sparkline' => $this->generateSparkline(12, (int) max(100, $totalMembers * 0.2), max(1000, $totalMembers))
            ],
            'presences_month' => [
                'value' => ScopeHelper::isSuperAdmin() ? 8752 : (int) max(100, $totalMembers * 8),
                'growth' => 15.3,
                'attendance_rate' => 70.2,
                'sparkline' => $this->generateSparkline(12, 1000, ScopeHelper::isSuperAdmin() ? 8752 : (int) max(100, $totalMembers * 8))
            ],
            'donations' => [
                'value' => ScopeHelper::isSuperAdmin() ? 25680000 : (int) max(100000, $totalMembers * 25000),
                'currency' => 'FCFA',
                'growth' => 18.6,
                'sparkline' => $this->generateSparkline(12, 500000, ScopeHelper::isSuperAdmin() ? 25680000 : (int) max(100000, $totalMembers * 25000))
            ],
            'events' => [
                'value' => ScopeHelper::isSuperAdmin() ? 24 : (int) max(2, min(12, round($totalMembers / 8))),
                'growth' => 9.1,
                'sparkline' => $this->generateSparkline(12, 5, ScopeHelper::isSuperAdmin() ? 24 : 12)
            ],
            'pending_requests' => [
                'value' => ScopeHelper::isSuperAdmin() ? 58 : (int) max(2, min(20, round($totalMembers / 3))),
                'growth' => -5.6,
                'sparkline' => $this->generateSparkline(12, 10, ScopeHelper::isSuperAdmin() ? 70 : 30)
            ]
        ]);
    }

    public function ministryDistribution(Request $request)
    {
        $membersQuery = Member::query();
        ScopeHelper::applyOwnedByScope($membersQuery);
        $totalMembers = (clone $membersQuery)->count();

        $ministryBase = Ministry::withCount(['members' => function ($q) {
            ScopeHelper::applyOwnedByScope($q);
        }])
            ->where('status', true);
        ScopeHelper::applyOwnedByScope($ministryBase);

        $ministries = $ministryBase
            ->get()
            ->map(function ($ministry) use ($totalMembers) {
                $count = $ministry->members_count;
                $percentage = $totalMembers > 0 ? round(($count / $totalMembers) * 100, 1) : 0;
                return [
                    'id' => $ministry->id,
                    'name' => $ministry->name,
                    'count' => $count,
                    'percentage' => $percentage
                ];
            });

        $assignedCount = $ministries->sum('count');
        $othersCount = max(0, $totalMembers - $assignedCount);
        $othersPercentage = $totalMembers > 0 ? round(($othersCount / $totalMembers) * 100, 1) : 0;

        if ($othersCount > 0) {
            $ministries->push([
                'id' => null,
                'name' => 'Autres',
                'count' => $othersCount,
                'percentage' => $othersPercentage
            ]);
        }

        $colors = ['#4F46E5', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#06B6D4', '#6B7280'];
        $ministries = $ministries->map(function ($item, $index) use ($colors) {
            if (is_array($item)) {
                $item['color'] = $colors[$index % count($colors)];
            } else {
                $item = $item->toArray();
                $item['color'] = $colors[$index % count($colors)];
            }
            return $item;
        });

        return response()->json([
            'total' => $totalMembers,
            'ministries' => $ministries->values()
        ]);
    }

    public function presenceDonationChart(Request $request)
    {
        $period = $request->input('period', 'monthly');
        $days = $period === 'weekly' ? 7 : 30;

        $labels = [];
        $presences = [];
        $donations = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            if ($period === 'weekly') {
                $labels[] = $date->format('D');
            } else {
                $labels[] = $date->format('d M');
            }
            $basePresence = 300 + rand(100, 500);
            $baseDonation = 500000 + rand(200000, 1500000);

            if ($date->isSunday()) {
                $basePresence *= 2.5;
                $baseDonation *= 3;
            }

            $presences[] = (int) $basePresence;
            $donations[] = (int) $baseDonation;
        }

        return response()->json([
            'period' => $period,
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Présences',
                    'data' => $presences,
                    'borderColor' => '#3B82F6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)'
                ],
                [
                    'label' => 'Dons (FCFA)',
                    'data' => $donations,
                    'borderColor' => '#10B981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'yAxisID' => 'y1'
                ]
            ]
        ]);
    }

    public function financialSummary(Request $request)
    {
        $totalReceipts = 28560000;
        $totalExpenses = 12850000;
        $netBalance = $totalReceipts - $totalExpenses;

        return response()->json([
            'period' => 'Mensuel',
            'receipts' => [
                'total' => $totalReceipts,
                'growth' => 18.6,
                'currency' => 'FCFA',
                'breakdown' => [
                    ['label' => 'Dîmes', 'value' => 12450000, 'percentage' => 43.6],
                    ['label' => 'Offrandes', 'value' => 9869000, 'percentage' => 34.6],
                    ['label' => 'Dons', 'value' => 4120000, 'percentage' => 14.4],
                    ['label' => 'Autres', 'value' => 2121000, 'percentage' => 7.4]
                ]
            ],
            'expenses' => [
                'total' => $totalExpenses,
                'growth' => -8.3,
                'currency' => 'FCFA',
                'breakdown' => [
                    ['label' => 'Salaires', 'value' => 5200000, 'percentage' => 40.5],
                    ['label' => 'Projets', 'value' => 3200000, 'percentage' => 24.9],
                    ['label' => 'Fonctionnement', 'value' => 2450000, 'percentage' => 19.1],
                    ['label' => 'Autres', 'value' => 2000000, 'percentage' => 15.6]
                ]
            ],
            'net_balance' => [
                'total' => $netBalance,
                'growth' => 27.4,
                'currency' => 'FCFA'
            ]
        ]);
    }

    public function upcomingEvents(Request $request)
    {
        $events = [
            [
                'id' => 1,
                'title' => 'Culte dominical',
                'location' => 'Temple principal',
                'start_date' => Carbon::now()->next(Carbon::SUNDAY)->setTime(8, 0)->toDateTimeString(),
                'end_date' => Carbon::now()->next(Carbon::SUNDAY)->setTime(11, 0)->toDateTimeString(),
                'day' => Carbon::now()->next(Carbon::SUNDAY)->format('d'),
                'month' => strtoupper(Carbon::now()->next(Carbon::SUNDAY)->format('M')),
                'time' => '08:00 - 11:00',
                'status' => 'À venir'
            ],
            [
                'id' => 2,
                'title' => 'Réunion des jeunes',
                'location' => 'Salle des jeunes',
                'start_date' => Carbon::now()->addDays(3)->setTime(17, 0)->toDateTimeString(),
                'end_date' => Carbon::now()->addDays(3)->setTime(19, 30)->toDateTimeString(),
                'day' => Carbon::now()->addDays(3)->format('d'),
                'month' => strtoupper(Carbon::now()->addDays(3)->format('M')),
                'time' => '17:00 - 19:30',
                'status' => 'À venir'
            ],
            [
                'id' => 3,
                'title' => 'Conférence des couples',
                'location' => 'Auditorium',
                'start_date' => Carbon::now()->addDays(6)->setTime(14, 0)->toDateTimeString(),
                'end_date' => Carbon::now()->addDays(6)->setTime(17, 30)->toDateTimeString(),
                'day' => Carbon::now()->addDays(6)->format('d'),
                'month' => strtoupper(Carbon::now()->addDays(6)->format('M')),
                'time' => '14:00 - 17:30',
                'status' => 'À venir'
            ],
            [
                'id' => 4,
                'title' => 'Réunion de la chorale',
                'location' => 'Salle de répétition',
                'start_date' => Carbon::now()->addDays(2)->setTime(18, 0)->toDateTimeString(),
                'end_date' => Carbon::now()->addDays(2)->setTime(20, 0)->toDateTimeString(),
                'day' => Carbon::now()->addDays(2)->format('d'),
                'month' => strtoupper(Carbon::now()->addDays(2)->format('M')),
                'time' => '18:00 - 20:00',
                'status' => 'À venir'
            ],
            [
                'id' => 5,
                'title' => 'Prière d\'intercession',
                'location' => 'Chapelle',
                'start_date' => Carbon::now()->addDay()->setTime(6, 0)->toDateTimeString(),
                'end_date' => Carbon::now()->addDay()->setTime(8, 0)->toDateTimeString(),
                'day' => Carbon::now()->addDay()->format('d'),
                'month' => strtoupper(Carbon::now()->addDay()->format('M')),
                'time' => '06:00 - 08:00',
                'status' => 'À venir'
            ]
        ];

        return response()->json($events);
    }

    public function recentActivities(Request $request)
    {
        $limit = $request->input('limit', 10);

        $recentMembersQuery = Member::with('family');
        ScopeHelper::applyOwnedByScope($recentMembersQuery);
        $recentMembers = $recentMembersQuery
            ->latest()
            ->take(3)
            ->get()
            ->map(function ($member) {
                return [
                    'id' => 'mem-' . $member->id,
                    'type' => 'new_member',
                    'icon' => 'user-plus',
                    'color' => '#8B5CF6',
                    'title' => 'Nouveau membre inscrit',
                    'description' => $member->first_name . ' ' . strtoupper($member->last_name),
                    'created_at' => $member->created_at->toDateTimeString(),
                    'time_ago' => $member->created_at->diffForHumans()
                ];
            })->values()->toArray();

        $otherActivities = [
            [
                'id' => 'act-1',
                'type' => 'attendance',
                'icon' => 'check',
                'color' => '#10B981',
                'title' => 'Présence enregistrée',
                'description' => 'Culte du dimanche',
                'created_at' => Carbon::now()->subMinutes(15)->toDateTimeString(),
                'time_ago' => Carbon::now()->subMinutes(15)->diffForHumans()
            ],
            [
                'id' => 'act-2',
                'type' => 'donation',
                'icon' => 'donate',
                'color' => '#F59E0B',
                'title' => 'Nouveau don reçu',
                'description' => 'Offrande - 50 000 FCFA',
                'created_at' => Carbon::now()->subMinutes(25)->toDateTimeString(),
                'time_ago' => Carbon::now()->subMinutes(25)->diffForHumans()
            ],
            [
                'id' => 'act-3',
                'type' => 'prayer_request',
                'icon' => 'pray',
                'color' => '#EC4899',
                'title' => 'Demande de prière',
                'description' => 'Par Jean Paul M.',
                'created_at' => Carbon::now()->subMinutes(35)->toDateTimeString(),
                'time_ago' => Carbon::now()->subMinutes(35)->diffForHumans()
            ],
            [
                'id' => 'act-4',
                'type' => 'event',
                'icon' => 'calendar',
                'color' => '#4F46E5',
                'title' => 'Nouvel événement créé',
                'description' => 'Conférence des couples',
                'created_at' => Carbon::now()->subHours(1)->toDateTimeString(),
                'time_ago' => Carbon::now()->subHours(1)->diffForHumans()
            ],
            [
                'id' => 'act-5',
                'type' => 'message',
                'icon' => 'message',
                'color' => '#06B6D4',
                'title' => 'Message envoyé',
                'description' => 'Newsletter mensuelle',
                'created_at' => Carbon::now()->subHours(2)->toDateTimeString(),
                'time_ago' => Carbon::now()->subHours(2)->diffForHumans()
            ],
            [
                'id' => 'act-6',
                'type' => 'attendance',
                'icon' => 'check',
                'color' => '#10B981',
                'title' => 'Réunion chorale',
                'description' => '42 présences enregistrées',
                'created_at' => Carbon::now()->subHours(4)->toDateTimeString(),
                'time_ago' => Carbon::now()->subHours(4)->diffForHumans()
            ],
            [
                'id' => 'act-7',
                'type' => 'new_member',
                'icon' => 'user-plus',
                'color' => '#8B5CF6',
                'title' => 'Famille ajoutée',
                'description' => 'Famille KOUASSI',
                'created_at' => Carbon::now()->subHours(6)->toDateTimeString(),
                'time_ago' => Carbon::now()->subHours(6)->diffForHumans()
            ]
        ];

        $activities = array_merge($recentMembers, $otherActivities);

        usort($activities, function ($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });

        return response()->json(array_slice($activities, 0, $limit));
    }

    public function devicesStatus(Request $request)
    {
        return response()->json([
            'smart_terminals' => [
                'label' => 'Borne intelligente',
                'status' => 'En ligne',
                'value' => '3 bornes actives',
                'color' => '#10B981'
            ],
            'rfid_cards' => [
                'label' => 'Cartes RFID',
                'status' => 'Actives',
                'value' => '2 458 cartes',
                'color' => '#8B5CF6'
            ],
            'qr_code' => [
                'label' => 'QR Code',
                'status' => 'Utilisés ce mois',
                'value' => '1 256 scans',
                'color' => '#8B5CF6'
            ],
            'telephone' => [
                'label' => 'Téléphone / USSD',
                'status' => 'Actif',
                'value' => '+228 90 XX XX XX',
                'color' => '#3B82F6'
            ],
            'voice_assistant' => [
                'label' => 'Assistant Vocal (IA)',
                'status' => 'Disponible',
                'value' => '24/7',
                'color' => '#8B5CF6'
            ]
        ]);
    }

    private function generateSparkline($count, $min, $max)
    {
        $data = [];
        $value = $min;
        for ($i = 0; $i < $count; $i++) {
            $value += rand(($max - $min) / (-2 * $count), ($max - $min) / $count);
            $value = max($min * 0.8, min($max * 1.1, $value));
            $data[] = (int) $value;
        }
        return $data;
    }
}
