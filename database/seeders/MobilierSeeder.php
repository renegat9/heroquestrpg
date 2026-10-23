<?php

namespace Database\Seeders;

use App\Models\Mobilier;
use Illuminate\Database\Seeder;

/**
 * Les types de mobilier du catalogue. Les EMPRISES ci-dessous ne sont pas des
 * mesures mais des DÉCISIONS DE RENÉ (2026-10-16), prises sur des dessins
 * mesurés à l'échelle ≈ 0,9 (la bibliothèque, mesurée 2,7 cases, en fait 3) :
 *   - 1×3 (3 cases) : bibliothèque, armoire, râtelier d'armes, cheminée
 *     (« 3-Space Furniture (1x3 Grid) ») ;
 *   - 3×2 (6 cases) : table, tombeau (couché, comme dessiné), établi
 *     d'alchimiste. Cette décision REMPLACE le 1×3 de l'établi.
 * Les emprises d'origine (table 2×1, tombeau 1×2) ne sont plus au catalogue.
 *
 * La TABLE DU SORCIER et le PORTANT n'entrent pas au catalogue, même si René a
 * décidé 3 cases pour la table du sorcier : son drapeau de vue et sa table de
 * fouille ne sont sourcés nulle part — une ligne les inventerait.
 *
 * `bloque_mouvement` = true partout (inchangé), et SOURCÉ depuis le
 * 2026-10-01 : Hasbro a confirmé officiellement que le mobilier est
 * infranchissable (compilation d'errata Ye Olde Inn, doc 17 §3). Le livret de
 * règles, lui, ne le dit toujours pas en toutes lettres.
 *
 * `bloque_vue` : ⚠ DIVERGENCE NOMMÉE, pas une donnée sourcée. Les sources
 * existent depuis First Light et elles disent autre chose : au jeu de base le
 * mobilier ne bloque PAS la vue (Hasbro, et First Light p. 8 : « it didn't
 * obstruct movement or line of sight »), et la règle OPTIONNELLE de First
 * Light le fait bloquer EN ENTIER. René a maintenu le partage par hauteur le
 * 2026-10-01 en connaissance des deux (errata 2021 C2) : un meuble HAUT (à
 * hauteur d'yeux ou plus) coupe la vue comme un mur ; un meuble BAS (hauteur
 * de table) laisse voir par-dessus.
 *   - true  : Bibliothèque, Râtelier d'armes, Armoire, Cheminée — mobilier
 *     vertical, dressé contre un mur, qui dépasse largement la taille d'un
 *     héros (la cheminée suit la famille bibliothèque, décision du 2026-10-16).
 *   - false : Table, Coffre, Trône, Établi d'alchimiste, Tombeau — mobilier
 *     bas, à hauteur de ceinture ou moins.
 * Ne pas prétendre que cette répartition est sourcée : c'est une convention
 * de jeu, au même titre que `bloque_mouvement`.
 *
 * `fouillable` reflète la colonne « Fouillable » du tableau doc 17 §1, et
 * commande bel et bien la fouille depuis le 2026-08-14. `effet.fouille` porte
 * désormais la TABLE de butin propre à chaque meuble (voir plus bas).
 */
