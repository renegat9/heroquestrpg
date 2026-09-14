// Scènes illustrées de l'écran de table (`.table.scene`) : activation et durée
// d'affichage.
//
// Préférence de l'APPAREIL qui tient la table — celui dont on voit l'écran —,
// pas de la campagne : elle vit en localStorage, comme le volume et la voix du
// narrateur, et non en base. Deux tables ouvertes sur la même partie peuvent
// donc régler la leur différemment, ce qui est le comportement voulu.
//
// ⚠ La durée est ICI, côté client, et c'est délibéré (décision de René,
// 2026-09-14 : « revenir à la carte en cliquant sur l'écran du narrateur ou
// après un délai configurable dans les paramètres du narrateur, défaut 5 s »).
// La règle « le serveur publie la décision » porte sur les règles de JEU — une
// préférence d'affichage d'appareil n'en est pas une, et le serveur n'a aucun
// moyen de savoir à quelle vitesse cette tablée lit.
import { ref } from 'vue';

const CLE_STOCKAGE = 'table:scenes';

/** 5 s par défaut. Les bornes évitent le réglage qui casse l'écran : une scène
 *  qui clignote (< 2 s) ou qui campe sur la carte (> 20 s). */
export const DUREE_DEFAUT = 5000;
const DUREE_MIN = 2000;
const DUREE_MAX = 20000;

function charger() {
    try {
        const brut = localStorage.getItem(CLE_STOCKAGE);
        return brut ? JSON.parse(brut) : {};
    } catch { return {}; }
}

const prefs = charger();

const actives = ref(prefs.actives ?? true);
const duree = ref(
    typeof prefs.duree === 'number'
        ? Math.min(DUREE_MAX, Math.max(DUREE_MIN, prefs.duree))
        : DUREE_DEFAUT,
);

function sauver() {
    try {
        localStorage.setItem(CLE_STOCKAGE, JSON.stringify({
            actives: actives.value, duree: duree.value,
        }));
    } catch { /* stockage indisponible (navigation privée…) — best-effort */ }
}

function basculerActives() {
    actives.value = !actives.value;
    sauver();
}

function definirDuree(ms) {
    duree.value = Math.min(DUREE_MAX, Math.max(DUREE_MIN, Number(ms) || DUREE_DEFAUT));
    sauver();
}

export function useScenesTable() {
    return { actives, duree, basculerActives, definirDuree, DUREE_MIN, DUREE_MAX };
}
