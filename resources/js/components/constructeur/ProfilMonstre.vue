<script setup>
// LE PROFIL D'AUTEUR d'un monstre posé ou apparu (lot K5m, R9 — René : « monstre existant avec sous-boss et boss, ou
// créé ») : chaque clé est FACULTATIVE et n'existe que remplie — une case vide retire la clé, jamais une valeur
// décorative. Les bornes sont celles de `ValidationCampagne::profilDeMonstre()`.
import { computed } from 'vue';
import MSym from '../ui/MSym.vue';

const props = defineProps({
    monstre: { type: Object, required: true },
    objets: { type: Array, required: true },
    pointer: { type: Function, required: true },
    libelle: { type: String, default: 'le monstre' },
});

const STATS = [
    ['deplacement', 'Déplacement', 0],
    ['attaque', 'Attaque', 0],
    ['defense', 'Défense', 1],
    ['pv_body', 'Corps', 1],
    ['pv_mind', 'Esprit', 0],
];
const LISTES = [
    ['vulnerable_uniquement_a', 'Seules ces armes le blessent'],
    ['invulnerable_casse_arme', 'Invulnérable — l\'arme qui frappe se brise, sauf'],
];

const m = computed(() => props.monstre);
function changerNom(v) {
    if (v.trim() === '') delete m.value.nom; else m.value.nom = v.trim();
}
function changerStat(cle, v) {
    if (v === '' || v === null) delete m.value[cle]; else m.value[cle] = Number(v);
}
function basculerListe(cle, avec) {
    if (avec) m.value[cle] = []; else delete m.value[cle];
}
function basculerObjet(cle, slug, avec) {
    const liste = m.value[cle];
    if (avec && !liste.includes(slug)) liste.push(slug);
    if (!avec) liste.splice(liste.indexOf(slug), 1);
}
function basculerFuite(avec) {
    if (avec) m.value.fuite = { x: m.value.x ?? 0, y: m.value.y ?? 0 }; else delete m.value.fuite;
}
</script>

<template>
    <div class="pm">
        <div class="cst-ligne">
            <label>Nom propre <input :value="m.nom ?? ''" class="cst-input" placeholder="(celui du catalogue)" @change="changerNom($event.target.value)" /></label>
            <label v-for="[cle, lib, min] in STATS" :key="cle">{{ lib }}
                <input :value="m[cle] ?? ''" type="number" :min="min" class="cst-input cst-num" placeholder="—" @change="changerStat(cle, $event.target.value)" />
            </label>
        </div>
        <div v-for="[cle, lib] in LISTES" :key="cle" class="cst-ligne">
            <label class="cst-case"><input type="checkbox" :checked="Array.isArray(m[cle])" @change="basculerListe(cle, $event.target.checked)" /> {{ lib }}</label>
            <template v-if="Array.isArray(m[cle])">
                <span v-for="slug in m[cle]" :key="slug" class="pm-puce">{{ objets.find((o) => o.slug === slug)?.nom ?? slug }}
                    <button type="button" class="cst-icone" title="Retirer" @click="basculerObjet(cle, slug, false)"><MSym n="close" :size="12" /></button>
                </span>
                <select class="cst-input" @change="basculerObjet(cle, $event.target.value, true); $event.target.value = ''">
                    <option value="">+ arme…</option>
                    <option v-for="o in objets.filter((o) => !m[cle].includes(o.slug))" :key="o.slug" :value="o.slug">{{ o.nom }}</option>
                </select>
            </template>
        </div>
        <div class="cst-ligne">
            <label class="cst-case"><input type="checkbox" :checked="!!m.fuite" @change="basculerFuite($event.target.checked)" /> Fuit vers un refuge</label>
            <template v-if="m.fuite">
                <input v-model.number="m.fuite.x" type="number" class="cst-input cst-num" title="x" />
                <input v-model.number="m.fuite.y" type="number" class="cst-input cst-num" title="y" />
                <button type="button" class="cst-icone" title="Choisir sur la carte" @click="pointer(m.fuite, `le refuge de ${libelle}`)"><MSym n="pin_drop" :size="16" /></button>
            </template>
        </div>
    </div>
</template>

<style scoped>
.pm { display: flex; flex-direction: column; gap: 4px; padding: 6px 0 2px 14px; border-left: 2px solid var(--stone-700); }
.pm-puce { display: inline-flex; align-items: center; gap: 2px; font-size: 12px; padding: 1px 6px; border-radius: 10px; background: var(--stone-800); }
</style>
