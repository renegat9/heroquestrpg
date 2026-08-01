<script setup>
// Onglet Marché (phase de ville) — port de MarketTab (manette-app.jsx).
// `live` (EtatMarche mappé, doc 04 §5 — saisie individuelle sur le
// téléphone : panier d'achats/ventes personnel, total projeté du groupe,
// confirmation).
import { computed } from 'vue';
import MSym from '../ui/MSym.vue';
import Vignette from '../ui/Vignette.vue';
import { PROFILS_MARCHE } from '../../store/game';

const props = defineProps({
    hero: { type: Object, required: true },
    /**
     * Forme (mappée dans ManetteView) :
     * { profil, or, items: [{id, name, rar, rarLabel, icon, price, stock}],
     *   inventaire: [{inventaire_id, name, rar, rarLabel, icon, revente}],
     *   achats: [{objet_id, quantite}], ventes: [inventaire_id…],
     *   confirme, confirmes, membres, totalAchats, totalVentes,
     *   totalProjete, erreur }
     */
    live: { type: Object, default: null },
});

const emit = defineEmits(['qty', 'vendre', 'confirmer']);

const profilLabel = computed(() => {
    const p = props.live?.profil;
    return PROFILS_MARCHE[p] ?? p ?? '';
});

function quantiteDe(objetId) {
    return props.live?.achats?.find((a) => a.objet_id === objetId)?.quantite ?? 0;
}

function estEnVente(inventaireId) {
    return (props.live?.ventes ?? []).includes(inventaireId);
}

/* ---- Badge « non maîtrisé » (doc 01 §7). Les maîtrises viennent de /moi,
   rendues par le MÊME service que le contrôle d'équipement — le badge ne peut
   donc pas annoncer autre chose que ce que le moteur appliquera.

   On n'empêche JAMAIS l'achat : chacun achète pour SOI (décision de René), et
   corrige après coup par un don au hub si la pièce échoit au mauvais héros. Le
   badge signale donc « tu ne pourras pas l'équiper », pas « n'achète pas ». ---- */
const maitrises = computed(() => props.hero?.maitrises ?? null);

function nonMaitrise(it) {
    // Pas de tag = aucune exigence ; maîtrises inconnues (ancien /moi) = pas de
    // badge, plutôt qu'un badge faux sur tout l'étal.
    if (!it.tag || maitrises.value === null) return false;

    return !maitrises.value.includes(it.tag);
}
</script>

