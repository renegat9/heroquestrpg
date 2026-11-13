<script setup>
// GUIDE DE L'ARÈNE (2026-11-12, René : « un guide dédié à l'arène ») — l'onglet « Arène » de /guide.
//
// ⚠ Aucun chiffre de règle n'est écrit en dur ici : chaque nombre vient du bloc `arene` que publie le serveur
// (`GuideController::arene()`, qui lit le roster, les alliés, les tuiles, la table de fouille, la manœuvre et le hall
// aux points de passage du jeu). Le texte décrit les règles ; le bloc donne les valeurs. Un chiffre ajouté ici à la main
// dériverait le jour où le livret, ou une constante, bougerait.
import { computed } from 'vue';
import MSym from '../ui/MSym.vue';
import PlanHallArene from './PlanHallArene.vue';

const props = defineProps({
    // `arene` : le bloc du contrat (roster, allies, puissance, trophees, coffres, manoeuvre, hall).
    arene: { type: Object, required: true },
});

const SECTIONS = [
    { id: 'arene-quoi', libelle: 'L’arène' },
    { id: 'arene-preparer', libelle: 'Préparer un combat' },
    { id: 'arene-hall', libelle: 'Le hall' },
    { id: 'arene-combat', libelle: 'Le combat' },
    { id: 'arene-trophees', libelle: 'Tuiles trophées' },
    { id: 'arene-coffres', libelle: 'Coffres' },
    { id: 'arene-mvm', libelle: 'Monster vs Monster' },
];

// Ce que le plan et les listes décident, relu une fois ici pour les phrases du texte.
const totalTuiles = computed(() => props.arene.trophees.tuiles.reduce((n, t) => n + t.nombre, 0));
const misesDeCote = computed(() => totalTuiles.value - props.arene.trophees.cases);
const departs = computed(() => props.arene.hall.depart_challenger.length);
const fouilleDe = computed(() => props.arene.coffres[0]?.de ?? '');
const fouilleA = computed(() => props.arene.coffres.at(-1)?.a ?? '');
// Une figure occupe autant de cases que son emprise : « 2 cases » pour un ogre de deux cases, rien pour une case.
const surface = (m) => (m.emprise?.l ?? 1) * (m.emprise?.h ?? 1);

