<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMemberRequest;
use App\Http\Requests\UpdateMemberRequest;
use App\Http\Resources\MemberResource;
use App\Models\Member;
use App\Support\ScopeHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;


class MemberController extends Controller
{

    /**
     * Liste des membres
     */
    public function index(Request $request)
    {
        $members = Member::with([
                'family',
                'ministries',
                'church:id,name,code',
                'user:id,name,email,church_id'
            ])

            ->when($request->search, function ($query) use ($request) {

                $search = $request->search;

                $query->where(function ($q) use ($search) {

                    $q->where('first_name', 'LIKE', "%$search%")
                    ->orWhere('last_name', 'LIKE', "%$search%")
                    ->orWhere('member_code', 'LIKE', "%$search%")
                    ->orWhere('phone', 'LIKE', "%$search%");

                });

            })

            ->when($request->member_type, function ($query) use ($request){

                $query->where(
                    'member_type',
                    $request->member_type
                );

            })


            ->when($request->gender, function ($query) use ($request){

                $query->where(
                    'gender',
                    $request->gender
                );

            })

            ->tap(fn($q) => ScopeHelper::applyMemberScope($q))

            ->latest()

            ->paginate(15);



        return MemberResource::collection($members);

    }




    /**
     * Voir un membre
     */
    public function show(Member $member)
    {
        if (!ScopeHelper::canAccessMember($member)) {
            return response()->json([
                'message' => "Vous n'avez pas accès aux informations de ce membre."
            ], 403);
        }

        $member->load([
            'family',
            'ministries',
            'creator',
            'updater',
            'church',
            'user'
        ]);


        return new MemberResource($member);

    }





    /**
     * Création d'un membre
     */
    public function store(StoreMemberRequest $request)
    {
        // Récupérer les données validées
        $data = $request->validated();

        $user = auth()->user();
        $targetChurchId = $request->input('church_id');

        if ($targetChurchId) {
            if (!ScopeHelper::isSuperAdmin()) {
                $allowedChurches = ScopeHelper::getMyChurchIds();
                if ($user && $user->church_id) {
                    $allowedChurches[] = (int) $user->church_id;
                }
                if (!in_array((int) $targetChurchId, $allowedChurches, true)) {
                    return response()->json([
                        'message' => "Vous n'avez pas l'autorisation d'assigner un membre à cette église."
                    ], 403);
                }
            }
            $data['church_id'] = $targetChurchId;
        } else {
            $contextChurchId = ScopeHelper::getRequestedChurchContext();
            if ($contextChurchId) {
                $data['church_id'] = $contextChurchId;
            } elseif ($user && $user->church_id) {
                $data['church_id'] = $user->church_id;
            } else {
                $data['church_id'] = \App\Models\Church::where('created_by', auth()->id())->value('id');
            }
        }

        /**
         * Génération automatique du matricule
         * Format: MEM-2026000001
         */
        $year = date('Y');
        $lastMember = Member::whereYear('created_at', $year)
                            ->orderBy('id', 'desc')
                            ->first();
        
        if ($lastMember) {
            // Extraire le numéro du dernier matricule
            $lastNumber = (int) substr($lastMember->member_code, -6);
            $number = str_pad($lastNumber + 1, 6, '0', STR_PAD_LEFT);
        } else {
            $number = '000001';
        }
        
        $data['member_code'] = 'MEM-' . $year . $number;

        /**
         * Upload de la photo
         */
        if ($request->hasFile('photo')) {
            $data['photo'] = $request->file('photo')->store('members', 'public');
        }

        /**
         * Utilisateur connecté
         */
        $data['created_by'] = auth()->id();
        $data['updated_by'] = auth()->id();

        /**
         * Création du membre
         */
        $member = Member::create($data);

        try {
            $member->ensureQrToken();
        } catch (\Throwable) { /* ignore QR errors on creation */ }

        if ($member->user_id) {
            try {
                $qrDataUrl = $member->getQrCodeDataUrl(280);
                $qrPayloadJson = $member->getQrCodeDataString();

                $church = $member->church;
                $senderId = $church?->created_by ?? null;
                if (!$senderId) {
                    $senderId = \App\Models\User::where('church_id', $member->church_id)
                        ->whereIn('role_id', [1, 2, 5])
                        ->orderBy('role_id', 'asc')
                        ->value('id');
                }
                if (!$senderId) {
                    $senderId = (int) auth()->id() ?: 1;
                }

                $churchName = $church?->name ?: config('app.name', 'Notre église');
                $welcomeMessage = "👋 Bienvenue {$member->first_name} {$member->last_name} !\n\n"
                    . "Votre compte membre a été ajouté dans l'église **{$churchName}**.\n\n"
                    . "🎫 **Votre code membre :** {$member->member_code}\n\n"
                    . "📱 **Votre QR Code personnel de présence est ci-joint.**\n"
                    . "Présentez-le lors des cultes et réunions pour être marqué(e) présent(e) automatiquement.\n\n"
                    . "Vous pouvez aussi le retrouver à tout moment dans votre profil ou dans la section \"Mon QR Code\".";

                $fullMessage = $welcomeMessage
                    . "\n\n---QR_CODE_DATA---\n"
                    . $qrPayloadJson
                    . "\n---QR_CODE_IMAGE---\n"
                    . $qrDataUrl;

                \App\Models\ChatMessage::create([
                    'church_id' => $member->church_id,
                    'sender_id' => $senderId,
                    'recipient_id' => $member->user_id,
                    'contenu' => $fullMessage,
                    'lu' => false,
                ]);
            } catch (\Throwable) { /* ignore chat / QR errors, the member is still created */ }
        }

        /**
         * Association des ministères
         */
        if ($request->has('ministries') && !empty($request->ministries)) {
            $member->ministries()->sync($request->ministries);
        }

        /**
         * Chargement des relations
         */
        $member->load(['family', 'ministries', 'church']);

        /**
         * Réponse
         */
        return response()->json([
            'message' => 'Membre créé avec succès',
            'member' => new MemberResource($member)
        ], 201);
    }






