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
                    {{ it.rarLabel }} · {{ it.stock > 0 ? `stock ${it.stock - quantiteDe(it.id)}` : 'épuisé' }}
                </div>
            </div>
            <span class="price"><MSym n="paid" :size="15" />{{ it.price }}</span>
            <div class="qty-ctl">
                <template v-if="quantiteDe(it.id) > 0">
                    <button class="btn btn-sm btn-ghost" @click="emit('qty', it.id, -1)"><MSym n="remove" :size="16" /></button>
                    <span class="qv">{{ quantiteDe(it.id) }}</span>
                </template>
                <button
                    class="btn btn-sm"
                    :class="quantiteDe(it.id) > 0 ? 'btn-torch' : 'btn-ghost'"
                    :disabled="quantiteDe(it.id) >= it.stock"
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
            <div class="row total"><span>Total projeté (groupe)</span><span>{{ live.totalProjete }} or</span></div>
            <p v-if="live.erreur" class="mk-err">{{ live.erreur }}</p>
            <button
                v-if="!live.confirme"
                class="btn btn-torch btn-block"
                style="margin-top: 12px"
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
.qty-ctl .qv { min-width: 18px; text-align: center; font-weight: 800; font-variant-numeric: tabular-nums; color: var(--parch-100); }
.mk-vide { font-size: 12.5px; color: var(--ink-500); font-style: italic; margin: 2px 2px 10px; }
.mk-err { font-size: 12px; color: var(--danger, #c33); margin: 8px 0 0; }
</style>
