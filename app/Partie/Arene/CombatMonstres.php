<?php

declare(strict_types=1);

namespace App\Partie\Arene;

use App\Events\EtatGroupeDiffuse;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Joueur;
use App\Models\Quete;
use App\Partie\EtatGroupe;
use App\Partie\ResolveurTour;
use App\Support\Journal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * MODE ARÈNE, lot A9c — le COMBAT Monster vs Monster (livret F9528 p. 12-16) : deux camps de monstres, chacun dirigé
 * depuis les manettes de ses joueurs.
 *
 * - **Activation alternée**, comme tout le tournoi : le camp qui a la main (`arene_mvm.main`) active UNE de ses
 *   figures qui n'a pas joué ce round — n'importe quel joueur du camp, le premier qui appuie. Une figure entamée
 *   (un créneau pris) garde la main jusqu'au bout. Puis la main passe à l'autre camp ; un camp à court laisse l'autre
 *   enchaîner ; le round se referme quand tout a joué, et le Defender rouvre le suivant.
 * - **Une activation** : se déplacer (son déplacement du catalogue) ET/OU frapper une figure adverse au contact, dans
 *   l'ordre qu'on veut, puis « terminer ». Les sorts de Dread viennent au lot A9d.
 * - **Fin** : un camp n'a plus de figure debout → l'autre gagne. Aucune statistique (René).
 *
 * Tout est décidé ici : `menu()` est la liste blanche que `agir()` revalide (« le menu ne propose jamais ce que le
 * résolveur refusera »). Les gestes eux-mêmes passent par les points de passage du moteur (`ResolveurTour::
 * deplacerMonstreArene()`, `frapperMonstreArene()`).
 */
final class CombatMonstres
{
    public function __construct(
        private readonly MonstresContreMonstres $salon,
        private readonly ResolveurTour $resolveur,
        private readonly EtatGroupe $etatGroupe,
    ) {}

    /**
     * Le menu de CE joueur : les figures de son camp qu'il peut activer, et pour chacune ses gestes.
     *
     * @return array{main: ?string, mon_camp: ?string, figures: list<array<string, mixed>>}
     */
    public function menu(Groupe $groupe, Joueur $joueur): array
    {
        $camp = $this->salon->campDe($groupe, (int) $joueur->id);
        $quete = $this->queteEnCours($groupe);
        $main = $this->salon->salon($groupe)['main'];

        if ($quete === null || $camp === null || $camp !== $main) {
            return ['main' => $main, 'mon_camp' => $camp, 'figures' => []];
        }

        return ['main' => $main, 'mon_camp' => $camp, 'figures' => $this->activables($quete, $camp)
            ->map(fn (InstanceMonstre $i) => $this->figure($quete, $i))->values()->all()];
    }

    /**
     * Un geste d'un joueur : `deplacer {x, y}`, `attaquer {cible_id}` ou `terminer`, pour une figure de son camp.
     *
     * @param  array<string, mixed>  $parametres
     * @return array<string, mixed>
     */
    public function agir(Groupe $groupe, Joueur $joueur, int $instanceId, string $geste, array $parametres = []): array
    {
        $quete = $this->queteEnCours($groupe) ?? throw ValidationException::withMessages(['arene' => 'Aucun combat en cours.']);

        $figure = collect($this->menu($groupe, $joueur)['figures'])->firstWhere('instance_id', $instanceId);
        if ($figure === null) {
            throw ValidationException::withMessages(['arene' => 'Cette figure n\'est pas à vous de jouer maintenant.']);
        }

        $instance = InstanceMonstre::with('monstre')->findOrFail($instanceId);

        $resultat = DB::transaction(function () use ($groupe, $quete, $instance, $figure, $geste, $parametres): array {
            switch ($geste) {
                case 'deplacer':
                    if (! $figure['peut_deplacer']) {
                        throw ValidationException::withMessages(['arene' => 'Cette figure s\'est déjà déplacée.']);
                    }
                    $payload = $this->resolveur->deplacerMonstreArene($groupe, $quete, $instance, (int) ($parametres['x'] ?? -1), (int) ($parametres['y'] ?? -1));
                    $instance->update(['mvm_deplace' => true]);
                    break;

                case 'attaquer':
                    $cibles = array_column($figure['cibles'], 'id');
                    if (! in_array((int) ($parametres['cible_id'] ?? 0), $cibles, true)) {
                        throw ValidationException::withMessages(['parametres' => 'Cette figure n\'est pas à portée de frappe.']);
                    }
                    $payload = $this->resolveur->frapperMonstreArene($groupe, $quete, $instance, (int) $parametres['cible_id']);
                    $instance->update(['mvm_agi' => true]);
                    break;

                case 'terminer':
                    $payload = ['type' => 'attente_mvm', 'nom' => $instance->nomAffiche()];
                    break;

                default:
                    throw ValidationException::withMessages(['geste' => 'Geste inconnu.']);
            }

            $instance->refresh();
            if ($geste === 'terminer' || ($instance->mvm_deplace && $instance->mvm_agi) || $instance->etat !== 'actif') {
                $this->clore($instance);
            }

            return $payload;
        });

        $this->apres($groupe, $quete);

        return $resultat;
    }

