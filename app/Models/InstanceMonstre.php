<?php

namespace App\Models;

use App\Partie\EffetsGlobauxQuete;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstanceMonstre extends Model
{
    protected $table = 'instances_monstres';

    protected $fillable = [
        'quete_id',
        'monstre_id',
        'pv_body',
        'pv_body_max',
        'pv_mind',
        'position_x',
        'position_y',
        'etat',
        'elite',
        'revele',
        // Brûlée : la régénération s'arrête définitivement (carte du troll,
        // « damage done by fire is permanent »).
        'brule',
        // Usages de sorts de Dread restants pour la rencontre, et les deux
        // verrous 1×/rencontre (Invocation, Fuite). EN COLONNE, jamais en
        // cache : perdre le compteur rendait le boss muet en silence.
        'usages_dread',
        'invocation_dread_utilisee',
        'fuite_dread_utilisee',
        // Capacités réactives à usage unique déjà dépensées cette RENCONTRE
        // (chantier monstre à phases, 2026-10-04) : `increvable_une_fois`
        // (Sir Ragnar) et chaque entrée de `monstres.capacites.reactions_defense`
        // (Resilience/Demon Wings…). Liste de noms, jamais réinitialisée par un
        // changement de phase — seule une nouvelle rencontre la réarme
        // ({@see \App\Partie\MoteurDread::reinitialiserUsagesInstance()}).
        'capacites_reactives_utilisees',
        // Sorts de Sorcier déjà lancés cette quête (Wizards of Morcar : « each spell
        // may only be used once per quest ») — noms, voir `MoteurDread::consommerUsage()`.
        'sorts_dread_lances',
        // Braise du *Toucher du Brasier* (Moine) : points qui tomberont à la
        // fin du PROCHAIN tour de la créature, puis s'éteignent.
        'degat_differe',
        // Héros qui a allumé la braise (Peacekeeper, 2026-10-08) : lu par
        // `ResolveurTour::consumerBraise()`, `null` quand la braise est éteinte.
        'degat_differe_personnage_id',
        // *Baguette d'Os* : la créature est passée du côté des héros pour un
        // tour. `controle_par` dit CHEZ QUI (la phase des sbires se joue à la
        // fin du tour de ce héros-là), `controle_agi` si elle a déjà joué —
        // sans quoi `phaseMonstres()` la ferait rejouer côté Zargon dans le
        // même round.
        'controle_par',
        'controle_agi',
        'habillage',
    ];

    protected function casts(): array
    {
        return [
            'habillage' => 'array',
            'revele' => 'boolean',
            'elite' => 'boolean',
            'brule' => 'boolean',
            'usages_dread' => 'integer',
            'invocation_dread_utilisee' => 'boolean',
            'fuite_dread_utilisee' => 'boolean',
            'degat_differe' => 'integer',
            'controle_par' => 'integer',
            'controle_agi' => 'boolean',
            'capacites_reactives_utilisees' => 'array',
            'sorts_dread_lances' => 'array',
        ];
    }

    /** Bonus fixe d'un monstre élite (3.6) : +1 attaque / +1 défense / +1 PV Body. */
    public const BONUS_ELITE = 1;

    /** Dés d'attaque effectifs (bloc de stats + bonus élite éventuel). */
    public function attaqueEffective(): int
    {
        $des = $this->statDeBase('attaque') + ($this->elite ? self::BONUS_ELITE : 0);

        // SORTS DE SORCIER (Wizards of Morcar) : +1 dé d'*Affûtage des lames*,
        // +2 de *Marteau de la Ruine* — lus ICI, au seul endroit qui compose
        // les dés d'attaque d'un monstre, avant les plafonds de condition.
        $des += $this->bonusDesDread('attaque');

        // EFFET GLOBAL DE QUÊTE (Gruulob : « All Goblins in this quest … roll 1
        // additional Attack die ») : lu au même endroit que les buffs de faction,
        // avant les plafonds de condition — un gobelin terrifié reste à 1 dé.
        $des += $this->bonusEffetGlobalQuete('attaque');

        // ÉTAT DE CHOC (*Against the Ogre Horde* p. 9) : « While at 0 Mind Points, they can only roll […]
        // 1 Attack die, and 2 Defend dice. […] The creature's Attack and Defend dice can be temporarily
        // increased by some spells and spell scrolls. » Un PLAFOND — « only » —, relevé par les sorts de
        // Sorcier posés sur la créature, jamais par le bonus d'élite ni par l'effet de quête.
        if ($this->estEnChoc()) {
            $des = min($des, 1 + $this->bonusDesDread('attaque'));
        }

        return $this->apresConditions($des, 'attaque');
    }

    /**
     * La stat de BASE d'une volée — celle du catalogue, SAUF pour la figure de la GARDE PERSONNELLE d'un
     * chef (`GardePersonnelle`, Festral : « They each roll 5 Attack dice and 5 Defend dice »), dont la
     * fiche est REMPLACÉE par les dés du livret. Le seul endroit qui lit cet état.
     */
    private function statDeBase(string $volee): int
    {
        $garde = $this->etatDread(\App\Partie\GardePersonnelle::ETAT);

        if (is_array($garde) && isset($garde[$volee])) {
            return (int) $garde[$volee];
        }

        return (int) $this->monstre->{$volee};
    }

    /**
     * ÉTAT DE CHOC d'un MONSTRE : sa jauge de Mind est à zéro ALORS QUE sa fiche en a un. DÉRIVÉ de
     * `instances_monstres.pv_mind`, jamais une colonne — le jumeau de `Personnage::estEnChoc()` : « when a
     * creature reaches 0 Mind Points, they go into shock » vaut pour TOUTE créature (F9528 p. 9), mais ne
     * pouvait pas s'écrire côté monstre tant que rien ne lui retirait de Mind. *Mind Burst* est ce producteur
     * (`MoteurDegats::infligerMindAMonstre()`).
     *
     * Une créature SANS Mind (un squelette, une momie : fiche à 0) n'est jamais « en choc » : elle n'a rien
     * perdu, c'est sa nature — l'immunité aux sorts mentaux, elle, est déjà lue sur la jauge.
     *
     * ⚠ Plafonne les DÉS d'attaque et de défense (`attaqueEffective()`, `defenseEffective()`,
     * `attaqueDistanceEffective()`). « One red movement die » n'est PAS porté : un monstre n'a pas de dé de
     * mouvement mais un score fixe, et la carte ne dit pas ce que devient ce score — nommé, pas deviné.
     */
    public function estEnChoc(): bool
    {
        return $this->monstre !== null
            && (int) $this->monstre->pv_mind > 0
            && (int) $this->pv_mind <= 0;
    }

    /**
     * Applique les conditions posées sur l'instance (`habillage.conditions`) à
     * un nombre de dés — deux sorts en dépendent, et tous deux plafonnent :
     *
     *  - **Terreur** (Warlock) : « attacks are reduced to 1 combat die ». Ce
     *    n'est PAS un malus : c'est un plafond, qui ramène un ogre à 4 dés
     *    comme un gobelin à 2.
     *  - **Ralenti** (Slow, répertoire elfique) : « rolls 1 less combat die
     *    when it attacks OR DEFENDS », donc les deux, « cannot be less than 1 ».
     *
     * ⚠ Un plancher à 1 dans les deux cas : le texte des deux cartes l'exige,
     * et un monstre à 0 dé d'attaque ne pourrait plus jamais toucher.
     *
     * Deux conditions échappent au plancher parce que leurs cartes le disent
     * mot pour mot — « unable to move, attack, or defend » (Paralysé) et « so it
     * cannot move, attack, or defend itself » (Sommeil, doc 16 §3bis) : elles
     * rendent ZÉRO. C'est la même phrase, donc la même ligne.
     */
    private function apresConditions(int $des, string $volee): int
    {
        $conditions = (array) data_get($this->habillage, 'conditions', []);

        if ($volee === 'attaque' && ! empty($conditions['terrifie'])) {
            $des = min($des, 1);
        }

        if (! empty($conditions['ralenti'])) {
            $des -= 1;
        }

        // Paralysé et ENDORMI : « unable to move, attack, or defend ». Zéro dé,
        // pas un malus — les deux seuls cas où le plancher à 1 ne s'applique
        // pas.
        //
        // ⚠ Le dormeur ne parait plus depuis le 2026-09-02 (René : « après tout,
        // il dort »). C'était le dernier écart de la carte de Sommeil, et le
        // corriger ICI plutôt qu'aux trois sites d'appel est ce qui garantit
        // qu'il vaut partout — coup de héros, sort, et attaque d'allié passent
        // tous par `defenseEffective()`.
        //
        // ⚠ L'ORDRE tient tout seul, et il compte : le réveil par l'attaque est
        // posé APRÈS la résolution dans les deux chemins de frappe. Le coup
        // porte donc bien sur un dormeur sans défense, puis le réveille.
        if (! empty($conditions['paralyse']) || ! empty($conditions['endormi'])) {
            return 0;
        }

        return max(1, $des);
    }

    // ------------------------------------------------------------------
    // État des sorts de Sorcier du Dread (`habillage.dread_etat`)
    // ------------------------------------------------------------------
    //
    // Durable (colonne `habillage`, JSON déjà dans le snapshot), jamais en
    // cache. Un seul dictionnaire, quatre sortes d'entrées :
    //   - `sorts_lances`   : noms des sorts déjà lancés cette QUÊTE (« each
    //                        spell may only be used once per quest ») ;
    //   - `jetons_ombre`   : jetons restants de *Scrolls of Morcar* ;
    //   - `bonus_attaque`  : dés d'attaque de *Hammer of Ruin* (0 = rompu) ;
    //   - `buff_defense`, `buff_attaque` : buffs de faction posés PAR un autre
    //                        lanceur (`['par' => id, 'des' => n]`).
    // `decrementerDureesMonstres()` ne touche que `habillage.conditions` : ces
    // états n'y passent pas, leur sortie est un déclencheur nommé.

    /** @return mixed */
    public function etatDread(string $cle, mixed $defaut = null): mixed
    {
        return data_get($this->habillage, "dread_etat.{$cle}", $defaut);
    }

    public function poserEtatDread(string $cle, mixed $valeur): void
    {
        $habillage = $this->habillage ?? [];

        if ($valeur === null) {
            unset($habillage['dread_etat'][$cle]);
        } else {
            $habillage['dread_etat'][$cle] = $valeur;
        }

        $this->update(['habillage' => $habillage]);
    }

    /** Dés ajoutés par les sorts de Sorcier du Dread posés sur cette créature. */
    public function bonusDesDread(string $volee): int
    {
        if ($volee === 'attaque') {
            return (int) $this->etatDread('bonus_attaque', 0)
                + (int) data_get($this->etatDread('buff_attaque'), 'des', 0);
        }

        return (int) data_get($this->etatDread('buff_defense'), 'des', 0);
    }

    /**
     * Les membres d'une faction qui NE portent pas son nom : `faction => [nom_base, …]`. Un
     * membre de plus se déclare ICI, avec sa raison — jamais en élargissant la comparaison de
     * `estDeFaction()`, qui rattacherait au passage « Gruulob, Sorcier Gobelin Corrompu ».
     *
     * Le Chamane Gobelin est un gobelin (René, 2026-10-15) : le bonus de Gruulob
     * (F9907 p. 27, « All Goblins in this quest are elite warriors dedicated to Gruulob and
     * roll 1 additional Attack die ») est pour lui comme pour le Gobelin et son archer.
     * Gruulob et sa forme démoniaque restent HORS faction : ils sont le chef auquel les
     * gobelins sont dévoués, et leur fiche imprimée (3 puis 4 dés) est la leur.
     * `BestiaireSourceTest` vérifie que chaque nom déclaré existe au catalogue.
     */
    public const MEMBRES_SUPPLEMENTAIRES_DE_FACTION = [
        'Gobelin' => ['Chamane Gobelin'],
    ];

    /**
     * La créature appartient-elle à la FACTION nommée ? « Orcs » = les figurines d'Orque,
     * y compris leur variante à distance (`variante_distance_de`) ; « Gobelin » = le Gobelin,
     * son archer et le Chamane Gobelin (`MEMBRES_SUPPLEMENTAIRES_DE_FACTION`). Toujours
     * `nom_base`, le nom de CATALOGUE : l'habillage IA rebaptise.
     *
     * ⚠ Point de passage UNIQUE : les buffs de faction de Morcar (`MoteurDread`) et l'effet
     * global de quête (`bonusEffetGlobalQuete()`) lisent la même identification.
     */
    public function estDeFaction(string $faction): bool
    {
        $monstre = $this->monstre;

        return $monstre !== null
            && ($monstre->nom_base === $faction
                || $monstre->variante_distance_de === $faction
                || in_array($monstre->nom_base, self::MEMBRES_SUPPLEMENTAIRES_DE_FACTION[$faction] ?? [], true));
    }

    /**
     * Dés d'attaque ajoutés par l'EFFET GLOBAL de la quête (`EffetsGlobauxQuete`) : chaque
     * effet de cette quête qui vise la faction de la créature, pour la volée demandée. Lu au
     * même endroit que les buffs de faction (`attaqueEffective()`).
     */
    public function bonusEffetGlobalQuete(string $volee): int
    {
        $quete = $this->quete;

        if ($quete === null) {
            return 0;
        }

        $bonus = 0;

        foreach (EffetsGlobauxQuete::de($quete) as $effet) {
            if (($effet['volee'] ?? null) === $volee && $this->estDeFaction((string) ($effet['faction'] ?? ''))) {
                $bonus += (int) ($effet['des'] ?? 0);
            }
        }

        return $bonus;
    }

    /** Dés d'attaque à distance effectifs (null si le monstre n'a pas de portée). */
    public function attaqueDistanceEffective(): ?int
    {
        $base = $this->monstre->attaque_distance;

        if ($base === null) {
            return null;
        }

        $des = (int) $base + ($this->elite ? self::BONUS_ELITE : 0);

        // Même plafond de choc que `attaqueEffective()` : « 1 Attack die ».
        return $this->estEnChoc() ? min($des, 1 + $this->bonusDesDread('attaque')) : $des;
    }

    /** Dés de défense effectifs (bloc de stats + bonus élite éventuel). */
    public function defenseEffective(): int
    {
        $des = $this->statDeBase('defense') + ($this->elite ? self::BONUS_ELITE : 0);

        // *Bouclier de protection* (Orc Warcaster) : +1 dé de défense tant que
        // le sort tient. Même point de lecture que les dés d'attaque.
        $des += $this->bonusDesDread('defense');

        // ÉTAT DE CHOC : « 2 Defend dice » au plus (voir `attaqueEffective()` et `estEnChoc()`).
        if ($this->estEnChoc()) {
            $des = min($des, 2 + $this->bonusDesDread('defense'));
        }

        return $this->apresConditions($des, 'defense');
    }

    /**
     * Le monstre porte-t-il `$capacite` (`monstres.capacites`, déclarée en LISTE
     * ou en MAP quand elle porte des paramètres) ?
     *
     * ⚠ Déménagée ici depuis `MoteurDread::aCapacite()` le 2026-10-04 : le
     * nouveau point de passage unique de la mort d'un monstre
     * ({@see \App\Partie\MoteurDegats::infligerAMonstre()}) doit pouvoir lire
     * `increvable_une_fois`/`phase_suivante`/`recompense_reddition` SANS
     * dépendre de `MoteurDread` — qui, lui, dépend déjà de `MoteurDegats`
     * (il lui délègue les dégâts aux héros). Une dépendance circulaire entre
     * les deux services aurait bloqué le conteneur. `MoteurDread::aCapacite()`
     * reste en place pour ses quinze appelants, réduite à un simple relais.
     */
    public function aCapacite(string $capacite): bool
    {
        $capacites = (array) ($this->monstre->capacites ?? []);

        return in_array($capacite, $capacites, true) || array_key_exists($capacite, $capacites);
    }

    /**
     * La valeur d'une capacité PARAMÉTRÉE (`['recompense_reddition' => ['or'
     * => 1000]]`), ou `null` si elle n'est pas déclarée — même lecture que
     * `MoteurDread::sortAVolonte()`, généralisée à n'importe quelle clé.
     */
    public function capaciteParametree(string $capacite): mixed
    {
        $capacites = (array) ($this->monstre->capacites ?? []);

        return $capacites[$capacite] ?? null;
    }

    /**
     * Une mécanique RÉACTIVE à usage unique (`increvable_une_fois`, ou l'une
     * des entrées de `capacites.reactions_defense`) est-elle encore
     * disponible pour CETTE instance, cette rencontre ?
     *
     * Les deux familles partagent le même compteur
     * (`capacites_reactives_utilisees`) : qu'il s'agisse d'un drapeau simple
     * ou d'une liste, la question posée est identique — « ce nom a-t-il déjà
     * été dépensé ? ».
     */
    public function reactionDisponible(string $mecanique): bool
    {
        if ($mecanique !== 'increvable_une_fois'
            && ! in_array($mecanique, (array) $this->capaciteParametree('reactions_defense'), true)) {
            return false;
        }

        if ($mecanique === 'increvable_une_fois' && ! $this->aCapacite('increvable_une_fois')) {
            return false;
        }

        return ! in_array($mecanique, (array) ($this->capacites_reactives_utilisees ?? []), true);
    }

    /** Marque `$mecanique` dépensée pour le reste de la rencontre. */
    public function consommerReaction(string $mecanique): void
    {
        $utilisees = (array) ($this->capacites_reactives_utilisees ?? []);
        $utilisees[] = $mecanique;
        $this->update(['capacites_reactives_utilisees' => array_values(array_unique($utilisees))]);
    }

    /**
     * PV Body MAX de cette instance : la valeur PROPRE (boss adaptés à la taille
     * du groupe, +1 élite intégré) si elle est fixée, sinon repli sur les PV du
     * catalogue + bonus élite (lignes antérieures à la colonne pv_body_max).
     */
    public function pvBodyMax(): int
    {
        return $this->pv_body_max !== null
            ? (int) $this->pv_body_max
            : (int) $this->monstre->pv_body + ($this->elite ? self::BONUS_ELITE : 0);
    }

    public function quete(): BelongsTo
    {
        return $this->belongsTo(Quete::class, 'quete_id');
    }

    /** Bloc de stats du catalogue. */
    public function monstre(): BelongsTo
    {
        return $this->belongsTo(Monstre::class, 'monstre_id');
    }

    /**
     * Nom tel qu'on le MONTRE au joueur : l'habillage IA (ou le nom de
     * catalogue), suivi d'une **étoile** quand l'instance est une élite.
     *
     * Une élite a plus de PV que sa fiche de bestiaire, et rien ne le disait :
     * deux « Pilleur des Cryptes affamé » côte à côte, l'un à 1 PV et l'autre à
     * 2, sans le moindre indice (signalé par René, 2026-08-07 — « un gobelin
     * devrait avoir 1 pv pas 2 »).
     *
     * ⚠ Réservé à l'AFFICHAGE. Le contexte de l'IA (`ContexteAssembleur`) reçoit
     * le nom NU : lui passer l'étoile ferait écrire au narrateur « le Gobelin ★
     * s'avance ».
     */
    public function nomAffiche(): string
    {
        $nom = $this->habillage['nom'] ?? $this->monstre?->nom_base ?? 'Créature';

        return $this->elite ? "{$nom} ★" : (string) $nom;
    }
}
