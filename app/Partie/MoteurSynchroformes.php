<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\Des\DeRouge;
use App\Engine\Des\LanceurDes;
use App\Models\EtatPersonnageQuete;
use App\Models\InstanceMonstre;
use App\Models\Monstre;

/**
 * LES FORMES QUI SUCCÈDENT à une créature, et ce qui arrive à la figure quand elle change de forme.
 *
 * Deux mécanismes de forme coexistent, et ce service est le SEUL endroit où l'on décide « quelle forme
 * vient après celle-ci ? » (`MoteurDegats::infligerAMonstre()` ne lit plus `phase_suivante` lui-même) :
 *
 *  - la chaîne STATIQUE des phases (Gruzbella, Gretzl, Gruulob…) : `monstres.phase_suivante`, la même
 *    créature « qui garde ses sorts » ; rien ne change d'autre que la fiche ;
 *  - la PIOCHE de Qwindrak (`synchroformes`, livret First Light p. 7 : « the warlock assumes one or more
 *    distinct creature forms called Synchroforms … If he transforms, place the new miniature on the square
 *    occupied by the previous Synchroform ») : à la première mort de Qwindrak, les cinq formes sont
 *    mélangées au dé injecté, le Dragon est posé AU FOND (la carte de Banjo, validée par René le
 *    2026-10-17 : « c'est le même fonctionnement »), puis chaque forme vaincue cède la place à la suivante.
 *
 * Pour la pioche, trois écritures, et aucune ailleurs :
 *  - l'ORDRE est tiré UNE fois (`tirer()`, par `OubliSorts::indiceAleatoire()` — le hasard du moteur) et
 *    stocké en base (`instances_monstres.formes_a_venir`) : la reprise d'une partie et le snapshot le
 *    rejouent à l'identique, et la file n'est jamais montrée au joueur (secret de Zargon) ;
 *  - les SORTS ACTIFS cessent à chaque changement de forme (« Any spell active on Qwindrak ends »), là où
 *    la chaîne des phases les garde (« still considered the same monster for game effects such as spells »,
 *    Ogre Horde p. 6). Les compteurs de la rencontre (usages de Dread, réactions déjà jouées) ne cessent
 *    PAS : ils ne sont pas des sorts actifs, et les rendre remettrait au boss ce qu'il a déjà dépensé ;
 *  - la forme DRAGON est la dernière : sa place doit être SÛRE (emprise 1 × 2, `ancrer()`), et son arrivée
 *    souffle la flamme du Dread (`flammeALArrivee()`, `reference/18` §6.1).
 *
 * LE RÉPERTOIRE ET LE MÉTÉORE de Qwindrak ne sont pas portés par les formes : elles sont des lignes génériques
 * partagées (Gobelin, Orque…). La figure garde son ORIGINE (`instances_monstres.origine_monstre`, écrite au
 * tirage de la pioche, rejouée par le snapshot), et tout ce qu'elle emporte de la source se lit sur la fiche
 * d'origine — `InstanceMonstre::ligneDeReference()`. Le répertoire est sourcé (livret de quêtes First Light,
 * p. 31, note C : « In all forms, Qwindrak knows the Dread spells Tempest, Cloud of Dread, and Lightning Bolt »).
 *
 * ⚠ Hors périmètre, nommé : les deux autres issues de la note de quête (« escape », « take on his true
 * warlock appearance ») concernent les quêtes 1 à 9, pas le combat final ; les règles propres à la quête 10
 * (relance de Mind, Hall of the Dead) ne s'appliquent pas aux quêtes générées, et ne sont pas portées.
 */
final class MoteurSynchroformes
{
    /**
     * Les états de Dread qu'un SORT pose SUR la créature (`habillage.dread_etat`) : ils cessent avec sa
     * forme. Le `maitre` de la Vrille et le `deguise` du Dreadshifter ne sont pas des sorts : ils restent.
     */
    private const ETATS_DE_SORT = [
        'bonus_attaque' => "bonus d'attaque",
        'buff_attaque' => "bonus d'attaque",
        'buff_defense' => 'bonus de défense',
        'jetons_ombre' => "jetons d'ombre",
    ];

