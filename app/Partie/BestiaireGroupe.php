<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Groupe;
use Illuminate\Support\Facades\DB;
use Random\Randomizer;

/**
 * Le BESTIAIRE d'une campagne — automatique ou manuel (René, 2026-09-28 :
 * « une option automatique (comme actuellement) ou manuelle (sélection
 * possible d'une ou plusieurs extensions, et si aucune sélection, système de
 * base seulement) »).
 *
 * ⚠ Point de passage UNIQUE pour « quelles boîtes cette campagne joue-t-elle ».
 * Six lecteurs posaient la question chacun à sa façon (`boite === $theme`) :
 * l'achat des rencontres, le monstre errant, le terrain, l'équipement de
 * glace, l'artefact du coffre et le libellé de la table. Avec plusieurs boîtes
 * cochées, `=== $theme` n'a plus de sens — et une copie oubliée aurait laissé
 * entrer par la fenêtre une extension que le joueur n'a pas cochée.
 *
 *  - **Manuel** : les boîtes COCHÉES (`groupes.boites_bestiaire`, liste,
 *    vide comprise). Un FILTRE : jeu de base + nos créatures (`boite = null`,
 *    sans lesquelles le jeu de base n'a ni sous-boss ni boss) + boîtes cochées.
 *    Parmi elles, les cochées gardent la préférence (boss, forts).
 *  - **Automatique, depuis le 2026-11-05** (René : « donner la liste et une
 *    description des extensions à l'agent IA afin qu'il choisisse des extensions
 *    selon le prompt donné, et si le prompt est trop vague, choisir 1, 2 ou 3
 *    extensions au hasard selon la longueur de la campagne ») : `tirer()` à la
 *    création (`tirage`), puis le MJ IA remplace le tirage par les boîtes que le
 *    thème évoque (`mj`, `adopterChoixDuMj()`). Même FILTRE que le manuel, même
 *    colonne ; `groupes.origine_boites` dit qui a choisi.
 *  - **Rotation historique** (`auto`) : les campagnes créées AVANT, sans liste.
 *    UNE boîte, tirée par rotation et figée à la première quête
 *    (`groupes.theme_bestiaire`). Une PRÉFÉRENCE sur le bestiaire commun, jamais
 *    un filtre. Rien ne les convertit.
 */
final class BestiaireGroupe
{
    /** Rotation historique : une campagne d'avant le 2026-11-05. Préférence, pas filtre. */
    public const MODE_AUTO = 'auto';

    /** Cochées par les joueurs (`origine_boites` `null`). */
    public const MODE_MANUEL = 'manuel';

    /** Choisies par le MJ IA selon le thème (`origine_boites = mj`). */
    public const MODE_MJ = 'mj';

    /** Tirées au hasard selon la longueur (`origine_boites = tirage`) : thème trop vague, ou IA indisponible. */
    public const MODE_TIRAGE = 'tirage';

    /**
     * Boîtes TIRÉES selon la longueur de la campagne (René, 2026-11-05 : 1 / 1 / 2 / 3 / 3). Une campagne courte n'a
     * pas le temps de montrer plusieurs extensions. Clés = `GroupeController::QUETES_PAR_LONGUEUR`, testé dans les deux sens.
     */
    public const BOITES_TIREES_PAR_LONGUEUR = [
        'tres_courte' => 1,
        'courte' => 1,
        'normale' => 2,
        'longue' => 3,
        'tres_longue' => 3,
    ];

    /** Le MJ IA en choisit de 1 à 3, quelle que soit la longueur (René : « 1 à 3, librement »). */
    public const BOITES_CHOISIES_MAX = 3;

    /** Boîte du jeu de base (`monstres.boite`). */
    public const BOITE_BASE = 'base';

    /**
     * Nom OFFICIEL du jeu de base — « requires HeroQuest Game System to play »
     * (`reference/18_extensions.md` l. 33), jamais une traduction inventée :
     * même règle que `DemarreurQuete::LIBELLES_BOITES`.
     */
    public const LIBELLE_BASE = 'HeroQuest Game System';

    /** @param  list<string>  $boites */
    private function __construct(
        public readonly string $mode,
        public readonly array $boites,
    ) {}

    /** Automatique : `$theme` est la boîte tirée, `null` avant la première quête. */
    public static function auto(?string $theme): self
    {
        return new self(self::MODE_AUTO, $theme === null ? [] : [$theme]);
    }

    /** @param  list<string>  $boites  vide = HeroQuest Game System seul */
    public static function manuel(array $boites): self
    {
        return new self(self::MODE_MANUEL, array_values(array_unique($boites)));
    }

    public static function duGroupe(Groupe $groupe): self
    {
        if ($groupe->boites_bestiaire !== null) {
            $mode = match ($groupe->origine_boites) {
                self::MODE_MJ => self::MODE_MJ,
                self::MODE_TIRAGE => self::MODE_TIRAGE,
                default => self::MODE_MANUEL,
            };

            return new self($mode, array_values(array_unique((array) $groupe->boites_bestiaire)));
        }

        return self::auto(app(DemarreurQuete::class)->themeBestiaireDuGroupe($groupe));
    }

