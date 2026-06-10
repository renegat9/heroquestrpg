<script setup>
// Panneau de phase marché — écran de table (doc 04 §5, vue partagée) :
// or commun en permanence, panier consolidé étiqueté par joueur (achats et
// ventes), total projeté recalculé en direct, état des confirmations.
import { computed } from 'vue';
import MSym from '../ui/MSym.vue';
import { marcheVersConsolide, PROFILS_MARCHE } from '../../store/game';

const props = defineProps({
    /** EtatMarche du contrat (profil, inventaire, paniers, total_projete, or_courant). */
    marche: { type: Object, required: true },
});

const emit = defineEmits(['annuler']);

const profilLabel = computed(() => PROFILS_MARCHE[props.marche.profil] ?? props.marche.profil ?? '');
const consolide = computed(() => marcheVersConsolide(props.marche));
const confirmes = computed(() => consolide.value.filter((p) => p.confirme).length);
const totalProjete = computed(() => props.marche.total_projete ?? props.marche.or_courant ?? 0);
</script>

<template>
    <div class="mkpanel">
        <div class="mk-head">
            <span class="mk-title"><MSym n="storefront" fill :size="22" /> Phase de marché{{ profilLabel ? ' · ' + profilLabel : '' }}</span>
            <span class="mk-gold"><MSym n="paid" :size="18" /> {{ marche.or_courant ?? 0 }} or commun</span>
        </div>

        <!-- panier consolidé, étiqueté par joueur -->
        <div class="mk-baskets">
            <div v-for="p in consolide" :key="p.joueur_id" class="mk-basket" :class="{ ok: p.confirme }">
                <div class="mk-bh">
                    <span class="mk-pseudo">{{ p.pseudo ?? `Joueur n°${p.joueur_id}` }}</span>
                    <span class="mk-conf" :class="{ ok: p.confirme }">
                        <MSym :n="p.confirme ? 'check_circle' : 'hourglass_top'" :size="15" :fill="p.confirme" />
                        {{ p.confirme ? 'Confirmé' : 'Compose…' }}
                    </span>
                </div>
                <div v-if="!p.lignes.length" class="mk-ligne vide">— panier vide —</div>
                <div v-for="(l, i) in p.lignes" :key="i" class="mk-ligne" :class="l.type">
                    <MSym :n="l.type === 'achat' ? 'shopping_cart' : 'sell'" :size="14" />
                    <span class="nm">{{ l.nom }}<template v-if="l.quantite > 1"> ×{{ l.quantite }}</template></span>
                    <span class="mt" :class="{ pos: (l.montant ?? 0) > 0 }">
                        {{ l.montant == null ? '—' : (l.montant > 0 ? `+${l.montant}` : l.montant) + ' or' }}
                    </span>
                </div>
            </div>
        </div>

        <div class="mk-foot">
            <span class="mk-confirms">
                <MSym n="how_to_reg" :size="16" /> {{ confirmes }}/{{ consolide.length || '?' }} paniers confirmés
            </span>
            <span class="mk-total">Total projeté <b>{{ totalProjete }} or</b></span>
            <button class="btn mk-cancel" @click="emit('annuler')">
                <MSym n="close" :size="16" /> Annuler le marché
            </button>
        </div>
    </div>
</template>

<style>
/* phase marché sur la table — mêmes tokens que les maquettes */
.table-screen .mkpanel { display: flex; flex-direction: column; gap: 12px; width: min(720px, 92%); max-height: 100%;
  overflow-y: auto; padding: 18px 20px; border-radius: var(--r-lg); border: var(--line-strong);
  background: linear-gradient(180deg, var(--stone-850), var(--stone-900)); box-shadow: var(--sh-3); }
.table-screen .mk-head { display: flex; align-items: center; justify-content: space-between; gap: 14px; }
.table-screen .mk-title { font-family: var(--font-display); font-size: 18px; color: var(--parch-100);
  letter-spacing: 0.04em; display: inline-flex; align-items: center; gap: 9px; }
.table-screen .mk-title .msym { color: var(--torch); }
.table-screen .mk-gold { display: inline-flex; align-items: center; gap: 6px; font-weight: 800; color: var(--gold);
  font-variant-numeric: tabular-nums; background: oklch(0.74 0.15 78 / 0.1); border: 1px solid oklch(0.74 0.15 78 / 0.35);
  border-radius: 99px; padding: 6px 14px; font-size: 14px; }
.table-screen .mk-baskets { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px; }
.table-screen .mk-basket { background: var(--stone-850); border: var(--line); border-radius: var(--r-md); padding: 10px 12px; transition: border-color .25s; }
.table-screen .mk-basket.ok { border-color: var(--ok); }
.table-screen .mk-bh { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
.table-screen .mk-pseudo { font-size: 11px; color: var(--torch); font-weight: 700; background: oklch(0.76 0.155 65 / 0.12);
  padding: 2px 9px; border-radius: 99px; }
.table-screen .mk-conf { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 700; color: var(--ink-500); }
.table-screen .mk-conf.ok { color: var(--ok); }
.table-screen .mk-ligne { display: flex; align-items: center; gap: 7px; font-size: 12.5px; color: var(--ink-300); padding: 3px 0; }
.table-screen .mk-ligne.vide { color: var(--ink-700); font-style: italic; justify-content: center; }
.table-screen .mk-ligne .msym { color: var(--ink-500); }
.table-screen .mk-ligne.vente .msym { color: var(--ok); }
.table-screen .mk-ligne .nm { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.table-screen .mk-ligne .mt { font-weight: 700; font-variant-numeric: tabular-nums; color: var(--body-bright); }
.table-screen .mk-ligne .mt.pos { color: var(--ok); }
.table-screen .mk-foot { display: flex; align-items: center; gap: 16px; border-top: var(--line); padding-top: 12px; }
.table-screen .mk-confirms { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 700; color: var(--ink-300); }
.table-screen .mk-total { margin-left: auto; font-size: 14px; color: var(--ink-300); }
.table-screen .mk-total b { font-family: var(--font-display); font-size: 20px; color: var(--gold); margin-left: 8px; }
.table-screen .mk-cancel { padding: 8px 13px; font-size: 12.5px; }
</style>
