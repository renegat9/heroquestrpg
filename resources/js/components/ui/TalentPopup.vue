<script setup>
/*
 * « Un talent qui s'active tout seul se VOIT » (René, 2026-09-25,
 * docs/contrat-api.md). Jusqu'ici la plupart des talents passifs jouaient EN
 * SILENCE — une porte secrète apparaissait, un poison glissait, un dé raté
 * était relancé — sans que le joueur sache que c'était SON talent. Un effet
 * automatique que rien n'annonce est injouable (règle dure du projet).
 *
 * Ce composant lit `.combat.journal` (via le store, `state.journalCombat`) :
 * chaque ligne `ton: "talent"` porte `talent: {personnage_id, heros, nom,
 * icone, effet}` — déjà décidée par le serveur, rien n'est redéduit ici.
 *
 * ⚠ FILE, pas un tas : plusieurs déclenchements à la suite s'enchaînent un par
 * un (~3,5 s chacun, fermable avant), jamais empilés à l'écran. Ce composant
 * suit `journal` en gardant le dernier id vu — comme `pousserJournalCombat()`
 * plafonne le fil à 24 lignes, on ne rejoue jamais une entrée déjà passée.
 *
 * Partagé par la table (`filtroPersonnageId` absent : tout héros) et la
 * manette (`filtroPersonnageId` = son propre héros, la carte ne montre pas le
 * secret d'un autre joueur).
 */
import { ref, watch, onBeforeUnmount, computed } from 'vue';
import MSym from './MSym.vue';

const props = defineProps({
    /** state.journalCombat — lignes {id, ton, talent?}. */
    journal: { type: Array, default: () => [] },
    /** null/undefined = tout héros (table) ; un id = seulement ce héros (manette). */
    filtroPersonnageId: { type: [Number, String], default: null },
    /** Durée d'affichage avant fermeture automatique. */
    dureeMs: { type: Number, default: 3500 },
});

/** File d'attente des annonces à montrer, une à la fois. */
const file = ref([]);
let dernierIdVu = -1;
let minuteur = null;

function correspond(ligne) {
    if (props.filtroPersonnageId == null) return true;

    return String(ligne?.talent?.personnage_id) === String(props.filtroPersonnageId);
}

watch(
    () => props.journal,
    (lignes) => {
        if (!Array.isArray(lignes) || lignes.length === 0) return;

        const nouvelles = lignes.filter((l) => l.id > dernierIdVu && l.ton === 'talent' && correspond(l));

        if (nouvelles.length === 0) return;

        dernierIdVu = Math.max(dernierIdVu, ...lignes.map((l) => l.id));
        file.value = [...file.value, ...nouvelles.map((l) => l.talent)];
    },
    { immediate: true },
);

const actif = computed(() => file.value[0] ?? null);

function armerMinuteur() {
    if (minuteur) clearTimeout(minuteur);
    minuteur = setTimeout(fermer, props.dureeMs);
}

function fermer() {
    if (minuteur) {
        clearTimeout(minuteur);
        minuteur = null;
    }
    file.value = file.value.slice(1);
}

watch(actif, (valeur) => {
    if (valeur) armerMinuteur();
});

onBeforeUnmount(() => {
    if (minuteur) clearTimeout(minuteur);
});
</script>

<template>
    <div v-if="actif" class="hq-talent-popup" role="status" @click="fermer">
        <MSym :n="actif.icone || 'hub'" :size="22" fill class="hq-talent-popup-icone" />
        <div class="hq-talent-popup-texte">
            <b class="hq-talent-popup-nom">{{ actif.nom }}</b>
            <span class="hq-talent-popup-heros">{{ actif.heros }}</span>
            <span class="hq-talent-popup-effet">{{ actif.effet }}</span>
        </div>
        <button
            type="button"
            class="hq-talent-popup-fermer"
            aria-label="Fermer"
            @click.stop="fermer"
        >✕</button>
    </div>
</template>

<style scoped>
.hq-talent-popup {
    position: fixed;
    top: max(14px, env(safe-area-inset-top));
    left: 50%;
    transform: translateX(-50%);
    z-index: 80;
    display: flex;
    align-items: center;
    gap: 10px;
    max-width: min(92vw, 380px);
    padding: 10px 12px;
    border-radius: 14px;
    background: var(--panel, #17120b);
    border: 1px solid rgba(201, 162, 74, 0.4);
    box-shadow: 0 14px 34px rgba(0, 0, 0, 0.5);
    cursor: pointer;
    animation: hq-talent-popup-pop 0.18s ease-out;
}
.hq-talent-popup-icone {
    flex: none;
    color: var(--gold, #c9a24a);
}
.hq-talent-popup-texte {
    display: flex;
    flex-direction: column;
    min-width: 0;
    line-height: 1.28;
}
.hq-talent-popup-nom {
    font-size: 13.5px;
    color: var(--ink-100, #f3e9d6);
}
.hq-talent-popup-heros {
    font-size: 11px;
    color: var(--ink-300, #b6a88a);
}
.hq-talent-popup-effet {
    font-size: 12px;
    color: var(--ink-200, #d8c9a8);
    overflow-wrap: anywhere;
}
.hq-talent-popup-fermer {
    flex: none;
    align-self: flex-start;
    background: none;
    border: none;
    color: var(--ink-300, #b6a88a);
    font-size: 13px;
    line-height: 1;
    padding: 2px 4px;
    cursor: pointer;
}
.hq-talent-popup-fermer:hover { color: var(--ink-100, #f3e9d6); }

@keyframes hq-talent-popup-pop {
    from { transform: translateX(-50%) translateY(-8px); opacity: 0; }
    to { transform: translateX(-50%) translateY(0); opacity: 1; }
}
</style>
