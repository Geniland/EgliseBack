<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Resource;
use App\Models\Purchase;
use App\Models\Church;
use App\Support\ScopeHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ResourceController extends Controller
{
    public function index(Request $request)
    {
        $query = Resource::with(['category', 'creator']);
        
        ScopeHelper::applyChurchScope($query);

        if ($request->search) {
            $query->where(function($q) use ($request) {
                $q->where('title', 'like', "%{$request->search}%")
                  ->orWhere('description', 'like', "%{$request->search}%");
            });
        }
        
        if ($request->category_id) {
            $query->where('category_id', $request->category_id);
        }
        
        if ($request->type) {
            $query->where('type', $request->type);
        }
        
        if ($request->status) {
            $query->where('status', $request->status);
        }

        $paginator = $query->orderByDesc('id')->paginate($request->per_page ?? 15);
        $user = auth()->user();

        $paginator->getCollection()->transform(function ($resource) use ($user) {
            return $this->formatResource($resource, $user);
        });

        return response()->json($paginator);
    }

    public function store(Request $request)
    {
        if ($request->category_id === '' || $request->category_id === 'null') {
            $request->merge(['category_id' => null]);
        }

        $validated = $request->validate([
            'category_id' => 'nullable|exists:resource_categories,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:pdf,video,audio,document',
            'is_free' => 'boolean',
            'price' => 'nullable|numeric|min:0',
            'status' => 'required|in:draft,published',
            'file' => 'nullable|file|max:102400', // 100MB max
            'cover_image' => 'nullable|image|max:5120',
        ]);

        $churchId = ScopeHelper::getRequestedChurchContext() ?: auth()->user()->church_id;
        if (!$churchId) {
            $myChurches = ScopeHelper::getMyChurchIds();
            if (!empty($myChurches) && (int)$myChurches[0] > 0) {
                $churchId = (int)$myChurches[0];
            }
        }
        if (!$churchId) {
            $churchId = Church::where('status', true)->value('id');
        }
        
        $resource = new Resource($validated);
        $resource->church_id = $churchId;
        $resource->created_by = auth()->id();
        
        if ($request->status === 'published') {
            $resource->published_at = now();
        }

        if ($request->hasFile('file')) {
            $path = $request->file('file')->store('resources/files', 'public');
            $resource->file_path = $path;
        }
        
        if ($request->hasFile('cover_image')) {
            $path = $request->file('cover_image')->store('resources/covers', 'public');
            $resource->cover_image = $path;
        }

        $resource->save();

        return response()->json($this->formatResource($resource->load('category'), auth()->user()), 201);
    }

    public function show($id)
    {
        $resource = Resource::with(['category', 'creator'])->findOrFail($id);
        return response()->json($this->formatResource($resource, auth()->user()));
    }

    public function update(Request $request, $id)
    {
        $resource = ScopeHelper::findOwnedOrFail(Resource::class, $id, 'church_id');

        if ($request->category_id === '' || $request->category_id === 'null') {
            $request->merge(['category_id' => null]);
        }

        $validated = $request->validate([
            'category_id' => 'nullable|exists:resource_categories,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:pdf,video,audio,document',
            'is_free' => 'boolean',
            'price' => 'nullable|numeric|min:0',
            'status' => 'required|in:draft,published',
        ]);

        if ($request->status === 'published' && $resource->status === 'draft') {
            $validated['published_at'] = now();
        }

        if ($request->hasFile('file')) {
            if ($resource->file_path) {
                Storage::disk('public')->delete($resource->file_path);
            }
            $validated['file_path'] = $request->file('file')->store('resources/files', 'public');
        }
        
        if ($request->hasFile('cover_image')) {
            if ($resource->cover_image) {
                Storage::disk('public')->delete($resource->cover_image);
            }
            $validated['cover_image'] = $request->file('cover_image')->store('resources/covers', 'public');
        }

        $resource->update($validated);

        return response()->json($this->formatResource($resource->load('category'), auth()->user()));
    }

    public function destroy($id)
    {
        $resource = ScopeHelper::findOwnedOrFail(Resource::class, $id, 'church_id');
        
        if ($resource->file_path) {
            Storage::disk('public')->delete($resource->file_path);
        }
        if ($resource->cover_image) {
            Storage::disk('public')->delete($resource->cover_image);
        }
        
        $resource->delete();

        return response()->json(null, 204);
    }

    /**
     * Téléchargement sécurisé avec restriction fidèle vs administrateur.
     */
    public function download($id)
    {
        $resource = Resource::findOrFail($id);
        $user = auth()->user();
        $access = $this->checkAccess($resource, $user);

        if (!$access['can_access']) {
            return response()->json([
                'message' => 'Ce document est payant. Seul l\'administrateur de l\'église ou un fidèle ayant acquis ce document peut le télécharger.'
            ], 403);
        }

        if (!$resource->file_path || !Storage::disk('public')->exists($resource->file_path)) {
            return response()->json([
                'message' => 'Le fichier demandé est introuvable ou n\'a pas encore été téléversé.'
            ], 404);
        }

        $fullPath = Storage::disk('public')->path($resource->file_path);
        return response()->download($fullPath, basename($resource->file_path));
    }

    /**
     * Vérifie si l'utilisateur a accès au document.
     * Règle clé : Seuls le Super Admin (1) et l'Administrateur de l'église (2)
     * peuvent voir/télécharger tous les documents (payants ou gratuits).
     * Les fidèles doivent acheter les documents payants pour y accéder.
     */
    protected function checkAccess(Resource $resource, $user): array
    {
        if (!$user) {
            return ['can_access' => (bool)$resource->is_free, 'is_admin_bypass' => false, 'is_purchased' => false];
        }

        $roleId = (int) $user->role_id;

        // Super Admin (1) ou Administrateur d'église (2)
        if ($roleId === 1 || $roleId === 2) {
            return ['can_access' => true, 'is_admin_bypass' => true, 'is_purchased' => true];
        }

        // Gratuit
        if ($resource->is_free) {
            return ['can_access' => true, 'is_admin_bypass' => false, 'is_purchased' => false];
        }

        // Payant -> Vérification d'achat
        $isPurchased = Purchase::where('user_id', $user->id)
            ->where('purchasable_type', Resource::class)
            ->where('purchasable_id', $resource->id)
            ->where('status', 'active')
            ->exists();

        return [
            'can_access' => $isPurchased,
            'is_admin_bypass' => false,
            'is_purchased' => $isPurchased
        ];
    }

    /**
     * Formate la ressource en masquant le document pour les non-autorisés.
     */
    protected function formatResource(Resource $resource, $user)
    {
        $access = $this->checkAccess($resource, $user);
        $data = $resource->toArray();
        $data['can_access'] = $access['can_access'];
        $data['is_admin_bypass'] = $access['is_admin_bypass'];
        $data['is_purchased'] = $access['is_purchased'];

        if ($access['can_access'] && $resource->file_path) {
            $data['file_url'] = asset('storage/' . $resource->file_path);
        } else {
            $data['file_url'] = null;
            // Ne pas exposer le chemin brut aux utilisateurs non autorisés
            if (!$access['can_access']) {
                $data['file_path'] = null;
            }
        }

        return $data;
    }
}
