<script setup>
// Onglet Sac (équipement, inventaire) — port de SacTab (manette-app.jsx).
import { computed, ref } from 'vue';
import MSym from '../ui/MSym.vue';
import { RARETE_LABELS, rareteVersCle } from '../../store/game';

const props = defineProps({
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
    // Autres héros actifs du groupe : destinataires possibles d'un don.
    compagnons: { type: Array, default: () => [] },
});
const emit = defineEmits(['boire', 'equiper', 'desequiper', 'donner']);

/* ---- Don d'un objet à un compagnon (hub, doc 01 §7). Le sélecteur s'ouvre
   sous la ligne concernée plutôt que dans une modale : le sac est déjà un
   panneau, en empiler un second sur un écran de téléphone ferait perdre le
   contexte de l'objet qu'on donne. ---- */
const donEnCours = ref(null); // inventaire_id dont le sélecteur est ouvert

function basculerDon(inventaireId) {
    donEnCours.value = donEnCours.value === inventaireId ? null : inventaireId;
}

function confirmerDon(inventaireId, versPersonnageId, quantite = 1) {
    donEnCours.value = null;
    emit('donner', { inventaireId, versPersonnageId, quantite });
}

// Un don n'a de sens qu'au hub, et qu'avec au moins un compagnon.
const peutDonner = computed(() => props.auHub && props.compagnons.length > 0);

// Sac en dépassement : un butin de quête est remis MÊME sac plein (refuser un
// artefact unique le perdrait à jamais). Le héros régularise en équipant la
// pièce — elle quitte le sac — ou en écoulant au marché.
const deborde = computed(() => {
    const cap = props.equipement?.capacite;
    const occ = props.equipement?.occupation;
    return Number.isFinite(cap) && Number.isFinite(occ) && occ > cap;
});
</script>

<template>
    <div>
        <div class="sect-title"><MSym n="checkroom" :size="16" /> Équipement</div>

        <!-- pièces équipées : chacune déséquipable au hub -->
        <div v-if="equipement.armes.length" v-for="a in equipement.armes" :key="`arme-${a.inventaire_id}`" class="item">
            <span class="ic"><MSym :n="a.bouclier ? 'shield' : 'swords'" /></span>
            <div>
                <div class="nm">{{ a.nom }}</div>
                <div class="rar">{{ a.bouclier ? 'Bouclier équipé' : 'Arme équipée' }}</div>
            </div>
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
            <template v-for="p in potions" :key="p.inventaire_id">
                <div class="item">
                    <span class="ic"><MSym n="science" /></span>
                    <div><div class="nm">{{ p.nom }}</div><div class="rar">×{{ p.quantite }}</div></div>
                    <button
                        v-if="peutDonner"
                        class="sac-btn ghost don-ic"
                        :disabled="equipEnCours"
                        title="Donner à un compagnon"
                        @click="basculerDon(p.inventaire_id)"
                    ><MSym n="volunteer_activism" :size="18" /></button>
                    <button
                        class="sac-btn gold"
                        :disabled="potionEnCours"
                        @click="emit('boire', p.inventaire_id)"
                    >Boire</button>
                </div>
                <div v-if="donEnCours === p.inventaire_id" class="don-cible">
                    <span class="don-lbl">Donner 1 × {{ p.nom }} à</span>
                    <button
                        v-for="c in compagnons"
                        :key="c.id"
                        class="sac-btn ghost"
                        :disabled="equipEnCours"
                        @click="confirmerDon(p.inventaire_id, c.id, 1)"
                    >{{ c.nom }}</button>
                </div>
            </template>
        </template>

        <div class="sect-title">
            <MSym n="inventory_2" :size="16" /> Sac à dos
            <span v-if="Number.isFinite(equipement.capacite)" class="sac-charge" :class="{ trop: deborde }">
                {{ equipement.occupation }}/{{ equipement.capacite }}
            </span>
        </div>
        <p v-if="deborde" class="sac-alerte">
            <MSym n="warning" :size="14" /> Sac en dépassement — équipe une pièce ou écoule-la au marché.
        </p>
        <template v-for="it in equipement.sac" :key="it.inventaire_id">
            <div class="item">
                <span class="ic"><MSym n="inventory_2" /></span>
                <div><div class="nm">{{ it.nom }}</div><div class="rar" :class="'rar-' + rareteVersCle(it.rarete)">{{ RARETE_LABELS[rareteVersCle(it.rarete)] }}</div></div>
                <button
                    v-if="peutDonner"
                    class="sac-btn ghost don-ic"
                    :disabled="equipEnCours"
                    title="Donner à un compagnon"
                    @click="basculerDon(it.inventaire_id)"
                ><MSym n="volunteer_activism" :size="18" /></button>
                <button
                    v-if="it.equipable && auHub"
                    class="sac-btn gold"
                    :disabled="equipEnCours"
                    @click="emit('equiper', it.inventaire_id)"
                >Équiper</button>
                <span v-else-if="!peutDonner" class="qty" style="margin-left: auto; font-weight: 700; color: var(--ink-300)">×{{ it.quantite }}</span>
            </div>
            <div v-if="donEnCours === it.inventaire_id" class="don-cible">
                <span class="don-lbl">Donner {{ it.nom }} à</span>
                <button
                    v-for="c in compagnons"
                    :key="c.id"
                    class="sac-btn ghost"
                    :disabled="equipEnCours"
                    @click="confirmerDon(it.inventaire_id, c.id, 1)"
                >{{ c.nom }}</button>
            </div>
        </template>
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
.sac-charge { margin-left: auto; font-size: 12px; font-weight: 700; color: var(--ink-500, #8d8574); }
/* Don : le bouton icône reste collé à droite, l'éventuel « Équiper » le suit. */
.don-ic { padding: 7px 10px; display: inline-flex; align-items: center; }
.don-ic + .sac-btn { margin-left: 8px; }
.don-cible {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin: -4px 0 10px;
    padding: 8px 10px;
    border-radius: 9px;
    background: var(--panel-2, oklch(0.28 0.02 70 / 0.5));
}
.don-cible .sac-btn { margin-left: 0; }
.don-lbl { font-size: 12px; color: var(--ink-300, #cfc3ad); }
.sac-charge.trop { color: oklch(0.75 0.16 30); }
.sac-alerte {
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 0 0 10px;
    font-size: 12px;
    color: oklch(0.78 0.14 30);
}
</style>
