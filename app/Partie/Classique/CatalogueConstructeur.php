<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\ClasseHeros;
use App\Models\Mobilier;
use App\Models\Monstre;
use App\Models\Objet;
use App\Models\Piege;
use App\Models\Terrain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Les éléments qu'une campagne construite peut poser, par COUCHE, et les classes de héros (MODE CLASSIQUE, lot K1).
 *
 * Les tables de catalogue n'ont PAS de colonne slug, et n'en reçoivent pas : le slug est `Str::slug()` du nom, calculé
 * ICI seulement ({@see self::slug()}), et l'index slug → ligne se construit ici seulement ({@see self::index()}).
 * Deux lignes d'une même couche qui donnent le même slug font échouer l'index : une référence serait ambiguë.
 */
final class CatalogueConstructeur
{
    /** Couche → modèle, et colonne qui porte le NOM (celle dont le slug est dérivé). */
    private const DEFINITIONS = [
        'mobilier' => [Mobilier::class, 'nom'],
        'monstres' => [Monstre::class, 'nom_base'],
        'pieges' => [Piege::class, 'nom'],
        'terrain' => [Terrain::class, 'nom'],
        'classes' => [ClasseHeros::class, 'nom'],
        // Ce qu'une note de quête peut DONNER (effet `objet`, lot K3) : lu par `NotesDeQuete::effetObjet()`.
        'objets' => [Objet::class, 'nom'],
    ];

    /** Les couches qu'une quête pose sur la carte (`monstre_errant` lit `monstres`, `prerequis_heros` lit `classes`). */
    public const COUCHES_POSABLES = ['mobilier', 'monstres', 'pieges', 'terrain'];

    /** @var array<string, array<string, Model>> couche → (slug → ligne), mémorisé pour la durée de la requête */
    private array $index = [];

    /** Le slug d'un nom de catalogue. UNE seule fonction : tout le reste passe par elle. */
    public static function slug(string $nom): string
    {
        return Str::slug($nom);
    }

    /**
     * L'index d'une couche : slug → ligne. Lève `LogicException` si deux lignes donnent le même slug.
     *
     * @return array<string, Model>
     */
    public function index(string $couche): array
    {
        if (isset($this->index[$couche])) {
            return $this->index[$couche];
        }

        [$modele, $colonne] = self::definition($couche);
        $index = [];
        foreach ($modele::query()->orderBy($colonne)->get() as $ligne) {
            $nom = (string) $ligne->{$colonne};
            $slug = self::slug($nom);
            if (isset($index[$slug])) {
                throw new LogicException(
                    "Catalogue « $couche » : « {$index[$slug]->{$colonne}} » et « $nom » donnent le même slug « $slug ».",
                );
            }
            $index[$slug] = $ligne;
        }

        return $this->index[$couche] = $index;
    }

    public function contient(string $couche, string $slug): bool
    {
        return array_key_exists($slug, $this->index($couche));
    }

    /** @return list<array{slug: string, nom: string}> trié par nom */
    public function palette(string $couche): array
    {
        [, $colonne] = self::definition($couche);

        return array_values(array_map(
            fn (Model $ligne): array => ['slug' => self::slug((string) $ligne->{$colonne}), 'nom' => (string) $ligne->{$colonne}],
            $this->index($couche),
        ));
    }

    /**
     * Le corps de GET /api/constructeur/catalogues : ce que l'écran et un client MCP proposent, et les vocabulaires
     * fermés, lus dans {@see ValidationCampagne} — la même source que la validation.
     *
     * @return array{couches: array<string, list<array{slug: string, nom: string}>>, classes: list<array{slug: string, nom: string}>, vocabulaires: array<string, list<mixed>>}
     */
    public function payload(): array
    {
        $couches = [];
        foreach (self::COUCHES_POSABLES as $couche) {
            $couches[$couche] = $this->palette($couche);
        }

        return [
            'couches' => $couches,
            'classes' => $this->palette('classes'),
            'objets' => $this->palette('objets'),
            'vocabulaires' => [
                'rotations' => ValidationCampagne::ROTATIONS,
                'cotes' => ValidationCampagne::COTES,
                'types_porte' => ValidationCampagne::TYPES_PORTE,
                'partages' => ValidationCampagne::PARTAGES,
                'types_fin' => ValidationCampagne::TYPES_FIN,
                // Les notes de quête (lot K3) : le vocabulaire fermé que `NotesDeQuete` joue, tel quel — chaque
                // déclencheur avec les cibles qu'il accepte, chaque effet avec ses paramètres.
                'declencheurs_de_note' => \App\Engine\MotsClesQuete::DECLENCHEURS,
                'effets_de_note' => \App\Engine\MotsClesQuete::EFFETS,
                'types_acces' => ValidationCampagne::TYPES_ACCES,
                'formats_acceptes' => ValidationCampagne::FORMATS_ACCEPTES,
            ],
        ];
    }

    /** @return array{0: class-string<Model>, 1: string} */
    private static function definition(string $couche): array
    {
        if (!isset(self::DEFINITIONS[$couche])) {
            throw new InvalidArgumentException("Couche de catalogue inconnue : « $couche ».");
        }

        return self::DEFINITIONS[$couche];
    }
}
