<?php

declare(strict_types=1);

namespace App\Partie\Arene;

use App\Models\Groupe;
use App\Models\Joueur;
use App\Models\Monstre;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * MODE ARÈNE, lot A9a — le SALON Monster vs Monster (livret F9528 p. 16, `docs/plan-mode-arene.md` §4, §7 bis) :
 *
 * « Both sides agree on an equal team power for both sides. Randomly determine which team will be the Challenger
 * team, and which team the Defender team. The Defender team selects a monster from those available first, and the two
 * teams proceed to alternate selecting monsters until each team meets the agreed-upon team power. »
 *
 * Deux camps, `a` et `b`, de JOUEURS sans personnage (`arene_joueurs`) — aucun héros, aucune doublure, aucune
 * statistique (René). Tout est décidé ici et publié (`publier()`) : la puissance restante, le tirage, le tour de
 * sélection, les monstres encore abordables. L'état vit en base (`groupes.arene_mvm`), jamais en cache.
 *
 * Le combat lui-même (monstres dirigés depuis la manette) vient avec les lots A9b-A9d : `motifLancement()` le dit.
 */
final class MonstresContreMonstres
{
    public const CAMPS = ['a', 'b'];

    /** Bornes de la puissance convenue : un gobelin vaut 2, et le hall tient au plus 16 figures par camp. */
    public const PUISSANCE_MIN = 1;

    public const PUISSANCE_MAX = 60;

    public const MOTIF_A_VENIR = 'Le combat Monster vs Monster arrive avec les monstres dirigés depuis la manette (lots A9b-A9d).';

    public function __construct(
        private readonly GroupesArene $arenes,
        private readonly PuissanceArene $puissance,
    ) {}

    /** Ouvre un combat Monster vs Monster ; son auteur entre dans le camp `a`. */
    public function ouvrir(Joueur $joueur): Groupe
    {
        return DB::transaction(function () use ($joueur): Groupe {
            $groupe = $this->arenes->ouvrir('Monster vs Monster');
            $groupe->update(['arene_variante' => Groupe::VARIANTE_MONSTRES, 'arene_mvm' => self::salonVide()]);
            $this->rejoindre($groupe->refresh(), $joueur, 'a');

            return $groupe->refresh();
        });
    }

    /** Rejoint le combat dans ce camp, ou en change. Avant le tirage. */
    public function rejoindre(Groupe $groupe, Joueur $joueur, string $camp): void
    {
        $this->exigerAvantTirage($groupe);
        $this->exigerCamp($camp);

        DB::table('arene_joueurs')->updateOrInsert(
            ['groupe_id' => $groupe->id, 'joueur_id' => $joueur->id],
            ['camp' => $camp, 'updated_at' => now(), 'created_at' => now()],
        );
        $groupe->touch();
    }

    public function estMembre(Groupe $groupe, int $joueurId): bool
    {
        return DB::table('arene_joueurs')->where('groupe_id', $groupe->id)->where('joueur_id', $joueurId)->exists();
    }

    public function campDe(Groupe $groupe, int $joueurId): ?string
    {
        $camp = DB::table('arene_joueurs')->where('groupe_id', $groupe->id)->where('joueur_id', $joueurId)->value('camp');

        return $camp === null ? null : (string) $camp;
    }

    /** La puissance convenue. Avant le tirage. */
    public function convenirPuissance(Groupe $groupe, int $puissance): void
    {
        $this->exigerAvantTirage($groupe);

        if ($puissance < self::PUISSANCE_MIN || $puissance > self::PUISSANCE_MAX) {
            throw ValidationException::withMessages([
                'puissance' => 'La puissance convenue va de '.self::PUISSANCE_MIN.' à '.self::PUISSANCE_MAX.'.',
            ]);
        }

        $this->ecrire($groupe, ['puissance_convenue' => $puissance]);
    }

    /** Pourquoi le tirage ne peut-il pas avoir lieu ? `null` s'il le peut. Publié et revalidé par `tirer()`. */
    public function motifTirage(Groupe $groupe): ?string
    {
        $salon = $this->salon($groupe);

        if ($salon['challenger'] !== null) {
            return 'Le tirage est déjà fait.';
        }

        if ($salon['puissance_convenue'] === null) {
            return 'La puissance convenue n\'est pas encore fixée.';
        }

        $camps = DB::table('arene_joueurs')->where('groupe_id', $groupe->id)->pluck('camp')->unique();
        if ($camps->count() < 2) {
            return 'Il faut au moins un joueur dans chaque camp.';
        }

        return null;
    }

