<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\Des\FaceDeCombat;
use App\Engine\Des\LanceurDes;
use App\Models\Condition;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\SortDread;
use App\Support\Journal;
use Illuminate\Support\Facades\DB;

/**
 * LE GEL DE *MIND LOCK* — *Against the Ogre Horde* (F9528 p. 10).
 *
 * « For each skull scored by the attacker, the defender is frozen for one turn. If no
 * skulls are scored against the defender, the spell has no effect. A frozen hero rolls
 * 1 Defend die and may not move, attack, or perform any other action. That hero takes a
 * Mind Lock card for each turn they are affected, returning one card to Zargon at the end
 * of each of their turns spent frozen. When they return a card, they may attempt to break
 * the spell's hold by rolling a number of combat dice equal to their Mind Points. On a roll
 * result of three or more skulls, they return all remaining Mind Lock cards and the spell
 * ends. » — et, p. 10 encore : « If a Dread Sorcerer has an active spell and is killed, the
 * effects of that spell immediately end. »
 *
 * UN ÉTAT, DEUX MOITIÉS, UN SEUL ÉCRIVAIN :
 *  - la CONDITION `Verrou mental` (`personnage_conditions`, sans compteur) porte ce que le gel
 *    FAIT — `deplacement_interdit`, `action_interdite`, `des_attaque_max: 0`, `des_defense_max: 1`,
 *    lus par les lecteurs qui existent déjà (menu, défense, attaque) : « 1 dé de défense, ne bouge
 *    ni n'agit » n'a aucune règle neuve à câbler, seulement un état à poser ;
 *  - la colonne `etat_personnage_quete.verrou_mental` porte ce que le gel COMPTE — `{lanceur_id,
 *    sort, cartes}`, les cartes que le héros tient encore et le Sorcier à qui elles reviennent.
 *    Colonne et non cache : le gel traverse la phase des monstres (où le sort se lance) et le round
 *    suivant (où le tour est perdu), un téléphone rechargé, une reprise de snapshot.
 *
 * ⚠ La condition n'a PAS de compteur (`duree: 0`) : `MoteurSorts::decrementerDurees()` la
 * décrémenterait chaque round et la retirerait dans le dos de la carte. Sa sortie est un
 * déclencheur — la dernière carte rendue, trois crânes, ou la mort du lanceur.
 *
 * ⚠ « At the END of each of their turns spent frozen » se joue à l'OUVERTURE du round, dans
 * {@see self::ouvrirTour()}, et non à la fin du tour du héros : un moteur par rounds n'a pas de
 * « fin du tour de ce héros » qui ne soit déjà le début de celui du suivant, et rien ne se passe
 * entre les deux — le héros est gelé, il ne fait rien. Équivalent, et c'est le même crochet que
 * l'*Étourdi* (`ResolveurTour::ouvrirTourDesHeros()`).
 */
final class VerrouMental
{
    public const CONDITION = 'Verrou mental';

    /** Le nom du sort au catalogue ET la source de la condition (`sort_dread:Verrou mental`). */
    public const SORT = 'Verrou mental';

    public function __construct(private readonly LanceurDes $des) {}

    /**
     * Enregistre le gel : combien de cartes le héros tient, et à qui elles reviennent.
     * Appelé par `MoteurDread::sortDreadControle()` APRÈS qu'il a posé la condition.
     *
     * ⚠ On ne prête JAMAIS plus de cartes qu'il n'en reste au Sorcier : « un crâne = un tour »
     * est borné par ce que Zargon tient en main — une carte ne se fabrique pas. Rend le nombre de
     * cartes réellement prêtées (0 = le sort a pris mais rien à tenir : voir l'appelant).
     */
    public function enregistrer(EtatPersonnageQuete $etat, InstanceMonstre $lanceur, SortDread $sort, int $cranesNets): int
    {
        $parCrane = max(1, (int) data_get($sort->effet, 'gel_par_crane', 1));
        $voulues = $cranesNets * $parCrane;
        $prises = min($voulues, CartesDread::restantes($lanceur, $sort->nom));

        if ($prises <= 0) {
            return 0;
        }

        for ($i = 0; $i < $prises; $i++) {
            CartesDread::depenser($lanceur, $sort->nom);
        }

        $etat->update(['verrou_mental' => [
            'lanceur_id' => (int) $lanceur->id,
            'sort' => $sort->nom,
            'cartes' => $prises,
        ]]);

        return $prises;
    }

