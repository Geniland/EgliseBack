<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ChatSetting;
use App\Models\User;
use App\Support\ScopeHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ChatController extends Controller
{
    /**
     * Liste des contacts disponibles pour la messagerie.
     * Cloisonné selon le rôle et l'église de l'utilisateur.
     */
    public function getContacts(Request $request)
    {
        $user = Auth::user();
        $userId = $user->id;
        $roleId = (int) $user->role_id;

        // Récupération des IDs d'utilisateurs avec lesquels une conversation existe déjà
        $conversationPartnerIds = ChatMessage::where('sender_id', $userId)
            ->pluck('recipient_id')
            ->merge(ChatMessage::where('recipient_id', $userId)->pluck('sender_id'))
            ->unique()
            ->values();

        // Récupération des églises accessibles
        $churchIds = [];
        if ($user->church_id) {
            $churchIds[] = (int) $user->church_id;
        }
        $myChurchIds = ScopeHelper::getMyChurchIds();
        if (!empty($myChurchIds)) {
            $churchIds = array_merge($churchIds, $myChurchIds);
        }
        $churchIds = array_values(array_unique(array_filter($churchIds)));

        // Construction de la requête pour les contacts
        $query = User::with(['role', 'church'])
            ->where('id', '!=', $userId);

        if (!ScopeHelper::isSuperAdmin()) {
            if ($roleId === 6) {
                // Fidèle : voit les responsables (5), administrateurs (2), secrétaires (3) de son église + ses interlocuteurs actuels
                $query->where(function ($q) use ($churchIds, $conversationPartnerIds) {
                    $q->where(function ($sub) use ($churchIds) {
                        if (!empty($churchIds)) {
                            $sub->whereIn('church_id', $churchIds);
                        }
                        $sub->whereIn('role_id', [1, 2, 3, 5]); // Staff église
                    })->orWhereIn('id', $conversationPartnerIds);
                });
            } else {
                // Responsables et Admins : voient tous les membres de leur(s) église(s) + interlocuteurs existants
                $query->where(function ($q) use ($churchIds, $conversationPartnerIds) {
                    if (!empty($churchIds)) {
                        $q->whereIn('church_id', $churchIds);
                    }
                    $q->orWhereIn('id', $conversationPartnerIds);
                });
            }
        }

        $contacts = $query->get();

        // Enrichir les contacts avec le dernier message et le nombre de non-lus
        $enriched = $contacts->map(function ($contact) use ($userId) {
            $lastMessage = ChatMessage::where(function ($q) use ($userId, $contact) {
                $q->where(function ($sub) use ($userId, $contact) {
                    $sub->where('sender_id', $userId)->where('recipient_id', $contact->id);
                })->orWhere(function ($sub) use ($userId, $contact) {
                    $sub->where('sender_id', $contact->id)->where('recipient_id', $userId);
                });
            })->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })->orderBy('created_at', 'desc')->first();

            $unreadCount = ChatMessage::where('sender_id', $contact->id)
                ->where('recipient_id', $userId)
                ->where('lu', false)
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->count();

            return [
                'id' => $contact->id,
                'name' => $contact->name,
                'email' => $contact->email,
                'phone' => $contact->phone,
                'initiales' => $contact->initiales,
                'role_id' => $contact->role_id,
                'role_name' => optional($contact->role)->name ?? 'Membre',
                'church_id' => $contact->church_id,
                'church_name' => optional($contact->church)->name ?? $contact->church_name,
                'unread_count' => $unreadCount,
                'last_message' => $lastMessage ? [
                    'id' => $lastMessage->id,
                    'contenu' => $lastMessage->contenu,
                    'created_at' => $lastMessage->created_at->toIso8601String(),
                    'is_mine' => (int) $lastMessage->sender_id === (int) $userId,
                ] : null,
            ];
        });

        // Trier par date du dernier message, les plus récents en premier
        $sorted = $enriched->sort(function ($a, $b) {
            $dateA = $a['last_message'] ? $a['last_message']['created_at'] : '';
            $dateB = $b['last_message'] ? $b['last_message']['created_at'] : '';
            return strcmp($dateB, $dateA);
        })->values();

        return response()->json($sorted);
    }

    /**
     * Historique des messages d'une conversation.
     * Purge automatique des messages éphémères expirés et marquage des messages comme lus.
     */
    public function getMessages($otherUserId)
    {
        $userId = Auth::id();
        $otherUser = User::with(['role', 'church'])->findOrFail($otherUserId);

        // 1. Purge des messages expirés entre ces deux utilisateurs
        ChatMessage::whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->where(function ($q) use ($userId, $otherUserId) {
                $q->where(function ($sub) use ($userId, $otherUserId) {
                    $sub->where('sender_id', $userId)->where('recipient_id', $otherUserId);
                })->orWhere(function ($sub) use ($userId, $otherUserId) {
                    $sub->where('sender_id', $otherUserId)->where('recipient_id', $userId);
                });
            })
            ->delete();

        // 2. Marquer les messages reçus de cet utilisateur comme lus
        ChatMessage::where('sender_id', $otherUserId)
            ->where('recipient_id', $userId)
            ->where('lu', false)
            ->update(['lu' => true]);

        // 3. Récupérer les messages valides
        $messages = ChatMessage::where(function ($q) use ($userId, $otherUserId) {
            $q->where(function ($sub) use ($userId, $otherUserId) {
                $sub->where('sender_id', $userId)->where('recipient_id', $otherUserId);
            })->orWhere(function ($sub) use ($userId, $otherUserId) {
                $sub->where('sender_id', $otherUserId)->where('recipient_id', $userId);
            });
        })
        ->where(function ($q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        })
        ->orderBy('created_at', 'asc')
        ->get();

        // 4. Paramètre de durée éphémère
        $setting = ChatSetting::getSettingBetween($userId, (int) $otherUserId);

        return response()->json([
            'contact' => [
                'id' => $otherUser->id,
                'name' => $otherUser->name,
                'email' => $otherUser->email,
                'phone' => $otherUser->phone,
                'initiales' => $otherUser->initiales,
                'role_name' => optional($otherUser->role)->name ?? 'Membre',
                'church_name' => optional($otherUser->church)->name ?? $otherUser->church_name,
            ],
            'messages' => $messages->map(function ($m) use ($userId) {
                return [
                    'id' => $m->id,
                    'sender_id' => $m->sender_id,
                    'recipient_id' => $m->recipient_id,
                    'contenu' => $m->contenu,
                    'lu' => (bool) $m->lu,
                    'expires_at' => $m->expires_at ? $m->expires_at->toIso8601String() : null,
                    'created_at' => $m->created_at->toIso8601String(),
                    'is_mine' => (int) $m->sender_id === (int) $userId,
                ];
            }),
            'ephemeral_duration' => $setting?->duree ?? null,
        ]);
    }

    /**
     * Envoyer un nouveau message à un utilisateur.
     */
    public function sendMessage(Request $request)
    {
        $request->validate([
            'recipient_id' => 'required|exists:users,id',
            'contenu' => 'required|string|max:5000',
        ]);

        $user = Auth::user();
        $recipientId = (int) $request->recipient_id;

        // Déterminer si une durée éphémère est activée
        $setting = ChatSetting::getSettingBetween($user->id, $recipientId);
        $expiresAt = null;

        if ($setting && $setting->duree) {
            $expiresAt = match ($setting->duree) {
                '24h' => now()->addHours(24),
                '7j' => now()->addDays(7),
                '1mois' => now()->addMonth(),
                default => null,
            };
        }

        $message = ChatMessage::create([
            'church_id' => $user->church_id,
            'sender_id' => $user->id,
            'recipient_id' => $recipientId,
            'contenu' => $request->contenu,
            'lu' => false,
            'expires_at' => $expiresAt,
        ]);

        return response()->json([
            'id' => $message->id,
            'sender_id' => $message->sender_id,
            'recipient_id' => $message->recipient_id,
            'contenu' => $message->contenu,
            'lu' => false,
            'expires_at' => $message->expires_at ? $message->expires_at->toIso8601String() : null,
            'created_at' => $message->created_at->toIso8601String(),
            'is_mine' => true,
        ], 201);
    }

    /**
     * Configurer la durée des messages éphémères pour une conversation.
     */
    public function setDuration(Request $request)
    {
        $request->validate([
            'contact_id' => 'required|exists:users,id',
            'duree' => 'nullable|in:24h,7j,1mois',
        ]);

        $userId = Auth::id();
        $contactId = (int) $request->contact_id;
        $duree = $request->duree ?: null;

        $setting = ChatSetting::setDurationBetween($userId, $contactId, $duree);

        return response()->json([
            'message' => 'Durée des messages éphémères mise à jour',
            'duree' => $setting->duree,
        ]);
    }

    /**
     * Obtenir le total global de messages non lus pour l'utilisateur.
     */
    public function getUnreadCount()
    {
        $userId = Auth::id();

        $unreadCount = ChatMessage::where('recipient_id', $userId)
            ->where('lu', false)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->count();

        return response()->json(['unread_count' => $unreadCount]);
    }
}
