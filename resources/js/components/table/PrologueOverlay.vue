<script setup>
// PROLOGUE DE CAMPAGNE (écran de table) — affiche la prémisse et la grande
// menace au lancement de la campagne, lue avec la voix de narrateur. La voix
// est pilotée par TableView (composable useVoix) ; ce composant ne fait que
// présenter le texte et émettre les actions.
import MSym from '../ui/MSym.vue';

defineProps({
    prologue: { type: Object, required: true }, // { texte, menace:{nom,description} }
    parle: { type: Boolean, default: false },
});

defineEmits(['commencer', 'rejouer']);
</script>

<template>
    <div class="prologue-ov">
        <div class="prologue-carte">
            <div class="prologue-orn"><MSym n="auto_stories" fill /></div>
            <div class="prologue-eyebrow">
                Le prologue
                <span v-if="parle" class="prologue-eq"><i /><i /><i /></span>
            </div>

            <h2 v-if="prologue.menace?.nom" class="prologue-menace">{{ prologue.menace.nom }}</h2>

            <p class="prologue-texte">{{ prologue.texte }}</p>

            <p v-if="prologue.menace?.description" class="prologue-menace-desc">
                {{ prologue.menace.description }}
            </p>

            <div class="prologue-actions">
                <button class="btn ghost" type="button" @click="$emit('rejouer')">
                    <MSym n="replay" /> Relire
                </button>
                <button class="btn torch" type="button" @click="$emit('commencer')">
                    Commencer l'aventure <MSym n="east" />
                </button>
            </div>
        </div>
    </div>
</template>

<style>
.prologue-ov { position: fixed; inset: 0; z-index: 80; display: grid; place-items: center;
  padding: 32px; background: oklch(0.12 0.02 60 / 0.82); backdrop-filter: blur(6px);
  animation: prologue-fade .35s ease; }
@keyframes prologue-fade { from { opacity: 0; } to { opacity: 1; } }

.prologue-carte { position: relative; width: 100%; max-width: 720px; text-align: center;
  padding: 44px 40px 32px; border-radius: var(--r-xl, 18px); border: var(--line);
  background: linear-gradient(180deg, var(--stone-850), var(--stone-900));
  box-shadow: 0 0 60px oklch(0.76 0.155 65 / 0.18), var(--sh-3);
  display: flex; flex-direction: column; align-items: center; gap: 16px; }

.prologue-orn { width: 76px; height: 76px; border-radius: 20px; display: grid; place-items: center;
  background: linear-gradient(150deg, var(--ember), var(--ember-deep));
  color: var(--parch-100); box-shadow: 0 0 36px oklch(0.76 0.155 65 / 0.3); margin-top: -6px; }
.prologue-orn .msym { font-size: 42px; }

.prologue-eyebrow { display: inline-flex; align-items: center; gap: 9px;
  font-size: 12.5px; font-weight: 800; letter-spacing: 0.22em; text-transform: uppercase;
  color: var(--torch); }
.prologue-eq { display: inline-flex; align-items: flex-end; gap: 2px; height: 12px; }
.prologue-eq i { width: 3px; background: var(--torch); border-radius: 2px;
  animation: prologue-eq 0.9s ease-in-out infinite; }
.prologue-eq i:nth-child(2) { animation-delay: .15s } .prologue-eq i:nth-child(3) { animation-delay: .3s }
@keyframes prologue-eq { 0%,100% { height: 4px } 50% { height: 12px } }

.prologue-menace { font-family: var(--font-display); font-size: clamp(24px, 4vw, 38px); font-weight: 800;
  letter-spacing: 0.02em; margin: 0; color: var(--parch-100); }

.prologue-texte { font-family: var(--font-narr, serif); font-style: italic;
  font-size: clamp(16px, 2.1vw, 20px); line-height: 1.65; color: var(--ink-100); margin: 0; max-width: 60ch; }

.prologue-menace-desc { font-size: 13.5px; line-height: 1.55; color: var(--ink-400); margin: 0; max-width: 58ch; }

.prologue-actions { display: flex; gap: 12px; margin-top: 8px; flex-wrap: wrap; justify-content: center; }
.prologue-actions .btn { padding: 11px 20px; font-size: 14px; }
</style>