    /**
     * LA FORME SUIVANTE — le point de passage unique de la mort d'une forme.
     *
     * Une pioche en cours (`formes_a_venir` non vide) l'emporte ; une pioche jamais tirée et portée par la
     * fiche courante est tirée ICI, à la première mort ; sinon, la chaîne statique des phases. `null` = la
     * créature meurt pour de vrai (dernière forme). Une forme absente du catalogue fait mourir la créature
     * normalement plutôt que de lever une exception au milieu d'un combat (même règle que `phase_suivante`).
     *
     * @return array{monstre: Monstre, synchro: bool}|null  `synchro` : le changement fait partie de la pioche
     */
    public function prochaine(InstanceMonstre $instance): ?array
    {
        // LE MÉTAMORPHE (Kellar's Keep, F4543 p. 25 note A) : sa règle vit sur la FICHE D'ORIGINE (`origine_monstre`, comme
        // celle de Qwindrak) ; un tirage frais à chaque mort, sans pioche stockée. Une figure ordinaire n'en sort jamais.
        if (($metamorphe = $this->metamorpheDe($instance)) !== null) {
            return $this->metamorphe($instance, $metamorphe);
        }

        $ligne = $instance->monstre;
        $formes = $instance->formes_a_venir;

        if ($formes === null && ($pioche = $this->pioche($ligne)) !== null) {
            $formes = $this->tirer($pioche);

            // L'ORIGINE de la figure, écrite AVEC la pioche : c'est la fiche qui a tiré les formes (Qwindrak)
            // qui porte le répertoire et le météore de chacune d'elles (`InstanceMonstre::ligneDeReference()`).
            $instance->update(['origine_monstre' => $ligne->nom_base]);
            $instance->unsetRelation('origine');
        }

        if (is_array($formes) && $formes !== []) {
            $suivante = (string) array_shift($formes);
            $instance->update(['formes_a_venir' => $formes]);

            $monstre = Monstre::where('nom_base', $suivante)->first();

            return $monstre === null ? null : ['monstre' => $monstre, 'synchro' => true];
        }

        $monstre = $ligne->monstrePhaseSuivante();

        return $monstre === null ? null : ['monstre' => $monstre, 'synchro' => false];
    }

    /**
     * « Any spell active on Qwindrak ends » : retire de la créature ses conditions de sort et ses bonus de
     * Dread, et rend LA LISTE de ce qui cesse, pour l'annoncer. Ne touche ni `sorts_lances`, ni les réactions
     * déjà jouées, ni les usages de la rencontre (voir le docblock de la classe).
     *
     * @return list<string>  libellés des effets qui cessent (vide = rien n'était actif)
     */
    public function terminerSorts(InstanceMonstre $instance): array
    {
        $habillage = $instance->habillage ?? [];
        $fins = [];

        foreach (array_keys((array) ($habillage['conditions'] ?? [])) as $cle) {
            $fins[] = MoteurSorts::libelleConditionMonstre((string) $cle);
        }

        $dread = (array) ($habillage['dread_etat'] ?? []);

        foreach (self::ETATS_DE_SORT as $cle => $libelle) {
            if (! empty($dread[$cle])) {
                $fins[] = $libelle;
            }
        }

        unset($habillage['conditions']);

        foreach (array_keys(self::ETATS_DE_SORT) as $cle) {
            unset($habillage['dread_etat'][$cle]);
        }

        if (($habillage['dread_etat'] ?? null) === []) {
            unset($habillage['dread_etat']);
        }

        if ($habillage !== ($instance->habillage ?? [])) {
            $instance->update(['habillage' => $habillage]);
        }

        return array_values(array_unique($fins));
    }

    /**
     * UNE PLACE SÛRE pour la nouvelle emprise. L'ancre reste si toute l'emprise tient
     * (`Grille::empriseArretPossible()` : libre, traversable, pas de terrain qui interdit l'arrêt — la même
     * lecture que le vol du Dragon) ; sinon la case sûre la plus proche (distance de Tchebychev, puis ordre
     * de lecture, pour rester déterministe). Aucune place sûre sur toute la carte : l'ancre reste — un
     * cas que la génération ne produit pas, et que les tests de placement couvrent.
     */
    public function ancrer(InstanceMonstre $instance): void
    {
        $quete = $instance->quete;

        if ($quete === null) {
            return;
        }

        $emprise = $instance->monstre->emprise();
        $x0 = (int) $instance->position_x;
        $y0 = (int) $instance->position_y;
        $grille = FabriqueGrille::pour($quete, null, (int) $instance->id);

        if ($grille->empriseArretPossible($x0, $y0, $emprise['l'], $emprise['h'])) {
            return;
        }

        $cases = (array) ($quete->carte?->grille['cases'] ?? []);
        $hauteur = count($cases);
        $largeur = $hauteur > 0 ? count((array) $cases[0]) : 0;
        $candidats = [];

        for ($y = 0; $y < $hauteur; $y++) {
            for ($x = 0; $x < $largeur; $x++) {
                if ($grille->empriseArretPossible($x, $y, $emprise['l'], $emprise['h'])) {
                    $candidats[] = [max(abs($x - $x0), abs($y - $y0)), $y, $x];
                }
            }
        }

        if ($candidats === []) {
            return;
        }

        usort($candidats, fn (array $a, array $b): int => $a <=> $b);
        [, $y, $x] = $candidats[0];

        $instance->update(['position_x' => $x, 'position_y' => $y]);
    }

