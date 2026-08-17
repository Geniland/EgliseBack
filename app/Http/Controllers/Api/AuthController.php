<?php

namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;


class AuthController extends Controller
{

    public function register(Request $request)
    {

        $request->validate([

            'name'=>'required|string',

            'email'=>'required|email|unique:users',

            'password'=>'required|min:8',

        ]);

        $fideleRole = \App\Models\Role::where('name', 'Fidèle')->first();
        $fideleFonction = \App\Models\Fonction::where('name', 'Fidèle')->first();

        $user = User::create([

            'name'=>$request->name,

            'email'=>$request->email,

            'password'=>Hash::make($request->password),

            'role_id' => $fideleRole ? $fideleRole->id : 6,
            'fonction_id' => $fideleFonction ? $fideleFonction->id : 9,
            'status' => true,

        ]);


        $token = $user->createToken('auth_token')
                      ->plainTextToken;


        return response()->json([

            'message'=>'Utilisateur créé avec succès',

            'user'=>$user->load(['role', 'fonction']),

            'token'=>$token

        ],201);

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

    $user->load(['role', 'fonction']);

    $token = $user->createToken('auth_token')
                  ->plainTextToken;


    return response()->json([

        'message'=>'Connexion réussie',

        'user'=>$user,

        'token'=>$token

    ]);

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