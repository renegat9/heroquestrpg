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
        // Les mercenaires qu'une campagne offre (lot K6, clé de campagne `mercenaires`) : `Classique\MercenairesCampagne`.
        'mercenaires' => [\App\Models\Mercenaire::class, 'nom'],
        // Les sorts qui seuls blessent une figure (`vulnerable_uniquement_a`, René 2026-11-20 : « il faut pouvoir
        // l'appliquer à des sorts aussi » — le Seigneur Sorcier, *Witch Lord* q. 10) : `InstanceMonstre::habillageDeQuete()`.
        'sorts' => [\App\Models\Sort::class, 'nom'],
        // Les sorts de DREAD qu'un profil d'auteur donne à une figure (`sorts_dread`, lot E1, 2026-11-22 : « Vilor knows
        // the following Dread spells ») : `MoteurDread::repertoireDe()`.
        'sorts_dread' => [\App\Models\SortDread::class, 'nom'],
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

        // L'EMPRISE d'un meuble, PAR ROTATION, calculée par la règle même que la validation applique
        // (`ValidationCampagne::empriseMeuble()`) : l'écran du constructeur la dessine, il ne la recalcule pas.
        $meubles = $this->index('mobilier');
        $couches['mobilier'] = array_map(
            function (array $ligne) use ($meubles): array {
                $meuble = $meubles[$ligne['slug']];
                $emprises = [];
                foreach (ValidationCampagne::ROTATIONS as $rotation) {
                    $emprises[$rotation] = ValidationCampagne::empriseMeuble((int) $meuble->largeur, (int) $meuble->hauteur, $rotation);
                }

                return $ligne + ['emprises' => $emprises];
            },
            $couches['mobilier'],
        );

        // Lot K5o : un terrain qui TÉLÉPORTE vers sa jumelle (`effet.teleportation`) se pose avec un `paire_id` — l'écran
        // ne propose ce champ que sur ceux-là, décidé ici plutôt que deviné d'après le nom.
        $terrains = $this->index('terrain');
        $couches['terrain'] = array_map(
            fn (array $ligne): array => $ligne + ['paire' => data_get($terrains[$ligne['slug']]->effet, 'teleportation') !== null,
                // Lot E2 : une GLISSIÈRE (tuile à sens unique) a une case d'arrivée (`terrain[].sortie`).
                'glissiere' => data_get($terrains[$ligne['slug']]->effet, 'sens_unique') === true],
            $couches['terrain'],
        );

        // Lot E1 : un piège de COFFRE (`declencheur: ouverture_tresor`) se pose dans `coffres_pieges`, jamais au sol — l'écran
        // le lit ici, il ne le devine pas.
        $pieges = $this->index('pieges');
        $couches['pieges'] = array_map(
            fn (array $ligne): array => $ligne + ['coffre' => data_get($pieges[$ligne['slug']]->effet, 'declencheur') === 'ouverture_tresor']
                // Lot E2 : la forme RELATIVE d'une lame balançoire (verticale, en décalages) — l'écran en déduit la zone par défaut.
                + (data_get($pieges[$ligne['slug']]->effet, 'zone_lames') !== null ? ['zone_lames' => array_values((array) $pieges[$ligne['slug']]->effet['zone_lames'])] : []),
            $couches['pieges'],
        );

        return [
            'couches' => $couches,
            'classes' => $this->palette('classes'),
            'objets' => $this->palette('objets'),
            // Lot K6 : les mercenaires RECRUTABLES (ni captif scénarisé ni allié d'un seul effet) — `mercenaires.offerts`.
            'mercenaires' => array_values(array_filter($this->palette('mercenaires'), function (array $ligne): bool {
                $m = $this->index('mercenaires')[$ligne['slug']];

                return ! $m->captif && ! $m->octroi_seul;
            })),
            // 2026-11-20 : ce que les clés ajoutées pour les livrets désignent — sans ces listes, l'écran et une IA
            // branchée sur le serveur MCP ne pouvaient pas trouver le slug que la validation attend.
            'sorts' => $this->palette('sorts'),
            'sorts_dread' => $this->palette('sorts_dread'),
            'captifs' => array_values(array_filter($this->palette('mercenaires'), function (array $ligne): bool {
                $m = $this->index('mercenaires')[$ligne['slug']];

                return $m->captif && $m->mode_captif === \App\Models\Mercenaire::MODE_CAPTIF_FIGURINE;
            })),
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
                // Lot K5a : les verrous d'une porte, et les conditions d'une porte `condition` (`MoteurPortes::CONDITIONS`).
                'verrous_de_porte' => ValidationCampagne::VERROUS_DE_PORTE,
                'conditions_de_porte' => collect(\App\Partie\MoteurPortes::CONDITIONS)
                    ->map(fn (array $c, string $nom) => ['nom' => $nom, 'libelle' => $c['libelle']])->values()->all(),
                'formats_acceptes' => ValidationCampagne::FORMATS_ACCEPTES,
                // Lot E1 : les boîtes dont les cartes de trésor peuvent rejoindre le paquet (`campagne.cartes_tresor`).
                'boites_de_cartes' => \App\Partie\Fouille\DeckFouille::BOITES_DE_CARTES,
                // Lot E1 : les capacités qu'un profil d'auteur peut donner (`InstanceMonstre::CAPACITES_DE_PROFIL`).
                'capacites_de_profil' => array_keys(\App\Models\InstanceMonstre::CAPACITES_DE_PROFIL),
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
