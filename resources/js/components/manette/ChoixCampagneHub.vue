<script setup>
// MODE CLASSIQUE — LA CAMPAGNE DU GROUPE AU HUB, ET SON CHOIX (fin du lot S1, contrat « Choisir sa campagne au hub »).
//
// ⚠ Rien ici n'est décidé. `groupe.classique` (EtatGroupe) dit où en est le groupe et si le choix est OUVERT ;
// `GET /groupes/{id}/campagnes` publie chaque campagne avec sa décision (`choisissable`, `motif` du gris). Ce composant
// rend ce qu'il reçoit : il ne relit ni les classes du groupe ni le rang de la campagne. Après un choix, le serveur
// diffuse `.groupe.etat` : le résumé se met à jour sans rien recalculer ici.
import { computed, ref } from 'vue';
import MSym from '../ui/MSym.vue';
import { useApi } from '../../composables/useApi';

const props = defineProps({
    // Le code du groupe.
    groupe: { type: String, required: true },
    // `groupe.classique` : {campagne, rang_quete, nb_quetes, prochaine_quete, terminee, choix_ouvert, choix_motif}.
    classique: { type: Object, required: true },
});

const api = useApi();
const ouvert = ref(false);
const liste = ref(null);
const chargement = ref(false);
const envoi = ref(false);
const erreur = ref('');

const avancement = computed(() => {
    const c = props.classique;
    if (!c.campagne) return 'Aucune campagne : choisissez-en une.';
    if (c.terminee) return `Campagne terminée — ${c.nb_quetes} quête${c.nb_quetes > 1 ? 's' : ''} jouée${c.nb_quetes > 1 ? 's' : ''}.`;
    return `Quête ${c.rang_quete} sur ${c.nb_quetes}${c.prochaine_quete ? ` : ${c.prochaine_quete}` : ''}`;
});

async function basculer() {
    erreur.value = '';
    if (ouvert.value) {
        ouvert.value = false;
        return;
    }
    ouvert.value = true;
    chargement.value = true;
    try {
        liste.value = await api.campagnesDuGroupe(props.groupe);
    } catch (e) {
        erreur.value = e?.message ?? 'Liste des campagnes indisponible.';
    } finally {
        chargement.value = false;
    }
}

async function choisir(c) {
    erreur.value = '';
    envoi.value = true;
    try {
        await api.choisirCampagne(props.groupe, c.slug);
        ouvert.value = false;
        liste.value = null;
    } catch (e) {
        erreur.value = e?.message ?? 'Choix refusé.';
    } finally {
        envoi.value = false;
    }
}
</script>

<template>
    <div class="campagne-hub">
        <div class="campagne-hub-tete">
            <MSym n="menu_book" fill :size="16" />
            <div class="campagne-hub-titres">
                <span class="campagne-hub-titre">{{ classique.campagne?.titre ?? 'Campagne' }}</span>
                <span class="campagne-hub-avancement">{{ avancement }}</span>
            </div>
            <button
                v-if="classique.choix_ouvert"
                type="button"
                class="campagne-hub-btn"
                :aria-expanded="ouvert ? 'true' : 'false'"
                @click="basculer"
            >
                {{ ouvert ? 'Fermer' : 'Choisir une campagne' }}
            </button>
        </div>

        <div v-if="ouvert" class="campagne-hub-liste">
            <p v-if="chargement" class="campagne-hub-aide">Chargement…</p>
            <template v-else-if="liste">
                <p v-if="!liste.choix_ouvert" class="campagne-hub-aide">{{ liste.choix_motif }}</p>
                <div
                    v-for="c in liste.campagnes"
                    :key="c.slug"
                    class="campagne-hub-ligne"
                    :class="{ grise: !c.choisissable }"
                >
                    <div class="campagne-hub-info">
                        <span class="campagne-hub-nom">{{ c.titre }}</span>
                        <span class="campagne-hub-detail">
                            {{ c.nb_quetes }} quête{{ c.nb_quetes > 1 ? 's' : '' }}
                            <template v-if="c.prerequis_heros.length">· exige {{ c.prerequis_heros.map((h) => h.nom).join(', ') }}</template>
                        </span>
                        <span v-if="c.motif" class="campagne-hub-motif">
                            <MSym n="block" :size="13" /> {{ c.motif }}
                        </span>
                    </div>
                    <!-- La campagne actuelle pas finie : la choisir ne changerait rien. Finie, la choisir la rejoue. -->
                    <span v-if="c.actuelle && !classique.terminee" class="campagne-hub-actuelle">Actuelle</span>
                    <button
                        v-else-if="c.choisissable && liste.choix_ouvert"
                        type="button"
                        class="campagne-hub-btn"
                        :disabled="envoi"
                        @click="choisir(c)"
                    >
                        {{ c.actuelle ? 'Rejouer' : 'Choisir' }}
                    </button>
                </div>
            </template>
        </div>
        <p v-if="erreur" class="campagne-hub-err"><MSym n="error" :size="13" /> {{ erreur }}</p>
    </div>
</template>

<style scoped>
/* Classes préfixées `campagne-hub-` : beaucoup de blocs <style> du projet sont globaux. */
.campagne-hub { display: grid; gap: 8px; margin: 0 0 10px; padding: 10px 12px; border-radius: 11px; background: var(--panel-2, oklch(0.28 0.02 70 / 0.5)); }
.campagne-hub-tete { display: flex; align-items: center; gap: 8px; color: var(--gold, #c9a24a); }
.campagne-hub-titres { display: grid; gap: 2px; min-width: 0; flex: 1; }
.campagne-hub-titre { font-weight: 700; font-size: 14px; color: var(--parch-100, #f1e6cf); }
.campagne-hub-avancement { font-size: 12px; color: var(--ink-300, #cfc3ad); }
.campagne-hub-liste { display: grid; gap: 6px; }
.campagne-hub-aide { margin: 0; font-size: 12px; color: var(--ink-500, #8d8574); }
.campagne-hub-ligne { display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-radius: 9px; border: 1px solid var(--line-soft, oklch(0.4 0.02 70 / 0.5)); }
.campagne-hub-ligne.grise { opacity: 0.55; }
.campagne-hub-info { display: grid; gap: 2px; min-width: 0; flex: 1; }
.campagne-hub-nom { font-weight: 700; font-size: 13px; color: var(--parch-100, #f1e6cf); }
.campagne-hub-detail { font-size: 12px; color: var(--ink-300, #cfc3ad); }
.campagne-hub-motif { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; color: var(--blood-300, #e08a7a); }
.campagne-hub-btn {
    flex: none;
    padding: 6px 12px;
    border-radius: 9px;
    font: inherit;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    background: transparent;
    color: var(--ink-300, #cfc3ad);
    border: 1px solid var(--line-soft, oklch(0.4 0.02 70 / 0.5));
}
.campagne-hub-actuelle { flex: none; font-size: 12px; font-weight: 700; color: var(--gold, #c9a24a); }
.campagne-hub-btn:disabled { opacity: 0.5; cursor: default; }
.campagne-hub-err { margin: 0; display: inline-flex; align-items: center; gap: 4px; font-size: 12px; color: var(--blood-300, #e08a7a); }
</style>
