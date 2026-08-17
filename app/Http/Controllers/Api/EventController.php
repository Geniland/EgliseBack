<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEventRequest;
use App\Http\Requests\UpdateEventRequest;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Support\ScopeHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EventController extends Controller
{
    public function index(Request $request)
    {
        $events = Event::with(['creator'])
            ->when($request->search, function ($q) use ($request) {
                $q->where(function ($sub) use ($request) {
                    $sub->where('title', 'LIKE', "%{$request->search}%")
                        ->orWhere('description', 'LIKE', "%{$request->search}%")
                        ->orWhere('location', 'LIKE', "%{$request->search}%");
                });
            })
            ->when($request->type, fn($q) => $q->where('type', $request->type))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->date_from, fn($q) => $q->whereDate('event_date', '>=', $request->date_from))
            ->when($request->date_to, fn($q) => $q->whereDate('event_date', '<=', $request->date_to))
            ->when($request->scope === 'upcoming', fn($q) => $q->upcoming())
            ->when($request->scope === 'past', fn($q) => $q->past())
            ->when($request->featured === 'true', fn($q) => $q->featured())
            ->tap(fn($q) => ScopeHelper::applyOwnedByScope($q))
            ->latest('event_date')
            ->latest('start_time')
            ->paginate($request->per_page ?? 20);

        return EventResource::collection($events);
    }

    public function show(Event $event)
    {
        $event->load(['creator', 'updater']);
        return new EventResource($event);
    }

    public function store(StoreEventRequest $request)
    {
        $data = $request->validated();
        $data['created_by'] = auth()->id();
        $data['updated_by'] = auth()->id();
        $data['status'] = $data['status'] ?? 'draft';

        $event = Event::create($data);
        $event->load(['creator']);

        return response()->json([
            'message' => 'Événement créé avec succès',
            'event' => new EventResource($event),
        ], 201);
    }

    public function update(UpdateEventRequest $request, Event $event)
    {
        $data = $request->validated();
        $data['updated_by'] = auth()->id();
        $event->update($data);
        $event->load(['creator', 'updater']);

        return response()->json([
            'message' => 'Événement mis à jour',
            'event' => new EventResource($event),
        ]);
    }

    public function destroy(Event $event)
    {
        $event->delete();
        return response()->json(['message' => 'Événement supprimé']);
    }

    public function publish(Event $event)
    {
        $event->update([
            'status' => 'published',
            'updated_by' => auth()->id(),
        ]);
        $event->load(['creator', 'updater']);

        return response()->json([
            'message' => 'Événement publié',
            'event' => new EventResource($event),
        ]);
    }

    public function cancel(Event $event)
    {
        $event->update([
            'status' => 'cancelled',
            'updated_by' => auth()->id(),
        ]);
        $event->load(['creator', 'updater']);

        return response()->json([
            'message' => 'Événement annulé',
            'event' => new EventResource($event),
        ]);
    }

    public function complete(Event $event)
    {
        $event->update([
            'status' => 'completed',
            'updated_by' => auth()->id(),
        ]);
        $event->load(['creator', 'updater']);

        return response()->json([
            'message' => 'Événement marqué comme terminé',
            'event' => new EventResource($event),
        ]);
    }

    public function toggleFeatured(Event $event)
    {
        $event->update([
            'is_featured' => !$event->is_featured,
            'updated_by' => auth()->id(),
        ]);

        return response()->json([
            'message' => $event->is_featured ? 'Événement mis en avant' : 'Événement retiré de la une',
            'event' => new EventResource($event->fresh()),
        ]);
    }

    public function stats(Request $request)
    {
        $base = Event::query()->tap(fn($q) => ScopeHelper::applyOwnedByScope($q));

        $total = (clone $base)->count();
        $drafts = (clone $base)->where('status', 'draft')->count();
        $published = (clone $base)->where('status', 'published')->count();
        $cancelled = (clone $base)->where('status', 'cancelled')->count();
        $completed = (clone $base)->where('status', 'completed')->count();
        $upcoming = (clone $base)->upcoming()->published()->count();
        $featured = (clone $base)->featured()->count();

        $types = Event::types();
        $byType = [];
        foreach ($types as $key => $label) {
            $count = (clone $base)->where('type', $key)->count();
            if ($count > 0) {
                $byType[] = ['type' => $key, 'label' => $label, 'count' => $count];
            }
        }

        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();
        $thisMonth = (clone $base)->whereBetween('event_date', [$monthStart, $monthEnd])->count();

        $nextMonthStart = now()->addMonth()->startOfMonth();
        $nextMonthEnd = now()->addMonth()->endOfMonth();
        $nextMonth = (clone $base)->whereBetween('event_date', [$nextMonthStart, $nextMonthEnd])->count();

        $trend = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $start = $month->startOfMonth();
            $end = $month->endOfMonth();
            $count = (clone $base)->whereBetween('event_date', [$start, $end])->count();
            $trend[] = [
                'label' => $month->format('M Y'),
                'count' => $count,
            ];
        }

        $upcomingEvents = (clone $base)
            ->with(['creator'])
            ->upcoming()
            ->published()
            ->orderBy('event_date')
            ->orderBy('start_time')
            ->limit(5)
            ->get();

        return response()->json([
            'overview' => [
                'total' => $total,
                'draft' => $drafts,
                'published' => $published,
                'cancelled' => $cancelled,
                'completed' => $completed,
                'upcoming' => $upcoming,
                'featured' => $featured,
                'this_month' => $thisMonth,
                'next_month' => $nextMonth,
            ],
            'by_type' => $byType,
            'trend' => $trend,
            'upcoming_events' => EventResource::collection($upcomingEvents),
        ]);
    }

    public function calendar(Request $request)
    {
        $start = $request->start ?: now()->subMonth()->toDateString();
        $end = $request->end ?: now()->addMonths(2)->toDateString();

        $events = Event::with(['creator'])
            ->whereBetween('event_date', [$start, $end])
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->tap(fn($q) => ScopeHelper::applyOwnedByScope($q))
            ->orderBy('event_date')
            ->orderBy('start_time')
            ->get();

        return EventResource::collection($events);
    }
}
