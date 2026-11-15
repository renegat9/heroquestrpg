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
    public function __construct(
        private readonly ValidationCampagne $validation,
        private readonly CatalogueConstructeur $catalogue,
    ) {}

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

    /**
     * Le document d'une campagne, ses quêtes RÉSUMÉES à `{rang, slug, titre}` (MCP `campagne_lire`). Le détail d'une quête
     * se lit par {@see lireQuete()} : une réponse d'outil trop longue est tronquée côté client.
     *
     * @return array<string, mixed>
     *
     * @throws ErreurConstructeur
     */
    public function lireSommaire(string $slug): array
    {
        $document = $this->lire($slug) ?? throw ErreurConstructeur::campagneInconnue($slug);

        $quetes = [];
        foreach (array_values($document['quetes']) as $indice => $quete) {
            $quetes[] = ['rang' => $indice + 1, 'slug' => $quete['slug'], 'titre' => $quete['titre']];
        }

        return [...$document, 'quetes' => $quetes];
    }

    /**
     * Le détail d'UNE quête, lu dans le document source (MCP `quete_lire`).
     *
     * @return array<string, mixed>
     *
     * @throws ErreurConstructeur
     */
    public function lireQuete(string $slugCampagne, string $slugQuete): array
    {
        $document = $this->lire($slugCampagne) ?? throw ErreurConstructeur::campagneInconnue($slugCampagne);

        foreach ($document['quetes'] as $quete) {
            if ($quete['slug'] === $slugQuete) {
                return $quete;
            }
        }

        throw ErreurConstructeur::queteInconnue($slugCampagne, $slugQuete);
    }

    /**
     * Modifie UNE quête par opérations ciblées ({@see OperationsDeQuete}) puis enregistre la campagne ENTIÈRE, donc
     * revalidée : une opération fautive ou un refus de validation n'écrit rien. La quête garde sa place.
     *
     * @param  array<int, mixed>  $operations  `[{op, chemin, valeur?}]`, appliquées dans l'ordre
     *
     * @throws ErreurConstructeur campagne ou quête introuvable ; opération fautive, nommée par son rang
     * @throws RefusCampagne la campagne, une fois la quête modifiée, est refusée
     */
    public function modifierQuete(string $slugCampagne, string $slugQuete, array $operations): CampagneConstruite
    {
        $quete = app(OperationsDeQuete::class)->appliquer($this->lireQuete($slugCampagne, $slugQuete), $operations);

        return $this->ecrireQuete($slugCampagne, $quete);
    }

    /**
     * Remplace la quête de même slug, ou l'ajoute, puis enregistre la campagne ENTIÈRE par {@see enregistrer()} : revalidée,
     * donc un refus n'écrit rien.
     *
     * Placement : sans `$rang`, une quête remplacée garde sa place et une nouvelle va à la fin. Avec `$rang` (1 = première),
     * la quête est placée à ce rang — une quête existante est alors DÉPLACÉE. Le rang vaut de 1 au nombre de quêtes après
     * retrait de la quête remplacée, plus 1.
     *
     * @param  array<string, mixed>  $quete
     *
     * @throws ErreurConstructeur campagne introuvable, rang hors de son intervalle
     * @throws RefusCampagne la campagne, une fois la quête posée, est refusée
     */
    public function ecrireQuete(string $slugCampagne, array $quete, ?int $rang = null): CampagneConstruite
    {
        $document = $this->lire($slugCampagne) ?? throw ErreurConstructeur::campagneInconnue($slugCampagne);

        $quetes = array_values($document['quetes']);
        $indiceAncienne = null;
        if (is_string($quete['slug'] ?? null)) {
            foreach ($quetes as $indice => $existante) {
                if ($existante['slug'] === $quete['slug']) {
                    $indiceAncienne = $indice;
                    break;
                }
            }
        }
        if ($indiceAncienne !== null) {
            array_splice($quetes, $indiceAncienne, 1);
        }

        $positions = count($quetes) + 1;
        if ($rang !== null && ($rang < 1 || $rang > $positions)) {
            throw ErreurConstructeur::rangHorsBornes($rang, $positions);
        }
        $indice = $rang !== null ? $rang - 1 : ($indiceAncienne ?? count($quetes));
        array_splice($quetes, $indice, 0, [$quete]);

        return $this->enregistrer([...$document, 'quetes' => $quetes], $slugCampagne);
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
            // DÉCISIONS publiées pour la page du joueur (mode classique) : elle ne les déduit pas.
            // `jouable` : au moins une quête — la condition même de `GroupesClassiques::ouvrir()`.
            'jouable' => count($campagne->document['quetes'] ?? []) > 0,
            // Les classes exigées, avec leur nom de catalogue (un slug disparu du catalogue garde son slug pour nom).
            'prerequis_heros' => array_map(
                fn (string $slug): array => ['slug' => $slug, 'nom' => (string) ($this->catalogue->index('classes')[$slug]->nom ?? $slug)],
                array_values(array_map('strval', (array) ($campagne->document['prerequis_heros'] ?? []))),
            ),
        ];
    }
}
