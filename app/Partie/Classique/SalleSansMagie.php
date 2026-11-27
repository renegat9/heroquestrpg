<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Equipement;
use App\Partie\MoteurSorts;
use App\Partie\Salles;
use App\Partie\TamponAnnonces;
use Illuminate\Support\Facades\DB;

/**
 * LA SALLE SANS MAGIE (mode classique, lot E2 — *Prophecy of Telor* q. 10 G, l'arène de Misildia) : « No spells, artifacts,
 * or equipment may be used in the arena. Spells cast before entering the arena are lost. Potions and elixirs can be used. »
 * Et Mentor : « The whole arena is built with Kertz stone. No magic can exist within its walls. »
 *
 * `quete.salles_speciales[].sans_magie: true`. Un héros dans cette salle ({@see pourHeros()}, point de passage unique) :
 *  - ne lance aucun sort (`MoteurSorts::sortsInterdits()`, menu et résolution — sorts de Dread du porteur compris) ;
 *  - n'utilise que ses potions et élixirs (`MenuMoteur`, liste blanche de « Utiliser un objet ») ;
 *  - se bat sans son équipement : les dés de sa classe, ni arme, ni armure, ni artefact porté (`Equipement::recalculerCombat()`) ;
 *  - perd en entrant les sorts qui l'affectaient ({@see actualiser()}, annonce `magie_dissipee`).
 * Les maxima de Corps et d'Esprit des pièces portées ne bougent pas (décision écrite : le livret interdit d'UTILISER, et
 * retirer un plafond ferait perdre des points qui ne reviendraient pas).
 */
final class SalleSansMagie
{
    /** Les index (dans `carte.salles`) des salles sans magie de la quête. @return list<int> */
    public static function salles(?Quete $quete): array
    {
        if ($quete === null || ! $quete->estConstruite()) {
            return [];
        }
        $ids = collect((array) data_get($quete->documentConstruit(), 'salles_speciales', []))
            ->filter(fn ($s) => ($s['sans_magie'] ?? false) === true)->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        return collect((array) ($quete->carte?->grille['salles'] ?? []))
            ->filter(fn ($s) => in_array($s['id'] ?? null, $ids, true))->keys()->map(fn ($i) => (int) $i)->values()->all();
    }

    public static function contient(Quete $quete, int $x, int $y): bool
    {
        $salles = self::salles($quete);

        return $salles !== [] && in_array(Salles::indexDe((array) $quete->carte->grille['salles'], $x, $y), $salles, true);
    }

    /** Ce héros se tient-il dans une salle sans magie de la quête courante de son groupe ? */
    public static function pourHeros(Personnage $heros): bool
    {
        $quete = $heros->groupe_actif_id === null ? null : Groupe::find($heros->groupe_actif_id)?->queteCourante;
        if (self::salles($quete) === []) {
            return false;
        }
        $etat = $quete->etatsPersonnages()->where('personnage_id', $heros->id)->first();

        return $etat !== null && $etat->position_x !== null && self::contient($quete, (int) $etat->position_x, (int) $etat->position_y);
    }

    /**
     * Après tout déplacement du héros (marche, note qui le déplace) : ses dés suivent la salle où il se tient, et s'il y est
     * entré, les sorts qui l'affectaient se dissipent. Rend l'annonce de la dissipation, ou `null`.
     */
    public function actualiser(Quete $quete, Personnage $heros): ?array
    {
        if (self::salles($quete) === []) {
            return null;
        }
        app(Equipement::class)->recalculerCombat($heros->fresh());
        if (! self::pourHeros($heros)) {
            return null;
        }
        $sorts = DB::table('personnage_conditions')->where('personnage_id', $heros->id)
            ->where('source', 'like', MoteurSorts::PREFIXE_SOURCE.'%')->pluck('source')->all();
        if ($sorts === []) {
            return null;
        }
        app(MoteurSorts::class)->purgerBuffsDeSorts($heros);
        $payload = ['type' => 'magie_dissipee', 'nom' => $heros->nom,
            'sorts' => array_values(array_unique(array_map(fn ($s) => explode('#', substr((string) $s, strlen(MoteurSorts::PREFIXE_SOURCE)), 2)[0], $sorts)))];
        app(TamponAnnonces::class)->annoncer($quete->groupe, $payload, ['type' => 'personnage', 'id' => $heros->id, 'nom' => $heros->nom]);

        return $payload;
    }
}
