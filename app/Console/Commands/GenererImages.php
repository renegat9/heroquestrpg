<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Agent\Exceptions\AppelLlmException;
use App\Agent\Image\ImageGemini;
use App\Models\ClasseHeros;
use App\Models\Monstre;
use App\Models\Objet;
use App\Models\Epreuve;
use App\Models\Mobilier;
use App\Models\Piege;
use App\Models\Sort;
use App\Partie\Images\BibliothequeImages;
use Illuminate\Console\Command;

/**
 * Pré-génère les images FIXES du catalogue (classes, monstres, objets, pièges)
 * via Gemini image, dans public/images/catalogue/{type}/{id}-{slug}.png.
 *
 * Étape HORS-LIGNE, une fois (ou après édition du catalogue). Sans
 * GEMINI_API_KEY : ne fait rien et l'explique — le jeu reste jouable, le front
 * retombe sur les icônes. Calqué sur `barks:generer`.
 *
 *   php artisan images:generer                 # toutes les catégories, fichiers absents
 *   php artisan images:generer --type=classes  # une catégorie
 *   php artisan images:generer --force         # régénère tout
 */
final class GenererImages extends Command
{
    protected $signature = 'images:generer
        {--type=tous : classes|monstres|objets|pieges|epreuves|mobiliers|leviers|portes|sorts|tous}
        {--force : Régénère même les fichiers déjà présents}';

    protected $description = 'Génère les images du catalogue (Gemini image) dans public/images/catalogue';

    public function handle(ImageGemini $image, BibliothequeImages $biblio): int
    {
        if (! $image->estConfigure()) {
            $this->warn('GEMINI_API_KEY absente : aucune génération. Le jeu affichera les icônes par défaut.');

            return self::SUCCESS;
        }

        $type = (string) $this->option('type');
        $force = (bool) $this->option('force');
        $cibles = $this->cibles($biblio);

        $faits = 0;
        $ignores = 0;
        $echecs = 0;

        foreach ($cibles as $cible) {
            if ($type !== 'tous' && $cible['type'] !== $type) {
                continue;
            }

            $fichier = public_path("images/{$cible['rel']}");

            if (! $force && is_file($fichier)) {
                $ignores++;

                continue;
            }

            try {
                $octets = $image->generer($cible['prompt']);
            } catch (AppelLlmException $e) {
                $this->error("✗ {$cible['rel']} : ".$e->getMessage());
                $echecs++;

                continue;
            }

            $dossier = dirname($fichier);
            if (! is_dir($dossier)) {
                mkdir($dossier, 0775, true);
            }

            file_put_contents($fichier, $octets);
            $this->line('✓ '.$cible['rel'].'  ('.round(strlen($octets) / 1024).' Ko)');
            $faits++;
        }

        $this->info("Images générées : {$faits} · ignorées (déjà présentes) : {$ignores} · échecs : {$echecs}");

        return $echecs > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Liste { type, rel (chemin relatif), prompt } pour tout le catalogue.
     *
     * @return list<array{type: string, rel: string, prompt: string}>
     */
    private function cibles(BibliothequeImages $biblio): array
    {
        $cibles = [];

        foreach (ClasseHeros::all() as $c) {
            $cibles[] = [
                'type' => 'classes',
                'rel' => $biblio->relatifClasse($c->nom),
                'prompt' => $biblio->prompt('classe', ['detail' => $biblio->detailClasse($c->nom)]),
            ];
        }

        // ⚠ Pas de `::all()` ici : ni le levier ni la porte n'ont de table.
        // Le levier est unique ; les états de porte viennent de la CONFIG, qui
        // les keye sur `MoteurPortes::ETAT_*` — inventer un libellé ici
        // produirait une image que rien n'irait jamais chercher.
        $cibles[] = [
            'type' => 'leviers',
            'rel' => $biblio->relatifLevier(),
            'prompt' => $biblio->prompt('levier', []),
        ];

        foreach (array_keys((array) config('images.portes', [])) as $etat) {
            $cibles[] = [
                'type' => 'portes',
                'rel' => $biblio->relatifPorte((string) $etat),
                'prompt' => $biblio->prompt('porte', ['detail' => (string) config("images.portes.{$etat}")]),
            ];
        }

        foreach (Monstre::all() as $m) {
            $cibles[] = [
                'type' => 'monstres',
                'rel' => $biblio->relatifCatalogue('monstres', $m->id, $m->nom_base),
                'prompt' => $biblio->prompt('monstre', ['nom' => $m->nom_base, 'tier' => (string) $m->tier]),
            ];
        }

        foreach (Objet::all() as $o) {
            $cibles[] = [
                'type' => 'objets',
                'rel' => $biblio->relatifCatalogue('objets', $o->id, $o->nom),
                'prompt' => $biblio->prompt('objet', ['nom' => $o->nom, 'categorie' => (string) $o->categorie]),
            ];
        }

        foreach (Piege::all() as $p) {
            $cibles[] = [
                'type' => 'pieges',
                'rel' => $biblio->relatifCatalogue('pieges', $p->id, $p->nom),
                'prompt' => $biblio->prompt('piege', ['nom' => $p->nom]),
            ];
        }

        foreach (Epreuve::all() as $e) {
            $cibles[] = [
                'type' => 'epreuves',
                'rel' => $biblio->relatifCatalogue('epreuves', $e->id, $e->nom),
                'prompt' => $biblio->prompt('epreuve', ['nom' => $e->nom, 'description' => (string) $e->description]),
            ];
        }

        foreach (Mobilier::all() as $m) {
            $cibles[] = [
                'type' => 'mobiliers',
                'rel' => $biblio->relatifCatalogue('mobiliers', $m->id, $m->nom),
                'prompt' => $biblio->prompt('mobilier', [
                    'nom' => $m->nom,
                    'description' => (string) $m->description,
                    'largeur' => (string) $m->largeur,
                    'hauteur' => (string) $m->hauteur,
                ]),
            ];
        }

        foreach (Sort::all() as $s) {
            $cibles[] = [
                'type' => 'sorts',
                'rel' => $biblio->relatifCatalogue('sorts', $s->id, $s->nom),
                'prompt' => $biblio->prompt('sort', ['nom' => $s->nom, 'element' => (string) $s->element, 'type' => (string) $s->type]),
            ];
        }

        return $cibles;
    }
}
