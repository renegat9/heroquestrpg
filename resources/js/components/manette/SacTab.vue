<script setup>
// Onglet Sac (équipement, inventaire) — port de SacTab (manette-app.jsx).
import MSym from '../ui/MSym.vue';
import { RARETE_LABELS, rareteVersCle } from '../../store/game';

defineProps({
    // Équipement réel (/moi.equipement) : {armes: [nom…], armure, sac: [...]}.
    equipement: { type: Object, default: () => ({ armes: [], armure: null, sac: [] }) },
    // Potions réelles du héros (/moi.consommables).
    potions: { type: Array, default: () => [] },
    potionEnCours: { type: Boolean, default: false },
});
const emit = defineEmits(['boire']);
</script>

<template>
    <div>
        <div class="sect-title"><MSym n="checkroom" :size="16" /> Équipement</div>
        <div class="slots">
            <div class="slot">
                <span class="ic"><MSym n="swords" /></span>
                <div><div class="sn">Arme</div><div class="iv">{{ equipement.armes.length ? equipement.armes.join(' + ') : 'Aucune' }}</div></div>
            </div>
            <div class="slot">
                <span class="ic"><MSym n="shield" /></span>
                <div><div class="sn">Armure</div><div class="iv">{{ equipement.armure ?? 'Aucune' }}</div></div>
            </div>
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
        <div v-for="it in equipement.sac" :key="it.inventaire_id" class="item">
            <span class="ic"><MSym n="inventory_2" /></span>
            <div><div class="nm">{{ it.nom }}</div><div class="rar" :class="'rar-' + rareteVersCle(it.rarete)">{{ RARETE_LABELS[rareteVersCle(it.rarete)] }}</div></div>
            <span class="qty" style="margin-left: auto; font-weight: 700; color: var(--ink-300)">×{{ it.quantite }}</span>
        </div>
        <p v-if="!equipement.sac.length" class="empty-note">Le sac est vide.</p>
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
