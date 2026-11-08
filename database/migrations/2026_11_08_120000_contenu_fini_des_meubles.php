<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CONTENU FINI DES MEUBLES (René, 2026-11-07, après le bilan `docs/bilan-economie-2026-11-07.md` : « on gagne plus d'or
 * que dans le jeu normal »). Trois changements de catalogue, les mêmes valeurs que `MobilierSeeder` :
 *
 * 1. L'or des meubles hors coffres DIVISÉ PAR DEUX : Trône 35-75 → 18-38, Tombeau 30-70 → 15-35, Armoire 15-40 → 8-20,
 *    Bibliothèque 10-25 → 5-12.
 * 2. LE COFFRE « fouillé seulement 1 fois avec 1 résultat seulement (artefact ou or) » : `un_seul_butin`, et une table
 *    réduite à l'or et au piège de Spirit Queen's Torment — plus de « rien », plus d'objet.
 * 3. La Table des brasseurs rend ses « three potions » (`fois` 3).
 *
 * La règle elle-même (chaque résultat une fois par meuble, « rien » excepté) est du MOTEUR (`MoteurMobilier`) : elle ne
 * demande aucune ligne. Les meubles déjà posés sur une carte en cours lisent le catalogue à chaque fouille — aucune
 * grille à réécrire.
 */
return new class extends Migration
{
    /** @var array<string, array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}}> nom => [ancien, nouveau] */
    private const OR_DIVISE = [
        'Trône' => [[35, 75], [18, 38]],
        'Tombeau' => [[30, 70], [15, 35]],
        'Armoire' => [[15, 40], [8, 20]],
        'Bibliothèque' => [[10, 25], [5, 12]],
    ];

    private const COFFRE_AVANT = [
        ['issue' => 'tresor', 'poids' => 4, 'or' => [25, 60]],
        ['issue' => 'objet', 'poids' => 2, 'categories' => ['consommable']],
        ['issue' => 'objet', 'poids' => 1, 'categories' => ['arme', 'armure']],
        ['issue' => 'piege', 'poids' => 1, 'piege' => 'Piège de Boule de Flammes'],
        ['issue' => 'rien', 'poids' => 3],
    ];

    private const COFFRE_APRES = [
        ['issue' => 'tresor', 'poids' => 3, 'or' => [25, 60]],
        ['issue' => 'piege', 'poids' => 1, 'piege' => 'Piège de Boule de Flammes'],
    ];

    public function up(): void
    {
        foreach (self::OR_DIVISE as $nom => [, $nouveau]) {
            $this->changerOr($nom, $nouveau);
        }

        $this->modifier('Coffre', function (array $effet): array {
            $effet['un_seul_butin'] = true;
            $effet['fouille'] = self::COFFRE_APRES;

            return $effet;
        });

        $this->modifier('Table des brasseurs', fn (array $effet): array => $this->fois($effet, 3));
    }

    public function down(): void
    {
        foreach (self::OR_DIVISE as $nom => [$ancien]) {
            $this->changerOr($nom, $ancien);
        }

        $this->modifier('Coffre', function (array $effet): array {
            unset($effet['un_seul_butin']);
            $effet['fouille'] = self::COFFRE_AVANT;

            return $effet;
        });

        $this->modifier('Table des brasseurs', fn (array $effet): array => $this->fois($effet, null));
    }

    /** @param  array{0: int, 1: int}  $or */
    private function changerOr(string $nom, array $or): void
    {
        $this->modifier($nom, function (array $effet) use ($or): array {
            foreach ((array) ($effet['fouille'] ?? []) as $i => $entree) {
                if (($entree['issue'] ?? null) === 'tresor') {
                    $effet['fouille'][$i]['or'] = $or;
                }
            }

            return $effet;
        });
    }

    /**
     * @param  array<string, mixed>  $effet
     * @return array<string, mixed>
     */
    private function fois(array $effet, ?int $fois): array
    {
        foreach ((array) ($effet['fouille'] ?? []) as $i => $entree) {
            if (($entree['issue'] ?? null) === 'objet' && ($entree['objet'] ?? null) === 'Potion de fortune') {
                if ($fois === null) {
                    unset($effet['fouille'][$i]['fois']);
                } else {
                    $effet['fouille'][$i]['fois'] = $fois;
                }
            }
        }

        return $effet;
    }

    /** @param  callable(array<string, mixed>): array<string, mixed>  $changer */
    private function modifier(string $nom, callable $changer): void
    {
        $ligne = DB::table('mobiliers')->where('nom', $nom)->first(['id', 'effet']);

        if ($ligne === null) {
            return;
        }

        $effet = $changer((array) json_decode((string) $ligne->effet, true));

        DB::table('mobiliers')->where('id', $ligne->id)->update(['effet' => json_encode($effet, JSON_UNESCAPED_UNICODE)]);
    }
};