    /** Le héros est-il gelé (état durable, jamais déduit de la seule condition) ? */
    public function estGele(?EtatPersonnageQuete $etat): bool
    {
        return $etat !== null && is_array($etat->verrou_mental) && (int) ($etat->verrou_mental['cartes'] ?? 0) > 0;
    }

    /**
     * Ce que la fiche du héros dit de son gel — la DÉCISION, décidée ici (`conditions[].effet_source`,
     * `EtatGroupe::conditionsHeros()`) : combien de tours encore, et ce qui le libère.
     */
    public function texte(?EtatPersonnageQuete $etat): ?string
    {
        if (! $this->estGele($etat)) {
            return null;
        }

        $n = (int) $etat->verrou_mental['cartes'];
        $seuil = $this->seuil((string) ($etat->verrou_mental['sort'] ?? self::SORT));

        return "Gelé pour {$n} tour".($n > 1 ? 's' : '')." encore (il tient {$n} carte".($n > 1 ? 's' : '').' de Verrou mental) : '
            .'à chaque tour gelé il en rend une, puis lance autant de dés de combat que de points de Mind'
            .($seuil !== null ? " — {$seuil} crânes ou plus brisent l'emprise" : '');
    }

    /**
     * L'OUVERTURE du round pour ce héros : s'il est gelé, son tour est PERDU, il rend une carte
     * au Sorcier, et — s'il lui en reste — tente de briser l'emprise.
     *
     * Rend le payload `verrou_mental_tour` (journalisé ici ET remonté par l'appelant dans le
     * résultat : un jet de dés que personne ne voit n'a pas eu lieu pour la table), ou `null`
     * si le héros n'est pas gelé.
     *
     * @return array<string, mixed>|null
     */
    public function ouvrirTour(Groupe $groupe, EtatPersonnageQuete $etat): ?array
    {
        $personnage = $etat->personnage;

        if ($personnage === null) {
            return null;
        }

        if (! $this->estGele($etat)) {
            // Une condition orpheline (état de quête recréé, quête précédente close gelée) ne
            // doit jamais geler un héros à vie : sans compteur ni colonne pour la lever, plus rien ne
            // la lèverait. Rien d'autre à faire — l'absence de gel EST la décision.
            $this->retirerCondition($personnage);

            return null;
        }

        $verrou = (array) $etat->verrou_mental;
        $sort = (string) ($verrou['sort'] ?? self::SORT);
        $lanceur = InstanceMonstre::with('monstre')->find((int) ($verrou['lanceur_id'] ?? 0));
        $avant = (int) $verrou['cartes'];
        $apres = $avant - 1;

        // Son tour est PERDU : même écriture que l'Étourdi (`consommerTourPerdu`) — le menu ne
        // s'ouvre pas pour un héros qui a joué, et la phase des monstres n'attend pas après lui.
        $etat->update(['a_joue' => true, 'a_deplace' => true, 'a_agi' => true]);

        // « Returning one card to Zargon » : elle revient dans la main de SON Sorcier.
        if ($lanceur !== null) {
            CartesDread::rendre($lanceur, $sort);
        }

        // « When they return a card, they may attempt to break the spell's hold » : s'il n'en reste
        // aucune, l'emprise cède d'elle-même et un jet ne déciderait rien — pas de dés pour rien.
        $tentative = null;
        $libere = $apres <= 0;
        $raison = $libere ? 'cartes_rendues' : null;

        if (! $libere) {
            $tentative = $this->tenterLiberation($personnage, $sort);

            if ($tentative !== null && $tentative['libere']) {
                $libere = true;
                $raison = 'cranes';

                // « They return ALL remaining Mind Lock cards. »
                if ($lanceur !== null) {
                    CartesDread::rendre($lanceur, $sort, $apres);
                }
            }
        }

        if ($libere) {
            $this->lever($etat);
        } else {
            $etat->update(['verrou_mental' => [...$verrou, 'cartes' => $apres]]);
        }

        $payload = [
            'type' => 'verrou_mental_tour',
            'personnage_id' => (int) $personnage->id,
            'nom' => $personnage->nom,
            'sort' => $sort,
            'lanceur' => $lanceur === null ? null : ['instance_id' => (int) $lanceur->id, 'nom' => $lanceur->nomAffiche()],
            'cartes_avant' => $avant,
            'cartes_apres' => $libere ? 0 : $apres,
            'tentative' => $tentative,
            'libere' => $libere,
            'raison' => $raison,
        ];

        Journal::ajouter($groupe, 'action', $payload, ['type' => 'personnage', 'id' => (int) $personnage->id, 'nom' => $personnage->nom]);

        return $payload;
    }