    /** « Randomly determine which team will be the Challenger team » — puis le Defender choisit le premier. */
    public function tirer(Groupe $groupe): string
    {
        $this->exigerMvm($groupe);

        if (($motif = $this->motifTirage($groupe)) !== null) {
            throw ValidationException::withMessages(['arene' => $motif]);
        }

        $challenger = self::CAMPS[random_int(0, 1)];
        $this->ecrire($groupe, ['challenger' => $challenger, 'tour' => self::autre($challenger)]);

        return $challenger;
    }

    /** Un joueur du camp dont c'est le tour ajoute un monstre du roster à son camp. */
    public function selectionner(Groupe $groupe, Joueur $joueur, int $monstreId): void
    {
        $this->exigerMvm($groupe);
        $salon = $this->salon($groupe);
        $camp = $this->campDe($groupe, (int) $joueur->id);

        if ($salon['tour'] === null) {
            throw ValidationException::withMessages(['arene' => 'La sélection n\'est pas ouverte.']);
        }

        if ($camp !== $salon['tour']) {
            throw ValidationException::withMessages(['arene' => 'Ce n\'est pas au tour de votre camp de choisir.']);
        }

        $monstre = $this->arenes->monstresDuRoster()->firstWhere('id', $monstreId);
        if ($monstre === null) {
            throw ValidationException::withMessages(['monstre_id' => 'Ce monstre ne fait pas partie du roster du tournoi.']);
        }

        if ($this->puissance->duMonstre($monstre) > $this->restant($salon, $camp)) {
            throw ValidationException::withMessages(['monstre_id' => 'Trop puissant pour ce qui reste à votre camp.']);
        }

        $selection = $salon['selection'];
        $selection[$camp][] = (int) $monstre->id;
        $salon['selection'] = $selection;

        $autre = self::autre($camp);
        $tour = $this->peutChoisir($salon, $autre) ? $autre : ($this->peutChoisir($salon, $camp) ? $camp : null);

        $this->ecrire($groupe, ['selection' => $selection, 'tour' => $tour]);
    }

    public function motifLancement(Groupe $groupe): string
    {
        $salon = $this->salon($groupe);

        if ($salon['challenger'] === null) {
            return $this->motifTirage($groupe) ?? 'Le tirage n\'est pas fait.';
        }

        if ($salon['tour'] !== null) {
            return 'La sélection des monstres n\'est pas terminée.';
        }

        return self::MOTIF_A_VENIR;
    }

    /**
     * Le salon publié dans `EtatGroupe.groupe.arene`. Tout est décidé ici.
     *
     * @return array<string, mixed>
     */
    public function publier(Groupe $groupe): array
    {
        $salon = $this->salon($groupe);
        $roster = $this->arenes->monstresDuRoster();
        $noms = $roster->keyBy('id');

        $joueurs = DB::table('arene_joueurs')
            ->join('joueurs', 'joueurs.id', '=', 'arene_joueurs.joueur_id')
            ->where('arene_joueurs.groupe_id', $groupe->id)
            ->orderBy('arene_joueurs.id')
            ->get(['arene_joueurs.joueur_id', 'joueurs.pseudo', 'arene_joueurs.camp'])
            ->map(fn ($j) => ['joueur_id' => (int) $j->joueur_id, 'pseudo' => (string) $j->pseudo, 'camp' => (string) $j->camp])
            ->all();

        $camps = [];
        foreach (self::CAMPS as $camp) {
            $camps[$camp] = [
                'role' => $salon['challenger'] === null ? null : ($salon['challenger'] === $camp ? 'challenger' : 'defender'),
                'monstres' => array_map(fn (int $id) => [
                    'monstre_id' => $id,
                    'nom_base' => $noms->get($id)?->nom_base ?? 'Monstre',
                ], $salon['selection'][$camp]),
                'puissance' => $this->depense($salon, $camp),
                'restant' => $this->restant($salon, $camp),
            ];
        }

        $tour = $salon['tour'];
        $restantDuTour = $tour === null ? 0 : $this->restant($salon, $tour);
        $motifTirage = $this->motifTirage($groupe);

        return [
            'variante' => Groupe::VARIANTE_MONSTRES,
            'bandeau' => GroupesArene::BANDEAU,
            'puissance_convenue' => $salon['puissance_convenue'],
            'puissance_min' => self::PUISSANCE_MIN,
            'puissance_max' => self::PUISSANCE_MAX,
            'tirage_fait' => $salon['challenger'] !== null,
            'tirage_possible' => $motifTirage === null,
            'tirage_motif' => $motifTirage,
            'joueurs' => $joueurs,
            'camps' => $camps,
            'tour_selection' => $tour,
            'selection_terminee' => $salon['challenger'] !== null && $tour === null,
            'monstres' => $roster->map(fn (Monstre $m) => [
                'id' => (int) $m->id,
                'nom_base' => $m->nom_base,
                'deplacement' => (int) $m->deplacement,
                'attaque' => (int) $m->attaque,
                'defense' => (int) $m->defense,
                'pv_body' => (int) $m->pv_body,
                'puissance' => $this->puissance->duMonstre($m),
                'abordable' => $tour !== null && $this->puissance->duMonstre($m) <= $restantDuTour,
            ])->values()->all(),
            'lancement_possible' => false,
            'lancement_motif' => $this->motifLancement($groupe),
        ];
    }

