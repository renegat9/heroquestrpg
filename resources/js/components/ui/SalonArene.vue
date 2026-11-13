<script setup>
// MODE ARÈNE, lot A5 — le SALON d'un combat d'arène, sur la manette ET sur la table (`docs/plan-mode-arene.md` §7 bis,
// `docs/contrat-api.md` §Le salon et les routes d'arène).
//
// ⚠ Rien ici n'est décidé. Le serveur publie le salon entier (`groupe.arene`) : les combattants et leur « prêt », le
// Defender, la LISTE BLANCHE des monstres (que `PUT …/defender` revalide), la décision de lancement et son motif, le
// résultat et le bandeau d'étanchéité. Ce composant ne connaît ni le roster du tournoi, ni la taille maximale d'un
// Defender au-delà de ce que le serveur refuse, ni ce qui rend un combat lançable : il rend ce qu'il reçoit.
import { computed, ref } from 'vue';
import MSym from './MSym.vue';

const props = defineProps({
    // `groupe.arene` : {combattants, defender, monstres, lancement_possible, lancement_motif, resultat, bandeau}.
    salon: { type: Object, required: true },
    // Code du combat (à donner aux autres joueurs et à la table).
    code: { type: String, required: true },
    // Doublure pilotée sur CE téléphone (manette) ; null sur la table.
    monPersonnageId: { type: Number, default: null },
    // Un geste est en cours (gèle les boutons).
    enCours: { type: Boolean, default: false },
    // Message d'erreur du dernier geste refusé par le serveur.
    erreur: { type: String, default: '' },
});
const emit = defineEmits(['ajouter', 'retirer', 'pret', 'allie', 'lancer', 'rejouer', 'quitter']);

const monCombattant = computed(() =>
    props.salon.combattants.find((c) => c.personnage_id === props.monPersonnageId) ?? null);

// Chaque ajout ou retrait part au serveur (`POST`/`DELETE …/defender`), qui republie le salon : la puissance des deux
// équipes et les monstres encore abordables (lot T3) sont DÉCIDÉS côté serveur, jamais additionnés ici.
const enSalon = computed(() => !props.salon.resultat);

const confirmerQuitter = ref(false);
</script>

