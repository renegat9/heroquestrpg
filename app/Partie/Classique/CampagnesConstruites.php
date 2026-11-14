<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\CampagneConstruite;
use App\Models\QueteConstruite;
use Illuminate\Support\Facades\DB;

/**
 * Les campagnes construites : liste, lecture, enregistrement, export et import (MODE CLASSIQUE, lot K1 —
 * `docs/plan-mode-classique.md` §8 bis : le MCP appellera CE service, comme la route HTTP).
 *
 * Aucune règle du format ici : tout contrôle passe par {@see ValidationCampagne}. Ce service ne fait que stocker, relire,
 * projeter les quêtes et calculer l'empreinte. Aucune donnée n'est mise en cache : la base est la seule source.
 */
final class CampagnesConstruites
{
    public function __construct(private readonly ValidationCampagne $validation) {}

    /** @return list<array<string, mixed>> les résumés, triés par slug */
    public function lister(): array
    {
        return CampagneConstruite::query()->orderBy('slug')->get()
            ->map(fn (CampagneConstruite $campagne): array => $this->resume($campagne))
            ->values()
            ->all();
    }

    /** @return array<string, mixed>|null le document enregistré, tel que PUT l'accepte */
    public function lire(string $slug): ?array
    {
        return CampagneConstruite::query()->where('slug', $slug)->first()?->document;
    }

    /** @return array<string, mixed>|null le document plus `empreinte` : le fichier exporté */
    public function exporter(string $slug): ?array
    {
        $campagne = CampagneConstruite::query()->where('slug', $slug)->first();
        if ($campagne === null) {
            return null;
        }

        return [...$campagne->document, 'empreinte' => $campagne->empreinte];
    }

    /** @param  array<string, mixed>  $document */
    public function valider(array $document): array
    {
        return $this->validation->valider($document);
    }

    /**
     * Crée, ou remplace, la campagne de ce slug. Valide d'abord : un document refusé n'écrit RIEN.
     *
     * @param  array<string, mixed>  $document
     * @param  string|null  $slugAttendu  le slug de l'URL, s'il y en a un : il doit égaler celui du document
     *
     * @throws RefusCampagne
     */
    public function enregistrer(array $document, ?string $slugAttendu = null): CampagneConstruite
    {
        $refus = $this->validation->valider($document, $slugAttendu);
        if ($refus !== []) {
            throw RefusCampagne::depuis($refus);
        }

        return DB::transaction(function () use ($document): CampagneConstruite {
            $campagne = CampagneConstruite::query()->updateOrCreate(
                ['slug' => $document['slug']],
                [
                    'titre' => $document['titre'],
                    'auteur' => $document['auteur'],
                    'mention' => $document['mention'],
                    'document' => $document,
                    'version_format' => $document['format'],
                    'empreinte' => EmpreinteCampagne::de($document),
                ],
            );

            // La projection se réécrit EN ENTIER, dans la même transaction que le document.
            QueteConstruite::query()->where('campagne_construite_id', $campagne->id)->delete();
            foreach (array_values($document['quetes']) as $rangIndexe => $quete) {
                QueteConstruite::query()->create([
                    'campagne_construite_id' => $campagne->id,
                    'rang' => $rangIndexe + 1,
                    'slug' => $quete['slug'],
                    'titre' => $quete['titre'],
                    'document' => $quete,
                ]);
            }

            return $campagne;
        });
    }

    /**
     * Importe un fichier exporté (ou écrit à la main) : retire `empreinte` s'il est présent et la VÉRIFIE contre le
     * document, valide, puis enregistre. Un `format` inconnu est refusé par la validation.
     *
     * @param  array<string, mixed>  $fichier
     *
     * @throws RefusCampagne
     */
    public function importer(array $fichier): CampagneConstruite
    {
        $avecEmpreinte = array_key_exists('empreinte', $fichier);
        $declaree = $fichier['empreinte'] ?? null;
        unset($fichier['empreinte']);

        $refus = $this->validation->valider($fichier);
        if ($refus === [] && $avecEmpreinte) {
            $refus = $this->validation->verifierEmpreinte($fichier, $declaree);
        }
        if ($refus !== []) {
            throw RefusCampagne::depuis($refus);
        }

        return $this->enregistrer($fichier);
    }

    /**
     * @return array{slug: string, titre: string, auteur: ?string, mention: ?string, version_format: int, empreinte: string, nb_quetes: int, mise_a_jour: ?string}
     */
    public function resume(CampagneConstruite $campagne): array
    {
        return [
            'slug' => $campagne->slug,
            'titre' => $campagne->titre,
            'auteur' => $campagne->auteur,
            'mention' => $campagne->mention,
            'version_format' => $campagne->version_format,
            'empreinte' => $campagne->empreinte,
            'nb_quetes' => count($campagne->document['quetes'] ?? []),
            'mise_a_jour' => $campagne->updated_at?->toIso8601String(),
        ];
    }
}
