<?php

declare(strict_types=1);

namespace App\Agent;

use Carbon\Carbon;
use FilesystemIterator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Battement de cœur des workers de file (`queue`, `queue-jeu`) — la page
 * Système doit pouvoir dire « ce worker tourne du code périmé », le défaut
 * qui a figé un playtest entier le 2026-08-05 (CLAUDE.md §Commands) : `app`
 * relit le code bind-monté à chaque requête, mais `queue`/`queue-jeu` sont
 * des démons `queue:work` qui chargent les classes UNE FOIS au boot — après
 * une modification PHP ils continuent de faire tourner l'ancien code contre
 * le nouveau schéma, sans qu'aucune erreur ne le signale.
 *
 * Écouté depuis `AppServiceProvider::boot()` sur l'événement `Looping` de
 * `Illuminate\Queue\Worker` (dispatché à CHAQUE itération de la boucle
 * `queue:work`, donc UNIQUEMENT dans un process worker — jamais dans `app`
 * ni dans un job synchrone/test). `$queue` y est la chaîne `--queue` passée
 * telle quelle au worker (`temps-reel,default` pour `queue`, `temps-reel`
 * pour `queue-jeu`, cf. docker-compose.yml) : on s'en sert directement comme
 * clé, sans avoir besoin d'énumérer les workers depuis le cache (le store
 * `database` n'offre pas de SCAN par motif).
 *
 * `version_code` = mtime le plus récent sous `app/` + `config/`, calculée UNE
 * SEULE FOIS par process (mémoïsée en propriété statique) au moment du tout
 * premier battement — c'est la empreinte du code que CE worker a chargé à son
 * démarrage. `SystemeController` la recalcule à CHAQUE requête (le process
 * `app` relit le code en direct) et compare : une différence veut dire que le
 * worker tourne un code plus ancien que celui qui répond en ce moment.
 */
final class SanteFileAttente
{
    /** File → nom du worker docker-compose qui la déclare (voir docker-compose.yml). */
    public const OUVRIERS_ATTENDUS = [
        'temps-reel,default' => 'queue',
        'temps-reel' => 'queue-jeu',
    ];

    private static ?string $versionCode = null;

    private static ?int $demarreA = null;

    private static ?int $dernierBattement = null;

    /**
     * Enregistre (au plus une fois par minute par process, voir doc de
     * classe) que ce worker est vivant. Best-effort : jamais relancé, une
     * écriture manquée ne doit jamais faire échouer un job.
     */
    public static function enregistrerBattement(string $queue): void
    {
        $maintenant = time();

        if (self::$dernierBattement !== null && ($maintenant - self::$dernierBattement) < 60) {
            return;
        }

        self::$dernierBattement = $maintenant;
        self::$demarreA ??= $maintenant;

        try {
            Cache::forever(self::cle($queue), [
                'queue' => $queue,
                'pid' => getmypid() ?: null,
                'demarre_a' => Carbon::createFromTimestamp(self::$demarreA)->toIso8601String(),
                'version_code' => self::versionCodeDuProcess(),
                'vu_a' => now()->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Battement de cœur du worker non enregistré (best effort).', [
                'queue' => $queue,
                'erreur' => $e->getMessage(),
            ]);
        }
    }

    /** @return array{queue: string, pid: int|null, demarre_a: string, version_code: string, vu_a: string}|null */
    public static function etat(string $queue): ?array
    {
        return Cache::get(self::cle($queue));
    }

    /**
     * Empreinte du code ACTUELLEMENT sur disque, calculée par le process qui
     * appelle (typiquement `app`, qui relit le bind-mount en direct) — à
     * comparer au `version_code` figé par chaque worker à son démarrage.
     */
    public static function versionCodeActuel(): string
    {
        return self::calculerVersionCode();
    }

    private static function versionCodeDuProcess(): string
    {
        return self::$versionCode ??= self::calculerVersionCode();
    }

    private static function calculerVersionCode(): string
    {
        $plusRecent = 0;

        foreach ([app_path(), config_path()] as $racine) {
            if (! is_dir($racine)) {
                continue;
            }

            $iterateur = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterateur as $fichier) {
                if ($fichier->isFile()) {
                    $plusRecent = max($plusRecent, $fichier->getMTime());
                }
            }
        }

        return (string) $plusRecent;
    }

    private static function cle(string $queue): string
    {
        return 'sante:worker:'.$queue;
    }
}
