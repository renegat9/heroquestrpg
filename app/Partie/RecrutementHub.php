<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Groupe;
use App\Models\GroupeMercenaire;
use App\Models\Mercenaire;
use App\Models\Personnage;
use App\Models\PersonnageFaveur;

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
     * de charme tant qu'il lui en reste une (`recrutements_a_rabais` > 0) — ou RIEN si la
     * faveur *Mercenary Guild* (Hopekins Rest) lui offre son recrutement (`$gratuitDuHeros`,
     * voir {@see FaveursHopekins::mercenaireGratuitDisponible()}) et que l'allié est un
     * mercenaire, pas un compagnon animal. La gratuité prime sur la remise : la potion n'est
     * alors PAS consommée (`remise_active` faux), elle servira au recrutement suivant.
     *
     * MODE CLASSIQUE (lot E2) : avec `$groupe`, un compagnon animal que la campagne offre (`MercenairesCampagne::animalOffert()`)
     * ne coûte rien (`compagnon_offert`).
     *
     * @return array{prix: int, prix_catalogue: int, rabais_po: int, remise_active: bool, gratuit_faveur: bool, compagnon_offert: bool}
     */
    public function prixPour(Personnage $recruteur, Mercenaire $mercenaire, bool $gratuitDuHeros = false, ?Groupe $groupe = null): array
    {
        $catalogue = (int) $mercenaire->prix;

        if ($groupe !== null && app(\App\Partie\Classique\MercenairesCampagne::class)->animalOffert($groupe, $mercenaire)) {
            return ['prix' => 0, 'prix_catalogue' => $catalogue, 'rabais_po' => $catalogue, 'remise_active' => false,
                'gratuit_faveur' => false, 'compagnon_offert' => true];
        }

        if ($gratuitDuHeros && ! $mercenaire->animal) {
            return [
                'prix' => 0,
                'prix_catalogue' => $catalogue,
                'rabais_po' => $catalogue,
                'remise_active' => false,
                'gratuit_faveur' => true,
                'compagnon_offert' => false,
            ];
        }

        $remiseActive = (int) $recruteur->recrutements_a_rabais > 0;
        $remise = $remiseActive ? (int) $recruteur->rabais_recrutement_po : 0;
        $prix = max(0, $catalogue - $remise);

        return [
            'prix' => $prix,
            'prix_catalogue' => $catalogue,
            'rabais_po' => $catalogue - $prix,
            'remise_active' => $remiseActive,
            'gratuit_faveur' => false,
            'compagnon_offert' => false,
        ];
    }

    /**
     * Ce héros a-t-il, dans ce groupe, un recrutement offert par la faveur *Mercenary Guild* ?
     * Le seul endroit que le contrôleur interroge pour appeler {@see self::prixPour()}.
     */
    public function gratuitPour(Groupe $groupe, Personnage $recruteur): bool
    {
        return FaveursHopekins::mercenaireGratuitDisponible($recruteur, (int) $groupe->id);
    }

    /**
     * Premier motif qui interdit CE recrutement — `null` s'il est permis.
     *
     * @return array{cle: string, message: string}|null
     */
    public function blocage(Groupe $groupe, Mercenaire $mercenaire, Personnage $recruteur, bool $parJeton = false): ?array
    {
        // MODE CLASSIQUE, régime `debloques` (lot K6) : payer d'un jeton de réputation plutôt que d'or.
        if ($parJeton) {
            return $groupe->estClassique()
                ? $this->verdictClassique($groupe, $mercenaire, $recruteur, $this->contexte($groupe), parJeton: true)
                : ['cle' => 'paiement', 'message' => 'Un jeton de réputation ne s\'échange que dans une campagne classique qui le permet.'];
        }

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

        $classique = app(\App\Partie\Classique\MercenairesCampagne::class);
        $offres = Mercenaire::query()
            ->where('octroi_seul', false)
            ->orderBy('prix')
            ->get()
            // MODE CLASSIQUE (lot K6) : seuls ceux que la campagne offre (et, en `debloques`, qu'elle a débloqués).
            ->filter(fn (Mercenaire $m) => ! $groupe->estClassique() || $classique->offre($groupe, $m))
            ->map(function (Mercenaire $mercenaire) use ($groupe, $heros, $contexte) {
                $decisions = $heros->map(function (Personnage $h) use ($groupe, $mercenaire, $contexte) {
                    $prix = $this->prixPour($h, $mercenaire, isset($contexte['gratuits'][$h->id]), $groupe);
                    $motif = $this->verdict($groupe, $mercenaire, $h, $contexte);

                    return [
                        'personnage_id' => (int) $h->id,
                        'nom' => (string) $h->nom,
                        'prix' => $prix['prix'],
                        'prix_catalogue' => $prix['prix_catalogue'],
                        'rabais_po' => $prix['rabais_po'],
                        // La faveur *Mercenary Guild* offre ce recrutement : la manette l'écrit,
                        // sans savoir ce qu'est la faveur ni si l'allié est un animal.
                        'offert_par_faveur' => $prix['gratuit_faveur'],
                        // MODE CLASSIQUE (lot E2) : le compagnon animal offert par la campagne, gratuit, un par héros.
                        'compagnon_offert' => $prix['compagnon_offert'],
                        'recrutable' => $motif === null,
                        'motif' => $motif['message'] ?? null,
                        // MODE CLASSIQUE (lot K6) : la bourse de CE héros, d'où l'or sortira, et l'embauche contre un jeton de
                        // réputation (régime `debloques`) — clés absentes au RPG.
                        ...($groupe->estClassique() ? [
                            'bourse' => app(Bourses::class)->solde($groupe, $h),
                            'payable_par_jeton' => $this->verdictClassique($groupe, $mercenaire, $h, $contexte, parJeton: true) === null,
                        ] : []),
                    ];
                })->values()->all();

                return ['mercenaire_id' => (int) $mercenaire->id, 'decisions' => $decisions];
            })
            ->values()
            ->all();

        // MODE CLASSIQUE (lot K6) : la règle d'embauche de la campagne, dite par le serveur — la manette ne connaît ni
        // l'entretien du RPG ni la bourse commune. Clé absente au RPG.
        $regle = app(\App\Partie\Classique\MercenairesCampagne::class)->regle($groupe);
        $compagnons = app(\App\Partie\Classique\MercenairesCampagne::class)->compagnonsOfferts($groupe) !== [];
        $texteCompagnons = 'Moins de quatre héros : chacun peut emmener un compagnon animal, gratuitement, pour la prochaine quête.';

        return ['offres' => $offres, ...($regle !== null ? ['classique' => [
            'regime' => $regle['regime'],
            'texte' => match ($regle['regime']) {
                'par_quete' => 'Chaque mercenaire s\'engage pour la prochaine quête seulement, payé de la bourse de son recruteur ; il faut le réengager avant la suivante. Jamais en quête solo.',
                'retenu_moitie' => 'Engagé au prix plein, un mercenaire reste d\'une quête à l\'autre si son recruteur paie la moitié de son prix au départ de chaque nouvelle quête ; sinon, il part.',
                default => 'Les mercenaires se débloquent au fil des quêtes ; chacun s\'engage pour la prochaine quête, payé de la bourse de son recruteur.',
            }.($compagnons ? ' '.$texteCompagnons : ''),
        ]] : ($compagnons ? ['classique' => ['regime' => null, 'texte' => $texteCompagnons]] : []))];
    }

    /**
     * MODE CLASSIQUE (lot K6) : la campagne offre-t-elle CE mercenaire (débloqué, en `debloques`), la prochaine quête
     * n'est-elle pas solo, et la bourse du recruteur suffit-elle ? Le compagnon animal reste unique.
     *
     * @param  array<string, mixed>  $contexte
     * @return array{cle: string, message: string}|null
     */
    private function verdictClassique(Groupe $groupe, Mercenaire $mercenaire, Personnage $recruteur, array $contexte, bool $parJeton = false): ?array
    {
        if (! $contexte['hub']) {
            return ['cle' => 'groupe', 'message' => "Le recrutement n'est possible qu'au hub, entre deux quêtes."];
        }
        $campagne = app(\App\Partie\Classique\MercenairesCampagne::class);
        if (! $campagne->ouvert($groupe)) {
            return ['cle' => 'groupe', 'message' => 'Cette campagne n\'offre aucun mercenaire.'];
        }
        if ($mercenaire->octroi_seul || ! $campagne->offre($groupe, $mercenaire)) {
            return ['cle' => 'mercenaire_id', 'message' => "« {$mercenaire->nom} » n'est pas à engager dans cette campagne."];
        }
        if (\App\Partie\Classique\MercenairesCampagne::prochaineSolo($groupe)) {
            return ['cle' => 'groupe', 'message' => 'La prochaine quête se joue seul : aucun mercenaire ne s\'y engage.'];
        }
        // LE COMPAGNON ANIMAL OFFERT (lot E2) : gratuit, un par héros (« A hero can control only one animal ally at a time »).
        if ($campagne->animalOffert($groupe, $mercenaire)) {
            if ($parJeton) {
                return ['cle' => 'paiement', 'message' => 'Ce compagnon est offert : aucun jeton à dépenser.'];
            }

            return $campagne->aSonCompagnon($groupe, $recruteur)
                ? ['cle' => 'personnage_id', 'message' => "« {$recruteur->nom} » emmène déjà un compagnon animal (un par héros)."]
                : null;
        }
        $regime = $campagne->regle($groupe)['regime'] ?? null;
        // *Dread Moon* p. 11 : « More than one of the same type of mercenary cannot be hired for the same quest. »
        if ($regime === 'debloques' && GroupeMercenaire::where('groupe_id', $groupe->id)->where('etat', 'actif')
            ->where('mercenaire_id', $mercenaire->id)->exists()) {
            return ['cle' => 'mercenaire_id', 'message' => "Un « {$mercenaire->nom} » sert déjà le groupe : un seul de chaque type."];
        }
        if ($parJeton) {
            // « a hero may spend one reputation token to hire a mercenary instead of paying the listed cost ».
            if ($regime !== 'debloques') {
                return ['cle' => 'paiement', 'message' => 'Cette campagne ne s\'engage pas contre un jeton de réputation.'];
            }
            if (app(\App\Partie\Classique\Reputation::class)->jetons($groupe) < 1) {
                return ['cle' => 'paiement', 'message' => 'Le groupe n\'a aucun jeton de réputation.'];
            }
        } else {
            $prix = $this->prixPour($recruteur, $mercenaire, isset($contexte['gratuits'][$recruteur->id]), $groupe)['prix'];
            $solde = app(Bourses::class)->solde($groupe, $recruteur);
            if ($solde < $prix) {
                return ['cle' => 'mercenaire_id', 'message' => "Or insuffisant : {$prix} requis, {$solde} dans la bourse de {$recruteur->nom}."];
            }
        }
        if ($mercenaire->animal && $contexte['animal_pris']) {
            return ['cle' => 'mercenaire_id', 'message' => 'Le groupe a déjà un compagnon animal (un seul autorisé).'];
        }

        return null;
    }

    /**
     * Les faits du groupe qui ne dépendent pas du héros : calculés UNE fois,
     * pour que la publication ne multiplie pas les requêtes par héros × allié.
     *
     * @return array{hub: bool, gardien: bool, animal_pris: bool, engages: array<int, int>, gratuits: array<int, true>}
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
            // Héros qui ont un recrutement OFFERT à prendre (faveur *Mercenary Guild*).
            'gratuits' => $this->heroisAvecRecrutementOffert($groupe),
        ];
    }

    /**
     * Les héros ACTIFS du groupe dont la faveur *Mercenary Guild* n'a pas (ou plus) de mercenaire
     * en poste. Une requête pour toutes les faveurs, puis une par faveur pour son allié.
     *
     * @return array<int, true>
     */
    private function heroisAvecRecrutementOffert(Groupe $groupe): array
    {
        $ids = $groupe->personnages()->wherePivot('actif', true)->pluck('personnages.id');

        $gratuits = [];

        foreach (PersonnageFaveur::query()
            ->where('cle', FaveursHopekins::MERCENARY_GUILD)
            ->whereIn('personnage_id', $ids)
            ->get() as $faveur) {
            if (! FaveursHopekins::mercenaireOffertEnPoste($faveur, (int) $groupe->id)) {
                $gratuits[(int) $faveur->personnage_id] = true;
            }
        }

        return $gratuits;
    }

    /**
     * L'ORDRE des motifs est celui qu'annonçait la validation d'origine : hub,
     * gardien, plafond par héros, allié non recrutable, or, animal unique.
     *
     * @param  array{hub: bool, gardien: bool, animal_pris: bool, engages: array<int, int>, gratuits: array<int, true>}  $contexte
     * @return array{cle: string, message: string}|null
     */
    private function verdict(Groupe $groupe, Mercenaire $mercenaire, Personnage $recruteur, array $contexte): ?array
    {
        if (! $contexte['hub']) {
            return ['cle' => 'groupe', 'message' => "Le recrutement n'est possible qu'au hub, entre deux quêtes."];
        }

        // MODE CLASSIQUE (lot K6) : la règle de la CAMPAGNE (`Classique\MercenairesCampagne`) — ni Gardien ni plafond ;
        // l'or est celui de la bourse du recruteur.
        if ($groupe->estClassique()) {
            return $this->verdictClassique($groupe, $mercenaire, $recruteur, $contexte);
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

        $prix = $this->prixPour($recruteur, $mercenaire, isset($contexte['gratuits'][$recruteur->id]))['prix'];

        if ((int) $groupe->or < $prix) {
            return ['cle' => 'mercenaire_id', 'message' => "Or insuffisant : {$prix} requis, {$groupe->or} disponible."];
        }

        if ($mercenaire->animal && $contexte['animal_pris']) {
            return ['cle' => 'mercenaire_id', 'message' => 'Le groupe a déjà un compagnon animal (un seul autorisé).'];
        }

        return null;
    }
}