    /**
     * La tentative de libération : autant de DÉS DE COMBAT que de points de Mind POSSÉDÉS (la jauge
     * `pv_mind`, errata 2021 C4 — un esprit entamé se libère moins bien, un esprit vide ne se libère
     * plus), « three or more skulls » libèrent. Le seuil est la donnée du sort (`liberation_cranes`).
     *
     * @return array{des: int, faces: list<string>, cranes: int, seuil: int, libere: bool}|null  `null` si le sort ne dit aucun seuil
     */
    public function tenterLiberation(Personnage $personnage, string $nomSort): ?array
    {
        $seuil = $this->seuil($nomSort);

        if ($seuil === null) {
            return null;
        }

        $nb = max(0, (int) $personnage->pv_mind);
        $faces = $this->des->desCombat($nb);
        $cranes = count(array_filter($faces, fn (FaceDeCombat $f) => $f === FaceDeCombat::Crane));

        return [
            'des' => $nb,
            'faces' => array_map(fn (FaceDeCombat $f) => $f->value, $faces),
            'cranes' => $cranes,
            'seuil' => $seuil,
            'libere' => $cranes >= $seuil,
        ];
    }

    /** Le nombre de crânes qui libèrent (`liberation_cranes`), ou `null` si le sort n'en dit rien. */
    private function seuil(string $nomSort): ?int
    {
        $seuil = data_get(SortDread::where('nom', $nomSort)->first()?->effet, 'liberation_cranes');

        return $seuil === null ? null : (int) $seuil;
    }

    /** Lève le gel : colonne ET condition, d'un même geste. Les cartes restantes sont rendues par l'appelant. */
    public function lever(EtatPersonnageQuete $etat): void
    {
        $etat->update(['verrou_mental' => null]);

        if ($etat->personnage !== null) {
            $this->retirerCondition($etat->personnage);
        }
    }

    private function retirerCondition(Personnage $personnage): void
    {
        $ids = Condition::where('nom', self::CONDITION)->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('personnage_conditions')
            ->where('personnage_id', $personnage->id)
            ->whereIn('condition_id', $ids)
            ->delete();
    }

    /**
     * Un nouveau départ de quête : plus personne n'est gelé. Appelé par
     * `MoteurSorts::reinitialiserQuete()` — les conditions d'un sort de Dread ne portent pas le
     * préfixe `sort:` que cette purge connaît, et un gel resté sur un héros d'une quête à l'autre
     * le priverait de son tour sans qu'aucune colonne ne sache plus le lever.
     */
    public function purger(Personnage $personnage): void
    {
        $this->retirerCondition($personnage);
    }

    /**
     * LA MORT DU SORCIER : « the effects of that spell immediately end ». Tout héros qu'il tenait
     * gelé est libéré sur-le-champ — sans jet, sans carte rendue (le lanceur est tombé).
     *
     * Appelé par `MoteurDegats::infligerAMonstre()`, l'UNIQUE point de passage de la mort d'un
     * monstre. Chaque libération est ANNONCÉE (`verrou_mental_leve`) : un héros qui retrouve son
     * menu sans un mot du fil croirait à un bug.
     *
     * @return list<array<string, mixed>>
     */
    public function leverPourLanceur(InstanceMonstre $lanceur): array
    {
        $quete = Quete::find($lanceur->quete_id);

        if ($quete === null) {
            return [];
        }

        $annonces = [];

        foreach ($quete->etatsPersonnages()->with('personnage')->get() as $etat) {
            if (! $this->estGele($etat) || (int) ($etat->verrou_mental['lanceur_id'] ?? 0) !== (int) $lanceur->id) {
                continue;
            }

            $sort = (string) ($etat->verrou_mental['sort'] ?? self::SORT);
            $this->lever($etat);

            $payload = [
                'type' => 'verrou_mental_leve',
                'personnage_id' => (int) $etat->personnage_id,
                'nom' => $etat->personnage?->nom,
                'sort' => $sort,
                'lanceur' => ['instance_id' => (int) $lanceur->id, 'nom' => $lanceur->nomAffiche()],
                'raison' => 'lanceur_vaincu',
            ];

            $annonces[] = $payload;

            if (($groupe = $quete->groupe) !== null) {
                app(TamponAnnonces::class)->annoncer($groupe, $payload, ['type' => 'monstre', 'id' => (int) $lanceur->id, 'nom' => $lanceur->nomAffiche()]);
            }
        }

        return $annonces;
    }
}
