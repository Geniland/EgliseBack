<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Formation;
use App\Models\FormationModule;
use App\Models\Content;
use App\Models\Enrollment;
use App\Models\Church;
use App\Support\ScopeHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FormationController extends Controller
{
    public function index(Request $request)
    {
        $query = Formation::with(['category', 'creator', 'modules.contents'])
            ->withCount('modules');
        
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
        
        if ($request->status) {
            $query->where('status', $request->status);
        }

        $paginator = $query->orderByDesc('id')->paginate($request->per_page ?? 15);
        $user = auth()->user();

        $paginator->getCollection()->transform(function ($formation) use ($user) {
            return $this->formatFormation($formation, $user);
        });

        return response()->json($paginator);
    }

    public function store(Request $request)
    {
        // Nettoyage category_id si chaîne vide
        if ($request->category_id === '' || $request->category_id === 'null') {
            $request->merge(['category_id' => null]);
        }

        $validated = $request->validate([
            'category_id' => 'nullable|exists:resource_categories,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_free' => 'boolean',
            'price' => 'nullable|numeric|min:0',
            'status' => 'required|in:draft,published',
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

        // Si gratuit, prix = 0
        if (!empty($validated['is_free'])) {
            $validated['price'] = 0;
        }
        
        $formation = new Formation($validated);
        $formation->church_id = $churchId;
        $formation->created_by = auth()->id();
        
        if ($request->status === 'published') {
            $formation->published_at = now();
        }
        
        if ($request->hasFile('cover_image')) {
            $path = $request->file('cover_image')->store('formations/covers', 'public');
            $formation->cover_image = $path;
        }

        $formation->save();

        // Support d'initialisation de modules si fournis (ex: JSON string ou tableau)
        $createdModules = [];
        if ($request->has('modules')) {
            $modules = is_string($request->modules) ? json_decode($request->modules, true) : $request->modules;
            if (is_array($modules)) {
                foreach ($modules as $idx => $m) {
                    $title = is_array($m) ? ($m['title'] ?? '') : (string)$m;
                    if (!empty(trim($title))) {
                        $mod = $formation->modules()->create([
                            'title' => trim($title),
                            'description' => is_array($m) ? ($m['description'] ?? null) : null,
                            'order' => $idx + 1
                        ]);
                        $createdModules[$idx] = $mod;
                    }
                }
            }
        }

        // Support d'initialisation de contenus/fichiers/vidéos/audios pédagogiques
        if ($request->has('contents_meta')) {
            $contentsMeta = is_string($request->contents_meta) ? json_decode($request->contents_meta, true) : $request->contents_meta;
            if (is_array($contentsMeta) && count($contentsMeta) > 0) {
                // S'il n'y avait aucun module défini, créer un module par défaut
                if (empty($createdModules)) {
                    $defaultModule = $formation->modules()->create([
                        'title' => 'Module 1 : Contenu du cours',
                        'description' => 'Supports pédagogiques et leçons',
                        'order' => 1
                    ]);
                    $createdModules[0] = $defaultModule;
                }

                foreach ($contentsMeta as $idx => $c) {
                    $cTitle = !empty($c['title']) ? trim($c['title']) : ('Leçon ' . ($idx + 1));
                    $type = in_array($c['type'] ?? '', ['video', 'audio', 'pdf', 'document', 'quiz']) ? $c['type'] : 'document';
                    $contentData = null;

                    $fileKey = "content_file_{$idx}";
                    if ($request->hasFile($fileKey)) {
                        $contentData = $request->file($fileKey)->store('formations/contents', 'public');
                    } elseif (!empty($c['external_url'])) {
                        $contentData = trim($c['external_url']);
                    }

                    $modIndex = isset($c['module_index']) ? (int)$c['module_index'] : 0;
                    $targetModule = $createdModules[$modIndex] ?? reset($createdModules);

                    if ($targetModule) {
                        Content::create([
                            'module_id' => $targetModule->id,
                            'title' => $cTitle,
                            'type' => $type,
                            'content_data' => $contentData,
                            'duration' => !empty($c['duration']) ? trim($c['duration']) : null,
                            'is_preview' => !empty($c['is_preview']),
                            'order' => $idx + 1,
                        ]);
                    }
                }
            }
        }

        // Synchroniser file_path sur la formation si un support/contenu avec fichier existe
        $firstContent = $formation->modules()->with('contents')->get()->flatMap(function($m) { return $m->contents; })->first();
        if ($firstContent && $firstContent->content_data) {
            $formation->file_path = $firstContent->content_data;
            $formation->save();
        }

        return response()->json(
            $this->formatFormation($formation->load(['category', 'modules.contents'])->loadCount('modules'), auth()->user()),
            201
        );
    }

    public function show($id)
    {
        $formation = ScopeHelper::findOwnedOrFail(Formation::class, $id, 'church_id');
        $formation->load(['category', 'creator', 'modules.contents']);
        return response()->json($this->formatFormation($formation, auth()->user(), true));
    }

    public function update(Request $request, $id)
    {
        $formation = ScopeHelper::findOwnedOrFail(Formation::class, $id, 'church_id');

        if ($request->category_id === '' || $request->category_id === 'null') {
            $request->merge(['category_id' => null]);
        }

        $validated = $request->validate([
            'category_id' => 'nullable|exists:resource_categories,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_free' => 'boolean',
            'price' => 'nullable|numeric|min:0',
            'status' => 'required|in:draft,published',
        ]);

        if (!empty($validated['is_free'])) {
            $validated['price'] = 0;
        }

        if ($request->status === 'published' && $formation->status === 'draft') {
            $validated['published_at'] = now();
        }
        
        if ($request->hasFile('cover_image')) {
            if ($formation->cover_image) {
                Storage::disk('public')->delete($formation->cover_image);
            }
            $validated['cover_image'] = $request->file('cover_image')->store('formations/covers', 'public');
        }

        $formation->update($validated);

        // Modules additionnels lors de la modification
        if ($request->has('modules')) {
            $modules = is_string($request->modules) ? json_decode($request->modules, true) : $request->modules;
            if (is_array($modules)) {
                foreach ($modules as $idx => $m) {
                    $title = is_array($m) ? ($m['title'] ?? '') : (string)$m;
                    if (!empty(trim($title))) {
                        $alreadyExists = $formation->modules()->where('title', trim($title))->exists();
                        if (!$alreadyExists) {
                            $maxOrder = $formation->modules()->max('order') ?? 0;
                            $formation->modules()->create([
                                'title' => trim($title),
                                'description' => is_array($m) ? ($m['description'] ?? null) : null,
                                'order' => $maxOrder + 1
                            ]);
                        }
                    }
                }
            }
        }

        // Nouveaux contenus / fichiers téléversés lors de la modification
        if ($request->has('contents_meta')) {
            $contentsMeta = is_string($request->contents_meta) ? json_decode($request->contents_meta, true) : $request->contents_meta;
            if (is_array($contentsMeta) && count($contentsMeta) > 0) {
                $existingModules = $formation->modules()->orderBy('order')->get();
                if ($existingModules->isEmpty()) {
                    $defaultMod = $formation->modules()->create([
                        'title' => 'Module 1 : Contenu du cours',
                        'order' => 1
                    ]);
                    $existingModules = collect([$defaultMod]);
                }

                foreach ($contentsMeta as $idx => $c) {
                    if (!empty($c['id'])) {
                        // Contenu déjà existant en base : mise à jour des métadonnées ou remplacement de fichier si fourni
                        $existingContent = Content::find($c['id']);
                        if ($existingContent) {
                            $fileKey = "content_file_{$idx}";
                            if ($request->hasFile($fileKey)) {
                                if ($existingContent->content_data && Storage::disk('public')->exists($existingContent->content_data)) {
                                    Storage::disk('public')->delete($existingContent->content_data);
                                }
                                $existingContent->content_data = $request->file($fileKey)->store('formations/contents', 'public');
                            } elseif (!empty($c['external_url'])) {
                                $existingContent->content_data = trim($c['external_url']);
                            }
                            if (!empty($c['title'])) {
                                $existingContent->title = trim($c['title']);
                            }
                            if (!empty($c['type']) && in_array($c['type'], ['video', 'audio', 'pdf', 'document', 'quiz'])) {
                                $existingContent->type = $c['type'];
                            }
                            $existingContent->duration = !empty($c['duration']) ? trim($c['duration']) : null;
                            $existingContent->is_preview = !empty($c['is_preview']);
                            $existingContent->save();
                        }
                        continue;
                    }
                    $cTitle = !empty($c['title']) ? trim($c['title']) : ('Leçon ' . ($idx + 1));
                    $type = in_array($c['type'] ?? '', ['video', 'audio', 'pdf', 'document', 'quiz']) ? $c['type'] : 'document';
                    $contentData = null;

                    $fileKey = "content_file_{$idx}";
                    if ($request->hasFile($fileKey)) {
                        $contentData = $request->file($fileKey)->store('formations/contents', 'public');
                    } elseif (!empty($c['external_url'])) {
                        $contentData = trim($c['external_url']);
                    }

                    $modIndex = isset($c['module_index']) ? (int)$c['module_index'] : 0;
                    $targetModule = $existingModules[$modIndex] ?? $existingModules->first();

                    if ($targetModule) {
                        $maxOrder = $targetModule->contents()->max('order') ?? 0;
                        Content::create([
                            'module_id' => $targetModule->id,
                            'title' => $cTitle,
                            'type' => $type,
                            'content_data' => $contentData,
                            'duration' => !empty($c['duration']) ? trim($c['duration']) : null,
                            'is_preview' => !empty($c['is_preview']),
                            'order' => $maxOrder + 1,
                        ]);
                    }
                }
            }
        }

        // Synchroniser file_path sur la formation si un support/contenu avec fichier existe
        $firstContent = $formation->modules()->with('contents')->get()->flatMap(function($m) { return $m->contents; })->first();
        if ($firstContent && $firstContent->content_data) {
            $formation->file_path = $firstContent->content_data;
            $formation->save();
        }

        return response()->json(
            $this->formatFormation($formation->load(['category', 'modules.contents'])->loadCount('modules'), auth()->user())
        );
    }

    public function destroy($id)
    {
        $formation = ScopeHelper::findOwnedOrFail(Formation::class, $id, 'church_id');
        
        if ($formation->cover_image) {
            Storage::disk('public')->delete($formation->cover_image);
        }
        
        $formation->delete();

        return response()->json(null, 204);
    }

    /**
     * Ajouter un module à une formation.
     */
    public function addModule(Request $request, $id)
    {
        $formation = ScopeHelper::findOwnedOrFail(Formation::class, $id, 'church_id');

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'order' => 'nullable|integer',
        ]);

        if (!isset($validated['order'])) {
            $maxOrder = $formation->modules()->max('order') ?? 0;
            $validated['order'] = $maxOrder + 1;
        }

        $module = $formation->modules()->create($validated);

        return response()->json($module, 201);
    }

    /**
     * Supprimer un module d'une formation.
     */
    public function deleteModule($formationId, $moduleId)
    {
        $formation = ScopeHelper::findOwnedOrFail(Formation::class, $formationId, 'church_id');
        $module = $formation->modules()->whereKey($moduleId)->firstOrFail();
        $module->delete();

        return response()->json(['message' => 'Module supprimé avec succès.'], 200);
    }

    /**
     * Ajouter un contenu / leçon (fichier, vidéo, audio, document) à un module existant.
     */
    public function addContent(Request $request, $formationId, $moduleId)
    {
        $formation = ScopeHelper::findOwnedOrFail(Formation::class, $formationId, 'church_id');
        $module = $formation->modules()->whereKey($moduleId)->firstOrFail();

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|in:video,audio,pdf,document,quiz',
            'duration' => 'nullable|string|max:50',
            'is_preview' => 'boolean',
            'external_url' => 'nullable|url|max:1000',
            'file' => 'nullable|file|max:102400', // 100MB
        ]);

        $contentData = null;
        if ($request->hasFile('file')) {
            $contentData = $request->file('file')->store('formations/contents', 'public');
        } elseif (!empty($validated['external_url'])) {
            $contentData = $validated['external_url'];
        }

        $maxOrder = $module->contents()->max('order') ?? 0;

        $content = $module->contents()->create([
            'title' => $validated['title'],
            'type' => $validated['type'],
            'content_data' => $contentData,
            'duration' => $validated['duration'] ?? null,
            'is_preview' => !empty($validated['is_preview']),
            'order' => $maxOrder + 1,
        ]);

        $contentArr = $content->toArray();
        if ($content->content_data) {
            $contentArr['file_url'] = (str_starts_with($content->content_data, 'http://') || str_starts_with($content->content_data, 'https://'))
                ? $content->content_data
                : asset('storage/' . $content->content_data);
        } else {
            $contentArr['file_url'] = null;
        }

        return response()->json($contentArr, 201);
    }

    /**
     * Supprimer un contenu d'une formation.
     */
    public function deleteContent($formationId, $contentId)
    {
        $formation = ScopeHelper::findOwnedOrFail(Formation::class, $formationId, 'church_id');
        $content = Content::whereHas('module', function($q) use ($formation) {
            $q->where('formation_id', $formation->id);
        })->whereKey($contentId)->firstOrFail();

        if ($content->content_data && Storage::disk('public')->exists($content->content_data)) {
            Storage::disk('public')->delete($content->content_data);
        }

        $content->delete();

        return response()->json(['message' => 'Contenu supprimé avec succès.'], 200);
    }

    /**
     * Vérification d'accès :
     * Seuls le Super Admin (1) et l'Admin de l'église (2) ont un accès total sans restriction
     * qu'elle soit payante ou non.
     * Pour les fidèles, les formations payantes nécessitent une inscription active.
     */
    protected function checkAccess(Formation $formation, $user): array
    {
        if (!$user) {
            return ['can_access' => (bool)$formation->is_free, 'is_admin_bypass' => false, 'is_enrolled' => false];
        }

        $roleId = (int) $user->role_id;

        // Super Admin (1), Admin (2), Responsable (5) ou créateur de la formation
        if (in_array($roleId, [1, 2, 5]) || ((int)$formation->created_by === (int)$user->id)) {
            return ['can_access' => true, 'is_admin_bypass' => true, 'is_enrolled' => true];
        }

        // Si gratuite
        if ($formation->is_free) {
            $isEnrolled = Enrollment::where('user_id', $user->id)
                ->where('formation_id', $formation->id)
                ->exists();
            return ['can_access' => true, 'is_admin_bypass' => false, 'is_enrolled' => $isEnrolled];
        }

        // Si payante : vérifier l'inscription
        $isEnrolled = Enrollment::where('user_id', $user->id)
            ->where('formation_id', $formation->id)
            ->where('status', 'active')
            ->exists();

        return [
            'can_access' => $isEnrolled,
            'is_admin_bypass' => false,
            'is_enrolled' => $isEnrolled
        ];
    }

    /**
     * Formatage de la formation avec gestion de sécurité et URLs des contenus.
     */
    protected function formatFormation(Formation $formation, $user, bool $detailView = false)
    {
        $access = $this->checkAccess($formation, $user);
        $data = $formation->toArray();
        $data['creator'] = $formation->creator ? [
            'id' => $formation->creator->id,
            'name' => $formation->creator->name,
        ] : null;
        $data['can_access'] = $access['can_access'];
        $data['is_admin_bypass'] = $access['is_admin_bypass'];
        $data['is_enrolled'] = $access['is_enrolled'];

        if ($formation->cover_image) {
            $data['cover_url'] = asset('storage/' . $formation->cover_image);
        } else {
            $data['cover_url'] = null;
        }

        if ($formation->file_path && $access['can_access']) {
            if (str_starts_with($formation->file_path, 'http://') || str_starts_with($formation->file_path, 'https://')) {
                $data['file_url'] = $formation->file_path;
            } else {
                $data['file_url'] = asset('storage/' . $formation->file_path);
            }
        } else {
            $data['file_url'] = null;
        }

        // Traiter les contenus de chaque module (URLs et masquage si non autorisé)
        if (isset($data['modules']) && is_array($data['modules'])) {
            foreach ($data['modules'] as &$mod) {
                if (isset($mod['contents']) && is_array($mod['contents'])) {
                    foreach ($mod['contents'] as &$cnt) {
                        $isContentAllowed = $access['can_access'] || !empty($cnt['is_preview']);

                        if ($isContentAllowed && !empty($cnt['content_data'])) {
                            if (str_starts_with($cnt['content_data'], 'http://') || str_starts_with($cnt['content_data'], 'https://')) {
                                $cnt['file_url'] = $cnt['content_data'];
                            } else {
                                $cnt['file_url'] = asset('storage/' . $cnt['content_data']);
                            }
                        } else {
                            $cnt['file_url'] = null;
                            if (!$isContentAllowed) {
                                $cnt['content_data'] = null; // Masquer la vidéo / fichier
                            }
                        }
                    }
                }
            }
        }

        return $data;
    }
}
