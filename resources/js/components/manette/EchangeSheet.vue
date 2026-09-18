<script setup>
// SÉANCE D'ÉCHANGE (révision R3, 2026-09-17, doc plan-echange-et-jeter §R3).
//
// La première livraison avait importé la forme du don au hub (unidirectionnel,
// une pièce = une action). René l'a refusée : le canon dit « transférer
// armes/armures ENTRE LES DEUX inventaires, dans la limite des capacités
// RESPECTIVES » — pluriel, bidirectionnel, pour UNE action. D'où la séance :
// les deux sacs, des piles déplaçables dans les deux sens, une quantité par
// pile, et une seule validation qui envoie TOUT d'un coup
// ({cle, transferts: [{inventaire_id, vers_personnage_id, quantite}]}).
//
// ⚠ Le client ADDITIONNE DES ENTIERS publiés (`encombrant` PAR PIÈCE,
// `ma_capacite`/`sa_capacite` : {occupation, max}) — il ne re-déduit JAMAIS
// qu'un consommable ne compte pas. C'est exactement le terrain de la règle
// enfreinte cinq fois en une semaine (2026-09-11/12) dans ce dossier.
//
// ⚠ Le seuil n'est PAS `final ≤ capacité` (corrigé au contrat le 2026-09-17,
// APRÈS la première passe de ce fichier) : un sac peut être LÉGITIMEMENT en
// dépassement (un butin de quête passe outre la capacité), et `DonObjet` dit
// que donner sert justement à régulariser. La vraie règle, « final ≤ capacité
// OU final ≤ occupation de départ », se résume à un seul seuil — voir
// `seuil()` — et cette somme est EXACTE PAR CONSTRUCTION : contrairement à
// l'aperçu du marché ou d'un sort, ce n'est le jugement d'AUCUNE règle de jeu
// que le serveur pourrait faire autrement, seulement l'addition des mêmes
// entiers qu'il validera. Le bouton « Valider » PEUT donc en dépendre (« le
// menu ne propose jamais ce que le résolveur refusera ») — mais l'état a pu
// bouger depuis le chargement de la séance : le refus 422 du serveur reste
// la dernière autorité, géré comme n'importe quel autre choix par
// `envoyerOption()`.
import { computed, reactive, watch } from 'vue';
import MSym from '../ui/MSym.vue';

const props = defineProps({
    /** Frame de la pile : { option, allie, retour }. `allie` est l'entrée
     *  choisie dans parametres.allies[] : { cle: "heros:{id}", nom,
     *  mon_sac: [{inventaire_id, nom, quantite, encombrant}], son_sac: [...],
     *  ma_capacite: {occupation, max}, sa_capacite: {occupation, max} }. */
    feuille: { type: Object, required: true },
    /** Mon nom — l'allié porte déjà le sien dans `feuille.allie.nom`. */
    monNom: { type: String, default: 'Toi' },
    /** Mon id de héros — c'est `vers_personnage_id` pour tout ce qui arrive
     *  DE son sac VERS le mien. */
    monPersonnageId: { type: [Number, String], default: null },
});

const emit = defineEmits(['valider', 'retour']);

const allie = computed(() => props.feuille.allie ?? {});
const monSac = computed(() => allie.value.mon_sac ?? []);
const sonSac = computed(() => allie.value.son_sac ?? []);

/** `heros:{id}` → id numérique — seul format publié par le contrat. */
const allieId = computed(() => Number(String(allie.value.cle ?? '').split(':')[1]));

/* Quantité choisie à DÉPLACER par pile, clé = inventaire_id, 0 = inchangée.
   Toutes les piles sont pré-remplies à 0 : un `v-model` sur une clé absente
   d'un objet réactif afficherait `undefined` plutôt qu'un champ à zéro. */
const mouvements = reactive({});
watch(() => props.feuille, () => {
    for (const k of Object.keys(mouvements)) delete mouvements[k];
    for (const p of [...monSac.value, ...sonSac.value]) mouvements[p.inventaire_id] = 0;
}, { immediate: true });

