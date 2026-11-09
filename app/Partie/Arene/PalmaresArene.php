<?php

declare(strict_types=1);

namespace App\Partie\Arene;

use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Quete;
use Illuminate\Support\Facades\DB;

/**
 * MODE ARÈNE, lot A6 — le palmarès et les statistiques par héros (`docs/plan-mode-arene.md` §5, AQ4).
 *
 * Point de passage UNIQUE de toute écriture des deux tables `arene_combats` et `arene_statistiques`. Deux crochets :
 * - `coupDeGrace()`, appelé au seul point de passage de la mort d'un monstre (`MoteurDegats`) : c'est le héros qui fait
 *   tomber le dernier PV qui gagne l'ennemi vaincu, compté par NOM DE CATALOGUE, crédité à son héros SOURCE ;
 * - `enregistrer()`, appelé à la fin d'un combat (victoire : `ResolveurTour::donjonNettoye()` ; défaite :
 *   `ResolveurTour::echouerQuete()`) : une ligne de palmarès, et combats/victoires de chaque héros source.
 *
 * Rien n'y touche la campagne ni le héros lui-même (plan §5).
 */
final class PalmaresArene
{
    /** Un monstre vient de tomber sous les coups de `$auteur` : en arène, son héros source gagne l'ennemi vaincu. */
    public function coupDeGrace(InstanceMonstre $instance, Personnage $auteur): void
    {
        $groupe = $instance->quete?->groupe;

        if ($groupe === null || ! $groupe->estArene() || ! $auteur->estDoublure()) {
            return;
        }

        $nom = (string) ($instance->monstre?->nom_base ?? 'Monstre');
        $ligne = $this->ligne((int) $auteur->doublure_de);
        $vaincus = (array) (json_decode((string) ($ligne->ennemis_vaincus ?? ''), true) ?: []);
        $vaincus[$nom] = (int) ($vaincus[$nom] ?? 0) + 1;

        DB::table('arene_statistiques')->where('id', $ligne->id)
            ->update(['ennemis_vaincus' => json_encode($vaincus, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
    }

    /** Fin d'un combat d'arène : la ligne de palmarès, et combats/victoires de chaque héros source. */
    public function enregistrer(Groupe $groupe, Quete $quete, bool $victoire): void
    {
        if (! $groupe->estArene()) {
            return;
        }

        $doublures = Personnage::whereNotNull('doublure_de')
            ->where('groupe_actif_id', $groupe->id)
            ->orderBy('id')
            ->get();

        $rounds = 1 + DB::table('evenements')
            ->where('groupe_id', $groupe->id)
            ->where('type', 'systeme')
            ->get(['payload'])
            ->filter(function ($e) use ($quete) {
                $p = json_decode((string) $e->payload, true) ?: [];

                return ($p['action'] ?? null) === 'nouveau_tour' && (int) ($p['quete_id'] ?? 0) === (int) $quete->id;
            })
            ->count();

        DB::table('arene_combats')->insert([
            'issue' => $victoire ? 'victoire' : 'defaite',
            'rounds' => $rounds,
            'combattants' => json_encode($doublures->map(fn (Personnage $d) => [
                'heros_id' => (int) $d->doublure_de, 'nom' => $d->nom, 'classe' => $d->classe,
            ])->values()->all(), JSON_UNESCAPED_UNICODE),
            'defender' => json_encode($quete->instancesMonstres()->with('monstre')->orderBy('id')->get()
                ->map(fn (InstanceMonstre $i) => (string) ($i->monstre?->nom_base ?? 'Monstre'))->values()->all(), JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($doublures as $doublure) {
            $ligne = $this->ligne((int) $doublure->doublure_de);
            DB::table('arene_statistiques')->where('id', $ligne->id)->update([
                'combats' => (int) $ligne->combats + 1,
                'victoires' => (int) $ligne->victoires + ($victoire ? 1 : 0),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Les statistiques d'arène d'un héros, publiées sur sa fiche (`/moi`). Les ennemis vaincus sont triés du plus
     * fréquent au moins fréquent.
     *
     * @return array{combats: int, victoires: int, ennemis_vaincus: list<array{nom_base: string, nombre: int}>}
     */
    public function statistiques(Personnage $heros): array
    {
        $ligne = DB::table('arene_statistiques')->where('personnage_id', $heros->id)->first();
        $vaincus = (array) (json_decode((string) ($ligne->ennemis_vaincus ?? ''), true) ?: []);
        arsort($vaincus);

        return [
            'combats' => (int) ($ligne->combats ?? 0),
            'victoires' => (int) ($ligne->victoires ?? 0),
            'ennemis_vaincus' => array_map(fn ($nom, $n) => ['nom_base' => (string) $nom, 'nombre' => (int) $n], array_keys($vaincus), $vaincus),
        ];
    }

    /**
     * Le palmarès général, du plus récent au plus ancien.
     *
     * @return list<array<string, mixed>>
     */
    public function palmares(int $limite = 20): array
    {
        return DB::table('arene_combats')->orderByDesc('id')->limit($limite)->get()
            ->map(fn ($c) => [
                'id' => (int) $c->id,
                'date' => (string) $c->created_at,
                'issue' => $c->issue,
                'rounds' => (int) $c->rounds,
                'combattants' => json_decode((string) $c->combattants, true) ?: [],
                'defender' => json_decode((string) $c->defender, true) ?: [],
            ])->values()->all();
    }

    /** La ligne de statistiques d'un héros source, créée à la première écriture. */
    private function ligne(int $herosId): object
    {
        DB::table('arene_statistiques')->insertOrIgnore([
            'personnage_id' => $herosId, 'combats' => 0, 'victoires' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('arene_statistiques')->where('personnage_id', $herosId)->first();
    }
}