    /**
     * LA FLAMME DU DREAD (`flamme_du_dread`, porté par la ligne du Dragon) : à l'arrivée de la forme, chaque
     * héros DEBOUT DANS LA MÊME SALLE lance un dé rouge ; 1-3 lui fait perdre `degats` (1) Body Point.
     * Pas de salle (la créature est en couloir) : personne n'est dans « la même salle », et le DIT.
     *
     * @return array{monstre: string, instance_id: int, resultats: list<array<string, mixed>>}|null
     */
    public function flammeALArrivee(InstanceMonstre $instance): ?array
    {
        $param = ((array) ($instance->monstre->capacites ?? []))['flamme_du_dread'] ?? null;
        $quete = $instance->quete;

        if (! is_array($param) || $quete === null) {
            return null;
        }

        $dread = app(MoteurDread::class);
        $salle = $dread->casesDeLaSalle($quete, $instance);
        $dansLaSalle = $salle === []
            ? collect()
            : $dread->herosDansLesCases($quete->etatsPersonnages()->with('personnage')->get(), $salle)
                ->filter(fn (EtatPersonnageQuete $e): bool => $e->personnage !== null && (int) $e->personnage->pv_body > 0)
                ->values();

        $des = app(LanceurDes::class);
        $degats = app(MoteurDegats::class);
        $sorts = app(MoteurSorts::class);
        $bruts = max(0, (int) ($param['degats'] ?? 1));
        $nom = $instance->nomAffiche();
        $resultats = [];

        foreach ($dansLaSalle as $cible) {
            $personnage = $cible->personnage;
            $face = $des->d6();
            $subis = DeRouge::brule($face)
                ? $degats->infligerAHeros($personnage, $bruts, MoteurDegats::SOURCE_ATTAQUE_MONSTRE, [
                    'monstre' => $nom, 'instance_id' => (int) $instance->id,
                ])
                : 0;

            if ($subis > 0) {
                $sorts->reveillerHeros($personnage);
            }

            $tombe = $subis > 0 && (int) $personnage->fresh()->pv_body === 0;

            if ($tombe) {
                $cible->update(['tombe' => true]);
            }

            $resultats[] = [
                'cible' => ['personnage_id' => (int) $personnage->id, 'nom' => $personnage->nom],
                'des_rouges' => [$face],
                'degats' => $subis,
                'pv_body_apres' => (int) $personnage->fresh()->pv_body,
                'cible_tombee' => $tombe,
            ];
        }

        return ['monstre' => $nom, 'instance_id' => (int) $instance->id, 'resultats' => $resultats];
    }

    /**
     * La fiche d'ORIGINE de la figure, si elle porte la règle du métamorphe (`capacites.metamorphe`), sinon `null`.
     * Une figure qui n'a encore jamais changé de forme est sa propre origine : c'est sa fiche courante qui est lue.
     */
    private function metamorpheDe(InstanceMonstre $instance): ?Monstre
    {
        $fiche = $instance->origine_monstre === null
            ? $instance->monstre
            : Monstre::where('nom_base', $instance->origine_monstre)->first();

        return $fiche !== null && isset(((array) ($fiche->capacites ?? []))['metamorphe'])
            ? $fiche
            : null;
    }

