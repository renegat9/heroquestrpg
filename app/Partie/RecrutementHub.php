<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Groupe;
use App\Models\GroupeMercenaire;
use App\Models\Mercenaire;
use App\Models\Personnage;

/**
 * Recrutement d'un allié au hub — la DÉCISION, prise en UN seul point.
 *
 * Deux lecteurs la consomment : `MercenaireController::recruter()`, qui la fait
 * respecter, et `EtatGroupe`, qui la PUBLIE héros par héros avec son prix réel.
 * La manette n'a donc plus rien à recalculer : ni la remise de la Potion de
 * charme, ni le plafond de quatre par héros, ni le statut de Gardien, ni
 * l'animal unique. Avant ce service, elle comparait l'or au prix catalogue et
 * grisait un recrutement que le serveur aurait accepté avec le rabais.
 *
 * Les motifs de blocage gardent les clés de requête de l'ancienne validation
 * (`groupe`, `personnage_id`, `mercenaire_id`) : le POST n'a rien à traduire.
 */
final class RecrutementHub
{
    /** « Wardens may each hire up to four followers at any time between quests. » */
    public const MAX_PAR_HEROS = 4;

    /**
     * Ce que CE héros paie réellement : le catalogue, moins sa remise de Potion
     * de charme tant qu'il lui en reste une (`recrutements_a_rabais` > 0).
     *
     * @return array{prix: int, prix_catalogue: int, rabais_po: int, remise_active: bool}
     */
    public function prixPour(Personnage $recruteur, Mercenaire $mercenaire): array
    {
        $catalogue = (int) $mercenaire->prix;
        $remiseActive = (int) $recruteur->recrutements_a_rabais > 0;
        $remise = $remiseActive ? (int) $recruteur->rabais_recrutement_po : 0;
        $prix = max(0, $catalogue - $remise);

        return [
            'prix' => $prix,
            'prix_catalogue' => $catalogue,
            'rabais_po' => $catalogue - $prix,
            'remise_active' => $remiseActive,
        ];
    }

    /**
     * Premier motif qui interdit CE recrutement — `null` s'il est permis.
     *
     * @return array{cle: string, message: string}|null
     */
    public function blocage(Groupe $groupe, Mercenaire $mercenaire, Personnage $recruteur): ?array
    {
        return $this->verdict($groupe, $mercenaire, $recruteur, $this->contexte($groupe));
    }

    /**
     * Décisions publiées au hub : pour chaque allié recrutable (hors octroi
     * seul), le prix et le verdict de CHAQUE héros actif du groupe. Le client
     * choisit la ligne de son propre héros et l'affiche telle quelle.
     *
     * @return array{offres: list<array{mercenaire_id: int, decisions: list<array<string, mixed>>}>}
     */
    public function publier(Groupe $groupe): array
    {
        $contexte = $this->contexte($groupe);

        $heros = $groupe->personnages()
            ->wherePivot('actif', true)
            ->orderBy('groupe_personnages.ordre_initiative')
            ->get();

        $offres = Mercenaire::query()
            ->where('octroi_seul', false)
            ->orderBy('prix')
            ->get()
            ->map(function (Mercenaire $mercenaire) use ($groupe, $heros, $contexte) {
                $decisions = $heros->map(function (Personnage $h) use ($groupe, $mercenaire, $contexte) {
                    $prix = $this->prixPour($h, $mercenaire);
                    $motif = $this->verdict($groupe, $mercenaire, $h, $contexte);

                    return [
                        'personnage_id' => (int) $h->id,
                        'nom' => (string) $h->nom,
                        'prix' => $prix['prix'],
                        'prix_catalogue' => $prix['prix_catalogue'],
                        'rabais_po' => $prix['rabais_po'],
                        'recrutable' => $motif === null,
                        'motif' => $motif['message'] ?? null,
                    ];
                })->values()->all();

                return ['mercenaire_id' => (int) $mercenaire->id, 'decisions' => $decisions];
            })
            ->values()
            ->all();

        return ['offres' => $offres];
    }

    /**
     * Les faits du groupe qui ne dépendent pas du héros : calculés UNE fois,
     * pour que la publication ne multiplie pas les requêtes par héros × allié.
     *
     * @return array{hub: bool, gardien: bool, animal_pris: bool, engages: array<int, int>}
     */
    private function contexte(Groupe $groupe): array
    {
        return [
            'hub' => $groupe->phase === 'hub',
            'gardien' => $groupe->estGardien(),
            'animal_pris' => $groupe->mercenaires()
                ->whereHas('mercenaire', fn ($q) => $q->where('animal', true))
                ->exists(),
            // Alliés ENCORE ACTIFS par recruteur — le plafond de quatre.
            'engages' => GroupeMercenaire::where('groupe_id', $groupe->id)
                ->where('etat', 'actif')
                ->selectRaw('recruteur_personnage_id, COUNT(*) AS n')
                ->groupBy('recruteur_personnage_id')
                ->pluck('n', 'recruteur_personnage_id')
                ->map(fn ($n) => (int) $n)
                ->all(),
        ];
    }

    /**
     * L'ORDRE des motifs est celui qu'annonçait la validation d'origine : hub,
     * gardien, plafond par héros, allié non recrutable, or, animal unique.
     *
     * @param  array{hub: bool, gardien: bool, animal_pris: bool, engages: array<int, int>}  $contexte
     * @return array{cle: string, message: string}|null
     */
    private function verdict(Groupe $groupe, Mercenaire $mercenaire, Personnage $recruteur, array $contexte): ?array
    {
        if (! $contexte['hub']) {
            return ['cle' => 'groupe', 'message' => "Le recrutement n'est possible qu'au hub, entre deux quêtes."];
        }

        // STATUT DE GARDIEN : 2 quêtes achevées, pour TOUS les groupes. Les
        // mercenaires déjà recrutés avant ce statut ne sont jamais retirés.
        if (! $contexte['gardien']) {
            return ['cle' => 'groupe', 'message' => "Le recrutement n'ouvre qu'après deux quêtes achevées (statut de Gardien)."];
        }

        if (($contexte['engages'][$recruteur->id] ?? 0) >= self::MAX_PAR_HEROS) {
            return [
                'cle' => 'personnage_id',
                'message' => "« {$recruteur->nom} » a déjà engagé ".self::MAX_PAR_HEROS.' mercenaires (maximum de Gardien).',
            ];
        }

        if ($mercenaire->octroi_seul) {
            return [
                'cle' => 'mercenaire_id',
                'message' => "« {$mercenaire->nom} » ne se recrute pas : il n'existe que par son propre effet.",
            ];
        }

        $prix = $this->prixPour($recruteur, $mercenaire)['prix'];

        if ((int) $groupe->or < $prix) {
            return ['cle' => 'mercenaire_id', 'message' => "Or insuffisant : {$prix} requis, {$groupe->or} disponible."];
        }

        if ($mercenaire->animal && $contexte['animal_pris']) {
            return ['cle' => 'mercenaire_id', 'message' => 'Le groupe a déjà un compagnon animal (un seul autorisé).'];
        }

        return null;
    }
}
