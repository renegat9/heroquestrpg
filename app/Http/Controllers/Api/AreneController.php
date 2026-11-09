<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\EtatGroupeDiffuse;
use App\Http\Controllers\Api\Concerns\AutoriseLectureGroupe;
use App\Http\Controllers\Controller;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Partie\Arene\GroupesArene;
use App\Partie\Arene\RefusArene;
use App\Partie\EtatGroupe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * MODE ARÈNE, lot A5 — les routes du salon (`docs/contrat-api.md` §Le salon et les routes d'arène). Toute la règle
 * vit dans `GroupesArene` ; ce contrôleur authentifie, valide la forme et diffuse l'état.
 */
class AreneController extends Controller
{
    use AutoriseLectureGroupe;

    public function __construct(
        private readonly GroupesArene $arenes,
        private readonly EtatGroupe $etatGroupe,
    ) {}

    /** POST /api/arene {personnage_id} — ouvre un combat et y engage SON héros d'arène. */
    public function ouvrir(Request $request): JsonResponse
    {
        $heros = $this->herosDuJoueur($request);
        $arene = $this->arenes->ouvrir();
        $doublure = $this->arenes->engager($arene, $heros);

        $this->diffuser($arene);

        return response()->json(['groupe' => ['identifiant' => $arene->identifiant], 'doublure_id' => $doublure->id], 201);
    }

    /** GET /api/arene/palmares — les derniers combats d'arène (Coop), du plus récent au plus ancien. */
    public function palmares(): JsonResponse
    {
        return response()->json(['combats' => app(\App\Partie\Arene\PalmaresArene::class)->palmares()]);
    }

    /** POST /api/arene/{identifiant}/combattants {personnage_id} — engage SON héros d'arène. */
    public function rejoindre(Request $request, string $identifiant): JsonResponse
    {
        $arene = $this->arene($identifiant);
        $doublure = $this->arenes->engager($arene, $this->herosDuJoueur($request));

        $this->diffuser($arene);

        return response()->json(['groupe' => ['identifiant' => $arene->identifiant], 'doublure_id' => $doublure->id], 201);
    }

    /** PUT /api/arene/{identifiant}/defender {monstre_ids} — membre OU table. */
    public function defender(Request $request, string $identifiant): JsonResponse
    {
        $arene = $this->areneLisible($request, $identifiant);
        $donnees = $request->validate([
            'monstre_ids' => ['required', 'array', 'min:1', 'max:'.GroupesArene::DEFENDER_MAX],
            'monstre_ids.*' => ['integer'],
        ]);

        $this->arenes->choisirDefender($arene, $donnees['monstre_ids']);

        return $this->etat($arene);
    }

    /** POST /api/arene/{identifiant}/pret {pret} — la doublure du joueur connecté. */
    public function pret(Request $request, string $identifiant): JsonResponse
    {
        $arene = $this->arene($identifiant);
        $donnees = $request->validate(['pret' => ['required', 'boolean']]);
        $joueur = Auth::guard('joueur')->user();

        $doublure = Personnage::query()
            ->whereNotNull('doublure_de')
            ->where('groupe_actif_id', $arene->id)
            ->where('joueur_id', $joueur->id)
            ->first();

        if ($doublure === null) {
            throw ValidationException::withMessages(['arene' => 'Vous n\'avez aucun combattant dans cette arène.']);
        }

        $this->arenes->marquerPret($arene, $doublure, (bool) $donnees['pret']);

        return $this->etat($arene);
    }

    /** POST /api/arene/{identifiant}/lancer — membre OU table. */
    public function lancer(Request $request, string $identifiant): JsonResponse
    {
        $arene = $this->areneLisible($request, $identifiant);
        $quete = $this->arenes->lancer($arene);

        return response()->json(['quete' => ['id' => $quete->id, 'etat' => $quete->etat]], 201);
    }

    /** POST /api/arene/{identifiant}/rejouer — membre OU table. */
    public function rejouer(Request $request, string $identifiant): JsonResponse
    {
        $arene = $this->areneLisible($request, $identifiant);
        $this->arenes->rejouer($arene);

        return $this->etat($arene);
    }

    /** DELETE /api/arene/{identifiant} — membre OU table : quitte et purge le combat. */
    public function quitter(Request $request, string $identifiant): Response
    {
        $arene = $this->areneLisible($request, $identifiant);
        $this->arenes->purger($arene);

        return response()->noContent();
    }

    private function herosDuJoueur(Request $request): Personnage
    {
        $donnees = $request->validate(['personnage_id' => ['required', 'integer']]);
        $joueur = Auth::guard('joueur')->user();

        $heros = Personnage::query()
            ->horsDoublures()
            ->where('id', $donnees['personnage_id'])
            ->where('joueur_id', $joueur->id)
            ->first();

        if ($heros === null) {
            throw ValidationException::withMessages(['personnage_id' => 'Ce héros n\'existe pas ou ne vous appartient pas.']);
        }

        if (! $heros->estHerosArene()) {
            throw ValidationException::withMessages(['personnage_id' => "« {$heros->nom} » est un héros de campagne : seul un héros d'arène combat en arène."]);
        }

        return $heros;
    }

    private function arene(string $identifiant): Groupe
    {
        $groupe = Groupe::where('identifiant', $identifiant)->firstOrFail();

        if (! $groupe->estArene()) {
            throw RefusArene::pasUnGroupeDArene($groupe);
        }

        return $groupe;
    }

    private function areneLisible(Request $request, string $identifiant): Groupe
    {
        $groupe = $this->groupeLisible($request, $identifiant);

        if (! $groupe->estArene()) {
            throw RefusArene::pasUnGroupeDArene($groupe);
        }

        return $groupe;
    }

    private function etat(Groupe $arene): JsonResponse
    {
        $payload = $this->diffuser($arene);

        return response()->json($payload);
    }

    /** @return array<string, mixed> */
    private function diffuser(Groupe $arene): array
    {
        $payload = $this->etatGroupe->payload($arene->refresh());
        broadcast(new EtatGroupeDiffuse($arene, $payload));

        return $payload;
    }
}
