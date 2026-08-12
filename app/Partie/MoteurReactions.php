<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\ReactionEffet;
use App\Events\ReactionProposee;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Sort;
use App\Support\Journal;
use Illuminate\Database\Eloquent\Collection;
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
    public function __construct(
        private readonly CapacitesInnees $capacites,
        private readonly StylesElementaires $styles,
    ) {}

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

        // *Torrent Tournoyant* (Moine, Style de l'Eau) : « Activate this
        // technique when you take damage to cancel that damage. » Même action
        // qu'un sort réactif, mais elle se paie en STYLE — d'où le passage par
        // l'arbitre, qui sait épuiser l'Eau et non un compteur de quête.
        $technique = $this->styles->sourceActivable(
            $heros, $etat, ReactionEffet::ANNULE_DEGATS, horsTour: true,
        );

        if ($technique !== null && ! empty($technique['effet']['reaction'])) {
            $this->deposer($etat, $heros, $heros, [
                'action' => ReactionEffet::ANNULE_DEGATS,
                'style' => $technique['mecanique'],
                'nom' => $technique['nom'],
                'description' => $technique['style']?->description,
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

        // *Représailles* (Berserker) : « you may use this skill when you take
        // damage from an adjacent monster. Immediately make an attack against
        // that monster. »
        //
        // ⚠ Trois conditions que le texte impose et que rien d'autre ne porte :
        // le coup vient d'un MONSTRE identifié (d'où `contexte.instance_id`),
        // ce monstre est encore AU CONTACT, et le Berserker tient debout — un
        // héros à terre ne rend pas de coup. Le seuil « 5 PV ou moins » est
        // lu par `disponible()`, sur les PV D'APRÈS le coup : c'est bien le
        // coup encaissé qui ouvre la capacité.
        if ((int) $heros->pv_body > 0
            && $source === MoteurDegats::SOURCE_ATTAQUE_MONSTRE
            && $this->capacites->disponible($heros, $etat, ReactionEffet::RIPOSTE)) {
            $instance = $this->monstreAuContact($etat, $contexte);

            if ($instance !== null) {
                $noeud = $this->capacites->noeud($heros, ReactionEffet::RIPOSTE);

                $this->deposer($etat, $heros, $heros, [
                    'action' => ReactionEffet::RIPOSTE,
                    'capacite' => $noeud?->nom,
                    'nom' => $noeud?->nom,
                    'description' => $noeud?->description,
                    'instance_id' => (int) $instance->id,
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
     * L'assaillant désigné par le contexte, s'il est encore actif, révélé et
     * AU CONTACT du héros. `null` sinon — un tir venu d'en face ne se riposte
     * pas au corps à corps.
     *
     * @param  array<string, mixed>  $contexte
     */
    private function monstreAuContact(EtatPersonnageQuete $etat, array $contexte): ?InstanceMonstre
    {
        $id = (int) ($contexte['instance_id'] ?? 0);

        if ($id === 0 || $etat->position_x === null) {
            return null;
        }

        $instance = InstanceMonstre::query()
            ->whereKey($id)
            ->where('quete_id', $etat->quete_id)
            ->where('etat', 'actif')
            ->where('revele', true)
            ->with('monstre')
            ->first();

        if ($instance === null || $instance->position_x === null) {
            return null;
        }

        // Emprise comprise : une grande figurine est au contact par n'importe
        // laquelle de ses cases (3.9), comme pour l'attaque du héros. Contact
        // ORTHOGONAL : c'est celui qui a permis au monstre de frapper.
        $emprise = $instance->monstre->emprise();

        for ($dy = 0; $dy < $emprise['h']; $dy++) {
            for ($dx = 0; $dx < $emprise['l']; $dx++) {
                $ex = abs(((int) $instance->position_x + $dx) - (int) $etat->position_x);
                $ey = abs(((int) $instance->position_y + $dy) - (int) $etat->position_y);

                if ($ex + $ey === 1) {
                    return $instance;
                }
            }
        }

        return null;
    }

    /**
     * DÉFI DU CHEVALIER — « Use this skill when a Wandering Monster is revealed
     * in the same room as you. You are now considered the treasure-searcher for
     * the encounter. The Wandering Monster is placed next to you and
     * immediately attacks you. »
     *
     * Le seul déclencheur qui ne soit pas un coup encaissé, et la seule
     * réaction qui aggrave volontairement la situation de son auteur : il prend
     * la bête à la place du fouilleur. D'où une entrée à part — `proposer()`
     * part de dégâts, pas d'une apparition.
     *
     * ⚠ Le FOUILLEUR est exclu : c'est déjà lui que l'errant vient chercher, se
     * défier soi-même ne changerait rien et gaspillerait la capacité.
     *
     * @param  Collection<int, EtatPersonnageQuete>  $candidats  héros de la salle
     */
    public function proposerDefi(
        InstanceMonstre $errant,
        Personnage $fouilleur,
        Collection $candidats,
    ): void {
        foreach ($candidats as $etat) {
            $chevalier = $etat->personnage;

            if ($chevalier === null
                || $chevalier->id === $fouilleur->id
                || $etat->tombe
                || $etat->position_x === null
                || $etat->reaction_en_attente !== null) {
                continue;
            }

            if (! $this->capacites->disponible($chevalier, $etat, ReactionEffet::DEFI_ERRANT)) {
                continue;
            }

            $noeud = $this->capacites->noeud($chevalier, ReactionEffet::DEFI_ERRANT);

            if (! $this->bouclierSiRequis($chevalier, $noeud?->effet ?? [])) {
                continue;
            }

            $this->deposer($etat, $chevalier, $fouilleur, [
                'action' => ReactionEffet::DEFI_ERRANT,
                'capacite' => $noeud?->nom,
                'nom' => $noeud?->nom,
                'description' => $noeud?->description,
                'instance_id' => (int) $errant->id,
                'monstre' => $errant->nomAffiche(),
            ], 0, ReactionEffet::SUR_ERRANT_REVELE, ['monstre' => $errant->nomAffiche()]);

            return; // un seul champion sollicité
        }
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

        // *Représailles* : le coup n'est PAS annulé — le Berserker encaisse et
        // rend. C'est tout l'esprit de la classe, dont deux capacités sur trois
        // exigent d'être blessé.
        if ($action === ReactionEffet::RIPOSTE) {
            return $this->riposter($groupe, $heros, $etat, $attente);
        }

        // *Défi du chevalier* : rien à rendre non plus — la bête change de
        // cible et frappe, ici et maintenant.
        if ($action === ReactionEffet::DEFI_ERRANT) {
            return $this->releverLeDefi($groupe, $heros, $etat, $attente);
        }

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

        // …et une technique du Moine épuise SON STYLE, pas un compteur de quête.
        if (isset($attente['style'])) {
            $source = $this->styles->sourceActivable(
                $heros, $etat, (string) $attente['style'], horsTour: true,
            );

            if ($source !== null) {
                $this->styles->depenser($heros, $etat, $source, horsTour: true);
            }
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
     * Rend le coup — *Représailles*. Une vraie attaque, avec les dés, les
     * bonus et le journal des autres : c'est la même frappe, jouée hors tour.
     *
     * ⚠ `app(ResolveurTour::class)` et non une injection au constructeur : le
     * résolveur dépend de `MoteurDegats`, qui dépend de ce moteur-ci. Le
     * conteneur bouclerait à l'infini. La dépendance est réelle mais elle ne
     * naît qu'ICI, au moment où le joueur accepte.
     *
     * @param  array<string, mixed>  $attente
     * @return array<string, mixed>
     */
    private function riposter(
        Groupe $groupe,
        Personnage $heros,
        EtatPersonnageQuete $etat,
        array $attente,
    ): array {
        $instance = $this->monstreAuContact($etat, ['instance_id' => $attente['instance_id'] ?? 0]);

        // Le monstre a pu tomber ou s'éloigner pendant que le joueur réfléchit
        // (la phase des monstres, elle, ne s'est pas arrêtée). On ne frappe
        // pas dans le vide, et la capacité n'est pas dépensée pour rien.
        if ($instance === null) {
            return [
                'type' => 'reaction',
                'personnage' => $heros->nom,
                'action' => ReactionEffet::RIPOSTE,
                'active' => false,
                'raison' => 'La cible n\'est plus au contact.',
            ];
        }

        if (isset($attente['capacite'])) {
            $utilisees = (array) ($etat->capacites_utilisees ?? []);
            $utilisees[] = (string) $attente['capacite'];
            $etat->update(['capacites_utilisees' => array_values(array_unique($utilisees))]);
        }

        $frappe = app(ResolveurTour::class)->frapper(
            $groupe,
            $etat->quete,
            $etat,
            $heros,
            $instance,
            meta: [
                'option_id' => ReactionEffet::RIPOSTE,
                'libelle' => $attente['nom'] ?? 'Représailles',
                'riposte' => true,
            ],
            acteur: ['type' => 'personnage', 'id' => $heros->id, 'nom' => $heros->nom],
        );

        return [
            'type' => 'reaction',
            'personnage' => $heros->nom,
            'victime' => $heros->nom,
            'sort' => $attente['nom'] ?? null,
            'action' => ReactionEffet::RIPOSTE,
            'active' => true,
            // ⚠ Aucun PV rendu : la carte ne parle que de rendre le COUP.
            'degats_annules' => 0,
            'source' => $attente['source'] ?? null,
            'frappe' => $frappe,
        ];
    }

    /**
     * Relève le défi : l'errant est déplacé au contact du Chevalier et frappe
     * aussitôt. Même dépendance différée que la riposte — le résolveur est
     * demandé au conteneur ici, pas injecté.
     *
     * @param  array<string, mixed>  $attente
     * @return array<string, mixed>
     */
    private function releverLeDefi(
        Groupe $groupe,
        Personnage $heros,
        EtatPersonnageQuete $etat,
        array $attente,
    ): array {
        $errant = InstanceMonstre::query()
            ->whereKey((int) ($attente['instance_id'] ?? 0))
            ->where('quete_id', $etat->quete_id)
            ->where('etat', 'actif')
            ->with('monstre')
            ->first();

        // Un compagnon a pu l'abattre pendant que le Chevalier réfléchissait :
        // le défi tombe, la capacité reste.
        if ($errant === null) {
            return [
                'type' => 'reaction',
                'personnage' => $heros->nom,
                'action' => ReactionEffet::DEFI_ERRANT,
                'active' => false,
                'raison' => 'Le monstre errant n\'est plus en jeu.',
            ];
        }

        $frappe = app(ResolveurTour::class)->releverLeDefi($groupe, $heros, $etat, $errant);

        if ($frappe === null) {
            return [
                'type' => 'reaction',
                'personnage' => $heros->nom,
                'action' => ReactionEffet::DEFI_ERRANT,
                'active' => false,
                'raison' => 'Aucune case libre à ton contact pour l\'y placer.',
            ];
        }

        if (isset($attente['capacite'])) {
            $utilisees = (array) ($etat->capacites_utilisees ?? []);
            $utilisees[] = (string) $attente['capacite'];
            $etat->update(['capacites_utilisees' => array_values(array_unique($utilisees))]);
        }

        return [
            'type' => 'reaction',
            'personnage' => $heros->nom,
            'victime' => $heros->nom,
            'sort' => $attente['nom'] ?? null,
            'action' => ReactionEffet::DEFI_ERRANT,
            'active' => true,
            'degats_annules' => 0,
            'source' => $attente['source'] ?? null,
            'monstre' => $attente['monstre'] ?? null,
            'frappe' => $frappe,
        ];
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
