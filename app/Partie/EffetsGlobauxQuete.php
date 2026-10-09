<?php

declare(strict_types=1);

namespace App\Partie;

use App\Events\JournalCombatDiffuse;
use App\Models\Groupe;
use App\Models\Monstre;
use App\Models\Quete;
use App\Support\Journal;

/**
 * Effets GLOBAUX de quête : une créature fait peser une règle sur TOUTE la quête où
 * elle est présente (*Jungles of Delthrak* q. 8, note A : « All Goblins in this quest
 * are elite warriors dedicated to Gruulob and roll 1 additional Attack die »).
 *
 * Déclaré dans la fiche du monstre, `capacites: {effet_global_quete: {titre, faction,
 * volee, des}}` — vocabulaire fermé, testé dans les deux sens par `BestiaireSourceTest`.
 *
 * - **Établi UNE fois**, au démarrage (`etablir()`, appelé par `DemarreurQuete`), et
 *   figé en colonne `quetes.effets_globaux` : il tient jusqu'à la FIN de la quête, même
 *   quand sa source tombe (« in this quest »).
 * - **Lu** au seul endroit qui compose les dés d'attaque d'un monstre :
 *   `InstanceMonstre::bonusEffetGlobalQuete()`, appelé par `attaqueEffective()`.
 * - **Annoncé** au journal et sur le fil direct (`annoncer()`), **publié** dans
 *   `EtatGroupe` (`publier()`) pour le bandeau de la table et de la manette.
 *
 * La FACTION se lit comme celle des buffs de Morcar : `InstanceMonstre::estDeFaction()`,
 * sur `nom_base` ou `variante_distance_de`. Une seule identification, deux usages.
 *
 * ⚠ Une source posée EN COURS de quête n'établit rien : aucune ne le fait aujourd'hui,
 * et une annonce qui arriverait à mi-partie n'aurait pas de point de passage. Le
 * garde-fou est nommé ici plutôt que deviné ailleurs.
 */
final class EffetsGlobauxQuete
{
    /** Clé de `capacites` qui déclare l'effet (paramétrée : titre, faction, volee, des). */
    public const CLE = 'effet_global_quete';

    /**
     * Les volées que l'effet peut viser. Seule `attaque` a un lecteur
     * (`InstanceMonstre::bonusEffetGlobalQuete()`) — la défense n'en a pas, donc pas de clé.
     */
    public const VOLEES = ['attaque'];

    /**
     * Ce que la roster de la quête porte, décidé à partir de ses monstres — un effet
     * par SOURCE, même présente en plusieurs exemplaires : l'effet est celui de la
     * quête, pas de chaque figurine.
     *
     * @return list<array{source: string, titre: string, faction: string, volee: string, des: int, texte: string}>
     */
    public static function pourRoster(Quete $quete): array
    {
        $effets = [];

        foreach ($quete->instancesMonstres()->with('monstre')->orderBy('id')->get() as $instance) {
            $monstre = $instance->monstre;
            $params = $instance->capaciteParametree(self::CLE);

            if ($monstre === null || ! is_array($params) || isset($effets[$monstre->nom_base])) {
                continue;
            }

            $effets[$monstre->nom_base] = self::entree($monstre, $params);
        }

        return array_values($effets);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{source: string, titre: string, faction: string, volee: string, des: int, texte: string}
     */
    public static function entree(Monstre $monstre, array $params): array
    {
        $entree = [
            'source' => (string) $monstre->nom_base,
            'titre' => (string) ($params['titre'] ?? $monstre->nom_base),
            'faction' => (string) ($params['faction'] ?? ''),
            'volee' => (string) ($params['volee'] ?? 'attaque'),
            'des' => (int) ($params['des'] ?? 1),
        ];

        return $entree + ['texte' => self::texte($entree)];
    }

    /**
     * La phrase annoncée et affichée, décidée ICI : « Effet en jeu — Les gobelins de
     * Gruulob : tous les gobelins de cette quête lancent 1 dé d'attaque de plus. »
     *
     * @param  array{titre: string, faction: string, des: int}  $effet
     */
    public static function texte(array $effet): string
    {
        $pluriel = mb_strtolower($effet['faction']).'s';
        $des = $effet['des'] === 1 ? '1 dé' : $effet['des'].' dés';

        // La seule volée déclarable (`VOLEES`) s'écrit « d'attaque » : pas de branche
        // pour la défense, qui n'a pas de lecteur.
        return "Effet en jeu — {$effet['titre']} : tous les {$pluriel} de cette quête lancent {$des} d'attaque de plus.";
    }

    /**
     * Les effets qui valent POUR CETTE quête : la liste figée au démarrage, ou — pour une
     * quête ouverte avant la colonne (NULL) — la lecture de sa roster, sans écriture.
     *
     * @return list<array<string, mixed>>
     */
    public static function de(Quete $quete): array
    {
        $fige = $quete->effets_globaux;

        return is_array($fige) ? array_values($fige) : self::pourRoster($quete);
    }

    /**
     * Ce que `EtatGroupe` publie : la source, le titre et la phrase — jamais les
     * ingrédients (`faction`, `volee`, `des`), que le client n'a pas à connaître.
     *
     * @return list<array{source: string, titre: string, texte: string}>
     */
    public static function publier(Quete $quete): array
    {
        return array_map(
            static fn (array $e) => ['source' => (string) $e['source'], 'titre' => (string) $e['titre'], 'texte' => (string) $e['texte']],
            self::de($quete),
        );
    }

    /**
     * Fige la liste à l'ouverture de la quête. Appelé par `DemarreurQuete::demarrer()`,
     * une fois les instances posées, dans la même transaction.
     *
     * @return list<array<string, mixed>>
     */
    public static function etablir(Quete $quete): array
    {
        $effets = self::pourRoster($quete);
        $quete->update(['effets_globaux' => $effets]);

        return $effets;
    }

    /**
     * L'annonce de démarrage : une entrée `combat` au journal — qui rejoue aussi
     * `journal_combat` à la reconnexion — puis le même fil en direct, lu par la table
     * ET les manettes (`.combat.journal`). Rien à annoncer, rien à écrire.
     */
    public static function annoncer(Groupe $groupe, Quete $quete): void
    {
        $effets = self::de($quete);

        if ($effets === []) {
            return;
        }

        $annonces = array_map(
            static fn (array $e) => ['type' => 'effet_global_quete', 'texte' => (string) $e['texte'], 'ton' => 'info'],
            $effets,
        );

        $evenement = Journal::ajouter($groupe, 'combat', ['effets_globaux_annonces' => $annonces]);

        $lignes = app(JournalCombat::class)->depuisResultat(['effets_globaux_annonces' => $annonces], 'Le maître du jeu');
        broadcast(new JournalCombatDiffuse($groupe, $lignes, (int) $evenement->sequence));
    }
}
