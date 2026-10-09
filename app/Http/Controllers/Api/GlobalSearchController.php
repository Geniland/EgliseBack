<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Member;
use App\Models\Transaction;
use App\Support\ScopeHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlobalSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $term = trim($validated['q']);
        if (mb_strlen($term) < 2) {
            return response()->json(['results' => []]);
        }
        $pattern = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
        $results = [];
        $user = $request->user();

        if ($user->hasPermission('members.view')) {
            $members = Member::query();
            ScopeHelper::applyMemberScope($members);
            $results = array_merge($results, $members
                ->where(function ($query) use ($pattern) {
                    $query->where('first_name', 'LIKE', $pattern)
                        ->orWhere('last_name', 'LIKE', $pattern)
                        ->orWhere('member_code', 'LIKE', $pattern)
                        ->orWhere('email', 'LIKE', $pattern)
                        ->orWhere('phone', 'LIKE', $pattern);
                })
                ->latest('id')
                ->limit(5)
                ->get(['id', 'first_name', 'last_name', 'member_code', 'phone'])
                ->map(fn (Member $member) => [
                    'id' => 'member-' . $member->id,
                    'type' => 'Membre',
                    'title' => trim($member->first_name . ' ' . $member->last_name),
                    'description' => implode(' · ', array_filter([$member->member_code, $member->phone])),
                    'url' => '/members',
                ])
                ->all());
        }

        if ($user->hasPermission('events.view')) {
            $events = Event::query();
            ScopeHelper::applyOwnedByScope($events);
            $results = array_merge($results, $events
                ->where(function ($query) use ($pattern) {
                    $query->where('title', 'LIKE', $pattern)
                        ->orWhere('description', 'LIKE', $pattern)
                        ->orWhere('location', 'LIKE', $pattern);
                })
                ->latest('event_date')
                ->limit(5)
                ->get(['id', 'title', 'event_date', 'location'])
                ->map(fn (Event $event) => [
                    'id' => 'event-' . $event->id,
                    'type' => 'Événement',
                    'title' => $event->title,
                    'description' => implode(' · ', array_filter([
                        $event->event_date?->format('d/m/Y'),
                        $event->location,
                    ])),
                    'url' => '/events',
                ])
                ->all());
        }

        if ($user->hasPermission('finance.view')) {
            $transactions = Transaction::query()->with('category:id,name');
            ScopeHelper::applyOwnedByScope($transactions);
            $results = array_merge($results, $transactions
                ->where(function ($query) use ($pattern) {
                    $query->where('description', 'LIKE', $pattern)
                        ->orWhere('reference', 'LIKE', $pattern)
                        ->orWhere('transaction_code', 'LIKE', $pattern)
                        ->orWhereHas('category', fn ($category) => $category->where('name', 'LIKE', $pattern));
                })
                ->latest('transaction_date')
                ->limit(5)
                ->get(['id', 'transaction_code', 'description', 'amount', 'transaction_date', 'status', 'category_id'])
                ->map(fn (Transaction $transaction) => [
                    'id' => 'transaction-' . $transaction->id,
                    'type' => 'Transaction',
                    'title' => $transaction->description ?: ($transaction->transaction_code ?: 'Transaction'),
                    'description' => implode(' · ', array_filter([
                        $transaction->category?->name,
                        number_format((float) $transaction->amount, 0, ',', ' ') . ' FCFA',
                        $transaction->status,
                    ])),
                    'url' => '/finances',
                ])
                ->all());
        }

        return response()->json([
            'results' => array_slice($results, 0, 15),
        ]);
    }
}