    /**
     * LE MÉTAMORPHE (Kellar's Keep, F4543 p. 25 note A) : « Every time it is killed, shuffle all the monster cards and take the
     * top card. This is the new form the monster takes. […] The monster can be permanently killed only if the new card drawn
     * matches the monster's most recent form. »
     *
     * À CHAQUE mort : un tirage UNIFORME sur le paquet DÉCLARÉ sur la fiche (`capacites.metamorphe.paquet` : les huit cartes 2021
     * de la boîte de base, dans l'ordre déclaré, le même hasard que la pioche de Qwindrak, `OubliSorts::indiceAleatoire()`). La carte tirée ÉGALE la forme qui vient de tomber :
     * mort DÉFINITIVE (`null`). Sinon elle devient la nouvelle forme, à la MÊME place (`infligerAMonstre()` écrit `monstre_id`,
     * donc un snapshot la rejoue sans rien retirer). Pas de `synchro` : la règle ne dit pas que les sorts actifs cessent.
     *
     * ⚠ Une figure du métamorphe garde sa forme de départ (la carte, Abomination) tant qu'elle n'a jamais changé : c'est elle
     * que le premier tirage doit égaler pour une mort définitive.
     * ⚠ Paquet vide : la créature meurt normalement — jamais une forme inventée.
     *
     * @return array{monstre: Monstre, synchro: bool}|null
     */
    private function metamorphe(InstanceMonstre $instance, Monstre $fiche): ?array
    {
        // Le paquet est DÉCLARÉ sur la fiche d'origine (`capacites.metamorphe.paquet`), dans l'ordre déclaré : l'indice
        // tiré tombe toujours sur la même carte pour le même dé. Une carte absente du catalogue est écartée (jamais inventée).
        $noms = array_values(array_map('strval', (array) (((array) $fiche->capacites)['metamorphe']['paquet'] ?? [])));
        $paquet = collect($noms)
            ->map(fn (string $nom) => Monstre::where('nom_base', $nom)->first())
            ->filter()
            ->values();

        if ($paquet->isEmpty()) {
            return null;
        }

        $formeDeDepart = (string) (((array) $fiche->capacites)['metamorphe']['forme_de_depart'] ?? $fiche->nom_base);
        $courante = $instance->monstre->nom_base === $fiche->nom_base ? $formeDeDepart : $instance->monstre->nom_base;

        $tire = $paquet[app(OubliSorts::class)->indiceAleatoire(app(LanceurDes::class), $paquet->count())];
        $definitif = $tire->nom_base === $courante;

        $groupe = $instance->quete?->groupe;

        if ($groupe !== null) {
            app(TamponAnnonces::class)->annoncer($groupe, [
                'type' => 'metamorphe_tirage',
                'monstre' => $instance->nomAffiche(),
                'carte' => $courante,
                'tire' => $tire->nom_base,
                'definitif' => $definitif,
            ]);
        }

        if ($definitif) {
            return null;
        }

        // L'origine s'écrit AVEC la première forme nouvelle : la fiche d'origine porte la règle pour toutes les suivantes.
        if ($instance->origine_monstre === null) {
            $instance->update(['origine_monstre' => $fiche->nom_base]);
            $instance->unsetRelation('origine');
        }

        return ['monstre' => $tire, 'synchro' => false];
    }

    /**
     * La déclaration de pioche de la fiche (`synchroformes`), ou `null` si la fiche n'en porte pas.
     *
     * @return array{formes: list<string>, dernier: string}|null
     */
    private function pioche(Monstre $ligne): ?array
    {
        $decl = ((array) ($ligne->capacites ?? []))['synchroformes'] ?? null;

        if (! is_array($decl) || ! is_array($decl['formes'] ?? null)) {
            return null;
        }

        return [
            'formes' => array_values(array_map('strval', $decl['formes'])),
            'dernier' => (string) ($decl['dernier'] ?? ''),
        ];
    }

    /**
     * Mélange les formes (Fisher–Yates sur le hasard du moteur) puis pose le dernier AU FOND. Un tirage
     * par rejet sur le d6 injecté : le même lanceur donne le même ordre, et la reprise le relit en base.
     *
     * @param  array{formes: list<string>, dernier: string}  $pioche
     * @return list<string>
     */
    private function tirer(array $pioche): array
    {
        $ordre = $pioche['formes'];
        $des = app(LanceurDes::class);
        $oubli = app(OubliSorts::class);

        for ($i = count($ordre) - 1; $i > 0; $i--) {
            $j = $oubli->indiceAleatoire($des, $i + 1);
            [$ordre[$i], $ordre[$j]] = [$ordre[$j], $ordre[$i]];
        }

        if ($pioche['dernier'] !== '') {
            $ordre[] = $pioche['dernier'];
        }

        return $ordre;
    }
}
