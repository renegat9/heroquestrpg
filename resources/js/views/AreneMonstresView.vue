<script setup>
// MODE ARÈNE, lot A9a — la manette d'un joueur Monster vs Monster : aucun héros, seulement son camp et le salon.
// Tout ce qui s'affiche vient de `groupe.arene` (variante `monstres`), décidé par le serveur.
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import SalonMonstres from '../components/ui/SalonMonstres.vue';
import DeplacementSheet from '../components/manette/DeplacementSheet.vue';
import MSym from '../components/ui/MSym.vue';
import { useApi } from '../composables/useApi';
import { souscrireGroupe } from '../composables/useEcho';

const props = defineProps({ groupe: { type: String, required: true } });
const api = useApi();
const router = useRouter();

const etat = ref(null);
const joueurId = ref(null);
const enCours = ref(false);
const erreur = ref('');
let desabonner = null;

const salon = computed(() => etat.value?.groupe?.arene ?? null);
// COMBAT (lot A9c) : le menu de CE joueur, décidé par le serveur (`GET …/figures`) — la liste blanche de ses gestes.
const enCombat = computed(() => etat.value?.groupe?.phase === 'quete');
const menu = ref(null);
const figureDeplacee = ref(null); // la figure dont la mini-carte est ouverte
const NOMS = { a: 'Camp A', b: 'Camp B' };

async function rafraichirMenu() {
    if (!enCombat.value) { menu.value = null; return; }
    try { menu.value = await api.figuresMonstres(props.groupe); } catch (e) { erreur.value = e.message; }
}
async function gesteFigure(figure, nom, parametres = {}) {
    figureDeplacee.value = null;
    await geste(async () => {
        const r = await api.gesteMonstres(props.groupe, figure.instance_id, nom, parametres);
        menu.value = r.menu;
        return null;
    });
}

onMounted(async () => {
    try {
        joueurId.value = (await api.moi()).joueur?.id ?? null;
        etat.value = await api.getEtat(props.groupe);
        await rafraichirMenu();
        desabonner = souscrireGroupe(props.groupe, { '.groupe.etat': (e) => { etat.value = e; rafraichirMenu(); } });
    } catch (e) {
        erreur.value = e.message;
    }
});
onUnmounted(() => { if (typeof desabonner === 'function') desabonner(); });

async function geste(fn, { quitter = false } = {}) {
    if (enCours.value) return;
    enCours.value = true;
    erreur.value = '';
    try {
        const r = await fn();
        if (quitter) { router.push({ name: 'joueur' }); return; }
        if (r?.groupe) etat.value = r;
    } catch (e) {
        erreur.value = e.message;
    } finally {
        enCours.value = false;
    }
}
</script>

<template>
    <div class="arene-monstres">
        <!-- ---- COMBAT (lot A9c) ---- -->
        <div v-if="salon && enCombat" class="amv-combat">
            <div class="amv-main">
                <MSym n="swords" :size="16" />
                <template v-if="menu?.mon_camp && menu.main === menu.mon_camp">À votre camp ({{ NOMS[menu.mon_camp] }}) d'activer une figure.</template>
                <template v-else>{{ NOMS[menu?.main] ?? 'L\'autre camp' }} joue…</template>
            </div>
            <div v-for="f in menu?.figures ?? []" :key="f.instance_id" class="amv-figure" :class="{ entamee: f.entamee }">
                <div class="amv-nom">{{ f.nom }} <span class="amv-pv"><MSym n="favorite" fill :size="12" /> {{ f.pv_body }}</span></div>
                <div class="amv-gestes">
                    <button v-if="f.peut_deplacer" class="amv-btn" :disabled="enCours" @click="figureDeplacee = f">
                        <MSym n="directions_walk" :size="15" /> Se déplacer
                    </button>
                    <button v-for="c in f.cibles" :key="c.id" class="amv-btn amv-frappe" :disabled="enCours"
                        @click="gesteFigure(f, 'attaquer', { cible_id: c.id })">
                        <MSym n="swords" :size="15" /> Frapper {{ c.nom }} ({{ c.pv_body }} PV)
                    </button>
                    <button class="amv-btn amv-fin" :disabled="enCours" @click="gesteFigure(f, 'terminer')">Terminer</button>
                </div>
            </div>
            <p v-if="erreur" class="arene-monstres-err">{{ erreur }}</p>
            <div v-if="figureDeplacee && etat?.carte" class="amv-feuille">
                <DeplacementSheet
                    :carte="etat.carte"
                    :entites="etat.entites ?? []"
                    :depart="{ x: figureDeplacee.x, y: figureDeplacee.y }"
                    :cases-ecart="figureDeplacee.destinations"
                    :groupe="props.groupe"
                    @deplacer="(c) => gesteFigure(figureDeplacee, 'deplacer', c)"
                    @close="figureDeplacee = null"
                />
            </div>
        </div>
        <SalonMonstres
            v-else-if="salon"
            :salon="salon"
            :code="props.groupe"
            :mon-joueur-id="joueurId"
            :en-cours="enCours"
            :erreur="erreur"
            @camp="(c) => geste(() => api.campMonstres(props.groupe, c))"
            @puissance="(n) => geste(() => api.puissanceMonstres(props.groupe, n))"
            @tirage="geste(() => api.tirageMonstres(props.groupe))"
            @choisir="(id) => geste(() => api.selectionMonstres(props.groupe, id))"
            @quitter="geste(() => api.quitterArene(props.groupe), { quitter: true })"
            @lancer="geste(() => api.lancerArene(props.groupe))"
            @rejouer="geste(() => api.rejouerArene(props.groupe))"
        />
        <p v-else-if="erreur" class="arene-monstres-err">{{ erreur }}</p>
        <p v-else class="arene-monstres-attente">Chargement du combat…</p>
    </div>
</template>

<style scoped>
.arene-monstres { max-width: 720px; margin: 0 auto; padding: 16px; }
.arene-monstres-err { color: var(--danger, #e0735c); }
.arene-monstres-attente { color: var(--ink-500); }
.amv-main { display: flex; align-items: center; gap: 6px; font-weight: 700; color: var(--gold, #c9a24a); margin-bottom: 10px; }
.amv-figure { padding: 10px; margin-bottom: 8px; border-radius: 10px; border: 1px solid var(--line-soft, oklch(0.4 0.02 70 / 0.4)); }
.amv-figure.entamee { border-color: var(--gold, #c9a24a); }
.amv-nom { font-weight: 800; color: var(--ink-100, #efe6d4); margin-bottom: 6px; }
.amv-pv { font-size: 12px; color: var(--ink-500); margin-left: 6px; }
.amv-gestes { display: flex; flex-wrap: wrap; gap: 6px; }
.amv-btn { display: inline-flex; align-items: center; gap: 4px; padding: 7px 10px; border-radius: 8px; border: 1px solid var(--gold, #c9a24a);
  background: none; color: var(--gold, #c9a24a); font-weight: 700; font-size: 13px; cursor: pointer; }
.amv-frappe { border-color: var(--danger, #e0735c); color: var(--danger, #e0735c); }
.amv-fin { border-color: var(--line-soft, #666); color: var(--ink-300, #cfc3ad); }
.amv-btn:disabled { opacity: 0.6; cursor: default; }
.amv-feuille { position: fixed; inset: 0; z-index: 50; background: rgba(0, 0, 0, 0.6); display: flex; align-items: flex-end; }
</style>
