<script setup>
// Onglet Marché (phase de ville) — port de MarketTab (manette-app.jsx).
import MSym from '../ui/MSym.vue';
import { SHOP, RAR_LABEL } from '../../data/demo';

defineProps({
    hero: { type: Object, required: true },
    gold: { type: Number, required: true },
    basket: { type: Array, required: true },
    projected: { type: Number, required: true },
});

const emit = defineEmits(['toggle']);
</script>

<template>
    <div>
        <div class="turn-banner mine" style="justify-content: space-between">
            <span class="phase-pill"><MSym n="storefront" :size="16" fill /> Phase de marché</span>
            <span style="display: flex; align-items: center; gap: 5px; color: var(--gold)"><MSym n="paid" :size="16" />{{ gold }} or</span>
        </div>
        <div class="sect-title"><MSym n="sell" :size="16" /> Échoppe</div>
        <div v-for="it in SHOP" :key="it.id" class="item">
            <span class="ic" :style="it.rar === 'unique' ? { color: 'var(--rar-unique)' } : {}"><MSym :n="it.icon" :fill="it.rar === 'unique'" /></span>
            <div><div class="nm">{{ it.name }}</div><div class="rar" :class="'rar-' + it.rar">{{ RAR_LABEL[it.rar] }}</div></div>
            <span class="price"><MSym n="paid" :size="15" />{{ it.price }}</span>
            <button
                class="btn btn-sm"
                :class="basket.includes(it.id) ? 'btn-torch' : 'btn-ghost'"
                style="margin-left: 10px"
                @click="emit('toggle', it.id)"
            >
                <MSym :n="basket.includes(it.id) ? 'check' : 'add'" :size="16" />
            </button>
        </div>

        <div class="basket-foot">
            <div class="row"><span><span class="tag-name">{{ hero.name.split(' ')[0] }}</span> · panier ({{ basket.length }})</span><span>{{ projected }} or</span></div>
            <div class="row"><span>Marchandage du Nain</span><span style="color: var(--ok)">−10%</span></div>
            <div class="row total"><span>Total projeté</span><span>{{ Math.round(projected * 0.9) }} or</span></div>
            <button class="btn btn-torch btn-block" style="margin-top: 12px" :disabled="!basket.length">
                <MSym n="shopping_cart_checkout" /> Confirmer l'achat
            </button>
        </div>
    </div>
</template>