    /**
     * Modification d'un membre
     */
    public function update(
        UpdateMemberRequest $request,
        Member $member
    )
    {
        if (!ScopeHelper::canAccessMember($member)) {
            return response()->json([
                'message' => "Vous n'avez pas l'autorisation de modifier ce membre."
            ], 403);
        }

        $data = $request->validated();

        if ($request->filled('church_id') && (int) $request->church_id !== (int) $member->church_id) {
            if (!ScopeHelper::isSuperAdmin()) {
                $allowedChurches = ScopeHelper::getMyChurchIds();
                if (auth()->user() && auth()->user()->church_id) {
                    $allowedChurches[] = (int) auth()->user()->church_id;
                }
                if (!in_array((int) $request->church_id, $allowedChurches, true)) {
                    return response()->json([
                        'message' => "Vous ne pouvez pas déplacer ce membre vers cette église."
                    ], 403);
                }
            }
        }



        /**
         * Nouvelle photo
         */
        if ($request->boolean('remove_photo')) {
            if ($member->photo && Storage::disk('public')->exists($member->photo)) {
                Storage::disk('public')->delete($member->photo);
            }
            $data['photo'] = null;
        } elseif ($request->hasFile('photo')) {
            if ($member->photo && Storage::disk('public')->exists($member->photo)) {
                Storage::disk('public')->delete($member->photo);
            }

            $data['photo'] = $request->file('photo')->store('members', 'public');
        }

        $data['updated_by'] = auth()->id();

        $member->update($data);

        // Synchroniser le compte utilisateur lié si présent
        if ($member->user_id && $member->user) {
            $userUpdates = [];
            if (isset($data['first_name']) || isset($data['last_name'])) {
                $userUpdates['name'] = ($data['first_name'] ?? $member->first_name) . ' ' . ($data['last_name'] ?? $member->last_name);
            }
            if (isset($data['email']) && $data['email'] !== $member->user->email) {
                $userUpdates['email'] = $data['email'];
            }
            if (isset($data['phone'])) {
                $userUpdates['phone'] = $data['phone'];
            }
            if (isset($data['status'])) {
                $userUpdates['status'] = (bool) $data['status'];
            }
            if (isset($data['church_id'])) {
                $userUpdates['church_id'] = $data['church_id'];
            }
            if (!empty($userUpdates)) {
                $member->user->update($userUpdates);
            }
        }

        if ($request->ministries) {
            $member->ministries()->sync($request->ministries);
        }

        $member->load([
            'family',
            'ministries',
            'church',
            'user'
        ]);

        return response()->json([
            'message' => 'Membre modifié avec succès',
            'member' => new MemberResource($member)
        ]);
    }

    /**
     * Activer / désactiver le statut et l'accès d'un membre
     */
    public function toggleStatus(Member $member)
    {
        if (!ScopeHelper::canAccessMember($member)) {
            return response()->json([
                'message' => "Vous n'avez pas l'autorisation de modifier ce membre."
            ], 403);
        }

        $member->status = !$member->status;
        $member->updated_by = auth()->id();
        $member->save();

        if ($member->user_id && $member->user) {
            $member->user->status = $member->status;
            $member->user->save();
        }

        $member->load(['family', 'ministries', 'church', 'user']);

        return response()->json([
            'message' => $member->status ? 'Membre activé avec succès' : 'Membre désactivé avec succès',
            'status' => $member->status,
            'member' => new MemberResource($member)
        ]);
    }

    /**
     * Suppression logique
     */
    public function destroy(Member $member)
    {
        if (!ScopeHelper::canAccessMember($member)) {
            return response()->json([
                'message' => "Vous n'avez pas l'autorisation de supprimer ce membre."
            ], 403);
        }

        $member->delete();

        return response()->json([
            'message' => 'Membre supprimé avec succès'
        ]);
    }

    /**
     * Restaurer un membre supprimé
     */
    public function restore($id)
    {
        $member = Member::withTrashed()->findOrFail($id);

        if (!ScopeHelper::canAccessMember($member)) {
            return response()->json([
                'message' => "Vous n'avez pas l'autorisation de restaurer ce membre."
            ], 403);
        }

        $member->restore();

        return response()->json([
            'message' => 'Membre restauré',
            'member' => new MemberResource($member)
        ]);
    }

    public function getQrCode(Request $request, Member $member)
    {
        if (!ScopeHelper::canAccessMember($member)
            && (auth()->check() && (int)($member->user_id ?? 0) !== (int)auth()->id())
        ) {
            return response()->json([
                'message' => "Vous n'avez pas accès aux informations de ce membre."
            ], 403);
        }

        $size = (int)($request->size ?? 280);
        $size = max(120, min(800, $size));

        $member->ensureQrToken();

        return response()->json([
            'member' => [
                'id' => $member->id,
                'first_name' => $member->first_name,
                'last_name' => $member->last_name,
                'member_code' => $member->member_code,
                'qr_token' => $member->qr_token,
            ],
            'qr_payload' => $member->getQrPayload(),
            'qr_data_string' => $member->getQrCodeDataString(),
            'qr_data_url' => $member->getQrCodeDataUrl($size),
            'size' => $size,
        ]);
    }

    public function myQrCode(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }
        $member = $user->member;
        if (!$member) {
            return response()->json(['message' => 'Aucun profil membre rattaché à votre compte.'], 404);
        }
        return $this->getQrCode($request, $member);
    }
}