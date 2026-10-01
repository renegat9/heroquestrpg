<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\Combat;
use App\Engine\Des\LanceurDes;
use App\Engine\ResultatAttaque;
use App\Engine\TypeFigurine;
use App\Models\EtatPersonnageQuete;
use App\Models\Personnage;

/**
 * L'ORACLE (First Light, FL-Q p. 6, lot C) — Bénédiction et Malédiction.
 *
 * Trois choses cohabitent ici et ne se confondent jamais :
 *
 *  - l'ÉPREUVE elle-même (`App\Engine\MotsClesEpreuve::MECANIQUES['oracle']`,
 *    posée sur la carte comme les six autres) : réussie → Bénédiction,
 *    ratée → Malédiction. Sa résolution reste dans
 *    `ResolveurTour::epreuveOracle()`, qui APPELLE ce moteur pour écrire les
 *    deux colonnes durables — un point de passage, pas deux.
 *  - la BÉNÉDICTION, un état PERSONNEL ponctuel : au choix, UNE fois, (a)
 *    révéler une salle derrière une porte fermée adjacente sans l'ouvrir, ou
 *    (b) après un jet d'Attaque ou de Défense, relancer tous les dés en
 *    gardant le second résultat (réaction hors tour, voir `MoteurReactions`).
 *  - la MALÉDICTION (jeton Mark of Zargon) : Zargon est ici le MOTEUR — rien
 *    ne lui demande son avis, l'IA n'invente aucune mécanique — et ce fichier
 *    DÉCLARE sa politique : **déterministe, et elle agit à la PREMIÈRE
 *    occasion éligible de la quête** (jamais « au choix du MJ », que ce
 *    moteur n'a pas les moyens d'arbitrer), en gardant le résultat le PIRE
 *    pour le héros entre le jet d'origine et la relance complète — René :
 *    « il garde le résultat le pire pour le héros ». La carte ne dit QUAND
 *    Zargon choisit d'agir dans la quête ; « au plus tôt » est NOTRE
 *    arbitrage, écrit comme tel, pas une donnée sourcée.
 */
final class MoteurOracle
{
    /** La bénédiction attend-elle encore d'être dépensée ? */
    public function benedictionDisponible(Personnage $heros): bool
    {
        return (bool) $heros->benediction_oracle;
    }

    /** Accorde la Bénédiction — réussite de l'épreuve de l'Oracle. */
    public function accorderBenediction(Personnage $heros): void
    {
        $heros->update(['benediction_oracle' => true]);
    }

    /** Dépense la Bénédiction, quelle que soit l'option choisie (a ou b). */
    public function consommerBenediction(Personnage $heros): void
    {
        $heros->update(['benediction_oracle' => false]);
    }

    /** Le héros porte-t-il le jeton Mark of Zargon ? */
    public function maledictionActive(Personnage $heros): bool
    {
        return (bool) $heros->malediction_oracle;
    }

    /** Accorde la Malédiction — échec de l'épreuve de l'Oracle. */
    public function accorderMalediction(Personnage $heros): void
    {
        $heros->update(['malediction_oracle' => true]);
    }

    /**
     * Lève la Malédiction — don de 800 po, entre deux quêtes, au marché
     * (`PhaseMarche::leverMalediction()`, qui porte le coût). Ce moteur ne
     * fait qu'écrire la colonne : il ne sait rien de la bourse.
     */
    public function leverMalediction(Personnage $heros): void
    {
        $heros->update(['malediction_oracle' => false]);
    }

    /**
     * Le jeton Mark of Zargon est-il encore utilisable CETTE quête ?
     */
    public function jetonDisponible(Personnage $heros, EtatPersonnageQuete $etat): bool
    {
        return $this->maledictionActive($heros) && ! $etat->malediction_oracle_utilisee;
    }

    /**
     * Zargon force la relance COMPLÈTE de l'échange (attaque ET défense,
     * notre moteur ne les sépare pas — voir `Combat::resoudreAttaque()`) si
     * le jeton est encore disponible ce tour-ci, et garde le résultat le PIRE
     * pour le héros.
     *
     * ⚠ Inséré AVANT toute application de dégâts (dans `ResolveurTour::frapper()`
     * et `resoudreAttaqueMonstre()`, juste après le jet brut) : contrairement
     * à la Bénédiction, rien n'est à défaire après coup — Zargon choisit
     * avant que quiconque n'ait vu le premier jet, exactement comme un MJ
     * humain déciderait AVANT d'annoncer le résultat à la table.
     *
     * @param  bool  $defenseurEstHeros  true si le défenseur de CET échange
     *                                   est le héros maudit (il encaisse une
     *                                   attaque de monstre) ; false s'il est
     *                                   l'attaquant (le monstre défend).
     * @return array{resultat: ResultatAttaque, applique: bool, detail: ?array<string, mixed>}
     */
    public function appliquerSiMaudit(
        ResultatAttaque $resultat,
        bool $defenseurEstHeros,
        int $desAttaque,
        int $desDefense,
        Personnage $heros,
        EtatPersonnageQuete $etat,
        LanceurDes $des,
    ): array {
        // Rien à relancer sans dés réels — une arme à dégâts fixes (Dague de
        // jet magique) ne porte aucune face, et « relancer tous les dés »
        // n'a alors aucun sens.
        if ($resultat->facesAttaque === [] && $resultat->facesDefense === []) {
            return ['resultat' => $resultat, 'applique' => false, 'detail' => null];
        }

        if (! $this->jetonDisponible($heros, $etat)) {
            return ['resultat' => $resultat, 'applique' => false, 'detail' => null];
        }

        $etat->update(['malediction_oracle_utilisee' => true]);

        $relance = (new Combat($des))->resoudreAttaque(
            desAttaque: $desAttaque,
            desDefense: $desDefense,
            typeDefenseur: $defenseurEstHeros ? TypeFigurine::Heros : TypeFigurine::Monstre,
            pvBodyDefenseur: $resultat->pvBodyAvant,
        );

        // « garde le résultat le pire pour le héros » : si le défenseur est
        // le héros, le pire est le PLUS de dégâts encaissés ; s'il attaque
        // (le monstre défend), le pire est le MOINS de dégâts infligés.
        $gardeLaRelance = $defenseurEstHeros
            ? $relance->degats >= $resultat->degats
            : $relance->degats <= $resultat->degats;

        $retenu = $gardeLaRelance ? $relance : $resultat;

        return [
            'resultat' => $retenu,
            'applique' => true,
            'detail' => [
                'degats_original' => $resultat->degats,
                'degats_relance' => $relance->degats,
                'garde' => $gardeLaRelance ? 'relance' : 'origine',
            ],
        ];
    }
}
