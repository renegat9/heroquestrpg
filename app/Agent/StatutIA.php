<?php

declare(strict_types=1);

namespace App\Agent;

use Illuminate\Support\Facades\Cache;

/**
 * État observable du dernier appel LLM du MJ IA — alimenté par
 * {@see ClientLLMAvecRepli}, lu par `ParametresController` (bandeau de statut
 * du panneau Réglages). GLOBAL (pas par groupe, comme le reste des réglages
 * IA), adossé au cache PARTAGÉ (`CACHE_STORE=database`, table `cache`
 * MariaDB) entre le conteneur `queue` (qui écrit pendant les jobs IA) et le
 * conteneur `app` (qui lit pour `GET /api/parametres`). Toujours écrasé au
 * dernier essai réel : pas de TTL à gérer, `Cache::forever` suffit.
 */
final class StatutIA
{
    private const CLE = 'ia:statut';

    public static function signalerSucces(string $fournisseur): void
    {
        Cache::forever(self::CLE, ['etat' => 'nominal', 'fournisseur' => $fournisseur, 'a' => now()->toIso8601String()]);
    }

    /**
     * $vers nullable : par construction {@see ClientLLMAvecRepli} n'appelle
     * ceci qu'avec un secours réellement configuré, mais le type reste
     * défensif (le contrat `statut_ia.fournisseur` est lui-même optionnel).
     */
    public static function signalerRepli(string $depuis, ?string $vers, string $raison): void
    {
        Cache::forever(self::CLE, [
            'etat' => 'repli', 'fournisseur' => $vers, 'depuis' => $depuis,
            'raison' => $raison, 'a' => now()->toIso8601String(),
        ]);
    }

    public static function signalerEchecTotal(string $tentatives, string $raison): void
    {
        Cache::forever(self::CLE, ['etat' => 'indisponible', 'tentatives' => $tentatives, 'raison' => $raison, 'a' => now()->toIso8601String()]);
    }

    /** @return array<string, mixed> */
    public static function actuel(): array
    {
        return Cache::get(self::CLE, ['etat' => 'inconnu']); // aucun appel IA depuis le dernier démarrage du cache
    }
}
