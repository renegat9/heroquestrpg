<?php

/*
 * SCÈNES DE TABLE POUR LE LIVRET — le déclencheur.
 *
 * Joue, sur la campagne de harnais `CODE`, une scène de chaque sorte que le
 * livret illustre. Les payloads ont la forme exacte de ceux du MOTEUR et
 * passent par le VRAI constructeur `SceneDeTable` : seul le déclenchement est
 * provoqué, le rendu est celui du jeu. On n'attend pas qu'un vrai tour sorte
 * trois crânes au bon moment — ce serait une heure de dés pour la même image.
 *
 * ⚠ La cohérence est tenue À LA MAIN, parce que le livret la montre : les PV
 * affichés sous chaque portrait sont ceux de la base, donc on les pose AVANT
 * la scène (le monstre touché garde ses PV moins le coup, Grom est à 0 quand
 * il s'effondre), et le nombre de dés lancés est celui de la fiche.
 * Ne JAMAIS pointer ce script sur une vraie campagne : il écrit des PV.
 *
 *   docker cp browser-shots/livret-scenes.php heroquestrpg-app-1:/tmp/
 *   docker compose exec -T -e CODE=<code> app php artisan tinker --execute="require '/tmp/livret-scenes.php';"
 */

use App\Events\SceneTable;
use App\Models\Evenement;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Mobilier;
use App\Models\Objet;
use App\Models\Quete;
use App\Partie\Images\BibliothequeImages;
use App\Partie\SceneDeTable;
use App\Partie\TamponScenes;

$code = getenv('CODE') ?: throw new RuntimeException('CODE manquant');
$g = Groupe::where('identifiant', $code)->firstOrFail();
$q = Quete::where('groupe_id', $g->id)->latest('id')->firstOrFail();
$sc = app(SceneDeTable::class);
$heros = fn (string $nom) => $g->personnages()->where('nom', $nom)->firstOrFail();
$grom = $heros('Grom');
$borin = $heros('Borin');

// ⚠ Des créatures ILLUSTRÉES, et ordinaires. La première série prenait « la plus
// robuste » : c'était l'Archimage elfe, le maître de la quête — sans image,
// donc l'emblème de repli au lieu d'un portrait, dans un livret qui montre
// précisément à quoi servent les illustrations.
$images = app(BibliothequeImages::class);
$illustree = fn (InstanceMonstre $i) => ! str_contains(
    $images->urlMonstre($i->id, $i->monstre_id, $i->monstre?->nom_base), '/placeholder/');
$ms = InstanceMonstre::where('quete_id', $q->id)->with('monstre')->get()
    ->filter($illustree)
    ->filter(fn ($i) => ($i->monstre?->tier ?? 'base') === 'base')
    ->sortByDesc(fn ($i) => (int) $i->monstre?->attaque)
    ->values();
$a = $ms->first() ?? throw new RuntimeException('aucune créature illustrée dans cette quête');
// Une seconde créature d'une AUTRE espèce, pour que la scène de salle en montre deux.
$b = $ms->first(fn ($i) => $i->monstre_id !== $a->monstre_id) ?? $a;

$seq = fn () => (int) Evenement::query()->where('groupe_id', $g->id)->max('sequence');
$go = function (?array $s) use ($g, $seq) {
    if ($s === null) {
        echo "  (aucune scène)\n";

        return;
    }
    broadcast(new SceneTable($g, $s, $seq()));
    echo '  → '.$s['titre'].' | '.$s['issue']['libelle']."\n";
    sleep(9);
};

/** `$n` faces, dont `$utiles` de la face qui compte, le reste d'une face qui ne compte pas. */
$faces = fn (int $n, int $utiles, string $utile, string $vide) => array_merge(
    array_fill(0, max(0, min($n, $utiles)), $utile),
    array_fill(0, max(0, $n - $utiles), $vide),
);

// Tout le monde repart de ses PV de catalogue : le script se relance, et une
// série précédente laissait « Graveleux 0 PV » dans la scène de salle.
$grom->update(['pv_body' => $grom->pv_body_max]);
$borin->update(['pv_body' => $borin->pv_body_max]);
$a->update(['pv_body' => $a->pv_body_max]);
$b->update(['pv_body' => $b->pv_body_max]);
sleep(10); // le temps que l'écran de table s'ouvre

