<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Engine\Combat;
use App\Engine\Des\LanceurDes;
use App\Engine\JetCompetence;
use App\Engine\TypeFigurine;
use App\Events\MjReflechit;
use App\Http\Controllers\Controller;
use App\Jobs\GenererMenu;
use App\Jobs\GenererNarration;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Support\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Réception d'un choix de menu (doc 11 §4, flux d'un tour) :
 *
 *  1. le téléphone envoie l'option choisie ;
 *  2. l'API VALIDE via le moteur (l'option est-elle légale / exécutable ?) ;
 *  3. si jet / attaque : le MOTEUR résout (déterministe), met à jour l'état
 *     vivant et journalise ;
 *  4. dispatch des jobs IA (narration + menu suivant) — rien ne bloque ;
 *  5. la suite arrive par Reverb (« le MJ réfléchit… » pendant le job).
 *
 * Le résultat moteur est aussi renvoyé immédiatement au téléphone (echo)
 * pour afficher les dés sans attendre la narration.
 *
 * TODO (garde-fou strict, doc 08 §2) : conserver le dernier menu proposé
 * par joueur pour n'accepter QUE ses options ; en attendant, la légalité
 * est vérifiée structurellement (bornes du jet, cible active…).
 */
class ChoixController extends Controller
{
    public function __construct(private readonly LanceurDes $des) {}

    /** POST /api/groupes/{identifiant}/choix */
    public function choisir(Request $request, string $identifiant): JsonResponse
    {
        $groupe = Groupe::where('identifiant', $identifiant)->firstOrFail();
        $joueur = Auth::guard('joueur')->user();

        $donnees = $request->validate([
            'personnage_id' => ['required', 'integer'],
            'option' => ['required', 'array'],
            'option.id' => ['required', 'string', 'max:64'],
            'option.libelle' => ['nullable', 'string', 'max:200'],
            'option.type' => ['required', Rule::in(['action', 'dialogue', 'jet', 'attaque', 'attente'])],
            'option.jet' => ['required_if:option.type,jet', 'array'],
            'option.jet.attribut' => ['required_with:option.jet', Rule::in(['body', 'mind'])],
            'option.jet.difficulte' => ['required_with:option.jet', 'integer', 'min:1', 'max:4'],
            'option.cible_id' => ['required_if:option.type,attaque', 'integer'],
        ]);

        $personnage = $this->personnageLegal($groupe, (int) $joueur->id, (int) $donnees['personnage_id']);
        $option = $donnees['option'];
        $acteur = ['type' => 'personnage', 'id' => $personnage->id, 'nom' => $personnage->nom];

        // Le choix lui-même entre au journal (source de vérité rejouable).
        Journal::ajouter($groupe, 'choix', [
            'option_id' => $option['id'],
            'libelle' => $option['libelle'] ?? null,
            'type' => $option['type'],
        ], $acteur);

        // Résolution déterministe par le moteur (jamais par l'IA).
        $resultatMoteur = match ($option['type']) {
            'jet' => $this->resoudreJet($groupe, $personnage, $option, $acteur),
            'attaque' => $this->resoudreAttaque($groupe, $personnage, $option, $acteur),
            default => [
                'type' => $option['type'],
                'option_id' => $option['id'],
                'libelle' => $option['libelle'] ?? null,
            ],
        };

        // Suite du tour en jobs : narration puis nouveau menu (doc 11 §4).
        broadcast(new MjReflechit($groupe->id, true));
        GenererNarration::dispatch($groupe->id, $resultatMoteur);
        GenererMenu::dispatch($groupe->id, (int) $joueur->id, $personnage->id);

        return response()->json(['resultat' => $resultatMoteur]);
    }

    /**
     * Le personnage appartient-il au joueur ET est-il actif dans ce groupe ?
     */
    private function personnageLegal(Groupe $groupe, int $joueurId, int $personnageId): Personnage
    {
        $personnage = $groupe->personnages()
            ->wherePivot('actif', true)
            ->where('personnages.id', $personnageId)
            ->where('joueur_id', $joueurId)
            ->first();

        if ($personnage === null) {
            throw ValidationException::withMessages([
                'personnage_id' => 'Ce personnage n\'est pas un héros actif de ce groupe contrôlé par vous.',
            ]);
        }

        return $personnage;
    }

    /**
     * Jet de compétence Body/Mind (doc 01 §3, P4) — moteur seul.
     *
     * @param  array<string, mixed>  $option
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    private function resoudreJet(Groupe $groupe, Personnage $personnage, array $option, array $acteur): array
    {
        $attribut = $option['jet']['attribut'];
        $difficulte = (int) $option['jet']['difficulte'];
        $nbDes = $attribut === 'body' ? (int) $personnage->attribut_body : (int) $personnage->attribut_mind;

        $resultat = (new JetCompetence($this->des))->resoudre($nbDes, $difficulte);

        $payload = [
            'type' => 'jet',
            'option_id' => $option['id'],
            'libelle' => $option['libelle'] ?? null,
            'attribut' => $attribut,
            'difficulte' => $difficulte,
            'des_lances' => $nbDes,
            'succes' => $resultat->succes,
            'issue' => $resultat->issue->value,
            'faces' => array_map(fn ($face) => $face->value, $resultat->faces),
        ];

        Journal::ajouter($groupe, 'jet', $payload, $acteur);

        return $payload;
    }

    /**
     * Attaque d'un monstre actif de la quête courante (doc 03 §4-6) — moteur seul.
     *
     * @param  array<string, mixed>  $option
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    private function resoudreAttaque(Groupe $groupe, Personnage $personnage, array $option, array $acteur): array
    {
        $instance = InstanceMonstre::query()
            ->where('id', (int) $option['cible_id'])
            ->where('quete_id', $groupe->quete_courante_id)
            ->where('etat', 'actif')
            ->with('monstre')
            ->first();

        if ($instance === null) {
            throw ValidationException::withMessages([
                'option.cible_id' => 'Cible invalide : ce monstre n\'est pas actif dans la quête en cours.',
            ]);
        }

        $resultat = (new Combat($this->des))->resoudreAttaque(
            desAttaque: (int) $personnage->des_attaque,
            desDefense: (int) $instance->monstre->defense,
            typeDefenseur: TypeFigurine::Monstre,
            pvBodyDefenseur: (int) $instance->pv_body,
        );

        $instance->update([
            'pv_body' => $resultat->pvBodyApres,
            'etat' => $resultat->pvBodyApres === 0 ? 'vaincu' : 'actif',
        ]);

        $payload = [
            'type' => 'attaque',
            'option_id' => $option['id'],
            'libelle' => $option['libelle'] ?? null,
            'cible' => [
                'instance_id' => $instance->id,
                'nom' => $instance->habillage['nom'] ?? $instance->monstre->nom_base,
            ],
            'touches' => $resultat->touches,
            'boucliers' => $resultat->boucliers,
            'degats' => $resultat->degats,
            'pv_body_apres' => $resultat->pvBodyApres,
            'cible_vaincue' => $resultat->pvBodyApres === 0,
            'faces_attaque' => array_map(fn ($face) => $face->value, $resultat->facesAttaque),
            'faces_defense' => array_map(fn ($face) => $face->value, $resultat->facesDefense),
        ];

        Journal::ajouter($groupe, 'combat', $payload, $acteur);

        return $payload;
    }
}
