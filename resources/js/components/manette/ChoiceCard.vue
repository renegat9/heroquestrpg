<script setup>
// Carte de choix tactile (composant Choice de manette-app.jsx).
import MSym from '../ui/MSym.vue';
import Vignette from '../ui/Vignette.vue';

defineProps({
    icon: { type: String, required: true },
    /** Image optionnelle (ex. illustration de sort) ; sinon l'icône. */
    image: { type: String, default: null },
    title: { type: String, required: true },
    meta: { type: String, default: '' },
    /** Petit badge inline après le titre (ex. type de sort du contrat). */
    badge: { type: String, default: '' },
    /** Option `creneau === 'interaction'` (contrat, 2026-09-18) : geste
     *  GRATUIT et répétable, aucun créneau de tour dépensé — affiche ∞ à la
     *  suite du titre. Discret (petite icône ton sur ton), pas une alerte :
     *  c'est une information de prix, pas un avertissement. */
    infini: { type: Boolean, default: false },
    sel: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    danger: { type: Boolean, default: false },
    elClass: { type: String, default: '' },
    chev: { type: Boolean, default: true },
});

const emit = defineEmits(['click']);
</script>

<template>
    <button
        class="choice"
        :class="[{ sel, disabled, danger }, elClass]"
        @click="!disabled && emit('click')"
    >
        <span class="ic"><Vignette :src="image" :icon="icon" /></span>
        <span style="flex: 1">
            <span class="ttl">{{ title }}<span
                v-if="badge" class="badge">{{ badge }}</span><MSym v-if="infini" n="all_inclusive" :size="14"
                class="infini" title="Action gratuite — répétable, ne consomme aucun créneau de tour"
                aria-label="Action gratuite — répétable, ne consomme aucun créneau de tour"
            /></span>
            <span v-if="meta" class="meta">{{ meta }}</span>
        </span>
        <MSym v-if="chev" n="chevron_right" />
    </button>
</template>
