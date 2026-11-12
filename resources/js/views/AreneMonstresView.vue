<script setup>
// MODE ARÈNE, lot A9a — la manette d'un joueur Monster vs Monster : aucun héros, seulement son camp et le salon.
// Tout ce qui s'affiche vient de `groupe.arene` (variante `monstres`), décidé par le serveur.
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import SalonMonstres from '../components/ui/SalonMonstres.vue';
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

onMounted(async () => {
    try {
        joueurId.value = (await api.moi()).joueur?.id ?? null;
        etat.value = await api.getEtat(props.groupe);
        desabonner = souscrireGroupe(props.groupe, { '.groupe.etat': (e) => { etat.value = e; } });
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
        <SalonMonstres
            v-if="salon"
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
        />
        <p v-else-if="erreur" class="arene-monstres-err">{{ erreur }}</p>
        <p v-else class="arene-monstres-attente">Chargement du combat…</p>
    </div>
</template>

<style scoped>
.arene-monstres { max-width: 720px; margin: 0 auto; padding: 16px; }
.arene-monstres-err { color: var(--danger, #e0735c); }
.arene-monstres-attente { color: var(--ink-500); }
</style>
