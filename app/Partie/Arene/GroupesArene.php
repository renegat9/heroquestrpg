<?php

declare(strict_types=1);

namespace App\Partie\Arene;

use App\Jobs\GenererMenu;
use App\Models\Groupe;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Partie\EtatGroupe;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
    /**
     * La LISTE BLANCHE du Defender (lot A5) : les huit monstres que le roster du tournoi nomme (livret F9528 p. 16, doc
     * `reference/18_extensions.md`), par leur `nom_base`, aux stats de notre catalogue.
     *
     * ⚠ Retrait écrit : le **Fimir** (nom de 1989, absent du roster 2021) et les figures nommées (boss, sous-boss) n'y
     * sont pas. Les variantes « équilibrage tournoi » du roster (Zombie à 5 cases…) ne remplacent pas notre bestiaire.
     */
    public const ROSTER_DEFENDER = [
        'Gobelin', 'Orque', 'Squelette', 'Zombie', 'Momie', 'Abomination', 'Guerrier du Chaos', 'Gargouille',
    ];

    /** Taille maximale d'un Defender composé au salon : la tuile de départ du hall en tient 16. */
    public const DEFENDER_MAX = 12;

    /** La phrase d'étanchéité, permanente sur les deux écrans (plan §4, T7). */
    public const BANDEAU = 'Arène — rien ne compte pour la campagne.';

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

        if ($arene->phase !== 'hub') {
            throw ValidationException::withMessages(['arene' => 'Le combat a déjà commencé : on ne le rejoint plus.']);
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
            // Un nouveau venu : le combat n'est plus le même, chacun redit « prêt ».
            $this->toutLeMondePasPret($arene);

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
     * Compose le Defender (lot A5) : 1 à `DEFENDER_MAX` figures de la liste blanche. Seulement au salon, avant le
     * combat.
     *
     * @param  list<int>  $monstreIds
     */
    public function choisirDefender(Groupe $arene, array $monstreIds): void
    {
        $this->exigerSalon($arene);

        $autorises = $this->monstresDuRoster()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $ids = array_values(array_map('intval', $monstreIds));

        if ($ids === [] || count($ids) > self::DEFENDER_MAX) {
            throw ValidationException::withMessages(['monstre_ids' => 'Le Defender compte de 1 à '.self::DEFENDER_MAX.' figures.']);
        }

        foreach ($ids as $id) {
            if (! in_array($id, $autorises, true)) {
                throw ValidationException::withMessages(['monstre_ids' => "Le monstre #{$id} n'est pas au roster du tournoi."]);
            }
        }

        $arene->update(['arene_defender' => $ids]);
        $this->toutLeMondePasPret($arene);
    }

    /** Marque prête (ou non) une doublure du combat. */
    public function marquerPret(Groupe $arene, Personnage $doublure, bool $pret): void
    {
        $this->exigerSalon($arene);

        if (! $doublure->estDoublure() || (int) $doublure->groupe_actif_id !== (int) $arene->id) {
            throw RefusArene::pasUneDoublure($doublure);
        }

        $arene->personnages()->updateExistingPivot($doublure->id, ['pret_arene' => $pret]);
        $arene->touch();
    }

    /**
     * Pourquoi le combat ne peut-il pas être lancé ? `null` s'il le peut. LE prédicat du lancement, publié au salon
     * (`lancement_possible`) et revalidé par `lancer()`.
     */
    public function motifLancement(Groupe $arene): ?string
    {
        if (! $this->estArene($arene)) {
            return 'Ce groupe n\'est pas un combat d\'arène.';
        }

        if ($arene->phase !== 'hub') {
            return 'Le combat est déjà en cours.';
        }

        if ($arene->quetes()->exists()) {
            return 'Ce combat est joué : proposez une revanche pour le rejouer.';
        }

        $combattants = $arene->personnages()->wherePivot('actif', true)->get();

        if ($combattants->isEmpty()) {
            return 'Aucun combattant dans l\'arène.';
        }

        if (($arene->arene_defender ?? []) === []) {
            return 'Le Defender n\'est pas encore composé.';
        }

        if ($combattants->contains(fn (Personnage $p) => ! (bool) $p->pivot->pret_arene)) {
            return 'Tous les combattants ne sont pas prêts.';
        }

        return null;
    }

    /** Lance le combat, s'il le peut. */
    public function lancer(Groupe $arene): \App\Models\Quete
    {
        if (($motif = $this->motifLancement($arene)) !== null) {
            throw ValidationException::withMessages(['arene' => $motif]);
        }

        return app(DemarreurArene::class)->demarrer($arene, array_values($arene->arene_defender ?? []));
    }

    /**
     * La REVANCHE, après la fin du combat : des doublures fraîches remplacent les anciennes (le héros d'arène, lui,
     * n'a jamais bougé), le combat joué est effacé, le Defender est gardé, les prêts retombent.
     */
    public function rejouer(Groupe $arene): void
    {
        if (! $this->estArene($arene)) {
            throw RefusArene::pasUnGroupeDArene($arene);
        }

        if ($arene->phase !== 'hub' || ! $arene->quetes()->exists()) {
            throw ValidationException::withMessages(['arene' => 'Il n\'y a pas de combat terminé à rejouer.']);
        }

        DB::transaction(function () use ($arene): void {
            $sources = [];

            foreach ($this->membres($arene) as $membre) {
                if (! $membre->estDoublure()) {
                    throw RefusArene::pasUneDoublure($membre);
                }

                $sources[] = Personnage::findOrFail($membre->doublure_de);
                $this->doublures->supprimer($membre);
            }

            $arene->quetes()->delete();
            $arene->update(['quete_courante_id' => null, 'phase' => 'hub']);

            foreach ($sources as $source) {
                $this->engager($arene->refresh(), $source);
            }
        });
    }

    /**
     * Le SALON publié dans `EtatGroupe.groupe.arene` (lot A5) : combattants, Defender, liste blanche, décision de
     * lancement, résultat, bandeau. Tout est décidé ici ; les écrans ne recalculent rien.
     *
     * @return array<string, mixed>
     */
    public function publier(Groupe $arene): array
    {
        $roster = $this->monstresDuRoster()->keyBy('id');
        $derniere = $arene->quetes()->orderByDesc('id')->first();

        $combattants = $arene->personnages()
            ->wherePivot('actif', true)
            ->orderBy('groupe_personnages.ordre_initiative')
            ->with('joueur:id,pseudo')
            ->get()
            ->map(fn (Personnage $p) => [
                'personnage_id' => (int) $p->id,
                'heros_id' => $p->doublure_de === null ? null : (int) $p->doublure_de,
                'nom' => $p->nom,
                'classe' => $p->classe,
                'joueur_id' => (int) $p->joueur_id,
                'joueur' => $p->joueur?->pseudo,
                'pret' => (bool) $p->pivot->pret_arene,
            ])
            ->values()
            ->all();

        $motif = $this->motifLancement($arene);

        return [
            'combattants' => $combattants,
            'defender' => array_map(fn ($id) => [
                'monstre_id' => (int) $id,
                // `nom_base` : une fiche du CATALOGUE (pas une figure sur la carte, qui passerait par `nomAffiche()`).
                'nom_base' => $roster->get((int) $id)?->nom_base ?? 'Monstre',
            ], array_values($arene->arene_defender ?? [])),
            'monstres' => $roster->values()->map(fn (Monstre $m) => [
                'id' => (int) $m->id,
                'nom_base' => $m->nom_base,
                'deplacement' => (int) $m->deplacement,
                'attaque' => (int) $m->attaque,
                'defense' => (int) $m->defense,
                'pv_body' => (int) $m->pv_body,
            ])->all(),
            'lancement_possible' => $motif === null,
            'lancement_motif' => $motif,
            'resultat' => $derniere === null || $derniere->etat === 'en_cours' ? null : [
                'issue' => $derniere->etat === 'terminee' ? 'victoire' : 'defaite',
                'quete_id' => (int) $derniere->id,
            ],
            'bandeau' => self::BANDEAU,
        ];
    }

    /** @return Collection<int, Monstre> */
    private function monstresDuRoster(): Collection
    {
        $ordre = array_flip(self::ROSTER_DEFENDER);

        return Monstre::whereIn('nom_base', self::ROSTER_DEFENDER)
            ->where('boite', 'base')
            ->get()
            ->sortBy(fn (Monstre $m) => $ordre[$m->nom_base] ?? 99)
            ->values();
    }

    /** Le salon : un combat d'arène, avant son premier round. */
    private function exigerSalon(Groupe $arene): void
    {
        if (! $this->estArene($arene)) {
            throw RefusArene::pasUnGroupeDArene($arene);
        }

        if ($arene->phase !== 'hub' || $arene->quetes()->exists()) {
            throw ValidationException::withMessages(['arene' => 'Le combat a déjà commencé : le salon est fermé.']);
        }
    }

    private function toutLeMondePasPret(Groupe $arene): void
    {
        DB::table('groupe_personnages')->where('groupe_id', $arene->id)->update(['pret_arene' => false]);
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
