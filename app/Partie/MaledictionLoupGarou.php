<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\Des\LanceurDes;
use App\Models\Condition;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\GroupeMercenaire;
use App\Models\Personnage;
use App\Models\Quete;
use App\Support\Journal;
use Illuminate\Support\Facades\DB;

/**
 * LA MALÉDICTION DU LOUP-GAROU — *The Mage of the Mirror* (F7539 p. 10-11, « Turning Heroes into Werewolves »).
 *
 * « If a hero is affected by a werewolf's curse spell or injured by the attack of a werewolf, the hero is cursed
 * as a werewolf to switch between hero form and wolf form. At the start of every turn, the hero must roll two red
 * dice to see if they transform into a wolf. A roll of 2 through 9 means the hero remains in hero form […]. A roll
 * of 10 through 12 means the hero transforms into wolf form and Zargon controls them as a monster for one turn
 * (on Zargon's next turn). […] At the end of Zargon's turn, the wolf transforms back into the hero and returns to
 * the player's control. […] until they are cured by drinking a wolfsbane potion (or the potion of superior
 * restoration from the alchemist's shop). »
 *
 * DEUX ÉTATS, DEUX CONDITIONS DU CATALOGUE (`database/seeders/ConditionSeeder.php`) — rien en cache, rien dans une
 * colonne de plus, et tout ce qui se lit sur la fiche et se sauve dans un snapshot passe par les pivots existants :
 *  - `Malédiction du loup-garou` (`malediction_loup`) : DURABLE. Posée par le sort de Dread, elle ne tombe qu'à un
 *    remède (`retire_condition`) : sans compteur, sans purge de fin de quête.
 *  - `Forme de loup` (`forme_loup`) : le temps du tour de Zargon. Posée au jet de transformation (début du tour du
 *    héros), retirée à la fin de la phase des monstres. Elle rend le héros INATTAQUABLE (`inattaquable`, lu par
 *    `MoteurSorts::estInattaquable()`) et sans action (`action_interdite`) : c'est une figurine qu'aucun héros ne
 *    frappe et qu'aucun menu ne sert.
 *
 * LE TOUR DU LOUP n'a AUCUNE seconde copie de la marche, du piège ou du coup : c'est la couture unique
 * {@see HerosJoueParLeMj}, avec le profil {@see ControleMj::loupGarou()} (vitesse et dés du Loup géant, pas de
 * pièges, contact diagonal). Ce service ne fait que décider DU MOMENT (le jet, la fin de la phase) et NOMMER.
 *
 * ⚠ Ce que le livret ne dit pas, écrit ici plutôt que deviné :
 *  - le loup n'est un monstre QUE pendant le tour de Zargon. Pendant le tour des héros, sa figurine est là, inerte
 *    (`Forme de loup` ⇒ inattaquable, sans action) : le livret le fait « monstre pour un tour », pas dès la pose ;
 *  - la vie du héros est celle du loup : ses PV ne changent ni à la transformation ni au retour (une seule réserve) ;
 *  - ses biens restent à lui (`inventaire` intact) : sous forme de loup il ne peut simplement pas s'en servir ;
 *  - la morsure d'un loup-garou (« injured by the attack of a werewolf ») n'est PAS portée : aucun loup-garou n'est
 *    au bestiaire (le monstre errant de la quête 7 n'a pas de gabarit). Le sort seul pose la malédiction.
 */
final class MaledictionLoupGarou
{
    /** Le nom de la condition de la forme, le temps du tour de Zargon. */
    public const CONDITION_FORME = 'Forme de loup';

    /** La source du pivot de la forme : elle ne commence pas par `sort_dread:`, donc n'entre pas dans les ruptures. */
    public const SOURCE_FORME = 'malediction_loup';

    /** « Two red dice » : le seuil qui transforme (2 à 9 reste sous forme humaine). */
    public const SEUIL_TRANSFORMATION = 10;

    public function __construct(private readonly LanceurDes $des) {}

    /** Le héros porte-t-il la malédiction ? (lit `malediction_loup` sur ses conditions) */
    public static function estMaudit(Personnage $personnage): bool
    {
        return $personnage->conditions()->get()
            ->contains(fn (Condition $c) => (bool) data_get($c->effet, 'malediction_loup', false));
    }

    /** Le héros est-il, à cet instant, sous forme de loup ? (lit `forme_loup` sur ses conditions) */
    public static function estEnLoup(Personnage $personnage): bool
    {
        return $personnage->conditions()->get()
            ->contains(fn (Condition $c) => (bool) data_get($c->effet, 'forme_loup', false));
    }