// 1. Un jet d'attribut, et CE QU'IL RAPPORTE.
$table = Mobilier::where('nom', 'Table')->first() ?? Mobilier::query()->firstOrFail();
$go($sc->depuisResultat([
    'type' => 'jet', 'libelle' => 'Fracasser la '.$table->nom.' — jet de Body',
    'jet' => ['succes' => 2, 'difficulte' => 2], 'mobilier' => $table->nom, 'detruit' => true,
    'butin' => ['issue' => 'tresor', 'or' => 45],
], $grom)[0] ?? null);

// 2. Une fouille qui trouve une arme — ses dés et ses règles avec elle.
$epee = Objet::where('nom', 'Épée large')->firstOrFail();
$go($sc->depuisResultat([
    'type' => 'fouille_mobilier', 'issue' => 'objet',
    'objet' => ['id' => $epee->id, 'nom' => $epee->nom, 'categorie' => $epee->categorie],
], $borin)[0] ?? null);

// 3. Un piège.
$borin->update(['pv_body' => max(1, (int) $borin->pv_body_max - 1)]);
$go($sc->depuisResultat([
    'type' => 'piege_declenche', 'piege' => ['nom' => 'Fosse'],
    'personnage' => ['id' => $borin->id, 'nom' => $borin->nom],
    'degats' => 1, 'immobilise' => true,
], $borin->fresh())[0] ?? null);

// 4. Une salle qui s'ouvre sur deux créatures, avec leurs caractéristiques —
//    AVANT tout coup, pour que leurs PV soient ceux du catalogue.
$go($sc->salle($q, 1, [$a, $b]) ?? $sc->salle($q, 0, [$a, $b]));

// 5. Grom frappe — 2 crânes contre 1 bouclier noir. Un monstre de base n'a
//    qu'un point de Body au plateau : un seul dégât le terrasse, et c'est la
//    règle que le livret doit montrer, pas un coup encaissé qui n'existe pas.
$desA = (int) $grom->des_attaque;
$desD = max(1, (int) ($b->monstre->defense ?? 2));
$b->update(['pv_body' => 0]);
$go($sc->depuisResultat([
    'type' => 'attaque',
    'cible' => ['instance_id' => $b->id, 'nom' => $b->nomAffiche()],
    'touches' => 2, 'boucliers' => 1, 'degats' => 1, 'cible_vaincue' => true,
    'faces_attaque' => $faces($desA, 2, 'crane', 'bouclier_blanc'),
    'faces_defense' => $faces($desD, 1, 'bouclier_noir', 'crane'),
    'face_touchante' => 'crane', 'face_defensive' => 'bouclier_noir',
], $grom->fresh())[0] ?? null);

// 6. Le coup qui fait tomber Grom — puis sa chute, DANS CET ORDRE, par le
//    tampon qui l'impose en jeu.
$desM = max(1, min(4, (int) ($a->monstre->attaque ?? 3)));
$touches = min($desM, 3);
$degats = max(1, $touches - 1);
$grom->update(['pv_body' => 0]);
app(TamponScenes::class)->ajouter($g, $sc->chute($grom->fresh(), true));
$go($sc->depuisResultat([
    'type' => 'attaque_monstre', 'monstre' => $a->nomAffiche(), 'instance_id' => $a->id,
    'cible' => ['personnage_id' => $grom->id, 'nom' => $grom->nom],
    'touches' => $touches, 'boucliers' => 1, 'degats' => $degats, 'cible_tombee' => true,
    'portee' => 'corps_a_corps',
    'faces_attaque' => $faces($desM, $touches, 'crane', 'bouclier_noir'),
    'faces_defense' => $faces((int) $grom->des_defense, 1, 'bouclier_blanc', 'crane'),
    'face_touchante' => 'crane', 'face_defensive' => 'bouclier_blanc',
], $grom->fresh())[0] ?? null);
app(TamponScenes::class)->vider();
echo "  → (tampon vidé : la chute suit le coup)\n";
sleep(10);

echo "fini\n";