    /** L'activation de cette figure est finie : elle porte son jeton, ses créneaux retombent. */
    private function clore(InstanceMonstre $instance): void
    {
        $instance->update(['active_ce_round' => true, 'mvm_deplace' => false, 'mvm_agi' => false]);
    }

    /** Après chaque geste : fin du combat, ou passage de la main, ou nouveau round. Puis l'état est diffusé. */
    private function apres(Groupe $groupe, Quete $quete): void
    {
        $salon = $this->salon->salon($groupe);
        $vivants = fn (string $camp) => $quete->instancesMonstres()->where('etat', 'actif')->where('camp_arene', $camp)->exists();

        foreach (MonstresContreMonstres::CAMPS as $camp) {
            if (! $vivants($camp)) {
                $this->finir($groupe, $quete, $camp === 'a' ? 'b' : 'a');

                return;
            }
        }

        $main = $salon['main'];
        $entamee = $quete->instancesMonstres()->where('etat', 'actif')->where('camp_arene', $main)
            ->where('active_ce_round', false)->where(fn ($q) => $q->where('mvm_deplace', true)->orWhere('mvm_agi', true))->exists();

        if (! $entamee) {
            $autre = $main === 'a' ? 'b' : 'a';
            $aJouer = fn (string $camp) => $quete->instancesMonstres()->where('etat', 'actif')->where('camp_arene', $camp)
                ->where('active_ce_round', false)->exists();

            if ($aJouer($autre)) {
                $main = $autre;
            } elseif (! $aJouer($main)) {
                // Tout a joué : le round se referme, le Defender rouvre le suivant.
                $quete->instancesMonstres()->update(['active_ce_round' => false, 'mvm_deplace' => false, 'mvm_agi' => false]);
                $main = $salon['challenger'] === 'a' ? 'b' : 'a';
            }
        }

        $groupe->update(['arene_mvm' => [...(array) $groupe->fresh()->arene_mvm, 'main' => $main]]);
        broadcast(new EtatGroupeDiffuse($groupe, $this->etatGroupe->payload($groupe->refresh())));
    }

    /** Un camp est éteint : l'autre gagne, le groupe revient au salon. Aucune statistique. */
    private function finir(Groupe $groupe, Quete $quete, string $gagnant): void
    {
        $quete->update(['etat' => 'terminee']);
        $groupe->update([
            'phase' => 'hub', 'quete_courante_id' => null,
            'arene_mvm' => [...(array) $groupe->fresh()->arene_mvm, 'main' => null, 'gagnant' => $gagnant],
        ]);
        Journal::ajouter($groupe, 'systeme', ['action' => 'mvm_victoire', 'quete_id' => $quete->id, 'gagnant' => $gagnant]);
        broadcast(new EtatGroupeDiffuse($groupe, $this->etatGroupe->payload($groupe->refresh())));
    }

    /** Les figures que ce camp peut activer : celle entamée s'il y en a une, sinon toutes celles qui n'ont pas joué. */
    private function activables(Quete $quete, string $camp): \Illuminate\Support\Collection
    {
        $libres = $quete->instancesMonstres()->where('etat', 'actif')->where('camp_arene', $camp)
            ->where('active_ce_round', false)->with('monstre')->orderBy('id')->get();
        $entamee = $libres->first(fn (InstanceMonstre $i) => $i->mvm_deplace || $i->mvm_agi);

        return $entamee !== null ? collect([$entamee]) : $libres;
    }

    /** @return array<string, mixed> */
    private function figure(Quete $quete, InstanceMonstre $instance): array
    {
        $destinations = $instance->mvm_deplace ? [] : array_map(function (string $cle) {
            [$x, $y] = array_map(intval(...), explode(',', $cle));

            return ['x' => $x, 'y' => $y];
        }, array_keys($this->resolveur->destinationsMonstreArene($quete, $instance)));

        $cibles = $instance->mvm_agi ? [] : $this->resolveur->ciblesMonstreArene($quete, $instance)
            ->map(fn (InstanceMonstre $c) => ['id' => (int) $c->id, 'nom' => $c->nomAffiche(), 'pv_body' => (int) $c->pv_body])
            ->values()->all();

        return [
            'instance_id' => (int) $instance->id,
            'nom' => $instance->nomAffiche(),
            'x' => (int) $instance->position_x,
            'y' => (int) $instance->position_y,
            'pv_body' => (int) $instance->pv_body,
            'entamee' => $instance->mvm_deplace || $instance->mvm_agi,
            'peut_deplacer' => $destinations !== [],
            'destinations' => $destinations,
            'peut_attaquer' => $cibles !== [],
            'cibles' => $cibles,
        ];
    }

    private function queteEnCours(Groupe $groupe): ?Quete
    {
        $groupe = $groupe->fresh();

        if ($groupe === null || ! $groupe->estMonstresContreMonstres() || $groupe->phase !== 'quete') {
            return null;
        }

        $quete = $groupe->queteCourante;

        return $quete !== null && $quete->etat === 'en_cours' ? $quete : null;
    }
}
