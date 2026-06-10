<script setup>
// Onglet Action (combat) — port de ActionTab (manette-app.jsx).
import MSym from '../ui/MSym.vue';
import ChoiceCard from './ChoiceCard.vue';
import InitMini from './InitMini.vue';

defineProps({
    myTurn: { type: Boolean, required: true },
    hero: { type: Object, required: true },
});

const emit = defineEmits(['attack', 'open-spells', 'move', 'search', 'pass']);
</script>

<template>
    <div v-if="!myTurn">
        <div class="turn-banner wait"><MSym n="hourglass_top" /> En attente du tour des autres héros…</div>
        <InitMini cur="orc" />
        <div class="empty-note">Les autres agissent. Tu reprendras la main au prochain tour.</div>
    </div>
    <div v-else>
        <div class="turn-banner mine"><MSym n="bolt" fill /> C'est ton tour — choisis une action</div>
        <InitMini :cur="hero.key.toUpperCase().slice(0, 3)" />
        <div class="sect-title"><MSym n="touch_app" :size="16" /> Actions</div>
        <div class="choices">
            <ChoiceCard icon="swords" title="Attaquer" :meta="`${hero.atk} dés de crâne · ennemi à portée`" @click="emit('attack')" />
            <ChoiceCard v-if="hero.hasSpells" icon="auto_awesome" title="Lancer un sort" meta="Choisir dans le grimoire" @click="emit('open-spells')" />
            <ChoiceCard icon="directions_walk" title="Se déplacer" meta="Jusqu'à 5 cases" @click="emit('move')" />
            <ChoiceCard icon="travel_explore" title="Fouiller la pièce" meta="Pièges · trésors · passages" @click="emit('search')" />
            <ChoiceCard icon="forum" title="Parler" meta="Interpeller une créature" disabled />
            <ChoiceCard icon="skip_next" title="Passer le tour" meta="Garder ses forces" danger :chev="false" @click="emit('pass')" />
        </div>
    </div>
</template>
