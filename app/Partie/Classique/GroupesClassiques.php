<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\CampagneConstruite;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Support\Journal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * MODE CLASSIQUE — ouvrir un groupe sur une CAMPAGNE CONSTRUITE (`docs/plan-mode-classique.md` §7).
 *
 * Le pendant de `GroupeController::creer()` pour un groupe qui ne joue pas une campagne générée : ni thème ni longueur
 * à choisir, ni bestiaire à tirer, et AUCUN travail d'IA lancé (`GenererSqueletteCampagne` est fermé à ce mode). La
 * campagne est celle que l'auteur a écrite dans le constructeur.
 *
 * BOURSE PAR HÉROS (René, 2026-11-13) : l'or du fondateur reste sur lui. Il n'est pas versé au pot commun, comme il
 * l'est à la création d'une campagne générée.
 */
final class GroupesClassiques
{
    public function ouvrir(string $nom, string $slugCampagne, Personnage $fondateur): Groupe
    {
        $campagne = CampagneConstruite::where('slug', $slugCampagne)->first();

        if ($campagne === null) {
            throw ValidationException::withMessages(['campagne' => "Aucune campagne construite « {$slugCampagne} »."]);
        }

        $nbQuetes = count((array) ($campagne->document['quetes'] ?? []));
        if ($nbQuetes === 0) {
            throw ValidationException::withMessages(['campagne' => "« {$campagne->titre} » n'a encore aucune quête : rien à jouer."]);
        }

        if ($fondateur->groupe_actif_id !== null) {
            throw ValidationException::withMessages(['personnage_id' => "« {$fondateur->nom} » est déjà engagé dans un groupe actif."]);
        }

        return DB::transaction(function () use ($nom, $campagne, $nbQuetes, $fondateur): Groupe {
            $groupe = Groupe::create([
                'identifiant' => $this->identifiantLibre($nom),
                'nom' => $nom,
                // Colonnes de campagne obligatoires. `theme` porte le titre de la campagne (affiché au hub) ; `longueur`
                // est un ENUM des longueurs de campagne GÉNÉRÉE, sans objet ici : la plus courte, faute de mieux, comme
                // en arène — c'est `mode` qui dit « classique », et `nb_quetes_total` le nombre réel de quêtes.
                'theme' => $campagne->titre,
                'longueur' => 'tres_courte',
                'nb_quetes_total' => $nbQuetes,
                'phase' => 'hub',
                'mode' => Groupe::MODE_CLASSIQUE,
                'campagne_construite_id' => $campagne->id,
                'rang_quete' => 1,
            ]);

            $groupe->personnages()->attach($fondateur->id, ['ordre_initiative' => 1, 'actif' => true]);
            $fondateur->update(['groupe_actif_id' => $groupe->id]);

            Journal::ajouter($groupe, 'systeme', [
                'action' => 'joueur_rejoint',
                'joueur_id' => (int) $fondateur->joueur_id,
                'personnage_ids' => [$fondateur->id],
                'fondateur' => true,
                'campagne_construite' => $campagne->slug,
            ]);

            return $groupe;
        });
    }

    private function identifiantLibre(string $nom): string
    {
        $base = substr(str()->slug($nom), 0, 24) ?: 'classique';

        do {
            $identifiant = $base.'-'.strtolower(str()->random(4));
        } while (Groupe::where('identifiant', $identifiant)->exists());

        return $identifiant;
    }
}
