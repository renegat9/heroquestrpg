<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\ResolveurTour;
use App\Partie\TamponAnnonces;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * LA BATAILLE — lot E2, *First Light* q. 6 (FL-Q p. 20, « Playing the Battle ») : « The hero to Zargon's left chooses one of the
 * Dread warriors and takes its movement and attack, and turn order continues with each hero choosing one Dread warrior to
 * control. In this quest, hero-controlled monsters may move through squares occupied by other hero-controlled monsters but may
 * not move through monsters controlled by Zargon. All monsters defend with black shields as usual. Monsters still cannot open
 * doors. The combatants in this battle will not retreat to undiscovered areas of the map. Once all of the hero-controlled Dread
 * warriors have taken their turns, Zargon takes their turn, and turn order continues back to the heroes. As soon as one side of
 * the conflict is defeated, the game returns to normal […]. Any surviving monsters are under your control, Zargon, and will
 * attack the heroes as usual. »
 *
 * Effet de note `bataille {heros: [index], zargon: [index]}` — deux camps de figures POSÉES, révélées et éveillées à l'ouverture.
 * La décision de René (2026-11-22) était de reprendre le Monster vs Monster de l'arène « si possible » : son salon et sa manette
 * vivent dans un groupe d'arène, pas dans le tour d'une quête ; on en reprend le COUP (`ResolveurTour::frapperEntreMonstres()`,
 * celui de la Baguette d'Os et du Monster vs Monster), pas le salon.
 *
 * Décisions écrites (contrat) :
 * - au tour d'un héros, son menu est FORCÉ : il choisit un guerrier de son camp qui n'a pas encore joué ce round
 *   (`bataille_diriger_{id}`), puis l'adversaire que ce guerrier va chercher (`bataille_frapper_{id}`) — le moteur le fait
 *   marcher de son déplacement vers lui et frapper s'il l'atteint —, ou le laisse sur place ; son tour s'arrête là
 *   (`FinDeTourForcee`). Le héros lui-même n'agit pas pendant la bataille ;
 * - au tour de Zargon, chaque figure de son camp va chercher le guerrier dirigé le plus proche et le frappe ; les guerriers
 *   dirigés ne jouent pas pour Zargon ; les autres monstres du donjon jouent comme d'ordinaire ;
 * - un camp sans figure debout : la bataille finit (annonce `bataille_finie`), les survivants des deux camps redeviennent
 *   des monstres de Zargon. État durable : `quetes.drapeaux._bataille`.
 */
final class Bataille
{
    private const ETAT = '_bataille';

    /** @return array{heros: list<int>, zargon: list<int>, tour: int, joues: list<int>, dirige: array<int, int>}|null */
    public static function enCours(Quete $quete): ?array
    {
        $etat = data_get($quete->relire('drapeaux')->drapeaux, self::ETAT);

        return is_array($etat) && empty($etat['finie']) ? $etat : null;
    }

    /** Effet de note : la bataille s'ouvre. @return array<string, mixed> */
    public function ouvrir(Quete $quete, array $effet): array
    {
        $poses = NotesDeQuete::figuresPosees($quete);
        $camp = fn (string $cle) => collect((array) ($effet[$cle] ?? []))->map(fn ($i) => $poses->get((int) $i))
            ->filter(fn ($m) => $m !== null && $m->etat === 'actif')->values();
        $heros = $camp('heros');
        $zargon = $camp('zargon');
        $tous = $heros->concat($zargon);
        app(\App\Partie\MoteurDormants::class)->eveillerParNote($tous->filter(fn ($m) => app(\App\Partie\MoteurDormants::class)->estDormant($m))->values());
        foreach ($tous as $m) {
            $m->update(['revele' => true]);
            if ($m->position_x !== null) {
                app(ResolveurTour::class)->revelerSalleEn($quete->fresh(), (int) $m->position_x, (int) $m->position_y);
            }
        }
        $drapeaux = (array) $quete->relire('drapeaux')->drapeaux;
        $drapeaux[self::ETAT] = ['heros' => $heros->pluck('id')->map(fn ($i) => (int) $i)->all(),
            'zargon' => $zargon->pluck('id')->map(fn ($i) => (int) $i)->all(), 'tour' => (int) $quete->tours_mj, 'joues' => [], 'dirige' => []];
        $quete->update(['drapeaux' => $drapeaux]);

        return ['heros' => $heros->map->nomAffiche()->all(), 'zargon' => $zargon->map->nomAffiche()->all()];
    }

    /** Les figures debout d'un camp. @return Collection<int, InstanceMonstre> */
    public static function camp(Quete $quete, array $bataille, string $cle): Collection
    {
        return $quete->instancesMonstres()->whereIn('id', (array) ($bataille[$cle] ?? []))->where('etat', 'actif')
            ->whereNotNull('position_x')->with('monstre')->orderBy('id')->get();
    }

    /** La figure fait-elle partie du camp des héros, bataille en cours ? */
    public static function dirigeeParLesHeros(Quete $quete, InstanceMonstre $instance): bool
    {
        $b = self::enCours($quete);

        return $b !== null && in_array((int) $instance->id, (array) $b['heros'], true);
    }

    /** La figure se bat-elle pour Zargon dans la bataille en cours ? */
    public static function duCampDeZargon(Quete $quete, InstanceMonstre $instance): bool
    {
        $b = self::enCours($quete);

        return $b !== null && in_array((int) $instance->id, (array) $b['zargon'], true);
    }

    /** Les guerriers que ce héros peut encore diriger ce round. @return Collection<int, InstanceMonstre> */
    public function dirigeables(Quete $quete, array $bataille): Collection
    {
        $joues = (int) ($bataille['tour'] ?? -1) === (int) $quete->tours_mj ? (array) $bataille['joues'] : [];

        return self::camp($quete, $bataille, 'heros')->reject(fn (InstanceMonstre $m) => in_array((int) $m->id, $joues, true))->values();
    }

    /**
     * Le menu forcé du héros, bataille en cours ; `null` hors bataille, ou si son tour est déjà joué.
     *
     * @return array{situation: string, options: list<array<string, mixed>>}|null
     */
    public function menu(Quete $quete, Personnage $heros, EtatPersonnageQuete $etat): ?array
    {
        $b = self::enCours($quete);
        if ($b === null || $etat->tombe) {
            return null;
        }
        $dirige = isset($b['dirige'][$heros->id]) ? $quete->instancesMonstres()->whereKey((int) $b['dirige'][$heros->id])->where('etat', 'actif')->first() : null;
        if ($dirige !== null) {
            $options = self::camp($quete, $b, 'zargon')
                ->sortBy(fn (InstanceMonstre $m) => abs((int) $m->position_x - (int) $dirige->position_x) + abs((int) $m->position_y - (int) $dirige->position_y))
                ->map(fn (InstanceMonstre $m) => ['id' => "bataille_frapper_{$m->id}", 'libelle' => "{$dirige->nomAffiche()} : aller frapper {$m->nomAffiche()}",
                    'type' => 'bataille_coup', 'parametres' => ['cible_id' => (int) $m->id]])->values()->all();
            $options[] = ['id' => 'bataille_frapper_0', 'libelle' => "{$dirige->nomAffiche()} : rester sur place", 'type' => 'bataille_coup', 'parametres' => ['cible_id' => 0]];

            return ['situation' => "La bataille : vous dirigez {$dirige->nomAffiche()}. Quel adversaire va-t-il chercher ?", 'options' => $options];
        }
        $options = $this->dirigeables($quete, $b)->map(fn (InstanceMonstre $m) => ['id' => "bataille_diriger_{$m->id}",
            'libelle' => "Diriger {$m->nomAffiche()} ({$m->position_x}, {$m->position_y}) — {$m->pv_body} PV", 'type' => 'bataille_diriger',
            'parametres' => ['instance_id' => (int) $m->id]])->all();
        $options[] = ['id' => 'attendre', 'libelle' => 'Terminer le tour', 'type' => 'attente'];

        return ['situation' => 'La bataille fait rage : choisissez un guerrier à diriger ce tour-ci.', 'options' => $options];
    }

    /** @return array<string, mixed> le résultat moteur `bataille_diriger` */
    public function diriger(Quete $quete, Personnage $heros, int $instanceId): array
    {
        $b = self::enCours($quete);
        $m = $b === null ? null : $this->dirigeables($quete, $b)->firstWhere('id', $instanceId);
        if ($m === null) {
            throw ValidationException::withMessages(['option_id' => 'Ce guerrier ne peut pas être dirigé maintenant.']);
        }
        $this->ecrire($quete, fn (array $e) => [...$e, 'dirige' => [...(array) $e['dirige'], $heros->id => (int) $m->id]]);

        return ['type' => 'bataille_diriger', 'personnage_id' => (int) $heros->id, 'nom' => (string) $heros->nom, 'monstre' => $m->nomAffiche()];
    }

    /** @return array<string, mixed> le résultat moteur `bataille_coup` */
    public function frapper(Groupe $groupe, Quete $quete, Personnage $heros, EtatPersonnageQuete $etat, int $cibleId): array
    {
        $b = self::enCours($quete);
        $dirige = $b === null || ! isset($b['dirige'][$heros->id]) ? null
            : $quete->instancesMonstres()->whereKey((int) $b['dirige'][$heros->id])->where('etat', 'actif')->with('monstre')->first();
        $cible = $cibleId === 0 ? null : self::camp($quete, (array) $b, 'zargon')->firstWhere('id', $cibleId);
        if ($dirige === null || ($cibleId !== 0 && $cible === null)) {
            throw ValidationException::withMessages(['option_id' => 'Ce coup n\'est pas à jouer.']);
        }
        $coup = $cible === null ? ['attaque' => false] : app(ResolveurTour::class)->coupDeBataille($quete, $dirige, $cible);
        $this->ecrire($quete, function (array $e) use ($heros, $dirige, $quete) {
            $joues = (int) ($e['tour'] ?? -1) === (int) $quete->tours_mj ? (array) $e['joues'] : [];
            $dirige_ = (array) $e['dirige'];
            unset($dirige_[$heros->id]);

            return [...$e, 'tour' => (int) $quete->tours_mj, 'joues' => [...$joues, (int) $dirige->id], 'dirige' => $dirige_];
        });
        FinDeTourForcee::demander($etat);
        $payload = [...$coup, 'type' => 'bataille_coup', 'personnage_id' => (int) $heros->id, 'maitre' => (string) $heros->nom,
            'monstre' => $dirige->nomAffiche(), 'cible' => $cible?->nomAffiche()];
        $this->verifierFin($groupe, $quete);

        return $payload;
    }

    /** Au tour de Zargon : la figure de son camp va chercher le guerrier dirigé le plus proche. @return array<string, mixed>|null */
    public function tourDeZargon(Groupe $groupe, Quete $quete, InstanceMonstre $instance): ?array
    {
        $b = self::enCours($quete);
        if ($b === null) {
            return null;
        }
        $cible = self::camp($quete, $b, 'heros')
            ->sortBy(fn (InstanceMonstre $m) => abs((int) $m->position_x - (int) $instance->position_x) + abs((int) $m->position_y - (int) $instance->position_y))
            ->first();
        if ($cible === null) {
            return null;
        }
        $payload = [...app(ResolveurTour::class)->coupDeBataille($quete, $instance, $cible), 'type' => 'bataille_coup',
            'monstre' => $instance->nomAffiche(), 'cible' => $cible->nomAffiche()];
        $this->verifierFin($groupe, $quete);

        return $payload;
    }

    /** Un camp sans figure debout : la bataille finit, annoncée. */
    public function verifierFin(Groupe $groupe, Quete $quete): void
    {
        $b = self::enCours($quete);
        if ($b === null) {
            return;
        }
        $heros = self::camp($quete, $b, 'heros')->isNotEmpty();
        $zargon = self::camp($quete, $b, 'zargon')->isNotEmpty();
        if ($heros && $zargon) {
            return;
        }
        $this->ecrire($quete, fn (array $e) => [...$e, 'finie' => $heros ? 'heros' : 'zargon']);
        app(TamponAnnonces::class)->annoncer($groupe, ['type' => 'bataille_finie', 'vainqueur' => $heros ? 'heros' : 'zargon'], null);
    }

    private function ecrire(Quete $quete, callable $modifier): void
    {
        $drapeaux = (array) $quete->relire('drapeaux')->drapeaux;
        $drapeaux[self::ETAT] = $modifier((array) ($drapeaux[self::ETAT] ?? []));
        $quete->update(['drapeaux' => $drapeaux]);
    }
}