function qte(pile) { return mouvements[pile.inventaire_id] ?? 0; }
function inc(pile) { mouvements[pile.inventaire_id] = Math.min(pile.quantite, qte(pile) + 1); }
function dec(pile) { mouvements[pile.inventaire_id] = Math.max(0, qte(pile) - 1); }
/** Borne la saisie libre (min 0, max la pile) — le serveur revalide de toute
 *  façon chaque `inventaire_id` et sa quantité contre la ligne en base. */
function clamp(pile) {
    mouvements[pile.inventaire_id] = Math.max(0, Math.min(pile.quantite, Math.round(Number(mouvements[pile.inventaire_id])) || 0));
}

/* Total ENCOMBRANT qui bouge dans un sens — simple somme d'entiers publiés
   (`p.encombrant`), jamais un jugement local sur ce qui compte. */
function sommeEncombrante(sac) {
    return sac.reduce((total, p) => total + (p.encombrant ? qte(p) : 0), 0);
}
const versLui = computed(() => sommeEncombrante(monSac.value)); // quitte mon sac, entre dans le sien
const versMoi = computed(() => sommeEncombrante(sonSac.value)); // quitte son sac, entre dans le mien

const projMoi = computed(() => (allie.value.ma_capacite?.occupation ?? 0) - versLui.value + versMoi.value);
const projLui = computed(() => (allie.value.sa_capacite?.occupation ?? 0) - versMoi.value + versLui.value);

/**
 * Seuil réel de non-dépassement pour UN sac : `final ≤ max OU final ≤
 * occupation de départ` se résume à `final ≤ max(max, occupation)` — quand le
 * sac tenait déjà dans sa capacité (occupation ≤ max), le seuil EST la
 * capacité ; quand il débordait déjà (butin de quête), le seuil devient
 * l'occupation de départ, c'est-à-dire « ne pas aggraver » plutôt que
 * « rentrer dans les clous ». Un seul héros au sac déjà chargé ne se
 * retrouve donc jamais interdit de séance pour un état qu'elle vient
 * justement améliorer.
 */
function seuil(cap) {
    const max = Number.isFinite(cap?.max) ? cap.max : null;
    const occ = Number.isFinite(cap?.occupation) ? cap.occupation : null;
    if (max === null) return occ;
    if (occ === null) return max;
    return Math.max(max, occ);
}
function deborde(proj, cap) {
    const s = seuil(cap);
    return s !== null && proj > s;
}
const moiDeborde = computed(() => deborde(projMoi.value, allie.value.ma_capacite));
const luiDeborde = computed(() => deborde(projLui.value, allie.value.sa_capacite));
/** De COMBIEN ça déborde — le grisage du bouton doit dire pourquoi, jamais
 *  se contenter de refuser (règle du grisage causal, 2026-09-14). */
const excesMoi = computed(() => (moiDeborde.value ? projMoi.value - seuil(allie.value.ma_capacite) : 0));
const excesLui = computed(() => (luiDeborde.value ? projLui.value - seuil(allie.value.sa_capacite) : 0));
const raisonDebordement = computed(() => {
    const raisons = [];
    if (moiDeborde.value) raisons.push(`ton sac déborderait de ${excesMoi.value}`);
    if (luiDeborde.value) raisons.push(`le sac de ${allie.value.nom} déborderait de ${excesLui.value}`);
    return raisons.join(' et ');
});

/** Le corps de la requête : un triplet par pile touchée, dans le bon sens. */
const transferts = computed(() => [
    ...monSac.value.filter((p) => qte(p) > 0).map((p) => ({
        inventaire_id: p.inventaire_id, vers_personnage_id: allieId.value, quantite: qte(p),
    })),
    ...sonSac.value.filter((p) => qte(p) > 0).map((p) => ({
        inventaire_id: p.inventaire_id, vers_personnage_id: props.monPersonnageId, quantite: qte(p),
    })),
]);