    /**
     * DÉBUT DU TOUR d'un héros maudit : deux dés rouges. 10 à 12 le transforme, et son tour est perdu — le loup
     * jouera au tour de Zargon. 2 à 9 : il reste sous forme humaine, et joue normalement.
     *
     * Le jet est TOUJOURS annoncé (`malediction_jet`), transformé ou non : un jet que personne ne voit n'a pas eu
     * lieu pour la table. Rend les payloads, que l'appelant journalise et remonte dans le résultat.
     *
     * @return list<array<string, mixed>>
     */
    public function ouvrirTour(EtatPersonnageQuete $etat): array
    {
        $personnage = $etat->personnage;

        if ($personnage === null || $etat->tombe || ! self::estMaudit($personnage)) {
            return [];
        }

        $faces = [$this->des->d6(), $this->des->d6()];
        $total = array_sum($faces);
        $transforme = $total >= self::SEUIL_TRANSFORMATION;

        if ($transforme) {
            if (! self::estEnLoup($personnage)) {
                $condition = Condition::where('nom', self::CONDITION_FORME)->firstOrFail();

                $personnage->conditions()->attach($condition->id, [
                    'duree' => 0,
                    'source' => self::SOURCE_FORME,
                ]);
            }

            // Le tour du héros est perdu, comme celui d'un Étourdi : le menu ne s'ouvre pas, la phase des
            // monstres n'attend pas après lui (même écriture que `tour_perdu` dans `ouvrirTourDesHeros()`).
            $etat->update(['a_joue' => true, 'a_deplace' => true, 'a_agi' => true]);

            // Son compagnon (allié recruté) joue DANS le tour de son héros (`OrdreDuTour::allieEnAttente()`) : sans
            // maître qui a la main, il ne joue pas non plus.
            GroupeMercenaire::where('groupe_id', $etat->quete->groupe_id)
                ->where('recruteur_personnage_id', $personnage->id)
                ->update(['a_joue' => true, 'a_deplace' => true, 'a_agi' => true]);
        }

        return [[
            'type' => 'malediction_jet',
            'personnage_id' => (int) $personnage->id,
            'nom' => $personnage->nom,
            'faces' => $faces,
            'total' => $total,
            'transforme' => $transforme,
        ]];
    }

    /**
     * LE TOUR DE ZARGON, pour chaque héros sous forme de loup : la couture {@see HerosJoueParLeMj} joue le loup
     * (sa vitesse, ses dés, pas de pièges). Les actions sont JOURNALISÉES ici — la couture ne journalise pas.
     *
     * @return list<array<string, mixed>>
     */
    public function jouerLoups(Groupe $groupe, Quete $quete): array
    {
        $actions = [];

        foreach ($this->enForme($quete, vivants: true) as $etat) {
            $payloads = app(HerosJoueParLeMj::class)->jouer(
                $groupe, $quete, $etat->personnage, $etat->fresh(), ControleMj::loupGarou(),
            );

            foreach ($payloads as $payload) {
                Journal::ajouter($groupe, 'action', $payload, ['type' => 'personnage', 'id' => $etat->personnage_id, 'nom' => $etat->personnage->nom]);
                $actions[] = $payload;
            }
        }

        return $actions;
    }

    /**
     * FIN DU TOUR DE ZARGON : chaque loup reprend sa forme humaine, et son héros revient sous le contrôle de son
     * joueur. Vaut aussi pour un héros tombé pendant sa forme : la forme finit, qu'il soit debout ou non.
     *
     * @return list<array<string, mixed>>
     */
    public function reprendreForme(Groupe $groupe, Quete $quete): array
    {
        $payloads = [];
        $condition = Condition::where('nom', self::CONDITION_FORME)->first();

        if ($condition === null) {
            return [];
        }

        foreach ($this->enForme($quete, vivants: false) as $etat) {
            DB::table('personnage_conditions')
                ->where('personnage_id', $etat->personnage_id)
                ->where('condition_id', $condition->id)
                ->delete();

            $payload = [
                'type' => 'loup_reprend_forme',
                'personnage_id' => (int) $etat->personnage_id,
                'nom' => $etat->personnage->nom,
            ];

            Journal::ajouter($groupe, 'action', $payload);
            $payloads[] = $payload;
        }

        return $payloads;
    }

    /** Y a-t-il un loup EN JEU dans cette quête ? (`jouerFinDeRound()` : un loup doit jouer même sans monstre.) */
    public function enFormeDans(Quete $quete): bool
    {
        return $this->enForme($quete, vivants: false)->isNotEmpty();
    }

    /**
     * Retire la forme d'un héros à la remise à zéro d'une quête (`MoteurSorts::reinitialiserQuete()`) : la forme tient
     * à la phase des monstres, et une quête refermée au milieu du tour de Zargon ne doit pas la porter à la suivante.
     * La malédiction, elle, reste (elle ne tombe qu'à un remède).
     */
    public function purger(Personnage $personnage): void
    {
        $condition = Condition::where('nom', self::CONDITION_FORME)->first();

        if ($condition !== null) {
            DB::table('personnage_conditions')
                ->where('personnage_id', $personnage->id)
                ->where('condition_id', $condition->id)
                ->delete();
        }
    }

    /**
     * Les héros de la quête sous forme de loup, dans l'ordre de leur ligne d'état.
     *
     * @return \Illuminate\Support\Collection<int, EtatPersonnageQuete>
     */
    private function enForme(Quete $quete, bool $vivants): \Illuminate\Support\Collection
    {
        return $quete->etatsPersonnages()->with('personnage')->orderBy('id')->get()
            ->filter(fn (EtatPersonnageQuete $e) => $e->personnage !== null
                && ($vivants ? ! $e->tombe : true)
                && self::estEnLoup($e->personnage))
            ->values();
    }
}
