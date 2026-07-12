<script setup>
// Recrutement d'alliés au hub (doc 14 §3.5) — bourse commune. Le joueur
// embauche un mercenaire/compagnon contre l'or COMMUN du groupe, avant une
// quête ; l'allié est un PNJ scripté consommé en fin de quête. La disponibilité
// (or suffisant, un seul animal) est calculée ici depuis l'état vivant du groupe.
import { computed } from 'vue';
import MSym from '../ui/MSym.vue';

const props = defineProps({
    // Catalogue recrutable (GET /mercenaires).
    catalogue: { type: Array, default: () => [] },
    // Alliés déjà recrutés (EtatGroupe.groupe.mercenaires au hub).
    recrues: { type: Array, default: () => [] },
    // Or de la bourse COMMUNE (EtatGroupe.groupe.or).
    or: { type: Number, default: 0 },
    // Un recrutement est en cours (gèle les boutons).
    enCours: { type: Boolean, default: false },
});
const emit = defineEmits(['recruter']);

const animalPris = computed(() => props.recrues.some((r) => r.animal));

// Motif de blocage d'une recrue (null = recrutable).
function blocage(m) {
    if (m.animal && animalPris.value) return 'Un seul compagnon animal';
    if (props.or < m.prix) return 'Or insuffisant';
    return null;
}

const TYPE_ICON = { archer: 'target', hallebardier: 'shield', compagnon: 'pets' };
</script>

<template>
    <div class="recrut">
        <div class="sect-title"><MSym n="groups" :size="16" /> Recruter un allié</div>
        <div class="recrut-bourse">
            <MSym n="paid" fill :size="15" /> Bourse commune : <b>{{ or }}</b> or
        </div>
        <p class="recrut-note">
            L'allié est un renfort scripté, embauché avec l'or du groupe et présent
            le temps d'une quête.
        </p>

        <div v-for="m in catalogue" :key="m.id" class="recrut-carte" :class="{ off: !!blocage(m) }">
            <div class="recrut-tete">
                <span class="recrut-ic"><MSym :n="TYPE_ICON[m.type] || 'swords'" /></span>
                <div class="recrut-nom">
                    <div class="rn">{{ m.nom }}</div>
                    <div class="rt">{{ m.animal ? 'Compagnon animal' : 'Mercenaire' }}</div>
                </div>
                <div class="recrut-prix"><MSym n="paid" fill :size="13" /> {{ m.prix }}</div>
            </div>
            <p class="recrut-desc">{{ m.description }}</p>
            <div class="recrut-stats">
                <span class="st"><MSym n="directions_run" :size="14" /> {{ m.deplacement }}</span>
                <span class="st">
                    <MSym :n="m.portee === 'distance' ? 'my_location' : 'swords'" :size="14" />
                    {{ m.portee === 'distance' ? m.attaque_distance : m.attaque }}
                </span>
                <span class="st"><MSym n="shield" :size="14" /> {{ m.defense }}</span>
                <span class="st"><MSym n="favorite" fill :size="14" /> {{ m.pv_body }}</span>
            </div>
            <button
                class="recrut-btn"
                :disabled="enCours || !!blocage(m)"
                @click="emit('recruter', m.id)"
            >
                <template v-if="blocage(m)">{{ blocage(m) }}</template>
                <template v-else><MSym n="handshake" :size="16" /> Recruter</template>
            </button>
        </div>

        <template v-if="recrues.length">
            <div class="sect-title"><MSym n="diversity_3" :size="16" /> Alliés recrutés</div>
            <div v-for="r in recrues" :key="r.id" class="recrut-recrue">
                <span class="recrut-ic"><MSym :n="TYPE_ICON[r.type] || 'swords'" /></span>
                <div class="recrut-nom"><div class="rn">{{ r.nom }}</div></div>
                <span class="recrut-pv"><MSym n="favorite" fill :size="13" /> {{ r.pv_body }}/{{ r.pv_body_max }}</span>
            </div>
        </template>
        <p v-else class="recrut-vide">Aucun allié recruté pour l'instant.</p>
    </div>
</template>

<style scoped>
.recrut { padding-top: 4px; }
.recrut-bourse {
    display: flex; align-items: center; gap: 6px;
    font-size: 14px; color: var(--gold, #c9a24a); font-weight: 700; margin: 0 0 6px;
}
.recrut-note {
    font-size: 12px; color: var(--ink-500); font-style: italic;
    margin: 0 0 14px;
}
.recrut-carte {
    border: 1px solid var(--line-soft, oklch(0.4 0.02 70 / 0.4));
    border-radius: 12px; padding: 12px; margin-bottom: 12px;
    background: var(--panel-2, oklch(0.22 0.02 70 / 0.4));
}
.recrut-carte.off { opacity: 0.6; }
.recrut-tete { display: flex; align-items: center; gap: 10px; }
.recrut-ic {
    display: grid; place-items: center; width: 34px; height: 34px; flex: none;
    border-radius: 9px; background: var(--panel-3, oklch(0.28 0.02 70 / 0.5));
    color: var(--gold, #c9a24a);
}
.recrut-nom { flex: 1; min-width: 0; }
.recrut-nom .rn { font-weight: 700; font-size: 14px; }
.recrut-nom .rt { font-size: 11px; color: var(--ink-500); text-transform: uppercase; letter-spacing: 0.04em; }
.recrut-prix {
    display: flex; align-items: center; gap: 4px; flex: none;
    font-weight: 800; color: var(--gold, #c9a24a); font-size: 15px;
}
.recrut-desc {
    font-family: var(--font-narr); font-style: italic; font-size: 13px;
    color: var(--ink-300, #cfc3ad); margin: 8px 0 10px; line-height: 1.35;
}
.recrut-stats { display: flex; gap: 14px; margin-bottom: 12px; }
.recrut-stats .st {
    display: flex; align-items: center; gap: 4px;
    font-size: 13px; font-weight: 700; color: var(--ink-300, #cfc3ad);
}
.recrut-btn {
    width: 100%; padding: 9px; border: 0; border-radius: 9px;
    display: flex; align-items: center; justify-content: center; gap: 6px;
    font-weight: 700; font-size: 14px; cursor: pointer;
    background: var(--gold, #c9a24a); color: #1a1204;
}
.recrut-btn:disabled {
    background: var(--panel-3, oklch(0.28 0.02 70 / 0.6));
    color: var(--ink-500); cursor: default;
}
.recrut-recrue {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 0; border-bottom: 1px solid var(--line-soft, oklch(0.4 0.02 70 / 0.25));
}
.recrut-pv {
    margin-left: auto; display: flex; align-items: center; gap: 4px;
    font-weight: 700; font-size: 13px; color: var(--body-bright, #e0574a);
}
.recrut-vide { font-size: 13px; color: var(--ink-500); font-style: italic; margin: 4px 0 0; }
</style>
