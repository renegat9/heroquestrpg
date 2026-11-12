<script setup>
// MODE ARÈNE, lot A9a — le SALON Monster vs Monster (livret F9528 p. 16, `docs/contrat-api.md` §Monster vs Monster).
//
// ⚠ Rien ici n'est décidé. Le serveur publie tout (`groupe.arene`, variante `monstres`) : les camps et leurs joueurs,
// la puissance convenue, le tirage et son motif, le camp dont c'est le tour de choisir, ce qui reste à chaque camp et
// les monstres encore abordables. Ce composant compare seulement le camp de CE joueur au camp publié.
import { computed, ref, watch } from 'vue';
import MSym from './MSym.vue';

const props = defineProps({
    salon: { type: Object, required: true },
    code: { type: String, required: true },
    // Joueur sur CE téléphone ; null sur la table.
    monJoueurId: { type: Number, default: null },
    enCours: { type: Boolean, default: false },
    erreur: { type: String, default: '' },
});
const emit = defineEmits(['camp', 'puissance', 'tirage', 'choisir', 'quitter']);

const NOMS = { a: 'Camp A', b: 'Camp B' };
const ROLES = { challenger: 'Challenger', defender: 'Defender' };

const monCamp = computed(() => props.salon.joueurs.find((j) => j.joueur_id === props.monJoueurId)?.camp ?? null);
const aMonTour = computed(() => monCamp.value !== null && props.salon.tour_selection === monCamp.value);
const joueursDu = (camp) => props.salon.joueurs.filter((j) => j.camp === camp);

const puissance = ref(props.salon.puissance_convenue ?? 15);
watch(() => props.salon.puissance_convenue, (v) => { if (v) puissance.value = v; });

const confirmerQuitter = ref(false);
</script>

<template>
    <div class="slm">
        <div class="slm-bandeau"><MSym n="shield_lock" fill :size="15" /> {{ salon.bandeau }} · Monster vs Monster</div>

        <div class="slm-code">
            <span class="slm-lbl">Code du combat</span>
            <code>{{ code }}</code>
        </div>

        <!-- ---- puissance convenue ---- -->
        <div class="slm-ligne">
            <span class="slm-lbl">Puissance convenue</span>
            <template v-if="!salon.tirage_fait">
                <input v-model.number="puissance" type="number" :min="salon.puissance_min" :max="salon.puissance_max" class="slm-num" />
                <button class="slm-btn-petit" :disabled="enCours" @click="emit('puissance', puissance)">Fixer</button>
            </template>
            <strong v-if="salon.puissance_convenue">{{ salon.puissance_convenue }}</strong>
        </div>

        <!-- ---- les deux camps ---- -->
        <div class="slm-camps">
            <div v-for="camp in ['a', 'b']" :key="camp" class="slm-camp" :class="{ tour: salon.tour_selection === camp, mien: monCamp === camp }">
                <div class="slm-titre">
                    {{ NOMS[camp] }}
                    <span v-if="salon.camps[camp].role" class="slm-role">{{ ROLES[salon.camps[camp].role] }}</span>
                    <span class="slm-puissance">{{ salon.camps[camp].puissance }}<template v-if="salon.puissance_convenue"> / {{ salon.puissance_convenue }}</template></span>
                </div>
                <div class="slm-joueurs">
                    <span v-for="j in joueursDu(camp)" :key="j.joueur_id" class="slm-joueur">{{ j.pseudo }}</span>
                    <span v-if="!joueursDu(camp).length" class="slm-vide">Personne</span>
                </div>
                <div class="slm-chips">
                    <span v-for="(m, i) in salon.camps[camp].monstres" :key="`${m.monstre_id}-${i}`" class="slm-chip">{{ m.nom_base }}</span>
                </div>
                <button v-if="monJoueurId && !salon.tirage_fait && monCamp !== camp" class="slm-btn-petit" :disabled="enCours"
                    @click="emit('camp', camp)">
                    Rejoindre {{ NOMS[camp] }}
                </button>
            </div>
        </div>

        <!-- ---- tirage ---- -->
        <button v-if="!salon.tirage_fait && salon.tirage_possible" class="slm-btn" :disabled="enCours" @click="emit('tirage')">
            <MSym n="casino" :size="16" /> Tirer au sort Challenger et Defender
        </button>
        <p v-else-if="!salon.tirage_fait" class="slm-motif"><MSym n="hourglass_top" :size="14" /> {{ salon.tirage_motif }}</p>

        <!-- ---- sélection alternée ---- -->
        <template v-if="salon.tirage_fait && !salon.selection_terminee">
            <p class="slm-motif">
                <MSym n="touch_app" :size="14" />
                {{ aMonTour ? 'À votre camp de choisir un monstre.' : `${NOMS[salon.tour_selection]} choisit un monstre…` }}
            </p>
            <div v-if="aMonTour" class="slm-roster">
                <button v-for="m in salon.monstres" :key="m.id" class="slm-monstre" :disabled="enCours || !m.abordable"
                    :title="m.abordable ? '' : 'Trop puissant pour ce qui reste'" @click="emit('choisir', m.id)">
                    <span class="slm-nom">+ {{ m.nom_base }} <span class="slm-cout">{{ m.puissance }}</span></span>
                    <span class="slm-stats">
                        <MSym n="directions_run" :size="12" />{{ m.deplacement }}
                        <MSym n="swords" :size="12" />{{ m.attaque }}
                        <MSym n="shield" :size="12" />{{ m.defense }}
                        <MSym n="favorite" fill :size="12" />{{ m.pv_body }}
                    </span>
                </button>
            </div>
        </template>

        <p v-if="salon.selection_terminee" class="slm-motif"><MSym n="info" :size="14" /> {{ salon.lancement_motif }}</p>
        <p v-if="erreur" class="slm-err"><MSym n="error" :size="13" /> {{ erreur }}</p>

        <div class="slm-quitter">
            <button v-if="!confirmerQuitter" class="slm-lien" :disabled="enCours" @click="confirmerQuitter = true">Quitter l'arène</button>
            <template v-else>
                <span class="slm-avert">Le combat sera fermé pour tout le monde.</span>
                <button class="slm-lien" :disabled="enCours" @click="confirmerQuitter = false">Annuler</button>
                <button class="slm-lien slm-danger" :disabled="enCours" @click="emit('quitter')">Fermer le combat</button>
            </template>
        </div>
    </div>