<template>
    <!-- ============================ mode connecté ============================ -->
    <div v-if="live">
        <div class="turn-banner mine" style="justify-content: space-between">
            <span class="phase-pill">
                <MSym n="storefront" :size="16" fill /> Marché{{ profilLabel ? ' · ' + profilLabel : '' }}
            </span>
            <span style="display: flex; align-items: center; gap: 5px; color: var(--gold)">
                <MSym n="paid" :size="16" />{{ live.or }} or commun
            </span>
        </div>

        <!-- échoppe : inventaire du profil (prix × multiplicateur, stock) -->
        <div class="sect-title"><MSym n="sell" :size="16" /> Échoppe</div>
        <div v-for="it in live.items" :key="it.id" class="item">
            <span class="ic" :style="it.rar === 'unique' ? { color: 'var(--rar-unique)' } : {}"><Vignette :src="it.img" :icon="it.icon" :fill="it.rar === 'unique'" /></span>
            <div>
                <div class="nm">{{ it.name }}</div>
                <div class="rar" :class="'rar-' + it.rar">
                    {{ it.rarLabel }} · {{ it.stock === null ? 'en stock' : (it.stock > 0 ? `stock ${it.stock - quantiteDe(it.id)}` : 'épuisé') }}
                </div>
                <div
                    v-if="nonMaitrise(it)"
                    class="mk-nonmaitrise"
                    :title="`${hero.name ?? 'Ce héros'} ne peut pas équiper cette pièce — tu pourras la donner à un compagnon au hub.`"
                >
                    <MSym n="block" :size="12" /> Non maîtrisé
                </div>
            </div>
            <span class="price"><MSym n="paid" :size="15" />{{ it.price }}</span>
            <!-- Gabarit STABLE : les trois éléments existent toujours, seuls
                 « − » et la quantité s'effacent à 0. Avec un `v-if`, ils
                 s'inséraient AVANT le « + » au premier tap, qui reculait de
                 ~70 px sous le doigt — deux testeurs ont acheté des objets non
                 voulus (dont une armure à 850 or) et coché une vente par erreur. -->
            <div class="qty-ctl">
                <button
                    class="btn btn-sm btn-ghost qty-pas"
                    :class="{ 'qty-vide': quantiteDe(it.id) === 0 }"
                    :aria-hidden="quantiteDe(it.id) === 0"
                    :tabindex="quantiteDe(it.id) === 0 ? -1 : 0"
                    @click="emit('qty', it.id, -1)"
                ><MSym n="remove" :size="16" /></button>
                <span class="qv" :class="{ 'qty-vide': quantiteDe(it.id) === 0 }">{{ quantiteDe(it.id) }}</span>
                <button
                    class="btn btn-sm qty-pas"
                    :class="quantiteDe(it.id) > 0 ? 'btn-torch' : 'btn-ghost'"
                    :disabled="it.stock !== null && quantiteDe(it.id) >= it.stock"
                    @click="emit('qty', it.id, 1)"
                >
                    <MSym n="add" :size="16" />
                </button>
            </div>
        </div>
        <p v-if="!live.items.length" class="mk-vide">L'échoppe est vide ici.</p>

        <!-- ventes : inventaire du personnage du joueur (revente 50 %) -->
        <div class="sect-title" style="margin-top: 16px"><MSym n="currency_exchange" :size="16" /> Vendre (50 %)</div>
        <div v-for="it in live.inventaire" :key="it.inventaire_id" class="item">
            <span class="ic"><MSym :n="it.icon" /></span>
            <div><div class="nm">{{ it.name }}</div><div class="rar" :class="'rar-' + it.rar">{{ it.rarLabel }}</div></div>
            <span class="price" style="color: var(--ok)">+{{ it.revente }}</span>
            <button
                class="btn btn-sm"
                :class="estEnVente(it.inventaire_id) ? 'btn-torch' : 'btn-ghost'"
                style="margin-left: 10px"
                @click="emit('vendre', it.inventaire_id)"
            >
                <MSym :n="estEnVente(it.inventaire_id) ? 'check' : 'sell'" :size="16" />
            </button>
        </div>
        <p v-if="!live.inventaire.length" class="mk-vide">Ton sac ne contient rien à vendre.</p>

        <!-- récapitulatif personnel + total projeté du groupe -->
        <div class="basket-foot">
            <div class="row">
                <span><span class="tag-name">{{ live.pseudo ?? hero.name.split(' ')[0] }}</span> · achats</span>
                <span>−{{ live.totalAchats }} or</span>
            </div>
            <div class="row">
                <span>Ventes</span>
                <span style="color: var(--ok)">+{{ live.totalVentes }} or</span>
            </div>
            <div class="row">
                <span>Confirmations</span>
                <span>{{ live.confirmes }}/{{ live.membres }}</span>
            </div>
            <div class="row total">
                <span>Total projeté (groupe)</span>
                <span :style="{ color: live.totalProjete < 0 ? 'var(--danger, #c33)' : null }">{{ live.totalProjete }} or</span>
            </div>
            <p v-if="live.erreur" class="mk-err">{{ live.erreur }}</p>
            <!-- Panier insolvable : on bloque la confirmation côté client (le serveur
                 la refusait déjà à la finalisation, mais rien n'empêchait de cliquer). -->
            <p v-if="!live.confirme && live.totalProjete < 0" class="mk-err">
                Total du groupe négatif — retire des achats ou vends davantage avant de confirmer.
            </p>
            <button
                v-if="!live.confirme"
                class="btn btn-torch btn-block"
                style="margin-top: 12px"
                :disabled="live.totalProjete < 0"
                @click="emit('confirmer')"
            >
                <MSym n="shopping_cart_checkout" /> Confirmer mon panier
            </button>
            <div v-else class="waiting" style="justify-content: center; margin-top: 12px">
                <MSym n="hourglass_top" :size="16" /> Panier confirmé — en attente des autres joueurs…
            </div>
        </div>
    </div>
</template>

<style>
/* compléments marché (mode connecté) — mêmes tokens que manette.css */
.qty-ctl { margin-left: 10px; display: flex; align-items: center; gap: 6px; flex: none; }
/* Largeurs FIXES : `min-width` laissait encore la ligne bouger au passage de
   9 à 10, et un bouton sans largeur imposée change de taille avec son icône. */
.qty-ctl .qv { width: 22px; text-align: center; font-weight: 800; font-variant-numeric: tabular-nums; color: var(--parch-100); }
.qty-ctl .qty-pas { width: 42px; padding-left: 0; padding-right: 0; justify-content: center; }
/* Effacé mais TOUJOURS PRÉSENT : la place reste réservée, rien ne se décale. */
.qty-ctl .qty-vide { visibility: hidden; pointer-events: none; }
.mk-vide { font-size: 12.5px; color: var(--ink-500); font-style: italic; margin: 2px 2px 10px; }
/* Badge « non maîtrisé » : informatif, jamais bloquant — d'où un ton d'alerte
   sourd plutôt qu'un rouge d'erreur. Classe préfixée `mk-` : beaucoup de blocs
   <style> du projet sont globaux, un `.nonmaitrise` nu fuiterait ailleurs. */
.mk-nonmaitrise {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    margin-top: 3px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.02em;
    color: oklch(0.72 0.11 60);
}
.mk-err { font-size: 12px; color: var(--danger, #c33); margin: 8px 0 0; }
</style>