function valider() {
    if (!transferts.value.length) return;
    emit('valider', transferts.value);
}
</script>

<template>
    <div class="overlay" @click.self="emit('retour')">
        <div class="sheet">
            <div class="grip" />
            <h3><MSym n="swap_horiz" fill :size="20" style="color: var(--torch); vertical-align: -3px" /> Échanger avec {{ allie.nom }}</h3>
            <p class="sh-sub">Déplace des pièces dans les deux sens, puis valide une seule fois — la séance coûte l'action.</p>

            <!-- Aperçu d'encombrement EN DIRECT (R3) : deux entiers publiés par
                 le serveur, additionnés ici, jamais réinterprétés. -->
            <div class="ech-capas">
                <div class="ech-capa" :class="{ 'ech-trop': moiDeborde }">
                    <span class="ech-capa-nom">{{ monNom }}</span>
                    <span class="ech-capa-val">{{ projMoi }}<template v-if="Number.isFinite(allie.ma_capacite?.max)">/{{ allie.ma_capacite.max }}</template></span>
                </div>
                <MSym n="sync_alt" :size="18" class="ech-capa-sep" />
                <div class="ech-capa" :class="{ 'ech-trop': luiDeborde }">
                    <span class="ech-capa-nom">{{ allie.nom }}</span>
                    <span class="ech-capa-val">{{ projLui }}<template v-if="Number.isFinite(allie.sa_capacite?.max)">/{{ allie.sa_capacite.max }}</template></span>
                </div>
            </div>
            <p v-if="raisonDebordement" class="ech-alerte">
                <MSym n="warning" :size="14" /> Validation impossible : {{ raisonDebordement }}.
            </p>

            <!-- mon sac → lui -->
            <div class="ech-titre-sac"><MSym n="arrow_forward" :size="14" /> {{ monNom }} donne</div>
            <p v-if="!monSac.length" class="empty-note">Ton sac ne porte rien à donner.</p>
            <div v-for="p in monSac" :key="`m-${p.inventaire_id}`" class="ech-pile">
                <div class="ech-pile-info">
                    <span class="ech-pile-nom">{{ p.nom }}</span>
                    <span class="ech-pile-qte">
                        ×{{ p.quantite }}<span v-if="p.encombrant" class="ech-enc"> · encombrant</span>
                    </span>
                </div>
                <div class="ech-stepper">
                    <button type="button" class="btn btn-ghost btn-sm" :disabled="qte(p) <= 0" @click="dec(p)"><MSym n="remove" /></button>
                    <input
                        v-model.number="mouvements[p.inventaire_id]"
                        class="ech-champ" type="number" inputmode="numeric"
                        min="0" :max="p.quantite" @change="clamp(p)"
                    >
                    <button type="button" class="btn btn-ghost btn-sm" :disabled="qte(p) >= p.quantite" @click="inc(p)"><MSym n="add" /></button>
                </div>
            </div>

            <!-- son sac → moi -->
            <div class="ech-titre-sac"><MSym n="arrow_back" :size="14" /> {{ allie.nom }} donne</div>
            <p v-if="!sonSac.length" class="empty-note">Son sac ne porte rien à recevoir.</p>
            <div v-for="p in sonSac" :key="`s-${p.inventaire_id}`" class="ech-pile">
                <div class="ech-pile-info">
                    <span class="ech-pile-nom">{{ p.nom }}</span>
                    <span class="ech-pile-qte">
                        ×{{ p.quantite }}<span v-if="p.encombrant" class="ech-enc"> · encombrant</span>
                    </span>
                </div>
                <div class="ech-stepper">
                    <button type="button" class="btn btn-ghost btn-sm" :disabled="qte(p) <= 0" @click="dec(p)"><MSym n="remove" /></button>
                    <input
                        v-model.number="mouvements[p.inventaire_id]"
                        class="ech-champ" type="number" inputmode="numeric"
                        min="0" :max="p.quantite" @change="clamp(p)"
                    >
                    <button type="button" class="btn btn-ghost btn-sm" :disabled="qte(p) >= p.quantite" @click="inc(p)"><MSym n="add" /></button>
                </div>
            </div>

            <!-- Une SEULE validation, pour toute la séance (R3) : pas d'accord
                 demandé à l'allié (choix assumé, canon = deux joueurs à la
                 même table), et pas de second écran de confirmation — le
                 geste EST la confirmation, contrairement à `jeter` qui détruit
                 sans rien rendre.
                 ⚠ Grisé aussi sur `moiDeborde`/`luiDeborde` (2026-09-17) : cet
                 aperçu n'est le jugement d'aucune règle de jeu locale, juste la
                 somme des mêmes entiers que le serveur revalidera — « le menu
                 ne propose jamais ce que le résolveur refusera ». L'état a pu
                 bouger depuis l'ouverture de la séance : un 422 reste possible
                 malgré tout, et reste traité normalement par `envoyerOption`. -->
            <button
                class="btn btn-torch btn-block" style="margin-top: 14px"
                :disabled="!transferts.length || moiDeborde || luiDeborde"
                @click="valider"
            >
                <MSym n="swap_horiz" fill :size="18" /> Valider l'échange
            </button>
            <button class="btn btn-ghost btn-block" style="margin-top: 8px" @click="emit('retour')">
                <MSym n="arrow_back" :size="18" /> {{ feuille.retour ?? 'Retour aux alliés' }}
            </button>
        </div>
    </div>
