<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClasseHeros;
use App\Models\Competence;
use App\Models\Monstre;
use App\Models\Objet;
use App\Models\Piege;
use App\Models\Sort;
use Illuminate\Http\JsonResponse;

/**
 * Guide / compendium PUBLIC (aucune auth — page /guide de la SPA, accessible
 * depuis l'accueil) : données de RÉFÉRENCE en lecture seule, jamais l'état
 * d'une partie. Classes de héros et leurs talents, bestiaire, équipements,
 * sorts, pièges — les catalogues seedés. Les effets sont renvoyés bruts
 * (JSON mécanique) et mis en forme côté front (compendium.js).
 */
class GuideController extends Controller
{
    public function index(): JsonResponse
    {
        // Rangs de tri stables (indépendants du SGBD : on trie en PHP plutôt
        // que via FIELD(), absent de sqlite utilisé par les tests).
        $rangTier = ['base' => 0, 'sous_boss' => 1, 'boss' => 2];
        $rangElement = ['feu' => 0, 'eau' => 1, 'terre' => 2, 'air' => 3];
        $rangCategorie = ['arme' => 0, 'armure' => 1, 'outil' => 2, 'consommable' => 3, 'parchemin' => 4];

        return response()->json([
            // `tags_equipement` : la maîtrise d'équipement de départ. Sans elle
            // le guide ne disait NULLE PART qu'un magicien ne porte pas
            // d'armure ni qu'un barbare ne manie pas la plate — le joueur ne
            // découvrait la règle qu'au refus, au moment d'équiper. Les tags
            // gagnés par l'arbre de talents sont déductibles côté front depuis
            // `competences.effet` (mecanique `acces_equipement`).
            'classes' => ClasseHeros::query()->orderBy('id')
                // `race` exposée (2026-08-13) : c'est elle qui explique le
                // mouvement de base (doc 01 §4bis-2). Sans elle, un joueur
                // voyait l'Explorateur marcher moins vite qu'un Rogue sans
                // pouvoir deviner que c'est un nain.
                // `objets_autorises` exposé (2026-08-22) : sans lui la page
                // montrait un Moine réduit à son talisman, donc sans AUCUNE
                // arme — les cinq qu'il manie sont nommées, pas déductibles
                // d'un tag.
                ->get(['nom', 'race', 'pv_body', 'pv_mind', 'attr_body', 'attr_mind', 'des_attaque', 'des_defense', 'deplacement_base', 'bonus_sac', 'tags_equipement', 'objets_autorises'])
                ->values()
                ->all(),

            // `innee` exposé (2026-08-13) : une capacité de CARTE est acquise
            // d'emblée et gratuitement, un nœud d'arbre se paie un point. Sans
            // ce drapeau, la page /guide affichait les deux dans la même liste —
            // un joueur lisant « Furie » dans l'arbre du Berserker croyait
            // devoir l'acheter.
            'competences' => Competence::query()->orderBy('classe')->orderBy('id')
                ->get(['id', 'classe', 'nom', 'description', 'type', 'effet', 'prerequis_id', 'innee'])
                ->values()
                ->all(),

            'monstres' => Monstre::query()
                ->get(['nom_base', 'deplacement', 'attaque', 'defense', 'pv_body', 'pv_mind', 'tier', 'cout', 'capacites'])
                ->sortBy(fn ($m) => sprintf('%d|%04d|%s', $rangTier[$m->tier] ?? 9, $m->cout, $m->nom_base))
                ->values()
                ->all(),

            // `tag_equipement` : la maîtrise EXIGÉE pour porter la pièce, pendant
            // de `classes.tags_equipement`. C'est ce couple qui rend la
            // restriction lisible des deux côtés (doc 01 §7).
            'objets' => Objet::query()
                // `metallique` : la MATIÈRE, que le tag ne dit pas (il porte le
                // poids). C'est elle qui ferme la cotte au Druide et au Rogue,
                // et qui coûte au Barde son dé de défense supplémentaire.
                ->get(['nom', 'categorie', 'rarete', 'prix_base', 'emplacement', 'effet', 'tag_equipement', 'metallique'])
                ->sortBy(fn ($o) => sprintf('%d|%06d|%s', $rangCategorie[$o->categorie] ?? 9, $o->prix_base, $o->nom))
                ->values()
                ->all(),

            // `id` exposé : le sélecteur de sorts elfiques (création et rechoix
            // au hub) choisit ICI ses 3 sorts et les renvoie par identifiant —
            // sans lui, la seule liste publique du répertoire serait inutilisable.
            'sorts' => Sort::query()
                ->get(['id', 'element', 'nom', 'type', 'difficulte_parchemin', 'effet'])
                ->sortBy(fn ($s) => sprintf('%d|%s', $rangElement[$s->element] ?? 9, $s->nom))
                ->values()
                ->all(),

            'pieges' => Piege::query()->orderBy('nom')
                ->get(['nom', 'detectable', 'desarmable', 'usage', 'effet'])
                ->values()
                ->all(),

            // PROVENANCE : les 61 cartes des deux paquets sources (armurerie et
            // artefacts), portées ou non. Sans ça, la page /guide affichait un
            // catalogue sans dire d'où il vient — et surtout, elle taisait les
            // 26 cartes qui existent au plateau et ne tournent pas encore ici.
            // Un test (CartesSourcesTest) confronte ce registre au catalogue
            // dans les deux sens : la provenance affichée ne peut pas dériver.
            'cartes' => $this->cartes(),
        ]);
    }

    /**
     * Registre des paquets de cartes sources (`config/cartes.php`), enrichi du
     * nom d'affichage : une carte portée prend le nom de notre catalogue, une
     * carte écartée garde le nom qu'on lui a donné en français.
     *
     * @return array<string, mixed>
     */
    private function cartes(): array
    {
        $paquets = [];

        foreach (['equipement', 'potions', 'artefacts'] as $cle) {
            $paquet = (array) config("cartes.{$cle}", []);

            $paquets[] = [
                'cle' => $cle,
                'libelle' => $paquet['libelle'] ?? $cle,
                'source' => $paquet['source'] ?? null,
                'url' => $paquet['url'] ?? null,
                'cartes' => array_map(static fn (array $c) => [
                    'carte' => $c['carte'],
                    'nom' => $c['objet'] ?? $c['nom'] ?? $c['carte'],
                    'paquet' => $c['paquet'] ?? null,
                    'porte' => isset($c['objet']),
                    'texte' => $c['texte'] ?? null,
                    'manque' => $c['manque'] ?? null,
                ], (array) ($paquet['cartes'] ?? [])),
            ];
        }

        return $paquets;
    }
}
