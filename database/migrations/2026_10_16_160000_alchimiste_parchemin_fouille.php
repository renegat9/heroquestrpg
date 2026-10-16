<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * L'ÉTABLI D'ALCHIMISTE rend un PARCHEMIN (René, 2026-10-16 : « on trouve les parchemins en
 * fouillant le mobilier, d'abord la bibliothèque et l'établi d'alchimiste »).
 *
 * Une entrée de plus dans la table de fouille du meuble : `objet` tiré dans la catégorie
 * `parchemin`, poids 2 (une fois sur six, face aux fioles à 5 et au reste à 5). Le tirage est
 * celui de toute table de meuble (`MoteurMobilier::carteDepuisEntree()`) : la rareté d'abord,
 * puis le sort uniformément dans cette rareté. La bibliothèque en a déjà une (`categories:
 * [parchemin]`, poids 3) : cette migration ne la touche pas.
 *
 * Changer une ligne EXISTANTE du catalogue se fait par migration dans ce projet (CLAUDE.md) :
 * `MobilierSeeder` pose la même table (`updateOrCreate`), mais un `migrate` seul doit suffire
 * sur la base de production, sans rejouer le catalogue entier.
 *
 * Ne touche QUE l'entrée de `effet.fouille` : ni l'emprise (posée par `mobilier_trois_cases` et
 * les migrations suivantes), ni le reste de `effet`, ni aucune ligne de carte ou d'inventaire.
 * Une quête en cours garde ses meubles tels quels : la table est lue au moment de la fouille.
 *
 * `down()` retire UNIQUEMENT l'entrée parchemin ajoutée ici, sans reposer la table entière.
 */
return new class extends Migration
{
    private const NOM = "Établi d'alchimiste";

    private const ENTREE = ['issue' => 'objet', 'poids' => 2, 'categories' => ['parchemin']];

    public function up(): void
    {
        $this->poser(true);
    }

    public function down(): void
    {
        $this->poser(false);
    }

    private function poser(bool $avecParchemin): void
    {
        foreach (DB::table('mobiliers')->where('nom', self::NOM)->get(['id', 'effet']) as $ligne) {
            $effet = json_decode((string) $ligne->effet, true) ?: [];

            // Une ligne sans table n'en reçoit pas une : une table réduite au seul parchemin
            // perdrait le « rien » que la règle exige (`MobilierSeeder`, garde-fous).
            if (! isset($effet['fouille']) || ! is_array($effet['fouille'])) {
                continue;
            }

            $table = array_values(array_filter(
                (array) ($effet['fouille'] ?? []),
                fn ($e) => ! self::estEntreeParchemin($e),
            ));

            if ($avecParchemin) {
                // Juste après les fioles : l'ordre se lit comme la fiche de l'établi.
                $position = count($table);

                foreach ($table as $indice => $entree) {
                    if (($entree['issue'] ?? null) === 'piege') {
                        $position = $indice;
                        break;
                    }
                }

                array_splice($table, $position, 0, [self::ENTREE]);
            }

            $effet['fouille'] = $table;

            DB::table('mobiliers')->where('id', $ligne->id)->update([
                'effet' => json_encode($effet, JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    /** @param mixed $entree */
    private static function estEntreeParchemin(mixed $entree): bool
    {
        return is_array($entree)
            && ($entree['issue'] ?? null) === 'objet'
            && ($entree['categories'] ?? null) === ['parchemin'];
    }
};
