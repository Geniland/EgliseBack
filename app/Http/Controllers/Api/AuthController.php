<?php

namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;


class AuthController extends Controller
{

    public function register(Request $request)
    {

        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'gender' => 'required|in:Homme,Femme',
            'church_code' => 'required|string',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:8',
        ]);

        $church = \App\Models\Church::where('code', $request->church_code)->first();
        if (!$church) {
            return response()->json([
                'message' => 'Code d\'église invalide.'
            ], 400);
        }

        $fideleRole = \App\Models\Role::where('name', 'Fidèle')->first();
        $fideleFonction = \App\Models\Fonction::where('name', 'Fidèle')->first();

        DB::beginTransaction();
        try {
            $user = User::create([
                'name' => $request->first_name . ' ' . $request->last_name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role_id' => $fideleRole ? $fideleRole->id : 6,
                'fonction_id' => $fideleFonction ? $fideleFonction->id : 9,
                'status' => true,
                'church_id' => $church->id,
                'church_name' => $church->name,
            ]);

            $year = date('Y');
            $lastMember = \App\Models\Member::whereYear('created_at', $year)
                                ->orderBy('id', 'desc')
                                ->first();

            if ($lastMember) {
                $lastNumber = (int) substr($lastMember->member_code, -6);
                $number = str_pad($lastNumber + 1, 6, '0', STR_PAD_LEFT);
            } else {
                $number = '000001';
            }

            $member_code = 'MEM-' . $year . $number;

            $member = \App\Models\Member::create([
                'church_id' => $church->id,
                'user_id' => $user->id,
                'member_code' => $member_code,
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'gender' => $request->gender,
                'email' => $request->email,
                'member_type' => 'Visiteur',
                'status' => true,
            ]);

            $member->ensureQrToken();
            $qrDataUrl = $member->getQrCodeDataUrl(280);
            $qrPayloadJson = $member->getQrCodeDataString();

            $senderId = $church->created_by ?? null;
            if (!$senderId) {
                $senderId = \App\Models\User::where('church_id', $church->id)
                    ->whereIn('role_id', [1, 2, 5])
                    ->orderBy('role_id', 'asc')
                    ->value('id');
            }
            if (!$senderId) {
                $senderId = 1;
            }

            $welcomeMessage = "👋 Bienvenue {$member->first_name} {$member->last_name} !\n\n"
                . "Votre compte a été créé avec succès dans l'église **{$church->name}**.\n\n"
                . "🎫 **Votre code membre :** {$member_code}\n\n"
                . "📱 **Votre QR Code personnel de présence est ci-joint.**\n"
                . "Présentez-le lors des cultes et réunions pour être marqué(e) présent(e) automatiquement.\n\n"
                . "Vous pouvez aussi le retrouver à tout moment dans votre profil ou dans la section \"Mon QR Code\".";

            $fullMessage = $welcomeMessage
                . "\n\n---QR_CODE_DATA---\n"
                . $qrPayloadJson
                . "\n---QR_CODE_IMAGE---\n"
                . $qrDataUrl;

            \App\Models\ChatMessage::create([
                'church_id' => $church->id,
                'sender_id' => $senderId,
                'recipient_id' => $user->id,
                'contenu' => $fullMessage,
                'lu' => false,
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Compte créé avec succès',
            'user' => $this->withPermissions($user->load(['role', 'fonction', 'member'])),
            'token' => $token,
            'member_id' => $member->id,
            'qr_code' => [
                'qr_token' => $member->qr_token,
                'payload' => $member->getQrPayload(),
                'qr_data_url' => $qrDataUrl,
            ],
        ], 201);

    }

    public function login(Request $request)
{

    $request->validate([

        'email'=>'required|email',

        'password'=>'required'

    ]);


    if(!Auth::attempt($request->only('email','password')))
    {

        return response()->json([

            'message'=>'Email ou mot de passe incorrect'

        ],401);

    }


    $user = Auth::user();

    if (!$user->status) {
        Auth::logout();
        return response()->json([
            'message' => 'Ce compte a été désactivé. Veuillez contacter le Super Administrateur.'
        ], 403);
    }

    $this->withPermissions($user->load(['role', 'fonction', 'member']));

    $token = $user->createToken('auth_token')
                  ->plainTextToken;


    return response()->json([

        'message'=>'Connexion réussie',

        'user'=>$user,

        'token'=>$token,
        
        'member_id'=>$user->member ? $user->member->id : null

    ]);

}

public function me(Request $request)
{
    return response()->json($this->withPermissions(
        $request->user()->load(['role', 'fonction', 'member'])
    ));
}

private function withPermissions(User $user): User
{
    $user->setAttribute(
        'permissions',
        $user->role ? $user->role->permissions()->pluck('name')->values()->all() : []
    );

    return $user;
}

public function logout(Request $request)
{

    $request->user()
            ->currentAccessToken()
            ->delete();


    return response()->json([

        'message'=>'Déconnexion réussie'

    ]);

}

}
