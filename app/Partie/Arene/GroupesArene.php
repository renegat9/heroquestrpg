<?php

declare(strict_types=1);

namespace App\Partie\Arene;

use App\Jobs\GenererMenu;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Partie\EtatGroupe;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * MODE ARÈNE, lot A2 — la vie d'un groupe d'arène : l'ouvrir, y engager des héros d'arène par leur doublure, le
 * purger (`docs/plan-mode-arene.md` §3, §6).
 *
 * ⚠ La purge ne passe JAMAIS par `partie:purger` seul ni par `ClotureCampagne::purger()` (fermée à l'arène,
 * `EtapesCampagne::PURGE`). Elle a son propre point de passage, à DOUBLE VERROU : elle refuse un groupe dont le mode
 * n'est pas `arene`, et un groupe qui compterait une seule ligne `personnages` qui ne soit pas une doublure. Les deux
 * sont vérifiés AVANT toute suppression : par ce chemin, aucune erreur d'identifiant ne peut emporter un vrai héros
 * ni une vraie campagne.
 */
final class GroupesArene
{
    /** Âge par défaut au-delà duquel `arene:nettoyer` tient un combat pour abandonné (onglet fermé, serveur redémarré). */
    public const HEURES_ABANDON = 6;

    public function __construct(private readonly Doublures $doublures) {}

    /** Ouvre un combat d'arène : un groupe `mode = arene`, sans campagne, sans IA. */
    public function ouvrir(string $nom = 'Arène'): Groupe
    {
        return Groupe::create([
            'identifiant' => $this->identifiantLibre(),
            'nom' => $nom,
            'theme' => 'Arène',
            // Colonnes de campagne obligatoires : sans objet en arène, aucune étape de campagne ne les lit (toutes
            // fermées par `exigerCampagne()`). `longueur` est un ENUM des longueurs de campagne : la plus courte, faute
            // de mieux — ce n'est pas elle qui dit « arène », c'est `mode`.
            'longueur' => 'tres_courte',
            'nb_quetes_total' => 1,
            'phase' => 'hub',
            'mode' => Groupe::MODE_ARENE,
        ]);
    }

    /**
     * Engage un héros d'arène dans un combat : c'est sa DOUBLURE, fraîche, qui entre dans le groupe. Un héros n'a
     * qu'une doublure par combat (plan §7 bis).
     */
    public function engager(Groupe $arene, Personnage $heros): Personnage
    {
        if (! $this->estArene($arene)) {
            throw RefusArene::pasUnGroupeDArene($arene);
        }

        if (! $heros->estHerosArene() || $heros->estDoublure()) {
            throw RefusArene::pasUnHerosDArene($heros);
        }

        $dejaLa = Personnage::where('groupe_actif_id', $arene->id)->where('doublure_de', $heros->id)->exists();
        if ($dejaLa) {
            throw RefusArene::dejaEngage($heros);
        }

        return DB::transaction(function () use ($arene, $heros): Personnage {
            $doublure = $this->doublures->doubler($heros);
            $ordre = (int) $arene->personnages()->count();

            $arene->personnages()->attach($doublure->id, ['ordre_initiative' => $ordre, 'actif' => true]);
            $doublure->forceFill(['groupe_actif_id' => $arene->id])->save();

            return $doublure->refresh();
        });
    }

    /**
     * Purge un combat d'arène — victoire, défaite, abandon ou ménage de secours : ses doublures (et, par cascade,
     * leurs lignes filles), puis le groupe (et, par cascade, ses quêtes, cartes, monstres, journaux).
     *
     * Double verrou, relu en BASE avant de supprimer quoi que ce soit (plan §6).
     */
    public function purger(Groupe $arene): void
    {
        if (! $this->estArene($arene)) {
            throw RefusArene::pasUnGroupeDArene($arene);
        }

        $membres = $this->membres($arene);

        $vrai = $membres->first(fn (Personnage $p) => ! $p->estDoublure());
        if ($vrai !== null) {
            throw RefusArene::pasUneDoublure($vrai);
        }

        $groupeId = (int) $arene->id;
        $joueurIds = $membres->pluck('joueur_id')->unique()->values();

        DB::transaction(function () use ($arene, $membres): void {
            foreach ($membres as $doublure) {
                $this->doublures->supprimer($doublure);
            }

            Groupe::whereKey($arene->id)->where('mode', Groupe::MODE_ARENE)->delete();
        });

        // Ephémères du groupe : le menu de chaque joueur et l'indicateur « le MJ réfléchit ».
        Cache::forget(EtatGroupe::cleMjReflechit($groupeId));
        foreach ($joueurIds as $joueurId) {
            Cache::forget(GenererMenu::cleMenu($groupeId, (int) $joueurId));
        }
    }

    /**
     * Les combats d'arène ABANDONNÉS : sans activité depuis `$heures`. Lus par `arene:nettoyer`.
     *
     * @return Collection<int, Groupe>
     */
    public function abandonnes(int $heures = self::HEURES_ABANDON): Collection
    {
        return Groupe::query()
            ->where('mode', Groupe::MODE_ARENE)
            ->where('updated_at', '<', Carbon::now()->subHours($heures))
            ->orderBy('id')
            ->get();
    }

    /**
     * Les doublures ORPHELINES : créées, jamais engagées dans un combat (un engagement interrompu), plus vieilles que
     * `$heures`. Une doublure engagée part avec son groupe.
     *
     * @return Collection<int, Personnage>
     */
    public function doubluresOrphelines(int $heures = self::HEURES_ABANDON): Collection
    {
        return Personnage::query()
            ->whereNotNull('doublure_de')
            ->whereNull('groupe_actif_id')
            ->where('created_at', '<', Carbon::now()->subHours($heures))
            ->orderBy('id')
            ->get();
    }

    /** Le mode RELU en base : une instance trafiquée en mémoire ne passe pas le verrou. */
    private function estArene(Groupe $groupe): bool
    {
        return DB::table('groupes')->where('id', $groupe->id)->value('mode') === Groupe::MODE_ARENE;
    }

    /**
     * Toute ligne `personnages` rattachée au groupe, par le pivot OU par `groupe_actif_id` — le verrou doit voir les
     * deux chemins.
     *
     * @return Collection<int, Personnage>
     */
    private function membres(Groupe $arene): Collection
    {
        $parPivot = DB::table('groupe_personnages')->where('groupe_id', $arene->id)->pluck('personnage_id');
        $parActif = Personnage::where('groupe_actif_id', $arene->id)->pluck('id');

        return Personnage::whereIn('id', $parPivot->merge($parActif)->unique())->orderBy('id')->get();
    }

    private function identifiantLibre(): string
    {
        do {
            $identifiant = 'arene-'.strtolower(str()->random(5));
        } while (Groupe::where('identifiant', $identifiant)->exists());

        return $identifiant;
    }
}