</template>

<style scoped>
.slm { padding: 12px; border: 1px solid var(--gold, #c9a24a); border-radius: 12px; background: rgba(201, 162, 90, 0.06); }
.slm-bandeau { display: flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 700; color: var(--gold, #c9a24a); margin-bottom: 10px; }
.slm-code, .slm-ligne { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 10px;
  padding: 8px 10px; border-radius: 9px; background: var(--panel-2, oklch(0.22 0.02 70 / 0.4)); }
.slm-lbl { font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--ink-500); margin-right: auto; }
.slm-code code { font-size: 16px; font-weight: 800; letter-spacing: 0.08em; }
.slm-num { width: 64px; padding: 4px 6px; border-radius: 6px; border: 1px solid var(--line-soft, #555); background: transparent; color: inherit; }
.slm-camps { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 8px; margin-bottom: 10px; }
.slm-camp { padding: 8px; border-radius: 10px; border: 1px solid var(--line-soft, oklch(0.4 0.02 70 / 0.4)); }
.slm-camp.tour { border-color: var(--gold, #c9a24a); }
.slm-camp.mien .slm-titre { color: var(--gold, #c9a24a); }
.slm-titre { display: flex; align-items: center; gap: 6px; font-weight: 800; font-size: 12px; text-transform: uppercase;
  letter-spacing: 0.06em; color: var(--ink-300, #cfc3ad); margin-bottom: 6px; }
.slm-role { font-size: 10.5px; padding: 1px 6px; border-radius: 99px; border: 1px solid currentColor; text-transform: none; }
.slm-puissance { margin-left: auto; color: var(--gold, #c9a24a); text-transform: none; }
.slm-joueurs, .slm-chips { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 6px; }
.slm-joueur { font-size: 12.5px; font-weight: 700; color: var(--ink-100, #efe6d4); }
.slm-chip { padding: 2px 8px; border-radius: 99px; font-size: 12px; border: 1px solid var(--danger, #e0735c); color: var(--ink-100, #efe6d4); }
.slm-vide { font-size: 12.5px; color: var(--ink-500); font-style: italic; }
.slm-roster { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 6px; margin: 8px 0; }
.slm-monstre { display: flex; flex-direction: column; align-items: flex-start; gap: 2px; padding: 7px 9px; border-radius: 9px;
  border: 1px solid var(--line-soft, oklch(0.4 0.02 70 / 0.4)); background: var(--panel-2, oklch(0.22 0.02 70 / 0.4)); cursor: pointer; text-align: left; }
.slm-monstre:disabled { opacity: 0.45; cursor: default; }
.slm-nom { font-weight: 700; font-size: 13.5px; color: var(--ink-100, #efe6d4); }
.slm-cout { margin-left: 4px; font-size: 11px; font-weight: 800; color: var(--gold, #c9a24a); }
.slm-stats { display: flex; align-items: center; gap: 3px; font-size: 11.5px; color: var(--ink-500); }
.slm-btn { width: 100%; margin-top: 4px; padding: 10px; border: 0; border-radius: 9px; display: flex; align-items: center;
  justify-content: center; gap: 6px; font-weight: 700; font-size: 14px; cursor: pointer; background: var(--gold, #c9a24a); color: #1a1204; }
.slm-btn:disabled, .slm-btn-petit:disabled { opacity: 0.6; cursor: default; }
.slm-btn-petit { padding: 4px 10px; border-radius: 7px; border: 1px solid var(--gold, #c9a24a); background: none; color: var(--gold, #c9a24a);
  font-weight: 700; font-size: 12px; cursor: pointer; }
.slm-motif { display: flex; align-items: center; gap: 6px; justify-content: center; margin: 10px 0 0; font-size: 12.5px; color: var(--ink-500); text-align: center; }
.slm-err { font-size: 12.5px; color: var(--danger, #e0735c); margin: 8px 0 0; }
.slm-quitter { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: 10px; margin-top: 12px; }
.slm-lien { background: none; border: 0; padding: 4px 2px; font-size: 12.5px; color: var(--ink-500); text-decoration: underline; cursor: pointer; }
.slm-danger, .slm-avert { color: var(--danger, #e0735c); }
.slm-avert { font-size: 12.5px; }
</style>
