<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LES TROIS PIÈGES DE SOL, ENFIN SOURCÉS (contrat, 2026-09-24 — livret de
 * Zargon p. 14, photo de René, recoupé par `reference/16_armurerie.md` §716
 * et `reference/17_mobilier.md` §121). Le catalogue avait aplati les trois à
 * « 1 PV de Body », valeur qui ne sourçait ni le dé du Piège à lances ni les
 * trois dés SANS DÉFENSE de la Chute de blocs — et `bloque_passage` n'avait
 * jamais eu de lecteur (`AssembleurCarte::placerPieges()` ne posait d'ailleurs
 * QUE la Fosse partout, corrigé à part, dans le code de tirage).
 *
 * `des_combat` (nouvelle clé, lue par `MoteurPieges::declencher()`) : nombre
 * de dés de combat à lancer, un crâne = 1 PV de Body, AUCUNE défense — c'est
 * la mécanique même du dé de combat qui l'implique, aucune clé `sans_defense`
 * n'est donc nécessaire (le moteur ne lance jamais de dé de défense pour un
 * piège). `bloc_permanent` remplace `bloque_passage` : c'est cette fois LU,
 * par `MoteurPieges::declencher()` (état → `bloc`) et par
 * `FabriqueGrille::pour()` (case bloquée ET opaque).
 *
 * La Fosse perd `condition_appliquee: Immobilisé` : ce n'était pas un
 * assouplissement, c'était mort depuis toujours (`declencher()` ne l'a jamais
 * lu — seul `declencherEphemere()`, le piège de coffre, lit cette clé) et le
 * livret dit maintenant explicitement que la fosse, comme les deux autres,
 * termine le tour du héros plutôt que de poser une condition à durée.
 *
 * ⚠ Migration, PAS seulement le seeder : ce sont les LIGNES EXISTANTES du
 * catalogue que la vraie partie relit. Elle n'est PAS jouée sur la base du
 * conteneur ici (René la joue lui-même, après sauvegarde). Ciblée par le nom,
 * sans effet si le piège n'est pas au catalogue. Aucune ligne de
 * `cartes.grille.pieges` n'est touchée : une quête EN COURS relit
 * `pieges.effet` à chaque déclenchement, elle n'a rien à elle de propre à
 * migrer — et un piège de coffre/meuble (déclencheur `ouverture_tresor`)
 * n'est pas concerné par cette migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->ecrire('Fosse', [
            'degats_pv_body' => 1,
            'franchissable' => ['jet' => 'body', 'difficulte' => 2, 'si' => 'detectee'],
        ]);
        $this->ecrire('Piège à lances', ['des_combat' => 1]);
        $this->ecrire('Chute de blocs', ['des_combat' => 3, 'bloc_permanent' => true]);
    }

    public function down(): void
    {
        $this->ecrire('Fosse', [
            'degats_pv_body' => 1,
            'condition_appliquee' => 'Immobilisé',
            'franchissable' => ['jet' => 'body', 'difficulte' => 2, 'si' => 'detectee'],
        ]);
        $this->ecrire('Piège à lances', ['degats_pv_body' => 1]);
        $this->ecrire('Chute de blocs', ['degats_pv_body' => 1, 'bloque_passage' => true]);
    }

    /** @param  array<string, mixed>  $effet */
    private function ecrire(string $nom, array $effet): void
    {
        DB::table('pieges')->where('nom', $nom)->update([
            'effet' => json_encode($effet, JSON_UNESCAPED_UNICODE),
        ]);
    }
};
