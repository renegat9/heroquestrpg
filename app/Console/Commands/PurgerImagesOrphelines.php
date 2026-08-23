<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Partie\Images\BibliothequeImages;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Efface les illustrations dynamiques dont le SUJET n'existe plus.
 *
 * Un `public/images/dyn/{sousType}/{id}.png` est indexé sur une clé
 * AUTO-INCRÉMENTÉE. InnoDB ne persiste pas son compteur : au redémarrage il le
 * recalcule à `max(id) + 1`. Après la purge d'une campagne, les ids repartent
 * donc EN ARRIÈRE, et un sujet neuf hérite du fichier d'un sujet mort — sans
 * la moindre erreur, l'écran affiche simplement l'illustration de quelqu'un
 * d'autre. Mesuré le 2026-08-22 : `quetes` en était à 43 quand le disque
 * portait des scènes jusqu'à 67, et le hub à 30 contre 89 — soit soixante
 * campagnes à venir qui auraient ouvert sur le hub d'une campagne oubliée.
 *
 * {@see \App\Partie\ClotureCampagne::purger()} ferme la source depuis : une
 * campagne emporte désormais ses illustrations. Cette commande soigne ce qui
 * est DÉJÀ sur le disque, que la purge à la source ne peut plus rattraper.
 *
 * ⚠ Sans `--supprimer`, elle ne fait que LISTER. Effacer une image payée est
 * irréversible, et le compte doit pouvoir être lu avant d'être appliqué.
 *
 *   php artisan images:purger-orphelines              # inventaire seul
 *   php artisan images:purger-orphelines --supprimer  # applique
 */
final class PurgerImagesOrphelines extends Command
{
    protected $signature = 'images:purger-orphelines
        {--supprimer : Efface réellement (sans ce drapeau, la commande se contente de lister)}';

    protected $description = 'Efface les illustrations dyn/ dont la ligne (quête, groupe, monstre, héros) a disparu';

    /**
     * Sous-type d'illustration → table qui en porte le sujet.
     *
     * ⚠ Un dossier ABSENT de cette table n'est pas touché : on n'efface que ce
     * dont on sait dire si le sujet vit encore.
     */
    private const SUJETS = [
        'quete' => 'quetes',
        'hub' => 'groupes',
        'monstre' => 'instances_monstres',
        'perso' => 'personnages',
    ];

    public function handle(BibliothequeImages $biblio): int
    {
        // ⚠ GARDE-FOU, payé cher le 2026-08-22 : cette commande croise le
        // DISQUE avec la BASE. Sous Pest la base est une sqlite en mémoire
        // quasi vide alors que `public_path()` reste le vrai dossier — donc
        // TOUTE illustration réelle y paraît orpheline. Un test qui l'appelait
        // avec `--supprimer` a effacé les 148 images de la machine (118 Mo,
        // hors dépôt, irrécupérables). Le critère n'est pas « c'est un test »
        // mais « cette base ne décrit pas ce disque » : `testing` est
        // exactement le cas où les deux ne se correspondent pas.
        if (app()->environment('testing')) {
            $this->error("Refusé en environnement `testing` : la base de test ne décrit pas ce disque, tout y paraîtrait orphelin.");

            return self::FAILURE;
        }

        $racine = public_path('images/dyn');

        if (! is_dir($racine)) {
            $this->info('Aucun dossier public/images/dyn — rien à faire.');

            return self::SUCCESS;
        }

        $applique = (bool) $this->option('supprimer');
        $totalOrphelins = 0;
        $totalOctets = 0;

        foreach (self::SUJETS as $sousType => $table) {
            $dossier = "{$racine}/{$sousType}";

            if (! is_dir($dossier)) {
                continue;
            }

            // Un id porte jusqu'à DEUX fichiers (le .png et son jumeau .webp,
            // que `BibliothequeImages::url()` fait gagner) : on raisonne sur
            // l'id, jamais sur le fichier, sinon on en laisserait un des deux
            // — et c'est justement celui qui est servi.
            $ids = collect(scandir($dossier) ?: [])
                ->filter(fn (string $f) => (bool) preg_match('/^(\d+)\.(png|webp)$/i', $f))
                ->map(fn (string $f) => (int) preg_replace('/\D.*$/', '', $f))
                ->unique()
                ->sort()
                ->values();

            if ($ids->isEmpty()) {
                continue;
            }

            $vivants = DB::table($table)->whereIn('id', $ids)->pluck('id')
                ->map(fn ($id) => (int) $id)->all();
            $orphelins = $ids->reject(fn (int $id) => in_array($id, $vivants, true))->values();

            $octets = 0;

            foreach ($orphelins as $id) {
                foreach (['png', 'webp'] as $ext) {
                    $chemin = "{$dossier}/{$id}.{$ext}";
                    $octets += is_file($chemin) ? (int) filesize($chemin) : 0;
                }

                if ($applique) {
                    $biblio->supprimerDyn($sousType, $id);
                }
            }

            $totalOrphelins += $orphelins->count();
            $totalOctets += $octets;

            $this->line(sprintf(
                '  %-8s %3d sujet(s) sur disque · %3d vivant(s) · %s%3d orphelin(s)  (%s)',
                $sousType,
                $ids->count(),
                count($vivants),
                $orphelins->isEmpty() ? '' : '⚠ ',
                $orphelins->count(),
                $this->poids($octets),
            ));
        }

        if ($totalOrphelins === 0) {
            $this->info('Aucune illustration orpheline : chaque fichier a encore son sujet.');

            return self::SUCCESS;
        }

        $applique
            ? $this->info(sprintf('%d illustration(s) orpheline(s) effacée(s), %s libéré(s).', $totalOrphelins, $this->poids($totalOctets)))
            : $this->warn(sprintf(
                "%d illustration(s) orpheline(s), %s. Relancer avec --supprimer pour les effacer.",
                $totalOrphelins,
                $this->poids($totalOctets),
            ));

        return self::SUCCESS;
    }

    private function poids(int $octets): string
    {
        return $octets >= 1_048_576
            ? sprintf('%.1f Mo', $octets / 1_048_576)
            : sprintf('%.0f Ko', $octets / 1024);
    }
}
