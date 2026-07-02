<script setup>
// Onglet Sac (équipement, inventaire, forge du Nain) — port de SacTab (manette-app.jsx).
import MSym from '../ui/MSym.vue';
import ChoiceCard from './ChoiceCard.vue';
import { BACKPACK, FORGE_CAT, RAR_LABEL } from '../../data/demo';

defineProps({
    hero: { type: Object, required: true },
    // Potions RÉELLES du héros (/moi.consommables) — [] en démo.
    potions: { type: Array, default: () => [] },
    potionEnCours: { type: Boolean, default: false },
});
const emit = defineEmits(['boire']);
</script>

<template>
    <div>
        <div class="sect-title"><MSym n="checkroom" :size="16" /> Équipement</div>
        <div class="slots">
            <div class="slot"><span class="ic"><MSym n="swords" /></span><div><div class="sn">Arme</div><div class="iv">{{ hero.gear.arme }}</div></div></div>
            <div class="slot"><span class="ic"><MSym n="shield" /></span><div><div class="sn">Armure</div><div class="iv">{{ hero.gear.armure }}</div></div></div>
            <div class="slot"><span class="ic"><MSym n="backpack" /></span><div><div class="sn">Sac</div><div class="iv">{{ hero.gear.sac }}</div></div></div>
            <div class="slot"><span class="ic"><MSym n="science" /></span><div><div class="sn">Consommables</div><div class="iv">{{ hero.gear.conso }}</div></div></div>
        </div>

        <!-- Potions réelles : action gratuite jouable À TOUT MOMENT (canon) -->
        <template v-if="potions.length">
            <div class="sect-title"><MSym n="science" :size="16" /> Potions</div>
            <p style="font-size: 12px; color: var(--ink-500); margin: 0 0 10px">
                Buvable à tout moment — même hors de ton tour.
            </p>
            <div v-for="p in potions" :key="p.inventaire_id" class="item">
                <span class="ic"><MSym n="science" /></span>
                <div><div class="nm">{{ p.nom }}</div><div class="rar">×{{ p.quantite }}</div></div>
                <button
                    class="sac-boire"
                    :disabled="potionEnCours"
                    @click="emit('boire', p.inventaire_id)"
                >Boire</button>
            </div>
        </template>

        <div class="sect-title"><MSym n="inventory_2" :size="16" /> Sac à dos</div>
        <div v-for="(it, i) in BACKPACK" :key="i" class="item">
            <span class="ic"><MSym :n="it.icon" /></span>
            <div><div class="nm">{{ it.name }}</div><div class="rar" :class="'rar-' + it.rar">{{ RAR_LABEL[it.rar] }}</div></div>
            <span class="qty" style="margin-left: auto; font-weight: 700; color: var(--ink-300)">×{{ it.qty }}</span>
        </div>

        <template v-if="hero.isSmith">
            <div class="sect-title" style="margin-top: 18px"><MSym n="hardware" :size="16" /> Forge du Nain</div>
            <p style="font-size: 12.5px; color: var(--ink-500); margin: 0 0 12px">Choisis une amélioration du catalogue.</p>
            <div class="choices">
                <ChoiceCard v-for="f in FORGE_CAT" :key="f.id" icon="build" :title="f.name" :meta="`${f.desc} · ${f.price} or`" />
            </div>
        </template>
    </div>
</template>

<style scoped>
.sac-boire {
    margin-left: auto;
    padding: 7px 16px;
    border: 0;
    border-radius: 9px;
    background: var(--gold, #c9a24a);
    color: #1a1204;
    font-weight: 700;
    font-size: 13px;
    cursor: pointer;
}
.sac-boire:disabled { opacity: 0.5; cursor: default; }
</style>
