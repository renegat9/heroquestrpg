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
            'classes' => ClasseHeros::query()->orderBy('id')
                ->get(['nom', 'pv_body', 'pv_mind', 'attr_body', 'attr_mind', 'des_attaque', 'des_defense', 'deplacement_base', 'bonus_sac'])
                ->values()
                ->all(),

            'competences' => Competence::query()->orderBy('classe')->orderBy('id')
                ->get(['id', 'classe', 'nom', 'description', 'type', 'effet', 'prerequis_id'])
                ->values()
                ->all(),

            'monstres' => Monstre::query()
                ->get(['nom_base', 'deplacement', 'attaque', 'defense', 'pv_body', 'pv_mind', 'tier', 'cout', 'capacites'])
                ->sortBy(fn ($m) => sprintf('%d|%04d|%s', $rangTier[$m->tier] ?? 9, $m->cout, $m->nom_base))
                ->values()
                ->all(),

            'objets' => Objet::query()
                ->get(['nom', 'categorie', 'rarete', 'prix_base', 'emplacement', 'effet'])
                ->sortBy(fn ($o) => sprintf('%d|%06d|%s', $rangCategorie[$o->categorie] ?? 9, $o->prix_base, $o->nom))
                ->values()
                ->all(),

            'sorts' => Sort::query()
                ->get(['element', 'nom', 'type', 'difficulte_parchemin', 'effet'])
                ->sortBy(fn ($s) => sprintf('%d|%s', $rangElement[$s->element] ?? 9, $s->nom))
                ->values()
                ->all(),

            'pieges' => Piege::query()->orderBy('nom')
                ->get(['nom', 'detectable', 'desarmable', 'usage', 'effet'])
                ->values()
                ->all(),
        ]);
    }
}
