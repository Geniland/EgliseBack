<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LiveStream;
use App\Models\Resource;
use App\Http\Requests\StoreLiveStreamRequest;
use App\Http\Requests\UpdateLiveStreamRequest;
use App\Http\Resources\LiveStreamResource;
use App\Support\ScopeHelper;
use Illuminate\Http\Request;

class LiveStreamController extends Controller
{
    private function findVisibleStream(LiveStream $liveStream): LiveStream
    {
        $user = auth()->user();
        $canManageStreams = $user && $user->hasPermission(
            'live_streams.create|live_streams.update|live_streams.delete|live_streams.publish|live_streams.end|live_streams.configure|live_streams.replay'
        );

        if (!$canManageStreams && $liveStream->status !== 'live') {
            abort(404);
        }

        if (!$canManageStreams && $liveStream->status === 'live') {
            return $liveStream;
        }

        return ScopeHelper::findOwnedOrFail(LiveStream::class, $liveStream->id);
    }

    /**
     * Extracts YouTube Video ID from a given URL
     */
    private function extractYoutubeId(?string $url): ?string
    {
        if (!$url) return null;
        preg_match('%(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?)/|.*[?&]v=)|youtu\.be/)([^"&?/\s]{11})%i', $url, $match);
        return $match[1] ?? null;
    }

    public function index(Request $request)
    {
        $query = LiveStream::with('event');
        $user = $request->user();
        $canManageStreams = $user->hasPermission(
            'live_streams.create|live_streams.update|live_streams.delete|live_streams.publish|live_streams.end|live_streams.configure|live_streams.replay'
        );
        if ($canManageStreams) {
            ScopeHelper::applyOwnedByScope($query);
        } else {
            $query->where('status', 'live');
        }
        $lives = $query->orderByDesc('created_at')->get();
        return LiveStreamResource::collection($lives);
    }

    public function store(StoreLiveStreamRequest $request)
    {
        $validated = $request->validated();

        $youtubeId = $this->extractYoutubeId($validated['youtube_url'] ?? null);

        $liveStream = LiveStream::create([
            'event_id' => $validated['event_id'] ?? null,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'provider' => 'youtube',
            'provider_live_input_id' => $youtubeId,
            'scheduled_at' => $validated['scheduled_at'] ?? null,
            'status' => $validated['status'] ?? 'draft',
            'created_by' => auth()->id(),
        ]);

        return new LiveStreamResource($liveStream);
    }

    public function show(LiveStream $liveStream)
    {
        $liveStream = $this->findVisibleStream($liveStream);
        return new LiveStreamResource($liveStream->load('event'));
    }

    public function update(UpdateLiveStreamRequest $request, LiveStream $liveStream)
    {
        $liveStream = $this->findVisibleStream($liveStream);
        $validated = $request->validated();

        if (isset($validated['youtube_url'])) {
            $validated['provider_live_input_id'] = $this->extractYoutubeId($validated['youtube_url']);
            unset($validated['youtube_url']);
        }

        $liveStream->update($validated);
        return new LiveStreamResource($liveStream);
    }

    public function destroy(LiveStream $liveStream)
    {
        $liveStream = $this->findVisibleStream($liveStream);
        $liveStream->delete();
        return response()->noContent();
    }

    public function status(LiveStream $liveStream)
    {
        $liveStream = $this->findVisibleStream($liveStream);
        return response()->json([
            'status' => $liveStream->status,
        ]);
    }

    public function start(LiveStream $liveStream)
    {
        $liveStream = $this->findVisibleStream($liveStream);
        $liveStream->update([
            'status' => 'live',
            'started_at' => now(),
        ]);

        return new LiveStreamResource($liveStream);
    }

    public function end(LiveStream $liveStream)
    {
        $liveStream = $this->findVisibleStream($liveStream);
        $liveStream->update([
            'status' => 'ended',
            'ended_at' => now(),
        ]);

        return new LiveStreamResource($liveStream);
    }

    public function active()
    {
        $liveStream = LiveStream::where('status', 'live')->first();
        
        if (!$liveStream) {
            return response()->json(null);
        }

        return new LiveStreamResource($liveStream);
    }

    public function publishAsResource(Request $request, LiveStream $liveStream)
    {
        $liveStream = $this->findVisibleStream($liveStream);
        if (!in_array($liveStream->status, ['ended', 'processing'])) {
            return response()->json(['message' => 'Seul un live terminé peut être publié en ressource.'], 400);
        }

        $validated = $request->validate([
            'category_id' => 'nullable|exists:resource_categories,id',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'is_free' => 'boolean',
            'price' => 'nullable|numeric|min:0',
            'status' => 'required|in:draft,published',
        ]);

        $churchId = ScopeHelper::getRequestedChurchContext() ?: auth()->user()->church_id;

        $resource = Resource::create([
            'church_id' => $churchId,
            'category_id' => $validated['category_id'] ?? null,
            'title' => $validated['title'] ?? $liveStream->title,
            'description' => $validated['description'] ?? $liveStream->description,
            'type' => 'video',
            'file_path' => $liveStream->provider_live_input_id ? 'https://youtube.com/watch?v='.$liveStream->provider_live_input_id : null,
            'is_free' => $validated['is_free'] ?? true,
            'price' => $validated['price'] ?? 0,
            'status' => $validated['status'] ?? 'draft',
            'published_at' => ($validated['status'] ?? 'draft') === 'published' ? now() : null,
            'created_by' => auth()->id(),
        ]);

        return response()->json([
            'message' => 'Replay publié en tant que ressource avec succès.',
            'resource' => $resource
        ]);
    }
}