    /** @return array{puissance_convenue: ?int, challenger: ?string, tour: ?string, selection: array{a: list<int>, b: list<int>}} */
    public function salon(Groupe $groupe): array
    {
        $brut = (array) ($groupe->fresh()?->arene_mvm ?? []);
        $vide = self::salonVide();

        return [
            'puissance_convenue' => isset($brut['puissance_convenue']) ? (int) $brut['puissance_convenue'] : null,
            'challenger' => in_array($brut['challenger'] ?? null, self::CAMPS, true) ? $brut['challenger'] : null,
            'tour' => in_array($brut['tour'] ?? null, self::CAMPS, true) ? $brut['tour'] : null,
            'selection' => [
                'a' => array_map('intval', (array) ($brut['selection']['a'] ?? $vide['selection']['a'])),
                'b' => array_map('intval', (array) ($brut['selection']['b'] ?? $vide['selection']['b'])),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function salonVide(): array
    {
        return ['puissance_convenue' => null, 'challenger' => null, 'tour' => null, 'selection' => ['a' => [], 'b' => []]];
    }

    private function depense(array $salon, string $camp): int
    {
        return $this->puissance->duDefender(new Groupe, $salon['selection'][$camp]);
    }

    private function restant(array $salon, string $camp): int
    {
        return max(0, (int) ($salon['puissance_convenue'] ?? 0) - $this->depense($salon, $camp));
    }

    /** Le camp peut-il encore ajouter le moins cher du roster ? */
    private function peutChoisir(array $salon, string $camp): bool
    {
        $moinsCher = $this->arenes->monstresDuRoster()->map(fn (Monstre $m) => $this->puissance->duMonstre($m))->min();

        return $moinsCher !== null && $moinsCher <= $this->restant($salon, $camp)
            && count($salon['selection'][$camp]) < GroupesArene::DEFENDER_MAX;
    }

    /** @param  array<string, mixed>  $champs */
    private function ecrire(Groupe $groupe, array $champs): void
    {
        $salon = $this->salon($groupe);
        $groupe->update(['arene_mvm' => [...$salon, ...$champs]]);
    }

    private static function autre(string $camp): string
    {
        return $camp === 'a' ? 'b' : 'a';
    }

    private function exigerMvm(Groupe $groupe): void
    {
        if (! $groupe->fresh()?->estMonstresContreMonstres()) {
            throw ValidationException::withMessages(['arene' => 'Ce combat n\'est pas un Monster vs Monster.']);
        }
    }

    private function exigerAvantTirage(Groupe $groupe): void
    {
        $this->exigerMvm($groupe);

        if ($this->salon($groupe)['challenger'] !== null) {
            throw ValidationException::withMessages(['arene' => 'Le tirage est fait : les camps et la puissance sont figés.']);
        }
    }

    private function exigerCamp(string $camp): void
    {
        if (! in_array($camp, self::CAMPS, true)) {
            throw ValidationException::withMessages(['camp' => 'Le camp est « a » ou « b ».']);
        }
    }
}
