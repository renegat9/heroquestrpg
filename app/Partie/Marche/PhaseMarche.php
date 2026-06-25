<?php

declare(strict_types=1);

namespace App\Partie\Marche;

use App\Events\EtatGroupeDiffuse;
use App\Events\MarcheFinalise;
use App\Events\MarcheMaj;
use App\Events\MarcheOuvert;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Joueur;
use App\Models\Objet;
use App\Models\Personnage;
use App\Partie\EtatGroupe;
use App\Support\Journal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase marché (doc 04 §5, contrat docs/contrat-api.md) — au hub uniquement.
 *
 * La phase vit en CACHE serveur (comme les menus : rien en base avant la
 * fin) : profil de lieu, inventaire dérivé du catalogue, panier par joueur.
 * Chaque joueur compose son panier (achats vers SON sac, ventes depuis SES
 * inventaires) ; rien n'est appliqué avant la confirmation de TOUS les
 * joueurs membres, puis l'application est ATOMIQUE (transaction) avec tous
 * les garde-fous du contrat :
 *  - total projeté ≥ 0 (la bourse commune couvre l'ensemble) ;
 *  - stocks du profil respectés (agrégés sur tous les paniers) ;
 *  - objets vendus réellement possédés par le joueur qui les vend ;
 *  - capacité de sac respectée pour chaque personnage après application.
 *
 * Prix d'achat = prix_base × multiplicateur du profil ; revente = 50 % du
 * prix du marchand courant (M1), à défaut 50 % du prix de base. L'or est la
 * bourse commune du groupe (M3 : `groupes.or`).
 */
final class PhaseMarche
{
    /** Durée de vie d'une phase marché abandonnée (séance de jeu). */
    public const TTL_MINUTES = 360;

    public function __construct(private readonly EtatGroupe $etatGroupe) {}

    /** Clé du cache de la phase marché d'un groupe. */
    public static function cle(int $groupeId): string
    {
        return "partie:marche:{$groupeId}";
    }

    /**
     * Ouvre la phase (POST marche) : profil choisi par le MJ IA, repli
     * `bourg` sans LLM. 422 si le groupe n'est pas au hub ou phase déjà
     * ouverte.
     *
     * @return array<string, mixed> EtatMarche
     */
    public function ouvrir(Groupe $groupe, ?string $profil = null): array
    {
        if ($groupe->phase !== 'hub') {
            throw ValidationException::withMessages([
                'groupe' => 'Le marché n\'est accessible qu\'au hub, entre deux quêtes.',
            ]);
        }

        if (Cache::has(self::cle($groupe->id))) {
            throw ValidationException::withMessages([
                'groupe' => 'Une phase marché est déjà ouverte pour ce groupe.',
            ]);
        }

        $profil ??= ProfilMarche::DEFAUT;
        $config = ProfilMarche::PROFILS[$profil];

        // Inventaire dérivé du catalogue : raretés du profil, jamais d'unique.
        $inventaire = Objet::query()
            ->whereIn('rarete', $config['raretes'])
            ->where('rarete', '!=', 'unique')
            ->orderBy('id')
            ->get()
            ->map(fn (Objet $o) => [
                'objet_id' => $o->id,
                'nom' => $o->nom,
                'categorie' => $o->categorie,
                'rarete' => $o->rarete,
                'prix' => (int) round($o->prix_base * $config['multiplicateur']),
                'stock' => ProfilMarche::STOCKS[$o->rarete] ?? null,
                'image_url' => app(\App\Partie\Images\BibliothequeImages::class)->urlObjet($o->id, $o->nom),
            ])
            ->values()
            ->all();

        $paniers = [];
        foreach ($this->membres($groupe) as $joueur) {
            $paniers[(string) $joueur->id] = $this->panierVide($joueur);
        }

        $phase = [
            'profil' => $profil,
            'multiplicateur' => $config['multiplicateur'],
            'inventaire' => $inventaire,
            'paniers' => $paniers,
        ];

        Cache::put(self::cle($groupe->id), $phase, now()->addMinutes(self::TTL_MINUTES));

        Journal::ajouter($groupe, 'systeme', ['action' => 'marche_ouvert', 'profil' => $profil]);

        $etat = $this->payload($groupe, $phase);
        broadcast(new MarcheOuvert($groupe, $etat));

        return $etat;
    }

    /**
     * EtatMarche courant (GET marche), ou null si aucune phase ouverte.
     *
     * @return array<string, mixed>|null
     */
    public function etat(Groupe $groupe): ?array
    {
        $phase = Cache::get(self::cle($groupe->id));

        return is_array($phase) ? $this->payload($groupe, $phase) : null;
    }

    /**
     * Remplace le panier du joueur (PUT panier) et ANNULE SA confirmation.
     * Les garde-fous individuels sont vérifiés dès ici (retour immédiat au
     * joueur) ; les garde-fous globaux (or, stocks agrégés, sacs) le sont à
     * l'application.
     *
     * @param  list<array{objet_id: int, quantite?: int, personnage_id?: int}>  $achats
     * @param  list<array{inventaire_id: int}>  $ventes
     * @return array<string, mixed> EtatMarche
     */
    public function majPanier(Groupe $groupe, Joueur $joueur, array $achats, array $ventes): array
    {
        $phase = $this->phaseOuverte($groupe);

        $inventaire = collect($phase['inventaire'])->keyBy('objet_id');
        $personnages = $this->personnagesDuJoueur($groupe, $joueur);

        if ($personnages->isEmpty()) {
            throw ValidationException::withMessages([
                'identifiant' => 'Vous n\'avez aucun héros actif dans ce groupe.',
            ]);
        }

        $lignesAchat = $this->validerAchats($achats, $inventaire, $personnages);
        $lignesVente = $this->validerVentes($ventes, $inventaire, $personnages);

        $phase['paniers'][(string) $joueur->id] = [
            ...$this->panierVide($joueur),
            'achats' => $lignesAchat,
            'ventes' => $lignesVente,
        ];

        Cache::put(self::cle($groupe->id), $phase, now()->addMinutes(self::TTL_MINUTES));

        $etat = $this->payload($groupe, $phase);
        broadcast(new MarcheMaj($groupe, $etat));

        return $etat;
    }

    /**
     * Confirmation du joueur (POST confirmation). Quand TOUS les paniers
     * sont confirmés : application atomique + clôture. Si un garde-fou
     * global échoue, la confirmation est REJETÉE (422) et la phase reste
     * ouverte — le panier fautif doit être corrigé.
     *
     * @return array{applique: bool|null, marche: array<string, mixed>|null}
     */
    public function confirmer(Groupe $groupe, Joueur $joueur): array
    {
        $phase = $this->phaseOuverte($groupe);

        $clePanier = (string) $joueur->id;

        if (! isset($phase['paniers'][$clePanier])) {
            $phase['paniers'][$clePanier] = $this->panierVide($joueur);
        }

        $phase['paniers'][$clePanier]['confirme'] = true;

        $tousConfirmes = collect($phase['paniers'])->every(fn ($p) => $p['confirme']);

        if (! $tousConfirmes) {
            Cache::put(self::cle($groupe->id), $phase, now()->addMinutes(self::TTL_MINUTES));

            $etat = $this->payload($groupe, $phase);
            broadcast(new MarcheMaj($groupe, $etat));

            return ['applique' => null, 'marche' => $etat];
        }

        // Tous confirmés → application atomique. En cas d'échec, rien n'est
        // persisté (ni en base, ni la confirmation en cache) : 422.
        $this->appliquer($groupe, $phase);

        Cache::forget(self::cle($groupe->id));

        broadcast(new MarcheFinalise($groupe, applique: true));
        broadcast(new EtatGroupeDiffuse($groupe, $this->etatGroupe->payload($groupe->fresh())));

        return ['applique' => true, 'marche' => null];
    }

    /** Annule la phase (DELETE marche) : rien n'est appliqué. */
    public function annuler(Groupe $groupe): void
    {
        $this->phaseOuverte($groupe);

        Cache::forget(self::cle($groupe->id));

        Journal::ajouter($groupe, 'systeme', ['action' => 'marche_annule']);

        broadcast(new MarcheFinalise($groupe, applique: false));
    }

    // ------------------------------------------------------------------
    // Validation des paniers
    // ------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $achats
     * @param  Collection<int|string, array<string, mixed>>  $inventaire
     * @param  Collection<int, Personnage>  $personnages
     * @return list<array<string, mixed>>
     */
    private function validerAchats(array $achats, Collection $inventaire, Collection $personnages): array
    {
        $lignes = [];
        $parObjet = [];

        foreach ($achats as $achat) {
            $objetId = (int) ($achat['objet_id'] ?? 0);
            $quantite = (int) ($achat['quantite'] ?? 1);
            $ligne = $inventaire->get($objetId);

            if ($ligne === null) {
                throw ValidationException::withMessages([
                    'achats' => 'Un objet du panier n\'est pas proposé par ce marchand (profil du lieu).',
                ]);
            }

            if ($quantite < 1) {
                throw ValidationException::withMessages(['achats' => 'Quantité invalide (minimum 1).']);
            }

            $parObjet[$objetId] = ($parObjet[$objetId] ?? 0) + $quantite;

            if ($ligne['stock'] !== null && $parObjet[$objetId] > $ligne['stock']) {
                throw ValidationException::withMessages([
                    'achats' => "Stock insuffisant pour « {$ligne['nom']} » ({$ligne['stock']} en boutique).",
                ]);
            }

            // Destinataire : un des héros du joueur (son premier par défaut) —
            // les achats vont vers SON sac (doc 04 §5).
            $personnageId = (int) ($achat['personnage_id'] ?? $personnages->first()->id);

            if (! $personnages->contains('id', $personnageId)) {
                throw ValidationException::withMessages([
                    'achats' => 'Le destinataire d\'un achat doit être un de vos héros actifs du groupe.',
                ]);
            }

            $lignes[] = [
                'objet_id' => $objetId,
                'nom' => $ligne['nom'],
                'quantite' => $quantite,
                'prix_unitaire' => $ligne['prix'],
                'total' => $ligne['prix'] * $quantite,
                'personnage_id' => $personnageId,
            ];
        }

        return $lignes;
    }

    /**
     * Revente (M1) : 50 % du prix du marchand courant si l'objet figure à
     * son étal, à défaut 50 % du prix de base. La ligne d'inventaire entière
     * est vendue (quantité comprise).
     *
     * @param  list<array<string, mixed>>  $ventes
     * @param  Collection<int|string, array<string, mixed>>  $inventaire
     * @param  Collection<int, Personnage>  $personnages
     * @return list<array<string, mixed>>
     */
    private function validerVentes(array $ventes, Collection $inventaire, Collection $personnages): array
    {
        $lignes = [];
        $vus = [];

        foreach ($ventes as $vente) {
            $inventaireId = (int) ($vente['inventaire_id'] ?? 0);

            if (isset($vus[$inventaireId])) {
                throw ValidationException::withMessages(['ventes' => 'Un même objet ne peut être vendu deux fois.']);
            }
            $vus[$inventaireId] = true;

            $ligne = Inventaire::with('objet')
                ->whereKey($inventaireId)
                ->whereIn('personnage_id', $personnages->pluck('id'))
                ->first();

            if ($ligne === null) {
                throw ValidationException::withMessages([
                    'ventes' => 'Un objet vendu n\'est pas possédé par vos héros de ce groupe.',
                ]);
            }

            $prixMarchand = $inventaire->get($ligne->objet_id)['prix'] ?? (int) $ligne->objet->prix_base;
            $prixRevente = intdiv($prixMarchand, 2);

            $lignes[] = [
                'inventaire_id' => $ligne->id,
                'objet_id' => $ligne->objet_id,
                'nom' => $ligne->objet->nom,
                'quantite' => (int) $ligne->quantite,
                'prix_revente' => $prixRevente,
                'total' => $prixRevente * (int) $ligne->quantite,
                'personnage_id' => $ligne->personnage_id,
            ];
        }

        return $lignes;
    }

    // ------------------------------------------------------------------
    // Application atomique
    // ------------------------------------------------------------------

    /**
     * Tous les garde-fous du contrat sont re-vérifiés ICI, sur l'état réel,
     * puis tout est appliqué dans une seule transaction.
     *
     * @param  array<string, mixed>  $phase
     */
    private function appliquer(Groupe $groupe, array $phase): void
    {
        DB::transaction(function () use ($groupe, $phase) {
            $groupe->refresh();

            $paniers = $phase['paniers'];
            $inventaire = collect($phase['inventaire'])->keyBy('objet_id');

            // Stocks agrégés sur TOUS les paniers.
            $parObjet = [];
            foreach ($paniers as $panier) {
                foreach ($panier['achats'] as $achat) {
                    $parObjet[$achat['objet_id']] = ($parObjet[$achat['objet_id']] ?? 0) + $achat['quantite'];
                }
            }
            foreach ($parObjet as $objetId => $quantite) {
                $stock = $inventaire->get($objetId)['stock'] ?? null;

                if ($stock !== null && $quantite > $stock) {
                    $nom = $inventaire->get($objetId)['nom'] ?? "objet {$objetId}";
                    throw ValidationException::withMessages([
                        'achats' => "Stock insuffisant pour « {$nom} » : {$quantite} demandés sur l'ensemble des paniers, {$stock} en boutique.",
                    ]);
                }
            }

            // Ventes : possession re-vérifiée sur l'état réel, sans doublon
            // entre paniers.
            $lignesVendues = [];
            foreach ($paniers as $panier) {
                $personnageIds = $this->personnagesDuJoueur($groupe, Joueur::findOrFail($panier['joueur_id']))->pluck('id');

                foreach ($panier['ventes'] as $vente) {
                    if (isset($lignesVendues[$vente['inventaire_id']])) {
                        throw ValidationException::withMessages(['ventes' => 'Un même objet ne peut être vendu deux fois.']);
                    }

                    $ligne = Inventaire::whereKey($vente['inventaire_id'])
                        ->whereIn('personnage_id', $personnageIds)
                        ->first();

                    if ($ligne === null) {
                        throw ValidationException::withMessages([
                            'ventes' => "« {$vente['nom']} » n'est plus possédé par le joueur qui le vend.",
                        ]);
                    }

                    $lignesVendues[$vente['inventaire_id']] = $ligne;
                }
            }

            // Total projeté ≥ 0 : la bourse commune couvre l'ensemble.
            $total = (int) $groupe->or + $this->sommeVentes($paniers) - $this->sommeAchats($paniers);

            if ($total < 0) {
                throw ValidationException::withMessages([
                    'achats' => 'Or insuffisant : le total projeté de la bourse commune est négatif.',
                ]);
            }

            // Capacité de sac après application, pour CHAQUE personnage :
            // + achats non consommables (ils vont au sac), − ventes de lignes
            // rangées au sac (les consommables ne comptent jamais, doc 01 §7).
            $objets = Objet::findMany(array_keys($parObjet))->keyBy('id');
            $deltas = [];

            foreach ($paniers as $panier) {
                foreach ($panier['achats'] as $achat) {
                    if ($objets[$achat['objet_id']]->emplacement !== 'consommable') {
                        $deltas[$achat['personnage_id']] = ($deltas[$achat['personnage_id']] ?? 0) + $achat['quantite'];
                    }
                }
            }
            foreach ($lignesVendues as $ligne) {
                if ($ligne->emplacement === 'sac') {
                    $deltas[$ligne->personnage_id] = ($deltas[$ligne->personnage_id] ?? 0) - (int) $ligne->quantite;
                }
            }

            foreach ($deltas as $personnageId => $delta) {
                $personnage = Personnage::findOrFail($personnageId);
                $capacite = CapaciteSac::pour($personnage);
                $apres = CapaciteSac::occupation($personnage) + $delta;

                if ($apres > $capacite) {
                    throw ValidationException::withMessages([
                        'achats' => "Sac plein pour « {$personnage->nom} » : {$apres} objets pour une capacité de {$capacite}.",
                    ]);
                }
            }

            // ---- Application ----

            foreach ($lignesVendues as $ligne) {
                $ligne->delete();
            }

            foreach ($paniers as $panier) {
                foreach ($panier['achats'] as $achat) {
                    $this->rangerAchat($objets[$achat['objet_id']], (int) $achat['personnage_id'], (int) $achat['quantite']);
                }
            }

            $groupe->update(['or' => $total]);

            Journal::ajouter($groupe, 'systeme', [
                'action' => 'marche_finalise',
                'profil' => $phase['profil'],
                'achats' => $this->sommeAchats($paniers),
                'ventes' => $this->sommeVentes($paniers),
                'or_apres' => $total,
                'paniers' => array_values(array_map(fn ($p) => [
                    'joueur_id' => $p['joueur_id'],
                    'achats' => $p['achats'],
                    'ventes' => $p['ventes'],
                ], $paniers)),
            ]);
        });
    }

    /**
     * Range un achat dans l'inventaire du destinataire : les consommables
     * s'empilent (quantité, illimités), le reste va au SAC, un exemplaire
     * par ligne (chaque pièce porte ses propres améliorations de Forge).
     */
    private function rangerAchat(Objet $objet, int $personnageId, int $quantite): void
    {
        if ($objet->emplacement === 'consommable') {
            $ligne = Inventaire::query()
                ->where('personnage_id', $personnageId)
                ->where('objet_id', $objet->id)
                ->where('emplacement', 'consommable')
                ->first();

            if ($ligne !== null) {
                $ligne->increment('quantite', $quantite);
            } else {
                Inventaire::create([
                    'personnage_id' => $personnageId,
                    'objet_id' => $objet->id,
                    'emplacement' => 'consommable',
                    'quantite' => $quantite,
                ]);
            }

            return;
        }

        for ($i = 0; $i < $quantite; $i++) {
            Inventaire::create([
                'personnage_id' => $personnageId,
                'objet_id' => $objet->id,
                'emplacement' => 'sac',
                'quantite' => 1,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function phaseOuverte(Groupe $groupe): array
    {
        $phase = Cache::get(self::cle($groupe->id));

        if (! is_array($phase)) {
            throw ValidationException::withMessages([
                'groupe' => 'Aucune phase marché ouverte pour ce groupe.',
            ]);
        }

        return $phase;
    }

    /**
     * Payload EtatMarche du contrat — total projeté recalculé en direct :
     * `or courant + ventes − achats`, agrégé sur tous les paniers (M3).
     *
     * @param  array<string, mixed>  $phase
     * @return array<string, mixed>
     */
    private function payload(Groupe $groupe, array $phase): array
    {
        $or = (int) $groupe->or;

        // Chaque panier embarque l'inventaire VENDABLE du joueur (ses héros
        // actifs du groupe) avec le prix de revente — c'est la source que la
        // manette affiche dans l'onglet « Vendre » (contrat EtatMarche).
        $paniers = array_map(
            fn (array $panier) => $panier + [
                'inventaire' => $this->inventaireVendable($groupe, (int) $panier['joueur_id'], $phase),
            ],
            array_values($phase['paniers']),
        );

        return [
            'profil' => $phase['profil'],
            'multiplicateur' => $phase['multiplicateur'],
            'inventaire' => array_values($phase['inventaire']),
            'paniers' => $paniers,
            'total_projete' => $or + $this->sommeVentes($phase['paniers']) - $this->sommeAchats($phase['paniers']),
            'or_courant' => $or,
        ];
    }

    /**
     * Lignes d'inventaire vendables d'un joueur : tout objet possédé par ses
     * héros actifs du groupe, avec prix de revente M1 (50 % du prix marchand
     * courant si l'objet figure au profil, sinon 50 % du prix de base).
     *
     * @param  array<string, mixed>  $phase
     * @return list<array<string, mixed>>
     */
    private function inventaireVendable(Groupe $groupe, int $joueurId, array $phase): array
    {
        $personnageIds = $groupe->personnages()
            ->wherePivot('actif', true)
            ->where('joueur_id', $joueurId)
            ->pluck('personnages.id');

        $marchand = collect($phase['inventaire'])->keyBy('objet_id');

        return Inventaire::query()
            ->with('objet')
            ->whereIn('personnage_id', $personnageIds)
            ->get()
            ->map(fn (Inventaire $ligne) => [
                'inventaire_id' => (int) $ligne->id,
                'personnage_id' => (int) $ligne->personnage_id,
                'objet_id' => (int) $ligne->objet_id,
                'nom' => $ligne->objet->nom,
                'categorie' => $ligne->objet->categorie,
                'rarete' => $ligne->objet->rarete,
                'emplacement' => $ligne->emplacement,
                'quantite' => (int) $ligne->quantite,
                'revente' => intdiv(
                    (int) ($marchand[$ligne->objet_id]['prix'] ?? $ligne->objet->prix_base),
                    2
                ),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $paniers
     */
    private function sommeAchats(array $paniers): int
    {
        return (int) collect($paniers)->sum(fn ($p) => collect($p['achats'])->sum('total'));
    }

    /**
     * @param  array<string, array<string, mixed>>  $paniers
     */
    private function sommeVentes(array $paniers): int
    {
        return (int) collect($paniers)->sum(fn ($p) => collect($p['ventes'])->sum('total'));
    }

    /**
     * @return array<string, mixed>
     */
    private function panierVide(Joueur $joueur): array
    {
        return [
            'joueur_id' => (int) $joueur->id,
            'pseudo' => $joueur->pseudo,
            'achats' => [],
            'ventes' => [],
            'confirme' => false,
        ];
    }

    /**
     * Joueurs membres : ceux qui ont au moins un héros ACTIF dans le groupe.
     *
     * @return Collection<int, Joueur>
     */
    private function membres(Groupe $groupe): Collection
    {
        $ids = $groupe->personnages()
            ->wherePivot('actif', true)
            ->pluck('joueur_id')
            ->unique();

        return Joueur::whereIn('id', $ids)->orderBy('id')->get();
    }

    /**
     * Héros actifs du joueur dans CE groupe.
     *
     * @return Collection<int, Personnage>
     */
    private function personnagesDuJoueur(Groupe $groupe, Joueur $joueur): Collection
    {
        return $groupe->personnages()
            ->wherePivot('actif', true)
            ->where('joueur_id', $joueur->id)
            ->orderBy('groupe_personnages.ordre_initiative')
            ->get();
    }
}
