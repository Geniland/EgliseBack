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
                'ministries'
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

            ->tap(fn($q) => ScopeHelper::applyOwnedByScope($q))

            ->latest()

            ->paginate(15);



        return MemberResource::collection($members);

    }




    /**
     * Voir un membre
     */
    public function show(Member $member)
    {

        $member->load([
            'family',
            'ministries',
            'creator',
            'updater'
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

    /**
     * Association des ministères
     */
    if ($request->has('ministries') && !empty($request->ministries)) {
        $member->ministries()->sync($request->ministries);
    }

    /**
     * Chargement des relations
     */
    $member->load(['family', 'ministries']);

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


        $data = $request->validated();



        /**
         * Nouvelle photo
         */
        if($request->hasFile('photo')){


            if($member->photo){

                Storage::disk('public')
                    ->delete($member->photo);

            }


            $data['photo'] =
                $request
                ->file('photo')
                ->store(
                    'members',
                    'public'
                );

        }




        $data['updated_by'] =
            auth()->id();



        $member->update($data);




        if($request->ministries){

            $member->ministries()
                   ->sync(
                       $request->ministries
                   );

        }



        $member->load([
            'family',
            'ministries'
        ]);



        return response()->json([

            'message'=>'Membre modifié avec succès',

            'member'=>new MemberResource($member)

        ]);

    }







    /**
     * Suppression logique
     */
    public function destroy(Member $member)
    {


        $member->delete();



        return response()->json([

            'message'=>'Membre supprimé avec succès'

        ]);

    }







    /**
     * Restaurer un membre supprimé
     */
    public function restore($id)
    {


        $member = Member::withTrashed()
                        ->findOrFail($id);



        $member->restore();



        return response()->json([

            'message'=>'Membre restauré',

            'member'=>new MemberResource($member)

        ]);

    }

}