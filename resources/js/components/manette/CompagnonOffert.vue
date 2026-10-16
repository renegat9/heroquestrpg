<script setup>
// Compagnon animal OFFERT (Against the Ogre Horde p. 8-9, « at no cost », René 2026-10-15) :
// proposé UNE fois, gratuitement, au hub, avant la première quête d'un groupe de moins de
// 4 héros. Le premier joueur qui prend un animal en devient le maître ; refuser clôt l'offre
// pour tous.
//
// ⚠ Rien ici n'est décidé. Le serveur publie la proposition entière (`groupe.compagnon_offert` :
// la consigne écrite, la liste des animaux = la liste blanche qu'il revalide) et la RETIRE
// quand elle n'existe plus. Ce composant ne connaît ni le seuil de quatre héros, ni « une seule
// fois », ni la liste : il rend ce qu'il reçoit, et n'est monté que lorsqu'elle est non nulle.
import { ref } from 'vue';
import MSym from '../ui/MSym.vue';
import Vignette from '../ui/Vignette.vue';

defineProps({
    // `groupe.compagnon_offert` : {nb_heros, seuil, consigne, animaux: [{mercenaire_id, nom, deplacement, attaque, defense, pv_body, description, image_url}]}.
    offre: { type: Object, required: true },
    // Un geste est en cours (gèle les boutons).
    enCours: { type: Boolean, default: false },
});
const emit = defineEmits(['prendre', 'refuser']);

// Refuser est définitif : un second tap confirme.
const confirmerRefus = ref(false);
</script>

<template>
    <div class="cpo">
        <div class="sect-title"><MSym n="pets" fill :size="16" /> Un compagnon animal vous est offert</div>
        <p class="cpo-consigne">{{ offre.consigne }}</p>

        <div v-for="a in offre.animaux" :key="a.mercenaire_id" class="cpo-carte">
            <div class="cpo-tete">
                <span class="cpo-ic"><Vignette :src="a.image_url" icon="pets" /></span>
                <div class="cpo-nom">{{ a.nom }}</div>
                <div class="cpo-offert"><MSym n="redeem" fill :size="13" /> offert</div>
            </div>
            <p v-if="a.description" class="cpo-desc">{{ a.description }}</p>
            <div class="cpo-stats">
                <span class="st"><MSym n="directions_run" :size="14" /> {{ a.deplacement }}</span>
                <span class="st"><MSym n="swords" :size="14" /> {{ a.attaque }}</span>
                <span class="st"><MSym n="shield" :size="14" /> {{ a.defense }}</span>
                <span class="st"><MSym n="favorite" fill :size="14" /> {{ a.pv_body }}</span>
            </div>
            <button class="cpo-btn" :disabled="enCours" @click="emit('prendre', a.mercenaire_id)">
                <MSym n="handshake" :size="16" /> Prendre {{ a.nom }}
            </button>
        </div>

        <div class="cpo-refus">
            <button v-if="!confirmerRefus" class="cpo-lien" :disabled="enCours" @click="confirmerRefus = true">
                Je n'en veux pas
            </button>
            <template v-else>
                <span class="cpo-avert">Définitif : l'offre ne reviendra pas.</span>
                <button class="cpo-lien" :disabled="enCours" @click="confirmerRefus = false">Annuler</button>
                <button class="cpo-lien cpo-danger" :disabled="enCours" @click="emit('refuser')">Refuser pour le groupe</button>
            </template>
        </div>
    </div>
</template>

<style scoped>
.cpo { margin: 0 0 16px; padding: 12px; border: 1px solid var(--gold, #c9a24a); border-radius: 12px;
  background: rgba(201, 162, 90, 0.08); }
.cpo-consigne { font-size: 13px; line-height: 1.4; color: var(--ink-300, #cfc3ad); margin: 4px 0 12px; }
.cpo-carte { border: 1px solid var(--line-soft, oklch(0.4 0.02 70 / 0.4)); border-radius: 10px;
  padding: 10px; margin-bottom: 10px; background: var(--panel-2, oklch(0.22 0.02 70 / 0.4)); }
.cpo-tete { display: flex; align-items: center; gap: 10px; }
.cpo-ic { display: grid; place-items: center; width: 44px; height: 44px; flex: none; overflow: hidden;
  border-radius: 9px; background: var(--panel-3, oklch(0.28 0.02 70 / 0.5)); color: var(--gold, #c9a24a); }
.cpo-nom { flex: 1; min-width: 0; font-weight: 700; font-size: 14px; }
.cpo-offert { display: flex; align-items: center; gap: 4px; flex: none; font-weight: 800; font-size: 12px;
  color: var(--gold, #c9a24a); text-transform: uppercase; letter-spacing: 0.04em; }
.cpo-desc { font-family: var(--font-narr); font-style: italic; font-size: 13px; color: var(--ink-300, #cfc3ad);
  margin: 8px 0 8px; line-height: 1.35; }
.cpo-stats { display: flex; gap: 14px; margin-bottom: 10px; }
.cpo-stats .st { display: flex; align-items: center; gap: 4px; font-size: 13px; font-weight: 700; color: var(--ink-300, #cfc3ad); }
.cpo-btn { width: 100%; padding: 9px; border: 0; border-radius: 9px; display: flex; align-items: center;
  justify-content: center; gap: 6px; font-weight: 700; font-size: 14px; cursor: pointer;
  background: var(--gold, #c9a24a); color: #1a1204; }
.cpo-btn:disabled { background: var(--panel-3, oklch(0.28 0.02 70 / 0.6)); color: var(--ink-500); cursor: default; }
.cpo-refus { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: 10px; }
.cpo-lien { background: none; border: 0; padding: 4px 2px; font-size: 12.5px; color: var(--ink-500); text-decoration: underline; cursor: pointer; }
.cpo-danger { color: var(--danger, #e0735c); }
.cpo-avert { font-size: 12.5px; color: var(--danger, #e0735c); }
</style>
