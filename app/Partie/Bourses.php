<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Groupe;
use App\Models\Personnage;
use Illuminate\Validation\ValidationException;

/**
 * OÙ VIT L'OR D'UN GROUPE — le point de passage unique de toute pièce gagnée ou dépensée EN JEU.
 *
 * Deux régimes, un seul prédicat ({@see parHeros()}) :
 *
 * - **campagne** (et tout ce qui n'est pas classique) : la BOURSE COMMUNE, `groupes.or` (doc 04, M3). Qui trouve l'or
 *   n'a aucune importance, il va au pot.
 * - **classique** : une BOURSE PAR HÉROS, `personnages.or` (décision de René, 2026-11-13, Q3 du plan
 *   `docs/plan-mode-classique.md`). L'or reste sur celui qui le trouve, et chacun paie ses achats avec le sien.
 *   `groupes.or` n'y est JAMAIS écrit : une pièce versée au pot d'un groupe classique serait une pièce que personne ne
 *   peut dépenser, puisque son marché ne lit que les bourses.
 *
 * ⚠ C'est exactement le défaut que ce fichier ferme. Avant lui, neuf endroits écrivaient `groupes.or` chacun de son
 * côté (trésor fouillé, épreuve, reddition d'un monstre, potion de transmutation, marché, levée de malédiction…) : le
 * premier marché classique aurait débité un pot que rien n'alimente pendant que l'or de la quête dormait ailleurs.
 * `BoursesPointDePassageTest` scanne les sources dans les deux sens : toute écriture de `or` hors d'ici doit être
 * déclarée dans {@see HORS_PASSAGE} avec la raison pour laquelle le classique ne peut pas l'atteindre.
 *
 * Cette classe ne journalise et n'annonce RIEN : l'appelant sait ce qui s'est passé (un coffre, une vente) et le dit.
 * Elle rend les parts versées pour qu'il puisse le dire juste.
 */
final class Bourses
{
    /**
     * Les écritures de `or` qui ne passent PAS par cette classe, chacune avec sa raison. Clé : `Classe::méthode`.
     * Registre testé dans les deux sens par `BoursesPointDePassageTest`.
     *
     * @var array<string, string>
     */
    public const HORS_PASSAGE = [
        'App\Http\Controllers\Api\GroupeController::creer' => 'entrée en campagne : l\'or du fondateur rejoint le pot commun (doc 05 §4) ; un groupe classique se crée par GroupesClassiques, qui laisse l\'or sur le héros',
        'App\Http\Controllers\Api\GroupeController::rejoindre' => 'entrée en campagne : gardée par estClassique() sur place, le héros classique garde sa bourse',
        'App\Partie\Votes\VoteGroupe::detacherJoueur' => 'part d\'or d\'un joueur qui quitte une campagne : sort du pot commun vers son héros ; en classique il n\'y a pas de pot à partager, la part est nulle',
        'App\Jobs\CloturerCampagne::handle' => 'partage du pot à la clôture d\'une campagne : étape fermée au classique (EtapesCampagne::CLOTURE)',
        'App\Partie\Entrainement::entrainer' => 'étape fermée au classique (EtapesCampagne::ENTRAINEMENT)',
        'App\Partie\Forge::appliquer' => 'étape fermée au classique (EtapesCampagne::FORGE)',
        'App\Http\Controllers\Api\MercenaireController::recruter' => 'étape fermée au classique (EtapesCampagne::MERCENAIRES)',
        'App\Partie\Sauvegarde::restaurer' => 'reprise d\'un instantané : remet le pot EXACTEMENT tel qu\'il a été saisi, ce n\'est ni un gain ni une dépense',
        'App\Partie\Sauvegarde::restaurerHeros' => 'reprise d\'un instantané : remet la bourse du héros EXACTEMENT telle qu\'elle a été saisie',
        'App\Partie\FaveursHopekins::reglerEntretien' => 'entretien des mercenaires : aucun mercenaire dans un groupe classique (EtapesCampagne::MERCENAIRES)',
    ];

    /** Ce groupe tient-il une bourse par héros (classique) plutôt qu'un pot commun ? */
    public function parHeros(Groupe $groupe): bool
    {
        return $groupe->estClassique();
    }

    /** L'or dont CE héros dispose pour payer : sa bourse en classique, le pot commun sinon. Relu en base. */
    public function solde(Groupe $groupe, Personnage $heros): int
    {
        return $this->parHeros($groupe)
            ? (int) Personnage::whereKey($heros->id)->value('or')
            : (int) Groupe::whereKey($groupe->id)->value('or');
    }

