<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `groupes.theme_bestiaire` — la boîte du bestiaire, FIGÉE pour toute la
 * campagne (arbitrage de René, 2026-09-06, phase 6a).
 *
 * `DemarreurQuete::themeBestiaire()` tournait jusqu'ici sur
 * `id_groupe % count(BOITES_THEMATIQUES)`, RECALCULÉ à chaque quête — sans
 * conséquence tant que la liste ne bougeait pas. Elle va bientôt passer de 4 à
 * 5 entrées (réactivation de la boîte de glace, phase à part) : le modulo
 * changerait, et une campagne EN COURS verrait son thème sauter de la jungle à
 * la banquise entre deux quêtes — ce que le commentaire de `themeBestiaire()`
 * interdit déjà explicitement pour le boss final (« le thème d'une campagne ne
 * change jamais en cours de route »). La règle valait déjà, elle n'était
 * simplement jamais mise en défaut.
 *
 * Exactement l'arbitrage déjà pris pour `quetes.objectif_majeur` et
 * `quetes.type_jalon` (migrations `2026_09_04_000003_objectif_majeur_sur_quetes`
 * et la colonne d'origine de `type_jalon`) : un fait décidé une fois, au
 * démarrage, plutôt que recalculé — pour la même raison ici, un cran plus
 * haut (le GROUPE plutôt que la quête) : rien ne doit pouvoir changer sa
 * propre réponse en vol.
 *
 * ⚠ `null` par défaut, PAS le calcul courant : une campagne existante avant
 * cette migration n'a jamais eu de thème figé, et l'écrire ici à la volée (via
 * une migration de données) supposerait connaître, pour chaque groupe, l'ID
 * qui a servi à son bestiaire jusqu'ici — ce que la migration a, mais fixer la
 * valeur MAINTENANT plutôt qu'au prochain démarrage réel ne changerait rien
 * pour une campagne qui ne rejouera peut-être jamais. C'est
 * `DemarreurQuete::demarrer()` qui écrit la colonne, UNE SEULE FOIS, si elle
 * est vide, au prochain démarrage de quête — et `themeBestiaireDuGroupe()`
 * retombe sur le calcul historique tant qu'elle l'est : aucun groupe ne voit
 * jamais `null` traité comme une erreur ou un thème « sans boîte ».
 *
 * ⚠ N'entre PAS dans le snapshot (`app/Partie/Sauvegarde.php`) — même
 * raisonnement que `groupes.chance_passage_secret`, qui n'y entre pas non
 * plus : c'est une constante de CAMPAGNE, écrite une fois sur la ligne
 * `groupes` elle-même, jamais réécrite par `restaurer()` (qui ne touche que
 * `or`/`phase`/`quete_courante_id`) ni remise à zéro par un redémarrage de
 * quête. Rien ne peut donc jamais la faire dériver d'un instantané à l'autre,
 * et il n'y a rien à « restaurer ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groupes', function (Blueprint $table) {
            $table->string('theme_bestiaire', 40)->nullable()->after('chance_passage_secret');
        });
    }

    public function down(): void
    {
        Schema::table('groupes', function (Blueprint $table) {
            $table->dropColumn('theme_bestiaire');
        });
    }
};
