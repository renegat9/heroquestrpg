<?php

declare(strict_types=1);

namespace App\Partie\Audio;

use App\Models\InstanceMonstre;

/**
 * Sélection des barks de monstres (config/barks.php). Pur ambiance, déterministe
 * côté données : à un (archétype/instance, événement) on associe un profil de
 * voix, une réplique TEXTE (toujours) et, si l'asset a été pré-généré, son URL
 * AUDIO. Sans audio, l'écran de table lit le texte via Web Speech.
 *
 * Pour un boss/sous-boss habillé par l'IA, des répliques sur mesure peuvent
 * avoir été générées par quête ({@see \App\Jobs\GenererBarksBoss}) : elles
 * priment sur la banque d'archétype.
 */
final class BanqueBarks
{
    /** Profil de voix d'un archétype (Monstre.nom_base), repli « defaut ». */
    public function profil(string $nomBase): string
    {
        $map = (array) config('barks.archetypes', []);

        return (string) ($map[$nomBase] ?? 'defaut');
    }

    /**
     * Bark pour une instance de monstre et un événement de combat.
     *
     * @return array{profil: string, evenement: string, nom: string, texte: ?string, url: ?string}|null
     */
    public function pourInstance(InstanceMonstre $instance, string $evenement): ?array
    {
        $nomBase = $instance->monstre?->nom_base ?? '';
        $nomAffiche = $instance->habillage['nom'] ?? $nomBase;
        $profil = $this->profil($nomBase);

        // 1) Répliques sur mesure du boss (générées par quête) si présentes.
        $surMesure = $this->depuisManifesteBoss((int) $instance->quete_id, (int) $instance->id, $evenement);

        if ($surMesure !== null) {
            return ['profil' => $profil, 'evenement' => $evenement, 'nom' => $nomAffiche] + $surMesure;
        }

        // 2) Banque d'archétype.
        $lignes = $this->lignes($profil, $evenement);

        if ($lignes === []) {
            return null;
        }

        $index = array_rand($lignes);
        $texte = (string) $lignes[$index];

        return [
            'profil' => $profil,
            'evenement' => $evenement,
            'nom' => $nomAffiche,
            'texte' => $texte,
            'url' => $this->urlAsset("{$profil}/{$evenement}/{$index}.wav"),
        ];
    }

    /**
     * Répliques texte d'un (profil, événement), repli sur le profil « defaut ».
     *
     * @return list<string>
     */
    public function lignes(string $profil, string $evenement): array
    {
        $toutes = (array) config('barks.lignes', []);
        $lignes = $toutes[$profil][$evenement] ?? $toutes['defaut'][$evenement] ?? [];

        return array_values(array_map('strval', (array) $lignes));
    }

    /**
     * Bark sur mesure d'un boss depuis le manifeste de quête (écrit par le job),
     * ou null. Le manifeste vit dans public/audio/barks/quete-{id}/manifeste.php.
     *
     * @return array{texte: ?string, url: ?string}|null
     */
    private function depuisManifesteBoss(int $queteId, int $instanceId, string $evenement): ?array
    {
        $chemin = public_path("audio/barks/quete-{$queteId}/manifeste.php");

        if ($queteId === 0 || ! is_file($chemin)) {
            return null;
        }

        /** @var array<string, array<string, list<array{texte: string, fichier: string}>>> $manifeste */
        $manifeste = require $chemin;
        $variantes = $manifeste[(string) $instanceId][$evenement] ?? [];

        if ($variantes === []) {
            return null;
        }

        $v = $variantes[array_rand($variantes)];

        return [
            'texte' => (string) ($v['texte'] ?? ''),
            'url' => $this->urlAsset("quete-{$queteId}/{$v['fichier']}"),
        ];
    }

    /** URL publique d'un asset s'il existe sur disque, sinon null (repli texte). */
    private function urlAsset(string $relatif): ?string
    {
        return is_file(public_path("audio/barks/{$relatif}"))
            ? '/audio/barks/'.$relatif
            : null;
    }
}