    /**
     * Verse de l'or GAGNÉ. En classique il va au bénéficiaire ; sans bénéficiaire (un gain que personne en particulier
     * n'a fait : la reddition d'un monstre), il est partagé entre les héros actifs ({@see partager()}).
     *
     * @return list<array{personnage_id: int, nom: string, or: int}> les parts versées à des bourses ; vide au pot commun
     */
    public function verser(Groupe $groupe, int $or, ?Personnage $beneficiaire = null): array
    {
        if ($or <= 0) {
            return [];
        }

        if (! $this->parHeros($groupe)) {
            $groupe->increment('or', $or);

            return [];
        }

        $parts = $beneficiaire !== null
            ? [['personnage_id' => (int) $beneficiaire->id, 'nom' => (string) $beneficiaire->nom, 'or' => $or]]
            : $this->partager($groupe, $or);

        foreach ($parts as $part) {
            Personnage::whereKey($part['personnage_id'])->increment('or', $part['or']);
        }

        // Le modèle que l'appelant tient en main ne doit pas mentir à la ligne suivante.
        $beneficiaire?->refresh();

        return $parts;
    }

    /**
     * Fait PAYER. En classique c'est la bourse du payeur, qui est alors obligatoire ; sinon le pot commun, quel que
     * soit le payeur.
     *
     * @throws ValidationException (clé `or`) si l'or manque — rien n'est débité
     */
    public function payer(Groupe $groupe, int $or, ?Personnage $payeur = null): void
    {
        if ($or <= 0) {
            return;
        }

        if ($payeur === null && $this->parHeros($groupe)) {
            throw new \LogicException('Bourse par héros : un paiement sans payeur ne débite la bourse de personne.');
        }

        $solde = $payeur !== null
            ? $this->solde($groupe, $payeur)
            : (int) Groupe::whereKey($groupe->id)->value('or');

        if ($solde < $or) {
            $manque = $or - $solde;
            $caisse = $this->parHeros($groupe) ? "à la bourse de {$payeur?->nom}" : 'à la bourse commune';

            throw ValidationException::withMessages([
                'or' => "Il manque {$manque} pièces d'or {$caisse}.",
            ]);
        }

        if ($payeur !== null && $this->parHeros($groupe)) {
            Personnage::whereKey($payeur->id)->decrement('or', $or);
            $payeur->refresh();

            return;
        }

        $groupe->decrement('or', $or);
    }

    /**
     * Partage ÉGAL d'une somme entre les héros actifs. Le reste de la division va, une pièce chacun, aux premiers dans
     * l'ordre du tour (décision écrite : le livret ne dit pas qui garde le reste). Ne verse rien : il dit qui reçoit.
     *
     * @return list<array{personnage_id: int, nom: string, or: int}>
     */
    public function partager(Groupe $groupe, int $or, bool $chacun = false): array
    {
        if ($or <= 0) {
            return [];
        }

        $heros = $groupe->personnages()
            ->wherePivot('actif', true)
            ->orderBy('groupe_personnages.ordre_initiative')
            ->get(['personnages.id', 'personnages.nom']);

        if ($heros->isEmpty()) {
            return [];
        }

        $base = $chacun ? $or : intdiv($or, $heros->count());
        $reste = $chacun ? 0 : $or % $heros->count();

        $parts = [];
        foreach ($heros->values() as $i => $h) {
            $montant = $base + ($i < $reste ? 1 : 0);
            if ($montant > 0) {
                $parts[] = ['personnage_id' => (int) $h->id, 'nom' => (string) $h->nom, 'or' => $montant];
            }
        }

        return $parts;
    }

    /**
     * Les bourses publiées d'un groupe classique, dans l'ordre du tour ; `null` pour un groupe à pot commun — l'écran
     * lit ce `null` comme « or commun », il ne re-dérive pas le mode.
     *
     * @return list<array{personnage_id: int, nom: string, or: int}>|null
     */
    public function publiees(Groupe $groupe): ?array
    {
        if (! $this->parHeros($groupe)) {
            return null;
        }

        return $groupe->personnages()
            ->wherePivot('actif', true)
            ->orderBy('groupe_personnages.ordre_initiative')
            ->get(['personnages.id', 'personnages.nom', 'personnages.or'])
            ->map(fn ($p) => ['personnage_id' => (int) $p->id, 'nom' => (string) $p->nom, 'or' => (int) $p->or])
            ->values()
            ->all();
    }
}
