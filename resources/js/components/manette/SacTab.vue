<script setup>
// Onglet Sac (équipement, inventaire) — port de SacTab (manette-app.jsx).
import MSym from '../ui/MSym.vue';
import { RARETE_LABELS, rareteVersCle } from '../../store/game';

defineProps({
    // Équipement réel (/moi.equipement) : armes/armure portent {inventaire_id, nom},
    // chaque objet du sac porte `equipable` (pièce montable dans un slot).
    equipement: { type: Object, default: () => ({ armes: [], armure: null, sac: [] }) },
    // Potions réelles du héros (/moi.consommables).
    potions: { type: Array, default: () => [] },
    potionEnCours: { type: Boolean, default: false },
    // Gérer l'équipement n'est possible qu'au hub (entre deux quêtes).
    auHub: { type: Boolean, default: false },
    // Un appel équiper/déséquiper est en cours (gèle les boutons).
    equipEnCours: { type: Boolean, default: false },
});
const emit = defineEmits(['boire', 'equiper', 'desequiper']);
</script>

<template>
    <div>
        <div class="sect-title"><MSym n="checkroom" :size="16" /> Équipement</div>

        <!-- pièces équipées : chacune déséquipable au hub -->
        <div v-if="equipement.armes.length" v-for="a in equipement.armes" :key="`arme-${a.inventaire_id}`" class="item">
            <span class="ic"><MSym n="swords" /></span>
            <div><div class="nm">{{ a.nom }}</div><div class="rar">Arme équipée</div></div>
            <button v-if="auHub" class="sac-btn ghost" :disabled="equipEnCours" @click="emit('desequiper', a.inventaire_id)">Déséquiper</button>
        </div>
        <div v-if="equipement.armure" class="item">
            <span class="ic"><MSym n="shield" /></span>
            <div><div class="nm">{{ equipement.armure.nom }}</div><div class="rar">Armure équipée</div></div>
            <button v-if="auHub" class="sac-btn ghost" :disabled="equipEnCours" @click="emit('desequiper', equipement.armure.inventaire_id)">Déséquiper</button>
        </div>
        <div v-if="!equipement.armes.length && !equipement.armure" class="slots">
            <div class="slot">
                <span class="ic"><MSym n="swords" /></span>
                <div><div class="sn">Arme</div><div class="iv">Aucune</div></div>
            </div>
            <div class="slot">
                <span class="ic"><MSym n="shield" /></span>
                <div><div class="sn">Armure</div><div class="iv">Aucune</div></div>
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
                    class="sac-btn gold"
                    :disabled="potionEnCours"
                    @click="emit('boire', p.inventaire_id)"
                >Boire</button>
            </div>
        </template>

        <div class="sect-title"><MSym n="inventory_2" :size="16" /> Sac à dos</div>
        <div v-for="it in equipement.sac" :key="it.inventaire_id" class="item">
            <span class="ic"><MSym n="inventory_2" /></span>
            <div><div class="nm">{{ it.nom }}</div><div class="rar" :class="'rar-' + rareteVersCle(it.rarete)">{{ RARETE_LABELS[rareteVersCle(it.rarete)] }}</div></div>
            <button
                v-if="it.equipable && auHub"
                class="sac-btn gold"
                :disabled="equipEnCours"
                @click="emit('equiper', it.inventaire_id)"
            >Équiper</button>
            <span v-else class="qty" style="margin-left: auto; font-weight: 700; color: var(--ink-300)">×{{ it.quantite }}</span>
        </div>
        <p v-if="!equipement.sac.length" class="empty-note">Le sac est vide.</p>
    </div>
</template>

<style scoped>
.sac-btn {
    margin-left: auto;
    padding: 7px 16px;
    border: 0;
    border-radius: 9px;
    font-weight: 700;
    font-size: 13px;
    cursor: pointer;
}
.sac-btn.gold { background: var(--gold, #c9a24a); color: #1a1204; }
.sac-btn.ghost {
    background: transparent;
    color: var(--ink-300, #cfc3ad);
    border: 1px solid var(--line-soft, oklch(0.4 0.02 70 / 0.5));
}
.sac-btn:disabled { opacity: 0.5; cursor: default; }
</style>
