<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\ReactionEffet;
use App\Events\ReactionProposee;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Sort;
use App\Support\Journal;
use Illuminate\Validation\ValidationException;

/**
 * Réactions HORS TOUR : proposer, puis résoudre le choix du joueur.
 *
 * Le problème que ça règle : *Dark Wings* et *Twisting Torrent* s'activent
 * pendant le tour d'un MONSTRE. Or cette phase se résout dans la requête HTTP
 * d'un autre joueur, à l'intérieur d'une transaction — impossible de la
 * suspendre le temps d'interroger un téléphone.
 *
 * La solution retenue est celle de la table réelle : le coup est annoncé, le
 * joueur dit ensuite « j'annule ». `proposer()` dépose la proposition sur
 * l'état du héros et la diffuse sur son canal privé ; `resoudre()` défait le
 * coup si le joueur accepte. Ce n'est pas un pis-aller : décider APRÈS avoir
 * vu le résultat est une meilleure décision, et c'est ainsi qu'on joue.
 *
 * ⚠ Limite assumée : un coup qui achève le DERNIER héros debout provoque un
 * TPK en fin de round, avant que le joueur ait pu répondre — la quête est alors
 * `echouee` et la proposition refusée. Un héros qui tombe pendant que ses
 * compagnons tiennent debout, lui, peut parfaitement être relevé par sa
 * réaction. Le groupe garde `/reprise` pour l'autre cas.
 */
final class MoteurReactions
{
    /**
     * Le héros a-t-il de quoi réagir à ce coup ? Si oui, dépose la proposition
     * et la diffuse. Sans effet si rien ne s'applique.
     *
     * @param  array<string, mixed>  $contexte
     */
    public function proposer(
        Personnage $heros,
        int $degats,
        string $source,
        array $contexte = [],
    ): void {
        if ($degats <= 0 || ! in_array($source, ReactionEffet::SOURCES_REACTIVES, true)) {
            return;
        }

        $etat = EtatPersonnageQuete::query()
            ->where('personnage_id', $heros->id)
            ->whereHas('quete', fn ($q) => $q->where('etat', 'en_cours'))
            ->first();

        if ($etat === null || $etat->reaction_en_attente !== null) {
            return; // hors quête, ou une proposition attend déjà : pas d'empilement
        }

        $sort = $this->sortReactifDisponible($heros);

        if ($sort === null) {
            return;
        }

        $etat->update(['reaction_en_attente' => [
            'sort_id' => $sort->id,
            'nom' => $sort->nom,
            'source' => $source,
            'degats' => $degats,
            'contexte' => $contexte,
            'expire_a' => now()->addSeconds(ReactionEffet::FENETRE_SECONDES)->toIso8601String(),
        ]]);

        $groupe = $etat->quete?->groupe;

        if ($groupe !== null && $heros->joueur_id !== null) {
            ReactionProposee::dispatch(
                (int) $heros->joueur_id,
                $groupe->identifiant,
                [
                    'personnage_id' => $heros->id,
                    'sort' => $sort->nom,
                    'description' => $sort->description,
                    'source' => $source,
                    'degats' => $degats,
                    'contexte' => $contexte,
                    'expire_dans' => ReactionEffet::FENETRE_SECONDES,
                ],
            );
        }
    }

    /**
     * Réponse du joueur. `true` = j'active (les dégâts sont rendus et le sort
     * dépensé), `false` = je laisse passer.
     *
     * @return array<string, mixed>  compte rendu, journalisable
     */
    public function resoudre(Groupe $groupe, Personnage $heros, bool $accepte): array
    {
        $etat = EtatPersonnageQuete::query()
            ->where('personnage_id', $heros->id)
            ->whereHas('quete', fn ($q) => $q->where('etat', 'en_cours'))
            ->first();

        $attente = $etat?->reaction_en_attente;

        if ($etat === null || ! is_array($attente)) {
            throw ValidationException::withMessages([
                'reaction' => 'Aucune réaction en attente.',
            ]);
        }

        // Toujours consommer la proposition, acceptée ou non : la laisser en
        // place ferait ressortir la feuille au prochain rafraîchissement.
        $etat->update(['reaction_en_attente' => null]);

        if (! $accepte) {
            return ['type' => 'reaction', 'sort' => $attente['nom'] ?? null, 'active' => false];
        }

        if (isset($attente['expire_a']) && now()->greaterThan($attente['expire_a'])) {
            throw ValidationException::withMessages([
                'reaction' => 'Trop tard : la fenêtre de réaction est passée.',
            ]);
        }

        $degats = (int) ($attente['degats'] ?? 0);
        $rendus = min($degats, (int) $heros->pv_body_max - (int) $heros->pv_body);

        $heros->update(['pv_body' => (int) $heros->pv_body + $rendus]);

        // Un héros relevé au-dessus de 0 se remet debout : le coup n'a pas eu
        // lieu, il ne doit pas rester à terre pour un dégât annulé.
        if ((int) $heros->pv_body > 0 && $etat->tombe) {
            $etat->update(['tombe' => false]);
        }

        // Le sort est dépensé — c'est ce qui empêche d'annuler tous les coups.
        if (isset($attente['sort_id'])) {
            $heros->sorts()->updateExistingPivot((int) $attente['sort_id'], ['disponible' => false]);
        }

        $payload = [
            'type' => 'reaction',
            'personnage' => $heros->nom,
            'sort' => $attente['nom'] ?? null,
            'active' => true,
            'degats_annules' => $rendus,
            'source' => $attente['source'] ?? null,
        ];

        Journal::ajouter($groupe, 'combat', $payload, ['nom' => $heros->nom]);

        return $payload;
    }

    /**
     * Premier sort DISPONIBLE du héros qui réagit aux dégâts subis.
     *
     * `disponible` est la seule limite : un sort épuisé ne réagit pas, sinon la
     * réaction serait gratuite et permanente.
     */
    private function sortReactifDisponible(Personnage $heros): ?Sort
    {
        foreach ($heros->sorts()->wherePivot('disponible', true)->get() as $sort) {
            $reaction = $sort->effet['reaction'] ?? null;

            if (is_array($reaction)
                && ($reaction['sur'] ?? null) === ReactionEffet::SUR_DEGATS_SUBIS
                && ($reaction['action'] ?? null) === ReactionEffet::ANNULE_DEGATS) {
                return $sort;
            }
        }

        return null;
    }
}
