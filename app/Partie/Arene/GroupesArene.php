<?php

declare(strict_types=1);

namespace App\Partie\Arene;

use App\Jobs\GenererMenu;
use App\Models\Groupe;
use App\Models\Mercenaire;
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

    public function __construct(
        private readonly Doublures $doublures,
        private readonly PuissanceArene $puissance,
    ) {}

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

        if ($arene->estMonstresContreMonstres()) {
            throw ValidationException::withMessages(['arene' => 'Un Monster vs Monster se joue sans héros : choisissez un camp.']);
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

        $this->exigerAbordable($arene, $ids);

        $arene->update(['arene_defender' => $ids]);
        $this->toutLeMondePasPret($arene);
    }

    /** Ajoute UNE figure au Defender (lot T3) — refusée si elle n'est pas abordable (publiée `abordable: false`). */
    public function ajouterAuDefender(Groupe $arene, int $monstreId): void
    {
        $this->choisirDefender($arene, [...array_map('intval', $arene->arene_defender ?? []), $monstreId]);
    }

    /** Retire la figure de rang `$index` du Defender. */
    public function retirerDuDefender(Groupe $arene, int $index): void
    {
        $this->exigerSalon($arene);
        $ids = array_values(array_map('intval', $arene->arene_defender ?? []));

        if (! array_key_exists($index, $ids)) {
            throw ValidationException::withMessages(['index' => 'Cette figure n\'est pas dans le Defender.']);
        }

        array_splice($ids, $index, 1);
        $arene->update(['arene_defender' => $ids === [] ? null : $ids]);
        $this->toutLeMondePasPret($arene);
    }

    /**
     * T3 : « The Defender team's power must be less than or equal to the Challenger team's power » (F9528 p. 15).
     *
     * @param  list<int>  $ids
     */
    private function exigerAbordable(Groupe $arene, array $ids): void
    {
        $challenger = $this->puissance->duChallenger($arene);
        $defender = $this->puissance->duDefender($arene, $ids);

        if ($defender > $challenger) {
            throw ValidationException::withMessages([
                'monstre_ids' => "Le Defender vaudrait {$defender} : il ne peut pas dépasser la puissance du Challenger ({$challenger}).",
            ]);
        }
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
     * L'ALLIÉ d'un combattant (lot A4) : un bloc recrutable du catalogue, sans coût, ou aucun (`null`). Seulement au
     * salon ; le « prêt » de ce combattant retombe (son équipe a changé de puissance).
     */
    public function choisirAllie(Groupe $arene, Personnage $doublure, ?int $mercenaireId): void
    {
        $this->exigerSalon($arene);

        if (! $doublure->estDoublure() || (int) $doublure->groupe_actif_id !== (int) $arene->id) {
            throw RefusArene::pasUneDoublure($doublure);
        }

        if ($mercenaireId !== null && ! $this->alliesDuCatalogue()->contains('id', $mercenaireId)) {
            throw ValidationException::withMessages(['mercenaire_id' => 'Cet allié ne fait pas partie de ceux qu\'on peut engager.']);
        }

        $arene->personnages()->updateExistingPivot($doublure->id, ['allie_arene_id' => $mercenaireId, 'pret_arene' => false]);
        $arene->touch();
    }

    /**
     * Les alliés qu'on peut engager au salon : les recrutables du catalogue (comme au hub, `octroi_seul` exclu — le
     * Squelette Hearthkin et les captifs n'existent que par leur règle).
     *
     * @return Collection<int, Mercenaire>
     */
    public function alliesDuCatalogue(): Collection
    {
        return Mercenaire::where('octroi_seul', false)->orderBy('prix')->orderBy('id')->get();
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

        if ($arene->estMonstresContreMonstres()) {
            return app(MonstresContreMonstres::class)->motifLancement($arene);
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

        // T3 : un combattant a pu partir, ou une copie fraîche valoir moins — revalidé ici, pas seulement à la
        // composition.
        $challenger = $this->puissance->duChallenger($arene);
        $defender = $this->puissance->duDefender($arene);
        if ($defender > $challenger) {
            return "Le Defender ({$defender}) dépasse la puissance du Challenger ({$challenger}) : retirez une figure.";
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

        if ($arene->estMonstresContreMonstres()) {
            return app(DemarreurArene::class)->demarrerMonstres($arene);
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

                // L'allié choisi au salon survit à la revanche : il est rattaché au héros d'arène, pas à sa copie.
                $allie = DB::table('groupe_personnages')->where('groupe_id', $arene->id)
                    ->where('personnage_id', $membre->id)->value('allie_arene_id');
                $sources[] = [Personnage::findOrFail($membre->doublure_de), $allie];
                $this->doublures->supprimer($membre);
            }

            $arene->quetes()->delete();
            // Les alliés du combat joué meurent avec lui ; le lancement suivant les recrée.
            \App\Models\GroupeMercenaire::where('groupe_id', $arene->id)->delete();
            $arene->update(['quete_courante_id' => null, 'phase' => 'hub']);

            // Monster vs Monster : mêmes camps, même sélection, un combat neuf.
            if ($arene->estMonstresContreMonstres()) {
                $arene->update(['arene_mvm' => [...(array) $arene->arene_mvm, 'main' => null, 'gagnant' => null]]);
            }

            foreach ($sources as [$source, $allie]) {
                $doublure = $this->engager($arene->refresh(), $source);

                if ($allie !== null) {
                    $arene->personnages()->updateExistingPivot($doublure->id, ['allie_arene_id' => (int) $allie]);
                }
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
        $allies = $this->alliesDuCatalogue()->keyBy('id');
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
                'puissance' => $this->puissance->duHeros($p),
                // A4 : son allié, et ce qu'il ajoute à l'équipe.
                'allie' => ($m = $allies->get((int) $p->pivot->allie_arene_id)) === null ? null : [
                    'mercenaire_id' => (int) $m->id,
                    'nom' => $m->nom,
                    'puissance' => $this->puissance->duMercenaire($m),
                ],
            ])
            ->values()
            ->all();

        $motif = $this->motifLancement($arene);
        $puissanceChallenger = $this->puissance->duChallenger($arene);
        $puissanceDefender = $this->puissance->duDefender($arene);
        $restant = $puissanceChallenger - $puissanceDefender;

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
                // T3 : ce que la figure coûte, et la DÉCISION « l'ajouter reste-t-il permis ? » — la manette et la table
                // n'additionnent rien.
                'puissance' => $this->puissance->duMonstre($m),
                'abordable' => $this->puissance->duMonstre($m) <= $restant,
            ])->all(),
            'allies' => $allies->values()->map(fn (Mercenaire $m) => [
                'id' => (int) $m->id,
                'nom' => $m->nom,
                'deplacement' => (int) $m->deplacement,
                'attaque' => (int) $m->attaque,
                'attaque_distance' => $m->attaque_distance === null ? null : (int) $m->attaque_distance,
                'defense' => (int) $m->defense,
                'pv_body' => (int) $m->pv_body,
                'animal' => (bool) $m->animal,
                'puissance' => $this->puissance->duMercenaire($m),
            ])->all(),
            'puissance_challenger' => $puissanceChallenger,
            'puissance_defender' => $puissanceDefender,
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
    public function monstresDuRoster(): Collection
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

        if ($arene->estMonstresContreMonstres()) {
            throw ValidationException::withMessages(['arene' => 'Un Monster vs Monster compose ses camps par la sélection alternée.']);
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
