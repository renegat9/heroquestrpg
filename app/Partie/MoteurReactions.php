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
    public function __construct(private readonly CapacitesInnees $capacites) {}

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

        // 1. La VICTIME elle-même : un sort réactif, ou une capacité de carte.
        $sort = $this->sortReactifDisponible($heros);

        if ($sort !== null) {
            $this->deposer($etat, $heros, $heros, [
                'action' => ReactionEffet::ANNULE_DEGATS,
                'sort_id' => $sort->id,
                'nom' => $sort->nom,
                'description' => $sort->description,
            ], $degats, $source, $contexte);

            return;
        }

        // *Inébranlable* (Chevalier) : « when your Body Points are reduced to 0
        // to instead reduce them to 1 ». ⚠ Seulement si le coup a VRAIMENT été
        // mortel — proposer un plancher à un héros encore debout gaspillerait
        // une capacité qui ne sert qu'une fois par quête.
        if ((int) $heros->pv_body === 0
            && $this->capacites->disponible($heros, $etat, ReactionEffet::PLANCHER_PV)) {
            $noeud = $this->capacites->noeud($heros, ReactionEffet::PLANCHER_PV);

            if ($this->bouclierSiRequis($heros, $noeud?->effet ?? [])) {
                $this->deposer($etat, $heros, $heros, [
                    'action' => ReactionEffet::PLANCHER_PV,
                    'capacite' => $noeud?->nom,
                    'nom' => $noeud?->nom,
                    'description' => $noeud?->description,
                ], $degats, $source, $contexte);

                return;
            }
        }

        // 2. Un VOISIN : *Parade au bouclier* (Chevalier). La seule réaction
        // proposée à quelqu'un d'autre que la victime — d'où un protecteur
        // distinct dans la proposition, et une adjacence revérifiée à la
        // résolution (les figures ont pu bouger entre-temps).
        $this->proposerAuVoisin($etat, $heros, $degats, $source, $contexte);
    }

    /**
     * Cherche un héros AU CONTACT du blessé capable de le couvrir.
     *
     * @param  array<string, mixed>  $contexte
     */
    private function proposerAuVoisin(
        EtatPersonnageQuete $etatVictime,
        Personnage $victime,
        int $degats,
        string $source,
        array $contexte,
    ): void {
        $quete = $etatVictime->quete;

        if ($quete === null || $etatVictime->position_x === null) {
            return;
        }

        foreach ($quete->etatsPersonnages()->with('personnage')->get() as $etat) {
            $protecteur = $etat->personnage;

            if ($protecteur === null
                || $protecteur->id === $victime->id
                || $etat->tombe
                || $etat->position_x === null
                || $etat->reaction_en_attente !== null) {
                continue;
            }

            if (abs((int) $etat->position_x - (int) $etatVictime->position_x)
                + abs((int) $etat->position_y - (int) $etatVictime->position_y) !== 1) {
                continue;
            }

            if (! $this->capacites->disponible($protecteur, $etat, ReactionEffet::ANNULE_DEGATS_VOISIN)) {
                continue;
            }

            $noeud = $this->capacites->noeud($protecteur, ReactionEffet::ANNULE_DEGATS_VOISIN);

            if (! $this->bouclierSiRequis($protecteur, $noeud?->effet ?? [])) {
                continue;
            }

            $this->deposer($etat, $protecteur, $victime, [
                'action' => ReactionEffet::ANNULE_DEGATS_VOISIN,
                'capacite' => $noeud?->nom,
                'nom' => $noeud?->nom,
                'description' => $noeud?->description,
            ], $degats, $source, $contexte);

            return; // un seul protecteur sollicité : le plus proche dans l'ordre
        }
    }

    /**
     * « **Requires shield** » — deux des trois capacités du Chevalier
     * l'exigent, et sa carte lui en donne un au départ. Vrai si la capacité ne
     * demande rien.
     *
     * @param  array<string, mixed>  $effet
     */
    private function bouclierSiRequis(Personnage $heros, array $effet): bool
    {
        if (empty($effet['necessite_bouclier'])) {
            return true;
        }

        return $heros->inventaire()
            ->whereIn('emplacement', ['arme_secondaire'])
            ->with('objet')
            ->get()
            ->contains(fn ($ligne) => ($ligne->objet?->tag_equipement) === 'bouclier');
    }

    /**
     * Dépose la proposition sur l'état du RÉPONDANT et la lui diffuse.
     *
     * @param  array<string, mixed>  $quoi
     * @param  array<string, mixed>  $contexte
     */
    private function deposer(
        EtatPersonnageQuete $etat,
        Personnage $repondant,
        Personnage $victime,
        array $quoi,
        int $degats,
        string $source,
        array $contexte,
    ): void {
        $etat->update(['reaction_en_attente' => [
            ...$quoi,
            'victime_id' => $victime->id,
            'victime' => $victime->nom,
            'source' => $source,
            'degats' => $degats,
            'contexte' => $contexte,
            'expire_a' => now()->addSeconds(ReactionEffet::FENETRE_SECONDES)->toIso8601String(),
        ]]);

        $groupe = $etat->quete?->groupe;

        if ($groupe === null || $repondant->joueur_id === null) {
            return;
        }

        ReactionProposee::dispatch(
            (int) $repondant->joueur_id,
            $groupe->identifiant,
            [
                'personnage_id' => $repondant->id,
                'sort' => $quoi['nom'] ?? null,
                'description' => $quoi['description'] ?? null,
                'action' => $quoi['action'],
                'victime' => $victime->nom,
                'source' => $source,
                'degats' => $degats,
                'contexte' => $contexte,
                'expire_dans' => ReactionEffet::FENETRE_SECONDES,
            ],
        );
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

        $action = (string) ($attente['action'] ?? ReactionEffet::ANNULE_DEGATS);

        // Qui a encaissé : la victime peut être un AUTRE héros que celui qui
        // répond (Parade au bouclier).
        $victime = isset($attente['victime_id'])
            ? Personnage::find((int) $attente['victime_id']) ?? $heros
            : $heros;

        $etatVictime = EtatPersonnageQuete::query()
            ->where('personnage_id', $victime->id)
            ->whereHas('quete', fn ($q) => $q->where('etat', 'en_cours'))
            ->first();

        $degats = (int) ($attente['degats'] ?? 0);

        if ($action === ReactionEffet::PLANCHER_PV) {
            // « reduced to 0 → instead reduce them to 1 » : on ne rend pas le
            // coup, on pose un plancher. Un seul PV, jamais davantage.
            $rendus = max(0, 1 - (int) $victime->pv_body);
            $victime->update(['pv_body' => max(1, (int) $victime->pv_body)]);
        } else {
            $rendus = min($degats, (int) $victime->pv_body_max - (int) $victime->pv_body);
            $victime->update(['pv_body' => (int) $victime->pv_body + $rendus]);
        }

        // Un héros relevé au-dessus de 0 se remet debout : le coup n'a pas eu
        // lieu (ou il a tenu), il ne doit pas rester à terre pour rien.
        if ((int) $victime->pv_body > 0 && $etatVictime?->tombe) {
            $etatVictime->update(['tombe' => false]);
        }

        // Le sort est dépensé — c'est ce qui empêche d'annuler tous les coups.
        if (isset($attente['sort_id'])) {
            $heros->sorts()->updateExistingPivot((int) $attente['sort_id'], ['disponible' => false]);
        }

        // …et une capacité « once per quest » se marque comme dépensée.
        if (isset($attente['capacite'])) {
            $utilisees = (array) ($etat->capacites_utilisees ?? []);
            $utilisees[] = (string) $attente['capacite'];
            $etat->update(['capacites_utilisees' => array_values(array_unique($utilisees))]);
        }

        $payload = [
            'type' => 'reaction',
            'personnage' => $heros->nom,
            'victime' => $victime->nom,
            'sort' => $attente['nom'] ?? null,
            'action' => $action,
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
