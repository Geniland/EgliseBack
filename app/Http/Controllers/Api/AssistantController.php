<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AssistantController extends Controller
{
    /**
     * Liste des conversations de l'utilisateur connecté.
     */
    public function index()
    {
        $conversations = AssistantConversation::where('user_id', Auth::id())
            ->withCount('messages')
            ->orderBy('updated_at', 'desc')
            ->get();

        return response()->json($conversations);
    }

    /**
     * Créer une nouvelle conversation.
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        $conversation = AssistantConversation::create([
            'user_id' => $user->id,
            'church_id' => $user->church_id,
            'titre' => $request->titre ?: 'Nouvelle discussion',
        ]);

        return response()->json($conversation, 201);
    }

    /**
     * Détails d'une conversation avec son historique de messages.
     */
    public function show($id)
    {
        $conversation = AssistantConversation::where('user_id', Auth::id())
            ->findOrFail($id);

        $messages = $conversation->messages()
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json([
            'conversation' => $conversation,
            'messages' => $messages,
        ]);
    }

    /**
     * Envoyer un message dans la conversation et obtenir la réponse IA.
     */
    public function sendMessage(Request $request, $id)
    {
        $conversation = AssistantConversation::where('user_id', Auth::id())
            ->findOrFail($id);

        $request->validate([
            'contenu' => 'required|string|max:4000',
        ]);

        // Enregistrement du message utilisateur
        $userMessage = AssistantMessage::create([
            'assistant_conversation_id' => $conversation->id,
            'expediteur' => 'utilisateur',
            'contenu' => $request->contenu,
        ]);

        // Mise à jour automatique du titre si c'est le premier message
        if ($conversation->titre === 'Nouvelle discussion' || empty($conversation->titre)) {
            $conversation->update([
                'titre' => Str::limit($request->contenu, 45),
            ]);
        }

        // Appel à l'IA Gemini
        $reponseIA = $this->callGemini($conversation);

        // Enregistrement de la réponse IA
        $assistantMessage = AssistantMessage::create([
            'assistant_conversation_id' => $conversation->id,
            'expediteur' => 'assistant',
            'contenu' => $reponseIA,
        ]);

        $conversation->touch();

        return response()->json([
            'user_message' => $userMessage,
            'assistant_message' => $assistantMessage,
            'conversation' => $conversation->fresh(),
        ]);
    }

    /**
     * Supprimer une conversation.
     */
    public function destroy($id)
    {
        $conversation = AssistantConversation::where('user_id', Auth::id())
            ->findOrFail($id);

        $conversation->delete();

        return response()->json([
            'message' => 'Discussion supprimée avec succès',
        ]);
    }

    /**
     * Communication avec l'API Google Gemini.
     */
    private function callGemini(AssistantConversation $conversation): string
    {
        $apiKey = env('GEMINI_API_KEY');

        if (empty($apiKey)) {
            return "L'assistant IA n'est pas encore configuré (clé API manquante). Veuillez contacter l'administrateur.";
        }

        // Construire l'historique complet pour préserver le contexte
        $messages = $conversation->messages()
            ->orderBy('created_at', 'asc')
            ->get();

        $contents = [];
        foreach ($messages as $msg) {
            $contents[] = [
                'role' => $msg->expediteur === 'utilisateur' ? 'user' : 'model',
                'parts' => [
                    ['text' => $msg->contenu],
                ],
            ];
        }

        $systemPrompt = "Tu es un assistant spirituel bienveillant et sage au sein d'une communauté chrétienne. "
            . "Tu aides les fidèles, responsables et pasteurs dans leurs réflexions spirituelles, questions bibliques, "
            . "prières, encouragements et méditations quotidiennes. Réponds avec douceur, profondeur, respect et foi, "
            . "en citant des passages bibliques pertinents lorsque cela enrichit la réponse.";

        // Modèles Google Gemini opérationnels par ordre de priorité
        $models = [
            'gemini-3.5-flash',
            'gemini-flash-latest',
            'gemini-3.5-flash-lite',
            'gemini-flash-lite-latest',
            'gemini-3.7-flash',
            'gemini-3.6-flash',
        ];

        foreach ($models as $model) {
            try {
                $response = Http::timeout(15)->post(
                    "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}",
                    [
                        'system_instruction' => [
                            'parts' => [
                                ['text' => $systemPrompt],
                            ],
                        ],
                        'contents' => $contents,
                        'generationConfig' => [
                            'temperature' => 0.7,
                            'maxOutputTokens' => 1500,
                        ],
                    ]
                );

                if ($response->successful()) {
                    $text = $response->json('candidates.0.content.parts.0.text');
                    if (!empty($text)) {
                        return trim($text);
                    }
                } else {
                    \Illuminate\Support\Facades\Log::warning("Gemini model {$model} returned {$response->status()}: " . substr($response->body(), 0, 150));
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Gemini model {$model} error: " . $e->getMessage());
                // Essayer le modèle suivant
                continue;
            }
        }

        return "Désolé, je rencontre une difficulté technique temporaire pour répondre. N'hésitez pas à reformuler votre question ou à réessayer dans un instant.";
    }
}
