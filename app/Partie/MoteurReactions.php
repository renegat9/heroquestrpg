<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\Combat;
use App\Engine\Des\LanceurDes;
use App\Engine\MotsClesEquipement;
use App\Engine\ReactionEffet;
use App\Engine\TypeFigurine;
use App\Events\ReactionProposee;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Inventaire;
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
 * Le coup qui achève le DERNIER héros debout a longtemps été le trou de cette
 * mécanique : le TPK se prononçait en fin de round, avant que le téléphone ait
 * sonné, et la réaction arrivait sur une quête déjà `echouee` — le moment le
 * plus dramatique du jeu se jouait sans son joueur. Depuis le 2026-08-13 le
 * verdict est SUSPENDU tant qu'une offre capable de relever quelqu'un attend
 * (`ReactionEffet::ACTIONS_RELEVANTES`, `ResolveurTour::verdictDeChute`), et il
 * se tranche à la réponse — ou, si elle ne vient jamais, à l'expiration de la
 * fenêtre (`rattraperExpiration`, appelée au battement de cœur de la table et à
 * chaque lecture d'état).
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
        if ($degats <= 0) {
            return;
        }

        $etat = EtatPersonnageQuete::query()
            ->where('personnage_id', $heros->id)
            ->whereHas('quete', fn ($q) => $q->where('etat', 'en_cours'))
            ->first();

        if ($etat === null || $etat->reaction_en_attente !== null) {
            return; // hors quête, ou une proposition attend déjà : pas d'empilement
        }

        // Les réactions qui DÉFONT le coup sont réservées aux COUPS : c'est le
        // sens de `SOURCES_REACTIVES`. Le soin d'urgence, lui, ne défait rien —
        // il se paie — et vaut donc quelle que soit la cause de la chute (plus
        // bas).
        if (! in_array($source, ReactionEffet::SOURCES_REACTIVES, true)) {
            $this->proposerSoinUrgence($etat, $heros, $degats, $source, $contexte);

            return;
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

        // *Bâton Ancien* : le reflet est proposé AVANT les planchers parce
        // qu'il est strictement meilleur — il rend tous les PV au lieu d'en
        // laisser un, et il retourne le sort contre la salle. Après les
        // réactions GRATUITES de la victime, en revanche : une charge finie ne
        // doit pas préempter un pouvoir qui ne coûte rien.
        if ($source === MoteurDegats::SOURCE_SORT_DREAD
            && $this->deposerReflet($etat, $heros, $degats, $source, $contexte)) {
            return;
        }

        // *Bouclier de l'Aube* : forcer le monstre à tout relancer. Après le
        // reflet (qui annule) et avant les planchers (qui laissent 1 PV) : la
        // relance peut ramener le coup à zéro, mais elle peut aussi le refaire.
        if ($source === MoteurDegats::SOURCE_ATTAQUE_MONSTRE
            && $this->deposerRelanceAttaque($etat, $heros, $degats, $source, $contexte)) {
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

        // ⚠ MÊME PLANCHER, PORTÉ PAR UN OBJET (2026-09-03) — *Cendres du Phénix* :
        // « Once per quest, when any one hero is reduced to 0 Body Points, use
        // this to instead reduce them to 1. Immediately roll 1 red die ; on a 5
        // or 6, this artifact is lost. »
        //
        // Le bloc au-dessus n'interrogeait que des nœuds de COMPÉTENCE ; c'est
        // le seul endroit du fichier où il fallait apprendre à regarder le sac,
        // exactement comme `soinsDisponibles()` l'a fait pour le Bracelet.
        if ((int) $heros->pv_body === 0) {
            $cendres = $this->artefactPlancherPv($heros, $etat);

            if ($cendres !== null) {
                $this->deposer($etat, $heros, $heros, [
                    'action' => ReactionEffet::PLANCHER_PV,
                    'artefact' => $cendres->id,
                    'nom' => $cendres->objet?->nom,
                    'description' => "Tu tombes. L'artefact peut te laisser un point de vie — au risque de se consumer.",
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

        // 3. DERNIER RECOURS — le héros vient de tomber et il lui restait de
        // quoi tenir. Proposé après tout le reste, et c'est voulu : une
        // capacité « once per quest » comme *Inébranlable* ne coûte aucun
        // objet, mieux vaut qu'elle passe d'abord.
        if ($etat->fresh()->reaction_en_attente === null) {
            $this->proposerSoinUrgence($etat, $heros, $degats, $source, $contexte);
        }
    }

    /**
     * SOIN D'URGENCE (demande de René, 2026-08-13) — « quand le joueur tombe,
     * lui offrir d'utiliser une potion ou un sort de soin pour rester en vie ».
     *
     * Mourir avec une potion au sac est la frustration la plus bête du jeu : au
     * plateau, on la boit — le canon dit d'ailleurs qu'une potion se boit à
     * TOUT MOMENT, y compris pendant le tour d'un monstre (`MoteurPotions`).
     * Notre boucle, elle, résout la phase des monstres d'un bloc : le héros
     * tombait sans qu'on lui demande rien.
     *
     * ⚠ Contrairement aux autres réactions, celle-ci vaut quelle que soit la
     * CAUSE de la chute — coup de monstre, sort de Dread, piège, jetons de
     * rejeton. La liste `SOURCES_REACTIVES` répond à la question « quel coup
     * peut-on ANNULER ? » ; ici on n'annule rien, on dépense une ressource pour
     * rester debout. Refuser le soin à un héros tombé dans une fosse n'aurait
     * eu aucun sens à la table.
     *
     * @param  array<string, mixed>  $contexte
     */
    private function proposerSoinUrgence(
        EtatPersonnageQuete $etat,
        Personnage $heros,
        int $degats,
        string $source,
        array $contexte,
    ): void {
        // Seulement s'il TOMBE : un héros qui garde des PV se soignera à son
        // tour, sans qu'on l'interrompe.
        if ((int) $heros->pv_body > 0) {
            return;
        }

        // ⚠ L'état de quête est passé : sans lui, un artefact à fenêtre « une
        // fois par quête » ne saurait pas si la sienne est déjà fermée, et
        // serait offert une seconde fois pour être refusé ensuite.
        $soins = $this->soinsDisponibles($heros, $etat);

        if ($soins === []) {
            return;
        }

        $this->deposer($etat, $heros, $heros, [
            'action' => ReactionEffet::SOIN_URGENCE,
            'nom' => 'Rester debout',
            'description' => "Tu tombes. Il te reste de quoi te soigner — c'est maintenant ou jamais.",
            'soins' => $soins,
        ], $degats, $source, $contexte);
    }

    /**
     * Ce dont le héros dispose pour se soigner LUI-MÊME, potions d'abord.
     *
     * Chaque entrée porte une `cle` (`potion:{inventaire_id}` /
     * `sort:{sort_id}`) : c'est elle que le joueur renvoie, et c'est la LISTE
     * BLANCHE que la résolution revalide — même principe que
     * `parametres.cibles` pour une attaque.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * Artefact PORTÉ capable de poser un plancher de PV, encore utilisable.
     *
     * Même famille que `soinsDisponibles()` : la réaction ne connaissait que les
     * nœuds d'arbre, et une carte qui promet la même chose n'avait aucun chemin.
     */
    /**
     * Dépense l'artefact de plancher, et le DÉTRUIT sur un 5-6.
     *
     * @return array<string, mixed> trace pour la charge utile
     */
    /**
     * Cherche, chez TOUS les héros de la quête, une pièce portant `$cle` et
     * encore utilisable ; dépose l'offre sur l'état de son porteur.
     *
     * ⚠ Le porteur peut être un autre héros que la victime — les deux cartes le
     * disent (« any one hero », « the Elf and their companions »). C'est la
     * deuxième réaction du jeu à sortir de la victime, après la *Parade au
     * bouclier*, et la seule à ne demander AUCUNE adjacence.
     *
     * @param  array<string, mixed>  $quoi  ce que la proposition ajoute
     * @param  array<string, mixed>  $contexte
     */
    private function deposerChezLePorteur(
        EtatPersonnageQuete $etatVictime,
        Personnage $victime,
        string $cle,
        array $quoi,
        int $degats,
        string $source,
        array $contexte,
    ): bool {
        $quete = $etatVictime->quete;

        if ($quete === null) {
            return false;
        }

        foreach ($quete->etatsPersonnages()->with('personnage')->get() as $etat) {
            $porteur = $etat->personnage;

            if ($porteur === null || $etat->tombe || $etat->reaction_en_attente !== null) {
                continue;
            }

            $ligne = app(MoteurCharges::class)->pieceActive($porteur, $cle, $etat);

            if ($ligne === null) {
                continue;
            }

            $this->deposer($etat, $porteur, $victime, [
                ...$quoi,
                'artefact' => $ligne->id,
                'nom' => $ligne->objet?->nom,
            ], $degats, $source, $contexte);

            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $contexte
     */
    private function deposerRelanceAttaque(
        EtatPersonnageQuete $etat,
        Personnage $victime,
        int $degats,
        string $source,
        array $contexte,
    ): bool {
        // Sans le monstre ni sa volée, il n'y a rien à relancer : mieux vaut ne
        // rien proposer qu'offrir un bouton dont la résolution échouerait.
        if (empty($contexte['instance_id']) || ! isset($contexte['des_attaque'])) {
            return false;
        }

        return $this->deposerChezLePorteur(
            $etat, $victime, MotsClesEquipement::RELANCE_ATTAQUE_MONSTRE,
            [
                'action' => ReactionEffet::RELANCE_ATTAQUE,
                'description' => "Force le monstre à relancer TOUS ses dés d'attaque. Le nouveau jet remplace l'ancien, en mieux comme en pire.",
            ],
            $degats, $source, $contexte,
        );
    }

    /**
     * @param  array<string, mixed>  $contexte
     */
    private function deposerReflet(
        EtatPersonnageQuete $etat,
        Personnage $victime,
        int $degats,
        string $source,
        array $contexte,
    ): bool {
        if (empty($contexte['lanceur_id'])) {
            return false;
        }

        return $this->deposerChezLePorteur(
            $etat, $victime, MotsClesEquipement::REFLET_SORT_DREAD,
            [
                'action' => ReactionEffet::REFLET_SORT,
                'description' => 'Renvoie le sort à son lanceur. Lui et tous les monstres de sa salle en subissent les effets ; toi et tes compagnons y êtes insensibles.',
            ],
            $degats, $source, $contexte,
        );
    }

    /**
     * Ouvre la fenêtre du *Bâton Ancien* sur un sort de contrôle, qui ne blesse
     * personne et n'atteint donc jamais `proposer()`.
     *
     * @param  array<string, mixed>  $contexte
     */
    public function proposerRefletControle(Personnage $victime, array $contexte): bool
    {
        $etat = EtatPersonnageQuete::query()
            ->where('personnage_id', $victime->id)
            ->whereHas('quete', fn ($q) => $q->where('etat', 'en_cours'))
            ->first();

        if ($etat === null) {
            return false;
        }

        return $this->deposerReflet($etat, $victime, 0, MoteurDegats::SOURCE_SORT_DREAD, $contexte);
    }

    private function consumerArtefactPlancher(Personnage $heros, EtatPersonnageQuete $etat, int $inventaireId): array
    {
        $ligne = $heros->inventaire()->with('objet')->whereKey($inventaireId)->first();

        if ($ligne === null) {
            return [];
        }

        app(MoteurCharges::class)->consommerUsage($ligne, $etat);

        $de = app(LanceurDes::class)->d6();
        $perdu = $de >= 5;

        if ($perdu) {
            $ligne->delete();
        }

        return ['artefact' => $ligne->objet?->nom, 'de_artefact' => $de, 'artefact_perdu' => $perdu];
    }

    private function artefactPlancherPv(Personnage $heros, ?EtatPersonnageQuete $etat): ?Inventaire
    {
        return $heros->inventaire()->with('objet')->get()
            ->first(fn (Inventaire $l) => ! empty(($l->objet?->effet ?? [])['plancher_pv'])
                && app(MoteurCharges::class)->utilisable($l, $etat));
    }

    public function soinsDisponibles(Personnage $heros, ?EtatPersonnageQuete $etat = null): array
    {
        $soins = [];

        foreach ($heros->inventaire()->with('objet')->get() as $ligne) {
            $effet = (array) ($ligne->objet?->effet ?? []);

            if ($ligne->objet?->categorie !== 'consommable'
                || (! isset($effet['soin_pv_body']) && ! isset($effet['soin_pv_body_de']))) {
                continue;
            }

            // ⚠ Ici on FILTRE, là où `/moi` se contente d'un badge : proposer un
            // soin d'urgence que la résolution refuserait serait pire que de ne
            // rien proposer — le joueur perdrait son tour à répondre à une offre
            // morte. Aucune potion de soin n'est restreinte aujourd'hui ; la
            // garde tient la cohérence des trois chemins.
            if (! app(Equipement::class)->estAccessible($heros, $ligne->objet)) {
                continue;
            }

            $soins[] = [
                'cle' => "potion:{$ligne->id}",
                'type' => 'potion',
                'nom' => $ligne->objet->nom,
                // Une fiole de fouille soigne 1d6 : on annonce le dé, pas un
                // chiffre qu'on ne connaît pas encore.
                'soin' => isset($effet['soin_pv_body_de'])
                    ? '1d'.(int) $effet['soin_pv_body_de']
                    : (string) (int) $effet['soin_pv_body'],
            ];
        }

        // ⚠ ARTEFACTS PORTÉS À CHARGES (2026-09-03) — le *Bracelet de Guérison*
        // dit précisément ce que cette réaction fait : « Restores 2 lost Body
        // Points once per quest. IF THE WEARER'S BODY POINTS ARE REDUCED TO 0,
        // USE IMMEDIATELY. » Il n'est pas un consommable — il se porte —, d'où
        // une famille à part et une `cle` distincte : la liste blanche doit
        // rester sans ambiguïté.
        foreach ($heros->inventaire()->with('objet')->get() as $ligne) {
            $effet = (array) ($ligne->objet?->effet ?? []);

            if ($ligne->objet?->categorie === 'consommable'
                || ! isset($effet['soin_pv_body'])
                || ! (isset($effet['charges']) || isset($effet['frequence']))
                || ! app(MoteurCharges::class)->utilisable($ligne, $etat)
                || ! app(Equipement::class)->estAccessible($heros, $ligne->objet)) {
                continue;
            }

            $soins[] = [
                'cle' => "artefact:{$ligne->id}",
                'type' => 'artefact',
                'nom' => $ligne->objet->nom,
                'soin' => (string) (int) $effet['soin_pv_body'],
            ];
        }

        foreach ($heros->sorts()->wherePivot('disponible', true)->get() as $sort) {
            $soin = (int) (($sort->effet['soin_pv_body'] ?? 0));

            // Un soin de ZONE se lance sur les héros vus : hors sujet ici, le
            // lanceur est à terre et c'est lui qu'il s'agit de relever.
            if ($soin <= 0) {
                continue;
            }

            $soins[] = [
                'cle' => "sort:{$sort->id}",
                'type' => 'sort',
                'nom' => $sort->nom,
                'soin' => (string) $soin,
            ];
        }

        return $soins;
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
     * @return array<string, mixed> compte rendu, journalisable
     */
    public function resoudre(Groupe $groupe, Personnage $heros, bool $accepte, ?string $soin = null): array
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
            // ⚠ Refuser peut CONCLURE le TPK : si le groupe ne tenait encore
            // que par cette offre en attente, c'est ce « non » qui tranche.
            $this->reprendreVerdictDeChute($groupe);

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

        // SOIN D'URGENCE : on ne défait rien, on paie pour rester debout.
        if ($action === ReactionEffet::SOIN_URGENCE) {
            return $this->soigner($groupe, $heros, $etat, $attente, $soin);
        }

        // *Bouclier de l'Aube* et *Bâton Ancien* : les deux rendent les PV puis
        // font autre chose du coup — rejouer l'attaque, ou la retourner. Le
        // tronc commun plus bas ne sait que rendre, d'où leur propre sortie.
        if ($action === ReactionEffet::RELANCE_ATTAQUE) {
            return $this->relancerLaVolee($groupe, $heros, $etat, $victime, $etatVictime, $attente);
        }

        if ($action === ReactionEffet::REFLET_SORT) {
            return $this->refleterLeSort($groupe, $heros, $etat, $victime, $etatVictime, $attente);
        }

        // ⚠ Le compte rendu du jet de destruction est mis DE CÔTÉ et fusionné
        // plus bas, une fois `$payload` construit. Il était fusionné ici, sur
        // une variable pas encore définie : `null + array` est un TypeError, et
        // les Cendres du Phénix plantaient net au moment exact où elles
        // sauvaient un héros. Aucun test ne passait par cette branche.
        $consomme = [];

        if ($action === ReactionEffet::PLANCHER_PV) {
            // « reduced to 0 → instead reduce them to 1 » : on ne rend pas le
            // coup, on pose un plancher. Un seul PV, jamais davantage.
            $rendus = max(0, 1 - (int) $victime->pv_body);
            $victime->update(['pv_body' => max(1, (int) $victime->pv_body)]);

            // ⚠ *Cendres du Phénix* : « Immediately roll 1 red die ; on a 5 or 6,
            // this artifact is LOST. » Perdu, pas épuisé — c'est le seul objet du
            // catalogue qui se détruit sur un jet, et il fallait le dire ici
            // plutôt que de le rendre inerte comme une charge à zéro.
            if (isset($attente['artefact'])) {
                $consomme = $this->consumerArtefactPlancher($heros, $etat, (int) $attente['artefact']);
            }
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
            ...$consomme,
        ];

        Journal::ajouter($groupe, 'combat', $payload, ['nom' => $heros->nom]);
        $this->reprendreVerdictDeChute($groupe);

        return $payload;
    }

    /**
     * Rend les PV du coup à la victime et la remet debout si elle était tombée.
     * Renvoie ce qui a réellement été rendu.
     */
    private function defaireLeCoup(
        Personnage $victime,
        ?EtatPersonnageQuete $etatVictime,
        int $degats,
    ): int {
        $rendus = min($degats, (int) $victime->pv_body_max - (int) $victime->pv_body);

        if ($rendus > 0) {
            $victime->update(['pv_body' => (int) $victime->pv_body + $rendus]);
        }

        if ((int) $victime->pv_body > 0 && $etatVictime?->tombe) {
            $etatVictime->update(['tombe' => false]);
        }

        return $rendus;
    }

    /**
     * *Bouclier de l'Aube* : le coup est défait, puis le monstre le rejoue avec
     * des dés neufs — attaque ET défense, puisque du point de vue de la carte
     * la défense n'avait pas encore été lancée.
     *
     * ⚠ Les dégâts repassent par `MoteurDegats::infligerAHeros()` : c'est le
     * point de passage unique, il applique les réductions de talent et rouvre
     * les réactions. Le bouclier, lui, a dépensé sa fenêtre — il ne peut pas
     * se proposer en boucle.
     *
     * @param  array<string, mixed>  $attente
     * @return array<string, mixed>
     */
    private function relancerLaVolee(
        Groupe $groupe,
        Personnage $heros,
        EtatPersonnageQuete $etat,
        Personnage $victime,
        ?EtatPersonnageQuete $etatVictime,
        array $attente,
    ): array {
        $contexte = (array) ($attente['contexte'] ?? []);
        $rendus = $this->defaireLeCoup($victime, $etatVictime, (int) ($attente['degats'] ?? 0));

        $this->consommerArtefact($heros, $etat, $attente);

        $resultat = (new Combat(app(LanceurDes::class)))->resoudreAttaque(
            desAttaque: max(0, (int) ($contexte['des_attaque'] ?? 0)),
            desDefense: max(0, (int) ($contexte['des_defense'] ?? 0)),
            typeDefenseur: TypeFigurine::Heros,
            pvBodyDefenseur: (int) $victime->fresh()->pv_body,
        );

        $subis = app(MoteurDegats::class)->infligerAHeros(
            $victime, $resultat->degats, (string) ($attente['source'] ?? MoteurDegats::SOURCE_ATTAQUE_MONSTRE),
            [...$contexte, 'relance' => true],
        );

        if ((int) $victime->fresh()->pv_body === 0 && $subis > 0) {
            $etatVictime?->update(['tombe' => true]);
        }

        $payload = [
            'type' => 'reaction',
            'personnage' => $heros->nom,
            'victime' => $victime->nom,
            'nom' => $attente['nom'] ?? null,
            'action' => ReactionEffet::RELANCE_ATTAQUE,
            'active' => true,
            'degats_annules' => $rendus,
            'degats_relance' => $subis,
            'pv_body_apres' => (int) $victime->fresh()->pv_body,
            ...$resultat->pourJournal(),
        ];

        Journal::ajouter($groupe, 'combat', $payload, ['nom' => $heros->nom]);
        $this->reprendreVerdictDeChute($groupe);

        return $payload;
    }

    /**
     * *Bâton Ancien* : le sort quitte la victime et retombe sur la salle du
     * lanceur.
     *
     * ⚠ Une charge est dépensée MÊME si le lanceur est déjà tombé entre-temps :
     * le bâton a agi, c'est ce que le joueur a choisi. Rendre la charge parce
     * que la cible a disparu serait un remboursement que la carte ne prévoit
     * pas.
     *
     * @param  array<string, mixed>  $attente
     * @return array<string, mixed>
     */
    private function refleterLeSort(
        Groupe $groupe,
        Personnage $heros,
        EtatPersonnageQuete $etat,
        Personnage $victime,
        ?EtatPersonnageQuete $etatVictime,
        array $attente,
    ): array {
        $contexte = (array) ($attente['contexte'] ?? []);
        $rendus = $this->defaireLeCoup($victime, $etatVictime, (int) ($attente['degats'] ?? 0));

        // Un sort de contrôle ne blessait pas : ce qu'il faut défaire, c'est la
        // condition qu'il vient de poser.
        $condition = (string) ($contexte['condition'] ?? '');

        if ($condition !== '') {
            app(MoteurDread::class)->retirerConditionHeros($victime, $condition);
        }

        $this->consommerArtefact($heros, $etat, $attente);

        $quete = $etat->quete;
        $lanceur = $quete?->instancesMonstres()
            ->whereKey((int) ($contexte['lanceur_id'] ?? 0))->with('monstre')->first();

        $retour = ($quete !== null && $lanceur !== null)
            ? app(ResolveurTour::class)->subirRefletDeSort($quete, $lanceur, $contexte)
            : ['effets' => []];

        $payload = [
            'type' => 'reaction',
            'personnage' => $heros->nom,
            'victime' => $victime->nom,
            'nom' => $attente['nom'] ?? null,
            'sort' => $contexte['sort'] ?? null,
            'action' => ReactionEffet::REFLET_SORT,
            'active' => true,
            'degats_annules' => $rendus,
            'condition_annulee' => $condition !== '' ? $condition : null,
            'reflet' => $retour,
        ];

        Journal::ajouter($groupe, 'combat', $payload, ['nom' => $heros->nom]);
        $this->reprendreVerdictDeChute($groupe);

        return $payload;
    }

    /**
     * Dépense la fenêtre ou la charge de la pièce qui a servi.
     *
     * @param  array<string, mixed>  $attente
     */
    private function consommerArtefact(Personnage $heros, EtatPersonnageQuete $etat, array $attente): void
    {
        if (! isset($attente['artefact'])) {
            return;
        }

        $ligne = $heros->inventaire()->with('objet')->whereKey((int) $attente['artefact'])->first();

        if ($ligne !== null) {
            app(MoteurCharges::class)->consommerUsage($ligne, $etat);
        }
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
     * Boit la potion ou lance le sort choisi, puis remet le héros debout si les
     * PV sont revenus au-dessus de zéro.
     *
     * ⚠ La `cle` renvoyée par le joueur est revalidée contre la liste DÉPOSÉE :
     * c'est la même règle que `parametres.cibles` pour une attaque — l'offre
     * porte la liste blanche, et rien d'autre n'est buvable. Sans elle, un
     * client pourrait faire boire n'importe quelle ligne d'inventaire, y compris
     * celle d'un compagnon.
     *
     * @param  array<string, mixed>  $attente
     * @return array<string, mixed>
     */
    private function soigner(
        Groupe $groupe,
        Personnage $heros,
        EtatPersonnageQuete $etat,
        array $attente,
        ?string $choix,
    ): array {
        $proposes = (array) ($attente['soins'] ?? []);
        $cles = array_column($proposes, 'cle');

        // Sans choix explicite, la première entrée — les potions d'abord. Un
        // client ancien (ou un joueur pressé) doit pouvoir répondre « oui ».
        $cle = $choix !== null && in_array($choix, $cles, true) ? $choix : ($cles[0] ?? null);

        if ($cle === null) {
            throw ValidationException::withMessages([
                'reaction' => 'Plus rien à boire ni à lancer.',
            ]);
        }

        [$type, $id] = array_pad(explode(':', $cle, 2), 2, null);
        $avant = (int) $heros->pv_body;
        $detail = collect($proposes)->firstWhere('cle', $cle);

        if ($type === 'potion') {
            $ligne = $heros->inventaire()->with('objet')->whereKey((int) $id)->first();

            if ($ligne === null) {
                throw ValidationException::withMessages([
                    'reaction' => "Cette potion n'est plus dans ton sac.",
                ]);
            }

            // Le moteur des potions fait foi : c'est lui qui connaît les soins
            // fixes, le 1d6 de la fiole et la consommation de l'exemplaire.
            app(MoteurPotions::class)->boire($heros, $ligne);
        } elseif ($type === 'artefact') {
            // Artefact PORTÉ : on soigne et on dépense une CHARGE — la pièce
            // reste au sac et devient inerte, elle n'est jamais consommée.
            $ligne = $heros->inventaire()->with('objet')->whereKey((int) $id)->first();
            $charges = app(MoteurCharges::class);

            if ($ligne === null || ! $charges->disponible($ligne)) {
                throw ValidationException::withMessages([
                    'reaction' => "Cet artefact n'est plus utilisable.",
                ]);
            }

            $heros->update(['pv_body' => min(
                (int) $heros->pv_body_max,
                $avant + (int) (($ligne->objet->effet['soin_pv_body'] ?? 0)),
            )]);
            $charges->consommer($ligne);
        } else {
            $sort = $heros->sorts()->wherePivot('disponible', true)->find((int) $id);

            if ($sort === null) {
                throw ValidationException::withMessages([
                    'reaction' => "Ce sort n'est plus disponible.",
                ]);
            }

            $heros->update(['pv_body' => min(
                (int) $heros->pv_body_max,
                $avant + (int) ($sort->effet['soin_pv_body'] ?? 0),
            )]);
            $heros->sorts()->updateExistingPivot($sort->id, ['disponible' => false]);
        }

        $heros->refresh();
        $rendus = (int) $heros->pv_body - $avant;

        // Debout — c'est tout l'objet de la manœuvre. Un soin qui laisserait le
        // héros à 0 (aucun PV rendu) le laisse à terre : la potion aura été bue
        // pour rien, mais rien n'est inventé pour le sauver.
        if ((int) $heros->pv_body > 0 && $etat->tombe) {
            $etat->update(['tombe' => false]);
        }

        $payload = [
            'type' => 'reaction',
            'personnage' => $heros->nom,
            'victime' => $heros->nom,
            'sort' => $detail['nom'] ?? null,
            'action' => ReactionEffet::SOIN_URGENCE,
            'active' => true,
            'soin' => $rendus,
            'pv_body' => (int) $heros->pv_body,
            'debout' => (int) $heros->pv_body > 0,
            'source' => $attente['source'] ?? null,
        ];

        Journal::ajouter($groupe, 'combat', $payload, ['nom' => $heros->nom]);
        $this->reprendreVerdictDeChute($groupe);

        return $payload;
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
     * Reprend le verdict de chute maintenant qu'une offre est consommée.
     *
     * Le TPK a pu être SUSPENDU en fin de round parce que cette réaction
     * attendait (`ResolveurTour::verdictDeChute`) : c'est ici qu'il se tranche,
     * dans un sens ou dans l'autre. Sans ce rappel, un groupe entièrement à
     * terre resterait en quête pour toujours.
     *
     * `app()` plutôt qu'une injection : le résolveur dépend de `MoteurDegats`,
     * qui dépend de ce moteur-ci — le conteneur boucterait.
     */
    private function reprendreVerdictDeChute(Groupe $groupe): void
    {
        $quete = $groupe->fresh()->queteCourante;

        if ($quete !== null && $quete->etat === 'en_cours') {
            app(ResolveurTour::class)->verdictDeChute($groupe, $quete);
        }
    }

    /**
     * RATTRAPAGE : consomme les propositions dont la fenêtre est passée, puis
     * reprend le verdict de chute. Rend `true` si quelque chose a bougé.
     *
     * ⚠ Filet indispensable, et la leçon a déjà été payée une fois avec le
     * verrou « MJ réfléchit » : **une expiration côté serveur n'atteint aucun
     * client**. Si le joueur ne répond jamais — téléphone verrouillé, appli
     * fermée, batterie morte — sa proposition reste en attente, et avec elle un
     * verdict de TPK suspendu : le groupe resterait figé à terre, en quête,
     * sans que rien ne puisse plus trancher. On repasse donc ici à chaque
     * battement de cœur de la table et à chaque lecture d'état.
     */
    public function rattraperExpiration(Groupe $groupe): bool
    {
        $quete = $groupe->queteCourante;

        if ($quete === null || $quete->etat !== 'en_cours') {
            return false;
        }

        $purgees = 0;

        foreach ($quete->etatsPersonnages()->whereNotNull('reaction_en_attente')->with('personnage')->get() as $etat) {
            $attente = (array) $etat->reaction_en_attente;
            $expire = $attente['expire_a'] ?? null;

            if ($expire === null || now()->lessThanOrEqualTo($expire)) {
                continue;
            }

            $etat->update(['reaction_en_attente' => null]);
            $purgees++;

            Journal::ajouter($groupe, 'combat', [
                'type' => 'reaction',
                'personnage' => $etat->personnage?->nom,
                'action' => $attente['action'] ?? null,
                'active' => false,
                'raison' => 'Fenêtre de réaction écoulée.',
            ], ['nom' => $etat->personnage?->nom ?? 'Un héros']);
        }

        if ($purgees === 0) {
            return false;
        }

        app(ResolveurTour::class)->verdictDeChute($groupe, $quete);

        return true;
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