    /**
     * Les boîtes FILTRENT-elles le bestiaire ? Oui pour une liste (cochée, choisie par le MJ, tirée), non pour la
     * rotation historique, qui n'est qu'une préférence.
     */
    public function estFiltre(): bool
    {
        return $this->mode !== self::MODE_AUTO;
    }

    /**
     * Le tirage d'une campagne automatique neuve, à sa création : 1 à 3 boîtes distinctes parmi celles qui tournent,
     * selon la longueur. Un vrai hasard, écrit tout de suite en base : rien ne le rejoue ensuite.
     *
     * ⚠ Le hasard vient du conteneur (`Randomizer`, moteur sûr par défaut) : la suite de tests le remplace par un
     * moteur à graine (`Tests\TestCase`). Sans lui, chaque test qui crée un groupe par l'API jouerait une boîte
     * différente à chaque passage — un test intermittent, pire qu'un test rouge.
     *
     * @return list<string>
     */
    public static function tirer(string $longueur): array
    {
        $boites = DemarreurQuete::BOITES_THEMATIQUES;
        $nombre = min(self::BOITES_TIREES_PAR_LONGUEUR[$longueur] ?? 1, count($boites));

        return array_map(fn (int $cle) => $boites[$cle], app(Randomizer::class)->pickArrayKeys($boites, $nombre));
    }

    /**
     * Le MJ IA peut-il encore REMPLACER les boîtes de ce groupe ? Seulement un tirage, et seulement tant qu'aucune
     * quête n'a démarré : une rencontre déjà achetée l'a été dans le tirage.
     */
    public static function choixOuvertAuMj(Groupe $groupe): bool
    {
        return $groupe->origine_boites === self::MODE_TIRAGE
            && ! DB::table('quetes')->where('groupe_id', $groupe->id)->exists();
    }

    /**
     * Le MJ IA remplace le tirage par les boîtes que le thème évoque. UNE requête conditionnelle, pour qu'une quête
     * démarrée pendant que le MJ écrivait garde le tirage : la condition de `choixOuvertAuMj()`, relue au moment
     * d'écrire.
     *
     * @param  list<string>  $boites  déjà validées : boîtes qui tournent, distinctes, 1 à `BOITES_CHOISIES_MAX`
     * @return bool vrai si le choix a été retenu
     */
    public static function adopterChoixDuMj(Groupe $groupe, array $boites): bool
    {
        if ($boites === []) {
            return false;
        }

        $retenu = Groupe::query()
            ->whereKey($groupe->id)
            ->where('origine_boites', self::MODE_TIRAGE)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('quetes')->whereColumn('quetes.groupe_id', 'groupes.id'))
            // Requête brute : le cast `array` du modèle ne s'applique pas, on encode comme lui.
            ->update(['boites_bestiaire' => json_encode(array_values($boites)), 'origine_boites' => self::MODE_MJ]) === 1;

        if ($retenu) {
            $groupe->refresh();
        }

        return $retenu;
    }

    /**
     * La créature (ou le terrain, l'objet) de cette boîte PEUT-elle apparaître ?
     * Toujours en rotation historique ; sinon jeu de base, `null` et boîtes de la liste.
     *
     * `$boite` est une boîte, ou la LISTE des boîtes d'un monstre imprimé dans plusieurs
     * (`Monstre::boites()`) : il suffit qu'UNE soit autorisée. Liste vide ou `null` = aucune
     * boîte, donc générique.
     *
     * @param  string|list<string>|null  $boite
     */
    public function autorise(string|array|null $boite): bool
    {
        if (! $this->estFiltre()) {
            return true;
        }

        $boites = self::enListe($boite);

        if ($boites === []) {
            return true;
        }

        foreach ($boites as $b) {
            if ($b === self::BOITE_BASE || in_array($b, $this->boites, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cette boîte est-elle un THÈME de la campagne — celle tirée en auto, une
     * cochée en manuel ? C'est la PRÉFÉRENCE (boss, forts) et ce qui allume le
     * matériel propre à une boîte (terrain, équipement de glace, artefact).
     * Comme `autorise()`, accepte la liste des boîtes d'un monstre : UNE suffit.
     *
     * @param  string|list<string>|null  $boite
     */
    public function contient(string|array|null $boite): bool
    {
        foreach (self::enListe($boite) as $b) {
            if (in_array($b, $this->boites, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  string|list<string>|null  $boite
     * @return list<string>
     */
    private static function enListe(string|array|null $boite): array
    {
        return $boite === null ? [] : array_values((array) $boite);
    }

    /** Libellé lisible, décidé ici — jamais par le client. */
    public function libelle(): string
    {
        $noms = array_map(
            fn (string $b) => app(DemarreurQuete::class)->libelleBoiteBestiaire($b),
            $this->boites,
        );

        return $this->estFiltre()
            ? implode(' + ', [self::LIBELLE_BASE, ...$noms])
            : ($noms[0] ?? '');
    }
}