// Le sommaire fait défiler jusqu'à la section : un lien d'ancre passerait par le routeur et ne défilerait pas.
function allerA(id) {
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
</script>

<template>
    <div class="ga">
        <header class="ga-entete">
            <p class="ga-chapo">
                Le tournoi se joue à part, hors de la campagne : des combats avec leurs propres héros,
                leurs propres monstres et les règles du livret.
            </p>
            <p class="ga-bandeau"><MSym n="shield_lock" fill :size="16" /> Rien ne compte pour la campagne.</p>
            <nav class="ga-sommaire" aria-label="Sommaire de l'arène">
                <button v-for="s in SECTIONS" :key="s.id" type="button" class="ga-sommaire-lien" @click="allerA(s.id)">
                    {{ s.libelle }}
                </button>
            </nav>
        </header>

        <!-- ===================== 1. QU'EST-CE QUE L'ARÈNE ===================== -->
        <section id="arene-quoi" class="ga-bloc">
            <h3 class="ga-titre"><MSym n="swords" :size="18" /> Qu'est-ce que l'arène ?</h3>

            <div class="ga-modes">
                <article class="ga-mode">
                    <h4><MSym n="groups" :size="16" /> Coop</h4>
                    <p>
                        Vos héros d'arène et leurs alliés, le <strong>Challenger</strong>, affrontent un
                        <strong>Defender</strong> de monstres que vous composez ensemble.
                    </p>
                </article>
                <article class="ga-mode">
                    <h4><MSym n="skull" :size="16" /> Monster vs Monster</h4>
                    <p>
                        Deux camps de joueurs, sans héros : chaque camp compose son armée de monstres, puis la dirige
                        contre l'autre.
                    </p>
                </article>
            </div>

            <h4 class="ga-sous">Le héros d'arène</h4>
            <ul class="ga-liste">
                <li>
                    Il se crée pour l'arène. Il naît comme un héros de campagne de sa classe (niveau 1, carte de classe,
                    équipement de départ, capacités de carte), mais sans talent de grille.
                </li>
                <li>
                    Il n'entre jamais en campagne : on ne peut ni le faire rejoindre une campagne, ni en fonder une avec lui.
                </li>
                <li>
                    Chaque combat engage une <strong>copie fraîche</strong> de lui, sa doublure. Aucune blessure ne suit
                    d'un combat à l'autre, et votre héros n'est pas touché.
                </li>
                <li>
                    Ses statistiques et son palmarès (combats, victoires, ennemis vaincus) se lisent sur sa carte. Un
                    ennemi vaincu est crédité au héros qui porte le coup de grâce.
                </li>
            </ul>
        </section>

        <!-- ===================== 2. PRÉPARER UN COMBAT ===================== -->
        <section id="arene-preparer" class="ga-bloc">
            <h3 class="ga-titre"><MSym n="group_add" :size="18" /> Préparer un combat (Coop)</h3>

            <ol class="ga-etapes">
                <li>
                    <strong>Ouvrir ou rejoindre.</strong> Sur la carte d'un héros d'arène, « Ouvrir un combat » crée le
                    combat et y engage le héros. « Rejoindre » accepte le code d'un combat ouvert par quelqu'un d'autre.
                    L'écran de table ouvre le même combat avec le même code.
                </li>
                <li>
                    <strong>Engager un allié (facultatif).</strong> Chaque combattant peut prendre <em>un</em> allié du
                    catalogue, sans rien payer. L'allié rejoint le Challenger et compte dans sa puissance. « Aucun »
                    s'en passe.
                </li>
                <li>
                    <strong>Composer le Defender.</strong> Touchez une figure du roster pour l'ajouter, et touchez-la dans
                    la liste du Defender pour la retirer. Une figure qui dépasse ce qui reste de puissance est grisée.
                </li>
                <li>
                    <strong>Dire « prêt ».</strong> Chaque joueur marque sa doublure prête. Un nouvel arrivant, ou un
                    allié changé, remet les « prêt » à zéro.
                </li>
                <li>
                    <strong>Lancer.</strong> « Lancer le combat », depuis la manette ou la table, dès que tous sont prêts,
                    qu'il y a au moins un combattant, et que le Defender ne dépasse pas le Challenger.
                </li>
                <li>
                    <strong>Revanche.</strong> Après le combat, « Revanche » remet des doublures fraîches, garde le
                    Defender et remet les « prêt » à zéro.
                </li>
            </ol>

            <h4 class="ga-sous">La puissance d'équipe</h4>
            <ul class="ga-liste">
                <li>
                    Puissance d'un héros : « {{ arene.puissance.heros }} ». Sa meilleure attaque compte en dés, arme ou sort.
                </li>
                <li>Un monstre vaut ses dés d'attaque. Un allié vaut comme un héros.</li>
                <li>Le Defender ne peut jamais valoir plus que le Challenger.</li>
            </ul>

            <h5 class="ga-sous-petit">Le roster du Defender</h5>
            <p class="ga-note">
                Une figure à distance vaut son tir : sa puissance est celle de son attaque à distance. La colonne « Att. »
                donne son attaque au contact.
            </p>
            <div class="ga-table-zone">
                <table class="ga-table ga-table-roster">
                    <thead>
                        <tr>
                            <th>Figure</th>
                            <th class="num" title="Déplacement">Dépl.</th>
                            <th class="num" title="Attaque au contact">Att.</th>
                            <th class="num" title="Défense">Déf.</th>
                            <th class="num" title="Points de vie (Body)">PV</th>
                            <th class="num" title="Puissance : ce que la figure coûte à l'équipe">Puiss.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in arene.roster" :key="m.id">
                            <td class="ga-nom">
                                {{ m.nom_base }}
                                <span v-if="m.a_distance" class="ga-tag">à distance</span>
                                <span v-if="surface(m) > 1" class="ga-tag">{{ surface(m) }} cases</span>
                            </td>
                            <td class="num">{{ m.deplacement }}</td>
                            <td class="num">{{ m.attaque }}</td>
                            <td class="num">{{ m.defense }}</td>
                            <td class="num">{{ m.pv_body }}</td>
                            <td class="num ga-puiss">{{ m.puissance }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <h5 class="ga-sous-petit">Les alliés</h5>
            <div class="ga-table-zone">
                <table class="ga-table ga-table-allies">
                    <thead>
                        <tr>
                            <th>Allié</th>
                            <th class="num" title="Déplacement">Dépl.</th>
                            <th class="num" title="Attaque au contact">Att.</th>
                            <th class="num" title="Attaque à distance">Att. dist.</th>
                            <th class="num" title="Défense">Déf.</th>
                            <th class="num" title="Points de vie (Body)">PV</th>
                            <th class="num" title="Puissance : ce que l'allié ajoute à l'équipe">Puiss.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in arene.allies" :key="a.id">
                            <td class="ga-nom">
                                {{ a.nom }}
                                <span v-if="a.animal" class="ga-tag">animal</span>
                            </td>
                            <td class="num">{{ a.deplacement }}</td>
                            <td class="num">{{ a.attaque }}</td>
                            <td class="num">{{ a.attaque_distance ?? '—' }}</td>
                            <td class="num">{{ a.defense }}</td>
                            <td class="num">{{ a.pv_body }}</td>
                            <td class="num ga-puiss">{{ a.puissance }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ===================== 3. LE HALL ===================== -->
        <section id="arene-hall" class="ga-bloc">
            <h3 class="ga-titre"><MSym n="map" :size="18" /> Le hall</h3>
            <p>
                Le combat se joue sur une carte fixe : une salle centrale, et de part et d'autre une tuile de départ de
                {{ departs }} cases. Le Challenger se place à l'ouest, le Defender à l'est. Aucune porte à ouvrir : la
                salle reste libre pendant tout le combat.
            </p>
            <p class="ga-note">
                Écart avec le plateau : là-bas, chaque tuile de départ ne s'ouvre sur la salle que par une double porte ;
                ici, la jonction est ouverte sur toute la hauteur de la tuile.
            </p>
            <PlanHallArene :hall="arene.hall" />
        </section>

        <!-- ===================== 4. LE COMBAT ===================== -->
        <section id="arene-combat" class="ga-bloc">
            <h3 class="ga-titre"><MSym n="sports_martial_arts" :size="18" /> Le combat</h3>

            <h4 class="ga-sous">L'activation alternée</h4>
            <ol class="ga-etapes ga-etapes-court">
                <li>Le <strong>Defender ouvre chaque round</strong> : une de ses figures s'active.</li>
                <li>
                    Ensuite, chaque héros encore debout qui n'a pas joué a la main. Le <strong>premier qui entame son
                    activation</strong> (un pas, un coup) la garde jusqu'au bout ; les autres attendent.
                </li>
                <li>
                    <strong>Chaque activation de héros reçoit sa réponse</strong> : une figure du Defender joue juste après.
                </li>
                <li>
                    Une équipe à court <strong>laisse l'autre enchaîner</strong>. Quand le Challenger a tout joué, le
                    Defender enchaîne toutes ses figures, et le round se referme.
                </li>
            </ol>

            <h4 class="ga-sous">Les alliés</h4>
            <p>
                Un allié joue à son tour, sur la manette de son joueur, juste après son héros. Les monstres le visent.
                Un allié dont le héros est tombé ne joue plus. Ses victimes ne sont créditées à personne au palmarès.
            </p>

            <h4 class="ga-sous">Tomber</h4>
            <p>
                Un combattant à terre est hors jeu jusqu'à la fin du combat : l'arène n'offre pas « Relever ». Les soins
                (sorts, potions, réactions) le préviennent ou le relèvent, comme en campagne. Ses objets restent dans le
                sac de sa doublure : personne ne les ramasse au sol (écart nommé).
            </p>
            <p>
                Le combat s'achève quand le Defender est vaincu (victoire), ou quand tous les héros sont à terre (défaite).
            </p>

            <h4 class="ga-sous">Le combattant seul</h4>
            <p>
                Un héros seul de son camp, le dernier debout, sans allié debout, prend une <strong>manœuvre de base</strong>
                après chaque activation adverse : se déplacer jusqu'à son mouvement, <em>ou</em> lancer
                {{ arene.manoeuvre.des_attaque }} dés d'attaque contre une créature au contact (en diagonale si son arme
                le permet).
            </p>
            <p>
                La manœuvre ne consomme pas l'activation : le combattant la garde, et la joue ensuite normalement. Elle
                s'ouvre même avant qu'il ait joué la sienne, à chaque activation adverse.
            </p>
            <p>
                Pendant une manœuvre, on peut <strong>boire une potion</strong> : boire ne coûte pas l'action, et la
                manœuvre reste ouverte. Seuls les objets gratuits, les potions, se prennent ainsi.
            </p>
            <p class="ga-note">
                Côté Defender, sa dernière figure, une fois son activation jouée, manœuvre de la même façon, après chaque
                activation de héros.
            </p>
        </section>

        <!-- ===================== 5. LES TUILES TROPHÉES ===================== -->
        <section id="arene-trophees" class="ga-bloc">
            <h3 class="ga-titre"><MSym n="emoji_events" :size="18" /> Les tuiles trophées</h3>
            <p>
                Le lot compte {{ totalTuiles }} tuiles. {{ arene.trophees.cases }} sont posées <strong>face cachée</strong>
                sur les cases du plan ; les {{ misesDeCote }} autres restent de côté, hors jeu.
            </p>

            <div class="ga-table-zone">
                <table class="ga-table ga-table-tuiles">
                    <thead>
                        <tr>
                            <th>Tuile</th>
                            <th>Effet</th>
                            <th class="num">Nombre</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="t in arene.trophees.tuiles" :key="t.cle">
                            <td class="ga-nom">{{ t.libelle }}</td>
                            <td>{{ t.effet }}</td>
                            <td class="num">× {{ t.nombre }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <h4 class="ga-sous">Les ramasser</h4>
            <p>
                En finissant son activation sur la case, héros <em>ou allié</em> ; un monstre ramasse tout ce qui se
                trouve sous son emprise. La tuile se révèle et rejoint la <strong>réserve de l'équipe</strong> : tout le camp
                peut s'en servir, et personne ne peut la voler. Un combattant seul la ramasse aussi en finissant sa manœuvre.
            </p>

            <h4 class="ga-sous">Les utiliser</h4>
            <ul class="ga-liste">
                <li>
                    <strong>Potion de soin</strong> : rend {{ arene.trophees.soin_potion }} PV, jusqu'au maximum, à un
                    héros blessé. Elle se boit aussi à 0 PV, et le héros se relève.
                </li>
                <li>
                    <strong>Élan</strong> : défausser n'importe quelle tuile pour gagner
                    {{ arene.trophees.cases_elan }} cases de déplacement, ce tour.
                </li>
                <li>
                    <strong>Tuiles de combat et riposte</strong> : elles se proposent au moment où elles comptent. Une
                    tuile de jet se propose après le jet, et seulement si elle change l'issue ; la riposte se propose après
                    un coup reçu au contact ou en diagonale. On accepte, ou on laisse passer.
                </li>
            </ul>
            <p class="ga-note">Dans le livret, toute tuile peut servir d'élan : « {{ arene.trophees.elan }} ».</p>

            <h4 class="ga-sous">Le Defender</h4>
            <p>
                Le Defender joue ses tuiles tout seul : il boit une potion dès qu'une de ses figures blessées s'active, ne
                prend jamais l'élan, et ses tuiles de combat partent d'elles-mêmes, annoncées au fil.
            </p>
        </section>

        <!-- ===================== 6. LES COFFRES ===================== -->
        <section id="arene-coffres" class="ga-bloc">
            <h3 class="ga-titre"><MSym n="inventory_2" :size="18" /> Les coffres du tournoi</h3>
            <p>
                Le plan porte {{ arene.hall.coffres.length }} coffres. Une fouille se fait <strong>au contact ou en
                diagonale</strong> d'un coffre, comme une action de fouille. On lance les dés (de {{ fouilleDe }} à
                {{ fouilleA }}) et l'on lit la table :
            </p>

            <div class="ga-table-zone">
                <table class="ga-table ga-table-coffres">
                    <thead>
                        <tr>
                            <th class="num">Total</th>
                            <th>Résultat</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in arene.coffres" :key="c.de" :class="'ga-issue-' + c.issue">
                            <td class="num ga-des">{{ c.de === c.a ? c.de : `${c.de} – ${c.a}` }}</td>
                            <td>{{ c.texte }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p>
                Le coffre quitte ensuite le plateau. Les pièges frappent le fouilleur. La hache rejoint le sac de la
                doublure, et disparaît avec elle. L'or va à la <strong>bourse du combat</strong>, jamais à un héros.
            </p>
        </section>

        <!-- ===================== 7. MONSTER VS MONSTER ===================== -->
        <section id="arene-mvm" class="ga-bloc">
            <h3 class="ga-titre"><MSym n="skull" :size="18" /> Monster vs Monster</h3>
            <p>
                Deux camps de joueurs, sans héros ni alliés : chaque camp compose son armée de monstres et la dirige.
                Aucune statistique n'est suivie, et il n'y a pas de palmarès.
            </p>

            <h4 class="ga-sous">Du salon au combat</h4>
            <ol class="ga-etapes">
                <li>
                    <strong>Ouvrir ou rejoindre.</strong> Dans la section « Monster vs Monster », « Ouvrir un combat » crée
                    le combat. Le « Code du combat » le rejoint, par « Camp A » ou « Camp B ».
                </li>
                <li>
                    <strong>La puissance convenue.</strong> Les joueurs s'accordent sur une puissance commune, jusqu'à
                    {{ arene.puissance.plafond_mvm }}. Elle se fixe avant le tirage.
                </li>
                <li>
                    <strong>Le tirage.</strong> Le serveur tire au sort quel camp est Challenger et quel camp est Defender.
                    Il faut au moins un joueur dans chaque camp.
                </li>
                <li>
                    <strong>La sélection.</strong> Le Defender choisit le premier ; les camps alternent et ajoutent chacun
                    un monstre du roster, jusqu'à la puissance convenue. Une figure trop puissante pour ce qui reste est grisée.
                </li>
                <li>
                    <strong>Le combat.</strong> Le Defender occupe la tuile est, le Challenger la tuile ouest. Chaque figure
                    porte son camp : « Gobelin (camp A) », et un badge A ou B marque son jeton.
                </li>
            </ol>

            <h4 class="ga-sous">Activation alternée, figure par figure</h4>
            <ul class="ga-liste">
                <li>
                    Le camp qui a la main active <strong>une</strong> figure qui n'a pas encore joué ce round. N'importe
                    quel joueur de ce camp peut la jouer : le premier qui appuie.
                </li>
                <li>
                    Une figure entamée garde la main jusqu'à « Terminer ». Puis la main passe à l'autre camp ; un camp à
                    court laisse l'autre enchaîner.
                </li>
                <li>
                    Une activation : marcher de son déplacement, et frapper un adversaire au contact (en diagonale si la
                    figure frappe ainsi), dans l'ordre voulu.
                </li>
                <li>
                    <strong>Tirs à distance</strong> : une figure à distance tire sur une figure en ligne de vue. Les
                    gravats et les autres figures bloquent la vue.
                </li>
                <li>
                    Une figure de plusieurs cases (le tableau du roster indique combien) se pose et se déplace avec toute
                    son emprise.
                </li>
            </ul>

            <h4 class="ga-sous">Ce qui n'existe pas dans ce mode</h4>
            <ul class="ga-liste">
                <li>
                    Ni trophées, ni coffres : le livret ne dit pas qui les ramasserait entre deux camps de monstres
                    (écart nommé).
                </li>
                <li>Aucun sort de Dread : le roster n'en compte aucun.</li>
            </ul>

            <h4 class="ga-sous">Fin et revanche</h4>
            <p>
                Un camp sans figure debout perd. Le salon revient, avec le camp gagnant. « Revanche » garde les camps et
                la sélection, et rouvre un combat neuf.
            </p>
        </section>
    </div>
</template>

<style scoped>
/* Une colonne de lecture centrée (860 px), dans la largeur de la page : le texte ne court pas sur tout l'écran. */
.ga { display: grid; gap: 14px; max-width: 860px; margin: 0 auto; color: var(--ink-200, #e7dcc6); font-family: var(--font-ui, inherit); }
.ga-entete { display: grid; gap: 12px; }
.ga-chapo { margin: 0; font-family: var(--font-narr, inherit); font-style: italic; font-size: 15px; line-height: 1.6;
  color: var(--ink-300, #cfc3ad); max-width: 860px; }
.ga-bandeau { display: inline-flex; align-items: center; gap: 8px; align-self: start; margin: 0; padding: 8px 12px;
  border-radius: 10px; font-weight: 800; font-size: 13.5px; color: var(--gold, #d8a23a);
  border: 1px solid oklch(0.80 0.135 88 / 0.45); background: oklch(0.80 0.135 88 / 0.08); }
.ga-sommaire { display: flex; flex-wrap: wrap; gap: 6px; }
.ga-sommaire-lien { padding: 5px 11px; border-radius: 99px; cursor: pointer; font: inherit; font-size: 12.5px; font-weight: 700;
  color: var(--ink-300, #cfc3ad); background: var(--stone-850, #2a241c); border: 1px solid var(--stone-700, #4a4236); }
.ga-sommaire-lien:hover { color: var(--torch, #e0a060); border-color: var(--torch, #e0a060); }

.ga-bloc { padding: 16px 16px 14px; border-radius: var(--r-lg, 14px);
  background: linear-gradient(180deg, var(--stone-850, #2a241c), var(--stone-900, #211c16)); border: var(--line); scroll-margin-top: 12px; }
.ga-titre { display: flex; align-items: center; gap: 8px; margin: 0 0 10px; font-family: var(--font-display, inherit);
  font-size: 19px; font-weight: 800; color: var(--parch-100, #f4ead6); letter-spacing: 0.01em; }
.ga-titre .msym { color: var(--torch, #e0a060); }
.ga-sous { margin: 18px 0 8px; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em;
  color: var(--gold, #d8a23a); }
.ga-sous-petit { margin: 16px 0 6px; font-size: 13px; font-weight: 800; color: var(--parch-100, #f4ead6); }
.ga-bloc p { margin: 0 0 10px; font-size: 14px; line-height: 1.6; }
/* Deux classes et non `!important` : la note bat le paragraphe de base par la spécificité, sans drapeau. */
.ga-bloc .ga-note { font-size: 12.5px; color: var(--ink-500, #8a7f6a); font-style: italic; }
.ga-note strong { font-style: normal; }
.ga-liste, .ga-etapes { margin: 0 0 10px; padding-left: 20px; display: grid; gap: 7px; font-size: 14px; line-height: 1.55; }
.ga-etapes-court { gap: 5px; }
.ga-liste li::marker, .ga-etapes li::marker { color: var(--torch, #e0a060); font-weight: 800; }
.ga strong { color: var(--parch-100, #f4ead6); font-weight: 700; }

.ga-modes { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 10px; margin-bottom: 4px; }
.ga-mode { padding: 12px 13px; border-radius: 11px; background: var(--stone-900, #211c16); border: 1px solid var(--stone-700, #4a4236); }
.ga-mode h4 { display: flex; align-items: center; gap: 6px; margin: 0 0 6px; font-family: var(--font-display, inherit); font-size: 15px;
  color: var(--parch-100, #f4ead6); }
.ga-mode h4 .msym { color: var(--torch, #e0a060); }
.ga-mode p { margin: 0; font-size: 13.5px; }

.ga-table-zone { overflow-x: auto; margin: 0 0 10px; border-radius: 10px; border: 1px solid var(--stone-700, #4a4236); }
.ga-table { width: 100%; border-collapse: collapse; font-size: 13px; background: var(--stone-900, #211c16); }
.ga-table th, .ga-table td { padding: 7px 9px; text-align: left; border-bottom: 1px solid var(--stone-800, #322b22); vertical-align: top; }
/* Les en-têtes peuvent se couper (« Att. » / « dist. ») : sur un téléphone, un en-tête serré collait à son voisin. */
.ga-table th { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: var(--ink-400, #a89c85);
  background: var(--stone-850, #2a241c); }
.ga-table tbody tr:last-child td { border-bottom: none; }
.ga-table .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
.ga-table .ga-nom { font-weight: 700; color: var(--parch-100, #f4ead6); min-width: 8em; }
.ga-table .ga-puiss { color: var(--gold, #d8a23a); font-weight: 800; }
.ga-table .ga-des { font-weight: 800; color: var(--parch-100, #f4ead6); }
.ga-tag { display: inline-flex; margin-left: 6px; padding: 1px 7px; border-radius: 99px; font-size: 10.5px; font-weight: 700;
  color: var(--ink-400, #a89c85); border: 1px solid var(--stone-600, #5a5044); white-space: nowrap; vertical-align: 1px; }
.ga-issue-piege .ga-des { color: var(--danger, #e0735c); }
.ga-issue-objet .ga-des { color: var(--elem-water, #6fb6ff); }
.ga-issue-or .ga-des { color: var(--gold, #d8a23a); }

@media (max-width: 480px) {
  .ga-bloc { padding: 13px 12px 12px; }
  .ga-table { font-size: 12.5px; }
  .ga-table th, .ga-table td { padding: 6px 6px; }
  .ga-table .ga-nom { min-width: 0; }
}
</style>