<template>
    <div class="sla">
        <div class="sla-bandeau"><MSym n="shield_lock" fill :size="15" /> {{ salon.bandeau }}</div>

        <div class="sla-code">
            <span class="sla-code-lbl">Code du combat</span>
            <code>{{ code }}</code>
        </div>

        <!-- ---- résultat (après le combat) ---- -->
        <div v-if="salon.resultat" class="sla-resultat" :class="salon.resultat.issue">
            <MSym :n="salon.resultat.issue === 'victoire' ? 'emoji_events' : 'skull'" fill :size="28" />
            <div>{{ salon.resultat.issue === 'victoire' ? 'Victoire ! Le Defender est vaincu.' : 'Défaite… tous les combattants sont à terre.' }}</div>
        </div>

        <!-- ---- combattants ---- -->
        <div class="sla-titre">
            <MSym n="groups" fill :size="15" /> Challenger
            <span class="sla-puissance">puissance {{ salon.puissance_challenger }}</span>
        </div>
        <div v-if="!salon.combattants.length" class="sla-vide">Aucun combattant pour l'instant.</div>
        <div v-for="c in salon.combattants" :key="c.personnage_id" class="sla-ligne" :class="{ pret: c.pret }">
            <MSym :n="c.pret ? 'check_circle' : 'radio_button_unchecked'" fill :size="15" />
            <span class="sla-nom">{{ c.nom }}</span>
            <span class="sla-sub">{{ c.classe }}<template v-if="c.joueur"> · {{ c.joueur }}</template> · {{ c.puissance }}</span>
            <span v-if="c.allie" class="sla-sub sla-allie"><MSym n="pets" :size="12" /> {{ c.allie.nom }} · {{ c.allie.puissance }}</span>
        </div>

        <!-- ---- mon allié (lot A4) : un par combattant, sans coût — la liste vient du serveur ---- -->
        <div v-if="enSalon && monCombattant && salon.allies?.length" class="sla-allies">
            <span class="sla-code-lbl">Mon allié</span>
            <div class="sla-chips">
                <button class="sla-chip sla-chip-allie" :class="{ actif: !monCombattant.allie }" :disabled="enCours"
                    @click="emit('allie', null)">Aucun</button>
                <button
                    v-for="a in salon.allies"
                    :key="a.id"
                    class="sla-chip sla-chip-allie"
                    :class="{ actif: monCombattant.allie?.mercenaire_id === a.id }"
                    :disabled="enCours"
                    :title="`Déplacement ${a.deplacement} · attaque ${a.attaque} · défense ${a.defense} · ${a.pv_body} PV`"
                    @click="emit('allie', a.id)"
                >
                    {{ a.nom }} <span class="sla-cout">{{ a.puissance }}</span>
                </button>
            </div>
        </div>

        <!-- ---- Defender ---- -->
        <div class="sla-titre">
            <MSym n="skull" fill :size="15" /> Defender
            <span class="sla-puissance">puissance {{ salon.puissance_defender }} / {{ salon.puissance_challenger }}</span>
        </div>
        <div class="sla-chips">
            <span v-if="!salon.defender.length" class="sla-vide">Aucune figure choisie.</span>
            <button
                v-for="(m, i) in salon.defender"
                :key="`${m.monstre_id}-${i}`"
                class="sla-chip"
                :disabled="!enSalon || enCours"
                :title="enSalon ? 'Retirer' : ''"
                @click="emit('retirer', i)"
            >
                {{ m.nom_base }} <MSym v-if="enSalon" n="close" :size="12" />
            </button>
        </div>
        <div v-if="enSalon" class="sla-roster">
            <button
                v-for="m in salon.monstres"
                :key="m.id"
                class="sla-monstre"
                :disabled="enCours || !m.abordable"
                :title="m.abordable ? '' : 'Trop puissant pour ce qui reste'"
                @click="emit('ajouter', m.id)"
            >
                <span class="sla-nom">+ {{ m.nom_base }} <span class="sla-cout">{{ m.puissance }}</span></span>
                <span class="sla-stats">
                    <MSym n="directions_run" :size="12" />{{ m.deplacement }}
                    <MSym n="swords" :size="12" />{{ m.attaque }}
                    <MSym n="shield" :size="12" />{{ m.defense }}
                    <MSym n="favorite" fill :size="12" />{{ m.pv_body }}
                </span>
            </button>
        </div>
        <!-- ---- prêt (manette) / lancer (tous) ---- -->
        <button
            v-if="enSalon && monCombattant"
            class="sla-btn"
            :class="{ secondaire: monCombattant.pret }"
            :disabled="enCours"
            @click="emit('pret', !monCombattant.pret)"
        >
            <MSym :n="monCombattant.pret ? 'cancel' : 'check_circle'" fill :size="16" />
            {{ monCombattant.pret ? 'Je ne suis plus prêt' : 'Je suis prêt !' }}
        </button>

        <button v-if="enSalon && salon.lancement_possible" class="sla-btn sla-lancer" :disabled="enCours" @click="emit('lancer')">
            <MSym n="swords" fill :size="18" /> Lancer le combat
        </button>
        <p v-else-if="enSalon" class="sla-motif"><MSym n="hourglass_top" :size="14" /> {{ salon.lancement_motif }}</p>

        <button v-if="salon.resultat" class="sla-btn sla-lancer" :disabled="enCours" @click="emit('rejouer')">
            <MSym n="replay" :size="18" /> Revanche
        </button>

        <p v-if="erreur" class="sla-err"><MSym n="error" :size="13" /> {{ erreur }}</p>

        <div class="sla-quitter">
            <!-- Les règles, dans un nouvel onglet : le combat reste sous les yeux. Onglet « Arène » du guide (2026-11-12). -->
            <a class="sla-lien sla-regles" href="/guide?onglet=arene" target="_blank" rel="noopener">
                <MSym n="menu_book" :size="13" /> Règles de l'arène
            </a>
            <button v-if="!confirmerQuitter" class="sla-lien" :disabled="enCours" @click="confirmerQuitter = true">
                Quitter l'arène
            </button>
            <template v-else>
                <span class="sla-avert">Le combat sera fermé pour tout le monde.</span>
                <button class="sla-lien" :disabled="enCours" @click="confirmerQuitter = false">Annuler</button>
                <button class="sla-lien sla-danger" :disabled="enCours" @click="emit('quitter')">Fermer le combat</button>
            </template>
        </div>
    </div>
</template>