</template>

<style scoped>
/* Préfixe `ech-` (règle du projet : beaucoup de <style> de SFC sont globaux,
   un nom générique comme `.pile` ou `.capa` fuirait sur d'autres écrans). */
.ech-capas { display: flex; align-items: center; justify-content: center; gap: 10px; margin: 0 0 4px; }
.ech-capa { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 2px;
  padding: 8px 10px; border-radius: var(--r-md); background: var(--stone-850); border: var(--line); }
.ech-capa-nom { font-size: 11px; font-weight: 700; color: var(--ink-500); text-transform: uppercase; letter-spacing: 0.04em; }
.ech-capa-val { font-size: 17px; font-weight: 800; font-variant-numeric: tabular-nums; color: var(--ink-100); }
.ech-capa.ech-trop .ech-capa-val { color: oklch(0.75 0.16 30); }
.ech-capa-sep { color: var(--ink-500); flex: none; }
.ech-alerte { display: flex; align-items: center; gap: 6px; margin: 6px 0 12px; font-size: 12px; color: oklch(0.78 0.14 30); }

.ech-titre-sac { display: flex; align-items: center; gap: 6px; margin: 14px 0 8px;
  font-size: 11px; font-weight: 800; letter-spacing: 0.09em; text-transform: uppercase; color: var(--torch); }
.ech-titre-sac:first-of-type { margin-top: 6px; }

.ech-pile { display: flex; align-items: center; gap: 10px; padding: 9px 12px; margin-bottom: 6px;
  border-radius: var(--r-sm); background: var(--stone-850); border: var(--line); }
.ech-pile-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.ech-pile-nom { font-size: 13.5px; font-weight: 600; color: var(--ink-100); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ech-pile-qte { font-size: 11px; color: var(--ink-500); }
.ech-enc { color: var(--torch); }

.ech-stepper { flex: none; display: flex; align-items: center; gap: 6px; }
.ech-champ {
    width: 44px; padding: 6px 2px; text-align: center;
    font-size: 15px; font-weight: 800; font-variant-numeric: tabular-nums;
    color: var(--ink-100); background: var(--stone-900); border: var(--line-strong);
    border-radius: var(--r-sm);
}
.ech-champ::-webkit-outer-spin-button, .ech-champ::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.ech-champ { appearance: textfield; }
</style>