class MobilierSeeder extends Seeder
{
    public function run(): void
    {
        // TABLE DE FOUILLE PROPRE À CHAQUE MEUBLE (`effet.fouille`), décision de
        // René du 2026-08-17 — elle remplace le tirage dans le deck de la quête.
        //
        // Le meuble tirait auparavant une carte du deck de fouille, une seule
        // fois pour tout le groupe : un râtelier d'armes pouvait donc rendre une
        // potion de soin, et un seul héros épuisait la pièce pour tous. Chaque
        // meuble a maintenant SA table, et se fouille UNE FOIS PAR HÉROS — comme
        // une salle.
        //
        // ⚠ Aucun livret ne source ces tables : ils ne disent même pas qu'un
        // meuble bloque le passage (doc 17 §3). C'est un choix de jeu, guidé par
        // ce que la pièce contient PLAUSIBLEMENT — des armes dans un râtelier,
        // des parchemins dans une bibliothèque, des fioles sur un établi.
        //
        // Format d'une entrée : `issue` + `poids` (tirage pondéré), plus les
        // clés que l'issue demande. Les issues sont celles du deck de fouille,
        // pour que `ResolveurTour::appliquerButin()` les applique sans rien
        // savoir de leur provenance :
        //   - `tresor` + `or: [min, max]`
        //   - `objet`  + `categories: [...]` → la pièce est tirée du catalogue au
        //     moment de la fouille, en DEUX TEMPS : la rareté d'abord, pondérée
        //     par le niveau moyen du groupe (`App\Engine\RareteButin`), puis la
        //     pièce uniformément dans cette rareté. Les tables ne filtrent donc
        //     plus la rareté elles-mêmes — la courbe s'en charge, et c'est elle
        //     qui fait qu'un groupe de niveau 1 trouve surtout du commun.
        //   - `piege`  + `piege: <nom>` → piège ÉPHÉMÈRE déclenché sur place
        //   - `rien`
        //
        // ⚠ CHAQUE table porte une issue `rien`, et un test l'exige : un meuble
        // qui donnerait toujours quelque chose ferait de l'exploration une
        // récolte, et l'or cesserait d'être une ressource.
        $mobiliers = [
            // Habillage de la fouille de SALLE (RB p. 14) : aucune note de quête
            // consultée n'accroche un trésor propre à une table — fouillable = false.
            // 3×2 : décision de René (2026-10-16) — mesurée ≈ 2,5 × 1,5 sur un dessin à
            // l'échelle ≈ 0,9, soit ≈ 2,8 × 1,7 réelles. Remplace le 2×1 de la mesure d'origine.
            ['nom' => 'Table', 'nom_anglais' => 'Table', 'largeur' => 3, 'hauteur' => 2, 'difficulte_destruction' => 1, 'fouillable' => false, 'bloque_vue' => false],

            // ===== Spirit Queen's Torment (G0053 p. 13, quête 4, note B ; boîte `spirit_queen`, thème allumé le 2026-10-23) =====
            // « The Orc and Goblins have been brewing strange concoctions on this table; three potions can be found. » Le
            // livret ne donne ni emprise ni destruction à cette table : emprise et difficulté de la Table de base, par
            // analogie de meuble (⚠ non trouvé pour cette pièce). Les trois potions ne sont pas comptées : chaque fouille tire
            // UNE carte, la potion de fortune a un poids sur trois (le livret ne dit pas combien de fouilles la vident).
            ['nom' => 'Table des brasseurs', 'nom_anglais' => 'Table', 'largeur' => 3, 'hauteur' => 2, 'difficulte_destruction' => 1, 'fouillable' => true, 'bloque_vue' => false,
                'boite' => 'spirit_queen',
                'effet' => ['fouille' => [
                    ['issue' => 'objet', 'poids' => 1, 'objet' => 'Potion de fortune'],
                    ['issue' => 'rien', 'poids' => 2],
                ]]],

            // Le coffre est le contenant à butin par excellence : c'est celui qui
            // paie le plus souvent, et le seul à pouvoir rendre un objet rare.
            ['nom' => 'Coffre', 'nom_anglais' => 'Chest', 'largeur' => 1, 'hauteur' => 1, 'difficulte_destruction' => 2, 'fouillable' => true, 'bloque_vue' => false,
                'effet' => ['fouille' => [
                    ['issue' => 'tresor', 'poids' => 4, 'or' => [25, 60]],
                    ['issue' => 'objet', 'poids' => 2, 'categories' => ['consommable']],
                    ['issue' => 'objet', 'poids' => 1, 'categories' => ['arme', 'armure']],
                    ['issue' => 'rien', 'poids' => 3],
                ]]],

            // Un trône ne se fouille pas, il se dépouille : pierreries du dossier,
            // pièces oubliées sous l'assise. De l'or, ou rien.
            ['nom' => 'Trône', 'nom_anglais' => 'Throne', 'largeur' => 1, 'hauteur' => 1, 'difficulte_destruction' => 3, 'fouillable' => true, 'bloque_vue' => false,
                'effet' => ['fouille' => [
                    ['issue' => 'tresor', 'poids' => 4, 'or' => [35, 75]],
                    ['issue' => 'rien', 'poids' => 4],
                ]]],

            // L'établi de l'alchimiste rend surtout des FIOLES — c'est le meuble le plus
            // spécialisé du lot. Depuis le 2026-10-16 (René : « on trouve les parchemins au
            // mobilier, d'abord la bibliothèque et l'établi »), il rend aussi, une fois sur six,
            // un PARCHEMIN : une chance de plus, pas une spécialité nouvelle. Parchemin tiré
            // par `MoteurMobilier` comme tout objet de table : rareté d'abord (difficulté du
            // sort), puis le sort uniformément dans cette rareté.
            // 3×2 : décision de René (2026-10-16), qui REMPLACE le 1×3 de la décision précédente.
            ['nom' => 'Établi d\'alchimiste', 'nom_anglais' => 'Alchemist\'s bench', 'largeur' => 3, 'hauteur' => 2, 'difficulte_destruction' => 2, 'fouillable' => true, 'bloque_vue' => false,
                'effet' => ['fouille' => [
                    ['issue' => 'objet', 'poids' => 5, 'categories' => ['consommable']],
                    ['issue' => 'objet', 'poids' => 2, 'categories' => ['parchemin']],
                    // Fouiller un établi d'alchimiste, c'est déranger des fioles :
                    // l'une se brise (piège, décision de René 2026-08-17).
                    ['issue' => 'piege', 'poids' => 2, 'piege' => 'Fiole de poison'],
                    ['issue' => 'rien', 'poids' => 3],
                ]]],

            // Le mobilier funéraire : de l'or déposé avec le mort, parfois une
            // arme de sa main, souvent la poussière seule.
            // 3×2 COUCHÉ : décision de René (2026-10-16), comme dessiné (Q14, p. 31 imprimée).
            ['nom' => 'Tombeau', 'nom_anglais' => 'Tomb', 'largeur' => 3, 'hauteur' => 2, 'difficulte_destruction' => null, 'fouillable' => true, 'bloque_vue' => false,
                'effet' => ['fouille' => [
                    ['issue' => 'tresor', 'poids' => 3, 'or' => [30, 70]],
                    ['issue' => 'objet', 'poids' => 2, 'categories' => ['arme', 'armure']],
                    // Un tombeau se défend (décision de René, 2026-08-17) :
                    // l'aiguille dans la serrure du sarcophage.
                    ['issue' => 'piege', 'poids' => 2, 'piege' => 'Aiguille empoisonnée'],
                    // Le cercueil peut contenir une créature morte-vivante (*Return of the Witch Lord* p. 4-5 :
                    // « may contain an undead creature and/or a treasure. They may also have traps on them »).
                    // Poids 2, comme le piège : la tuile est un tombeau, et ce n'est pas le livret qui pèse.
                    // Les trois morts-vivants de base, à égalité (`MoteurMobilier::carteDepuisEntree()`).
                    ['issue' => 'monstre', 'poids' => 2, 'monstres' => ['Squelette', 'Zombie', 'Momie']],
                    ['issue' => 'rien', 'poids' => 4],
                ]]],

            // Une bibliothèque contient des ÉCRITS : c'est le meuble qui rend le plus
            // souvent des parchemins, ce qui en fait la pièce du lanceur de sorts. Avec
            // l'établi d'alchimiste (2026-10-16), c'est l'une des DEUX sources de parchemins
            // au mobilier — le marché n'en vend plus (`PhaseMarche::estHorsMarche()`).
            // 3×1 (1×3 en longueur) : décision de René (2026-10-16), voir l'en-tête.
            ['nom' => 'Bibliothèque', 'nom_anglais' => 'Bookcase', 'largeur' => 3, 'hauteur' => 1, 'difficulte_destruction' => 2, 'fouillable' => true, 'bloque_vue' => true, 'adosse_au_mur' => true,
                'effet' => ['fouille' => [
                    ['issue' => 'objet', 'poids' => 3, 'categories' => ['parchemin']],
                    ['issue' => 'tresor', 'poids' => 1, 'or' => [10, 25]],
                    ['issue' => 'rien', 'poids' => 4],
                ]]],

            // Le râtelier d'armes : armes et armures, l'exemple donné par René.
            // Aucune chance d'or — on n'y range pas sa bourse.
            // 1×3 : décision de René (2026-10-16), mesuré ≈ 2,7-2,8 cases sur F2847.
            ['nom' => 'Râtelier d\'armes', 'nom_anglais' => 'Weapons rack', 'largeur' => 1, 'hauteur' => 3, 'difficulte_destruction' => 2, 'fouillable' => true, 'bloque_vue' => true, 'adosse_au_mur' => true,
                'effet' => ['fouille' => [
                    ['issue' => 'objet', 'poids' => 4, 'categories' => ['arme', 'armure']],
                    ['issue' => 'rien', 'poids' => 4],
                ]]],

            // L'armoire est le meuble à tout faire : un peu de tout, souvent rien.
            // 3×1 (1×3 en longueur) : décision de René (2026-10-16), voir l'en-tête.
            ['nom' => 'Armoire', 'nom_anglais' => 'Cupboard', 'largeur' => 3, 'hauteur' => 1, 'difficulte_destruction' => 2, 'fouillable' => true, 'bloque_vue' => true, 'adosse_au_mur' => true,
                'effet' => ['fouille' => [
                    ['issue' => 'objet', 'poids' => 2, 'categories' => ['consommable']],
                    ['issue' => 'objet', 'poids' => 2, 'categories' => ['outil']],
                    ['issue' => 'tresor', 'poids' => 2, 'or' => [15, 40]],
                    ['issue' => 'rien', 'poids' => 4],
                ]]],

            // CHEMINÉE (« Fireplace ») — MEUBLE DU JEU DE BASE : l'inventaire de la boîte de base
            // (RB p. 4) compte « fireplace » parmi ses 15 pièces, et la légende de F2847 p. 33 la
            // dessine. Aucune boîte : elle entre dans tous les donjons, comme la bibliothèque.
            // C'est la RÈGLE qui est propre à First Light : *Healing Hearth* (FL-Q p. 7, « When a hero
            // would draw a treasure card in a room containing a fireplace, they may instead heal 1 Body
            // point. »), lue par `MoteurCheminee` et scopée au groupe qui joue `first_light`.
            // ⚠ EMPRISE 1×3 : décision de René (2026-10-16). Mesurée ≈ 2,6 cases (extensions F4193 et
            // G1798 : horizontale 2,6 × 0,9 ; verticale 0,96 × 2,6) — l'arrondi à 3 est la décision.
            // ⚠ `bloque_vue` et `adosse_au_mur` : convention de la famille BIBLIOTHÈQUE (meuble haut,
            // dressé contre un mur), décidée le 2026-10-16 — pas une donnée sourcée.
            // ⚠ `difficulte_destruction` absente (donc `null`, indestructible) : AUCUNE source ne dit
            // comment on fracasse une cheminée ; on n'en invente pas une.
            // ⚠ Pas fouillable : la règle vise la SALLE (« a room containing a fireplace »), le livret ne
            // fait jamais d'un meuble une cible de fouille.
            ['nom' => 'Cheminée', 'nom_anglais' => 'Fireplace', 'largeur' => 3, 'hauteur' => 1,
                'fouillable' => false, 'bloque_vue' => true, 'adosse_au_mur' => true],

            // ===== Against the Ogre Horde (livret F9528 p. 5, lot B) =====
            // CAISSE DE RAVITAILLEMENT (« Supply Crate ») : « The first hero
            // to search for treasure in a room containing one of these chests
            // will find 4 Potions of Healing. » — un butin FIXE au premier
            // chercheur, pas une table pondérée (`effet.fouille` absent).
            // ⚠ FOUILLÉE AU CONTACT depuis le 2026-10-02 (René : « seulement
            // quand on est adjacent et non quand on cherche la salle ») :
            // `fouillable => true`, et `ResolveurTour::resoudreFouilleMobilier()`
            // rend 4× *Potion de guérison* (`soin_pv_body_de: 6`, « roll 1 red
            // die ») au PREMIER qui l'ouvre, une caisse vide aux suivants.
            //
            // ⚠ PORTAGE : aucun livret ne chiffre l'emprise au sol de cette
            // caisse (elle n'apparaît que sur les plans de quête imprimés, que
            // nous ne reprenons pas — donjons générés). Faute de mesure
            // indépendante (la règle de `MobilierSeeder`, doc 17 §1, est de ne
            // JAMAIS inventer une emprise), on reprend celle du *Coffre* —
            // 1×1, difficulté de destruction 2 — par analogie fonctionnelle
            // (c'est un coffre) plutôt que par mesure. Boîte `horde_ogre`.
            ['nom' => 'Caisse de ravitaillement', 'nom_anglais' => 'Supply crate', 'largeur' => 1, 'hauteur' => 1, 'difficulte_destruction' => 2, 'fouillable' => true, 'bloque_vue' => false, 'boite' => 'horde_ogre'],

            // ===== Jungles of Delthrak (livret F9907 p. 4-5, lot C/D) =====
            // AMAS DE CRISTAL (« Crystal Cluster ») : « These crystals radiate
            // Dread energy. […] The crystal cluster can be attacked; it has
            // 6 Body Points and cannot defend. If destroyed, remove the
            // cluster from the gameboard. The crystal cluster blocks line of
            // sight. » Troisième voie de destruction (`pv_body`/`defense_dice`,
            // 2026-10-04) : pas de jet de Body (`difficulte_destruction` reste
            // `null`, aucune source n'en décrit un), on l'épuise au combat —
            // `defense_dice = 0` est la valeur même de « cannot defend », pas
            // une absence.
            // ⚠ Partage le socle « Basin » avec *Pool of Water* et *Bonfire*
            // (même page) — René a tranché Q5 pour trois ENTRÉES distinctes de
            // catalogue plutôt qu'un sélecteur d'effet. Seule celle-ci entre
            // dans ce chantier : les deux autres ne sont PAS détruites au
            // combat (une fouille alternative qui soigne, un franchissement
            // qui blesse au passage) et réclament chacune un mot-clé de
            // TERRAIN inédit (« ne jamais finir son tour ici », « jet au
            // franchissement sans bloquer ») — un chantier séparé, nommé ici
            // plutôt qu'omis, voir le rapport du chantier.
            // ⚠ Emprise NON mesurée indépendamment (aucun livret ne la
            // chiffre) : 1×1 par analogie avec les autres pièces de décor de
            // cette taille — même repli que la Caisse de ravitaillement
            // ci-dessus, pas une mesure.
            ['nom' => 'Amas de cristal', 'nom_anglais' => 'Crystal Cluster', 'largeur' => 1, 'hauteur' => 1,
                'fouillable' => false, 'bloque_vue' => true, 'pv_body' => 6, 'defense_dice' => 0, 'boite' => 'jungles_delthrak'],

            // COCON (« Cocoon Tiles », p. 4) : « These tiles represent cocoons,
            // concentrations of webbing that may contain treasure or deadly
            // surprises. A hero adjacent to a cocoon can spend an action to
            // destroy it, which removes the obstacle from board. Cocoons block
            // line of sight and cannot be moved through. »
            // ⚠ MÉCANIQUE DISTINCTE de l'Amas de cristal ci-dessus : ni PV ni
            // dés de défense (`pv_body`/`defense_dice` restent `null`), ni jet de
            // Body (`difficulte_destruction` reste `null`, aucune source n'en
            // décrit un) — « spend an action to destroy it », point. D'où la clé
            // `detruit_par_action` (vocabulaire fermé `MotsClesMobilier`) : une
            // action, aucun jet, aucune tentative à compter. Il bloque la vue ET
            // le passage (`bloque_vue: true` ; `bloque_mouvement` l'est pour
            // tout le catalogue).
            // ⚠ « may contain treasure or deadly surprises » : ce que cache un
            // cocon est écrit dans les NOTES DE QUÊTE du livret (une quête
            // imprimée), jamais dans la règle du composant. Nos donjons sont
            // générés : le cocon est ici un OBSTACLE à détruire, sans contenu
            // inventé — l'ancien plan qui lui prêtait un « butin progressif »
            // (docs/plan-delthrak.md lot E) n'a aucune source dans le livret.
            // ⚠ Emprise 1×1 NON mesurée (aucun livret ne la chiffre) : repli par
            // analogie, comme l'Amas de cristal — c'est UNE tuile de carton.
            ['nom' => 'Cocon', 'nom_anglais' => 'Cocoon', 'largeur' => 1, 'hauteur' => 1,
                'fouillable' => false, 'bloque_vue' => true, 'boite' => 'jungles_delthrak',
                'effet' => ['detruit_par_action' => true]],

            // ===== Wizards of Morcar (livret G1504 p. 2-3/35/39, lot A) =====
            // HAUT AUTEL (« High Altar ») : « The High Altar may be attacked
            // using normal combat and has 6 Body Points. It rolls four dice
            // when defending. » Objectif de la quête 10 dans le livret ; chez
            // nous, dressing procédural du thème `wizards_of_morcar` — même
            // divergence déjà acceptée pour les monstres « sous-boss » d'autres
            // boîtes (on ne rejoue pas les quêtes nommées du livret).
            // ⚠ `bloque_vue` : ⚠ non trouvé — aucune des deux pages ne
            // mentionne la ligne de vue (contrairement au Crystal Cluster, qui
            // la précise explicitement dans un sens ou l'autre) ; `false` par
            // défaut, comme le Coffre/Trône/Tombeau (mobilier bas), jamais une
            // supposition.
            ['nom' => 'Haut Autel', 'nom_anglais' => 'High Altar', 'largeur' => 1, 'hauteur' => 1,
                'fouillable' => false, 'bloque_vue' => false, 'pv_body' => 6, 'defense_dice' => 4, 'boite' => 'wizards_of_morcar'],

            // COFFRE DU DREAD (« Dread Chest ») : « These are the Dread
            // Chests. They have 1 Body Point and can be attacked but take no
            // damage from fire. They roll 6 Defend dice. Once destroyed, the
            // matching Sorcerer lurches to life. »
            // ⚠ L'immunité au feu N'EST PAS câblée : aucune arme du catalogue
            // ne porte de `type_degat` (seuls les SORTS en portent un), donc
            // rien ne produirait jamais un dégât de feu contre ce meuble par
            // ce lecteur — une clé `immunite_degat` ici serait un lecteur SANS
            // PRODUCTEUR, la faute que ce projet nomme et évite. Nommé plutôt
            // qu'omis ; à câbler le jour où une arme/un sort de feu peut viser
            // du mobilier.
            // ⚠ « Once destroyed, the matching Sorcerer lurches to life » est
            // un déclenchement de GABARIT DE QUÊTE (hors périmètre de ce
            // chantier, qui ne porte que le meuble générique) — nommé, pas
            // oublié.
            ['nom' => 'Coffre du Dread', 'nom_anglais' => 'Dread Chest', 'largeur' => 1, 'hauteur' => 1,
                'fouillable' => false, 'bloque_vue' => false, 'pv_body' => 1, 'defense_dice' => 6, 'boite' => 'wizards_of_morcar'],

            // MUR DE PIERRE (« Wall of Stone », *Spells of Protection*, sort de
            // HÉROS) — carton *Magic Reference Chart* ET carte, mot pour mot
            // identiques : « This barrier may be placed across two squares…
            // The wall has 1 Body Point and rolls 6 Defend dice. If the wall
            // takes 1 Body Point or more of damage, it is destroyed. »
            // ⚠ Posé par le sort lui-même EN COURS DE QUÊTE
            // (`MoteurMobilier::poserMurMagique()`), jamais par
            // `AssembleurCarte` — c'est pourquoi cette ligne n'a ni position
            // ni `l`/`h` fixés ici : le gabarit ne la place pas, le sort si.
            // Le sort pose DEUX cases (`l`×`h` = 2×1 ou 1×2 selon la paire
            // choisie, décision de René 2026-10-05) ; `largeur`/`hauteur` ci-
            // dessous restent la forme d'une case, celle du catalogue.
            // ⚠ `bloque_vue: true`, à la différence du Haut Autel/Coffre du
            // Dread (mobilier BAS, `false`) : la carte l'appelle elle-même
            // « a solid, impassable wall » — un MUR, pas un meuble — et c'est
            // la seule des trois pièces attaquables de cette boîte dont la
            // source dit explicitement qu'elle remplace la roche.
            // ⚠ Wall of Ice (Storm Master) et Wall of Flame (High Mage)
            // PARTAGENT cette même règle générique (carton *Magic Reference
            // Chart*) mais restent NON seedés : leurs sorts (vague 2) n'ont
            // pas encore de lecteur, et une ligne sans producteur est
            // exactement le défaut que ce projet nomme et évite ailleurs.
            ['nom' => 'Mur de Pierre', 'nom_anglais' => 'Wall of Stone', 'largeur' => 1, 'hauteur' => 1,
                'fouillable' => false, 'bloque_vue' => true, 'pv_body' => 1, 'defense_dice' => 6, 'boite' => 'wizards_of_morcar'],

            // MUR DE GLACE (*Wall of Ice*, Storm Master) et MUR DE FEU (*Wall of Flame*,
            // High Mage) — les deux autres pièces du carton *Magic Reference Chart*
            // (« Wall of Ice, Wall of Flame, and Wall of Stone […] 6 Defend dice […]
            // if the wall takes 1 Body Point or more of damage, it is destroyed »).
            // Même fiche que le Mur de Pierre, posées par les sorts de Dread
            // (`MoteurDread::sortDreadMurMagique()` → `MoteurMobilier::poserMurMagique()`).
            // ⚠ Elles ont attendu la vague 2 pour entrer au catalogue : une ligne sans
            // producteur est la clé décorative que ce projet traque.
            // ⚠ Distinct du *Mur de Glace* du Dread (SortDread, couche `grille['glace']`,
            // Frozen Horror) : ce sont deux cartes, deux tables, deux règles.
            ['nom' => 'Mur de Glace', 'nom_anglais' => 'Wall of Ice', 'largeur' => 1, 'hauteur' => 1,
                'fouillable' => false, 'bloque_vue' => true, 'pv_body' => 1, 'defense_dice' => 6, 'boite' => 'wizards_of_morcar'],
            ['nom' => 'Mur de Feu', 'nom_anglais' => 'Wall of Flame', 'largeur' => 1, 'hauteur' => 1,
                'fouillable' => false, 'bloque_vue' => true, 'pv_body' => 1, 'defense_dice' => 6, 'boite' => 'wizards_of_morcar'],
        ];

        // ⚠ On CLÉ SUR LE NOM, on ne purge PAS.
        //
        // Ce seeder purgeait puis recréait, en s'appuyant sur un commentaire qui
        // affirmait qu'« aucune clé étrangère ne pointe vers `mobiliers` ». C'est
        // vrai au sens SQL et faux en pratique : `cartes.grille.mobilier[]`
        // stocke un `mobilier_id`. Purger réattribue les identifiants, et une
        // quête EN COURS se retrouve avec du mobilier qui ne référence plus rien
        // — meubles ni fouillables, ni bloquants, sans la moindre erreur.
        // Constaté au moment de semer les tables de butin (2026-08-17).
        foreach ($mobiliers as $mobilier) {
            Mobilier::updateOrCreate(
                ['nom' => $mobilier['nom']],
                [
                    ...$mobilier,
                    'bloque_mouvement' => true,
                    'effet' => $mobilier['effet'] ?? null,
                    // ⚠ Écrit MÊME quand la ligne l'omet : `null` veut dire
                    // « indestructible », pas « pas renseigné ». Le laisser
                    // tomber de la mise à jour rendrait une valeur ancienne
                    // survivante à un re-semis qui voulait justement l'effacer.
                    'difficulte_destruction' => $mobilier['difficulte_destruction'] ?? null,
                    // Même garde, même raison, pour la troisième voie de
                    // destruction (2026-10-04) : `null` = ne se détruit pas au
                    // combat.
                    'pv_body' => $mobilier['pv_body'] ?? null,
                    'defense_dice' => $mobilier['defense_dice'] ?? null,
                ],
            );
        }
    }
}