<style scoped>
.sla { padding: 12px; border: 1px solid var(--gold, #c9a24a); border-radius: 12px; background: rgba(201, 162, 90, 0.06); }
.sla-bandeau { display: flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 700; color: var(--gold, #c9a24a);
  margin-bottom: 10px; }
.sla-code { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 12px;
  padding: 8px 10px; border-radius: 9px; background: var(--panel-2, oklch(0.22 0.02 70 / 0.4)); }
.sla-code-lbl { font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--ink-500); }
.sla-code code { font-size: 16px; font-weight: 800; letter-spacing: 0.08em; }
.sla-resultat { display: flex; align-items: center; gap: 10px; padding: 12px; margin-bottom: 12px; border-radius: 10px;
  font-weight: 700; font-size: 14px; }
.sla-resultat.victoire { color: var(--ok, #6fbf73); border: 1px solid var(--ok, #6fbf73); }
.sla-resultat.defaite { color: var(--danger, #e0735c); border: 1px solid var(--danger, #e0735c); }
.sla-titre { display: flex; align-items: center; gap: 6px; margin: 10px 0 6px; font-weight: 800; font-size: 12px;
  text-transform: uppercase; letter-spacing: 0.06em; color: var(--ink-300, #cfc3ad); }
.sla-vide { font-size: 12.5px; color: var(--ink-500); font-style: italic; }
.sla-ligne { display: flex; align-items: center; gap: 8px; padding: 5px 0; color: var(--ink-500); }
.sla-ligne.pret { color: var(--ok, #6fbf73); }
.sla-nom { font-weight: 700; font-size: 13.5px; color: var(--ink-100, #efe6d4); }
.sla-sub { font-size: 12px; color: var(--ink-500); }
.sla-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
.sla-chip { display: inline-flex; align-items: center; gap: 4px; padding: 4px 9px; border-radius: 99px; font-size: 12.5px;
  font-weight: 700; border: 1px solid var(--danger, #e0735c); color: var(--ink-100, #efe6d4); background: none; cursor: pointer; }
.sla-chip:disabled { cursor: default; }
.sla-allie { margin-left: auto; }
.sla-allies { margin: 6px 0 4px; }
.sla-chip-allie { border-color: var(--line-soft, oklch(0.4 0.02 70 / 0.4)); }
.sla-chip-allie.actif { border-color: var(--gold, #c9a24a); color: var(--gold, #c9a24a); }
.sla-roster { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 6px; margin-bottom: 8px; }
.sla-monstre { display: flex; flex-direction: column; align-items: flex-start; gap: 2px; padding: 7px 9px; border-radius: 9px;
  border: 1px solid var(--line-soft, oklch(0.4 0.02 70 / 0.4)); background: var(--panel-2, oklch(0.22 0.02 70 / 0.4));
  cursor: pointer; text-align: left; }
.sla-monstre:disabled { opacity: 0.45; cursor: default; }
.sla-puissance { margin-left: auto; font-size: 11px; font-weight: 700; letter-spacing: 0.02em; text-transform: none; color: var(--gold, #c9a24a); }
.sla-cout { margin-left: 4px; font-size: 11px; font-weight: 800; color: var(--gold, #c9a24a); }
.sla-stats { display: flex; align-items: center; gap: 3px; font-size: 11.5px; color: var(--ink-500); }
.sla-btn { width: 100%; margin-top: 8px; padding: 10px; border: 0; border-radius: 9px; display: flex; align-items: center;
  justify-content: center; gap: 6px; font-weight: 700; font-size: 14px; cursor: pointer;
  background: var(--gold, #c9a24a); color: #1a1204; }
.sla-btn.secondaire { background: var(--panel-3, oklch(0.28 0.02 70 / 0.6)); color: var(--ink-100, #efe6d4); }
.sla-btn:disabled { opacity: 0.6; cursor: default; }
.sla-lancer { font-size: 15px; }
.sla-motif { display: flex; align-items: center; gap: 6px; justify-content: center; margin: 10px 0 0; font-size: 12.5px;
  color: var(--ink-500); text-align: center; }
.sla-err { font-size: 12.5px; color: var(--danger, #e0735c); margin: 8px 0 0; }
.sla-quitter { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: 10px; margin-top: 12px; }
.sla-lien { background: none; border: 0; padding: 4px 2px; font-size: 12.5px; color: var(--ink-500); text-decoration: underline; cursor: pointer; }
.sla-regles { margin-right: auto; display: inline-flex; align-items: center; gap: 4px; color: var(--ink-500); }
.sla-regles:hover { color: var(--gold, #c9a24a); }
.sla-danger { color: var(--danger, #e0735c); }
.sla-avert { font-size: 12.5px; color: var(--danger, #e0735c); }
</style>
