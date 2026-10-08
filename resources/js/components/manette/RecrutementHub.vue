<script setup>
// Recrutement d'alliés au hub (doc 14 §3.5) — bourse commune. Le joueur
// embauche un mercenaire/compagnon contre l'or COMMUN du groupe, avant une
// quête. L'allié persiste d'une quête à l'autre contre un entretien de 10 po
// par quête, et le recrutement n'ouvre qu'au statut de Gardien.
//
// ⚠ Rien ici n'est recalculé. Le serveur publie, héros par héros, le PRIX
// RÉEL (remise de la Potion de charme comprise) et le VERDICT (recrutable ou
// non, et le motif) dans `groupe.recrutement.offres`. Ce composant choisit la
// ligne de SON héros et l'affiche : il ne compare plus l'or au prix catalogue
// — c'est cette comparaison qui grisait un recrutement que le serveur aurait
// accepté avec le rabais.
import MSym from '../ui/MSym.vue';
import Vignette from '../ui/Vignette.vue';

const props = defineProps({
    // Catalogue recrutable (GET /mercenaires).
    catalogue: { type: Array, default: () => [] },
    // Alliés déjà recrutés (EtatGroupe.groupe.mercenaires au hub).
    recrues: { type: Array, default: () => [] },
    // Or de la bourse COMMUNE (EtatGroupe.groupe.or).
    or: { type: Number, default: 0 },
    // Décisions publiées (EtatGroupe.groupe.recrutement.offres) :
    // [{mercenaire_id, decisions: [{personnage_id, nom, prix, prix_catalogue,
    //   rabais_po, recrutable, motif}]}].
    offres: { type: Array, default: () => [] },
    // Le héros dont on affiche la ligne (celui que cette manette pilote).
    personnageId: { type: Number, default: null },
    // Remise de Potion de charme de CE héros (/moi) : {restants, po}.
    rabais: { type: Object, default: null },
    // Un recrutement est en cours (gèle les boutons).
    enCours: { type: Boolean, default: false },
});
const emit = defineEmits(['recruter']);

// La décision du serveur pour CE héros sur CET allié. Absente (payload
// incomplet) : on ne la devine pas, l'allié reste indisponible.
function decision(m) {
    const offre = props.offres.find((o) => o.mercenaire_id === m.id);
    return offre?.decisions.find((d) => d.personnage_id === props.personnageId) ?? null;
}

// Motif de blocage tel que le serveur l'a écrit (null = recrutable).
function motif(m) {
    const d = decision(m);
    if (!d) return 'Indisponible pour l\'instant';
    return d.recrutable ? null : d.motif;
}

const TYPE_ICON = { archer: 'target', hallebardier: 'shield', compagnon: 'pets' };
</script>

<template>
    <div class="recrut">
        <div class="sect-title"><MSym n="groups" :size="16" /> Recruter un allié</div>
        <div class="recrut-bourse">
            <MSym n="paid" fill :size="15" /> Bourse commune : <b>{{ or }}</b> or
        </div>
        <p class="recrut-note">
            L'allié est un renfort scripté, embauché avec l'or du groupe. Il reste
            avec vous d'une quête à l'autre contre 10 po d'entretien par quête ;
            impayé, il quitte le groupe.
        </p>
        <p v-if="rabais && rabais.restants > 0" class="recrut-rabais">
            <MSym n="science" fill :size="15" />
            Potion de charme : {{ rabais.restants }} recrutement{{ rabais.restants > 1 ? 's' : '' }}
            à {{ rabais.po }} po de moins.
        </p>

        <div v-for="m in catalogue" :key="m.id" class="recrut-carte" :class="{ off: !!motif(m) }">
            <div class="recrut-tete">
                <span class="recrut-ic"><Vignette :src="m.image_url" :icon="TYPE_ICON[m.type] || 'swords'" /></span>
                <div class="recrut-nom">
                    <div class="rn">{{ m.nom }}</div>
                    <div class="rt">{{ m.animal ? 'Compagnon animal' : 'Mercenaire' }}</div>
                </div>
                <div class="recrut-prix">
                    <template v-if="decision(m)">
                        <s v-if="decision(m).rabais_po > 0" class="recrut-prix-catalogue">{{ decision(m).prix_catalogue }}</s>
                        <MSym n="paid" fill :size="13" /> {{ decision(m).prix }}
                    </template>
                    <template v-else><MSym n="paid" fill :size="13" /> {{ m.prix }}</template>
                </div>
            </div>
            <p class="recrut-desc">{{ m.description }}</p>
            <div class="recrut-stats">
                <span class="st"><MSym n="directions_run" :size="14" /> {{ m.deplacement }}</span>
                <span class="st">
                    <MSym :n="m.portee === 'distance' ? 'my_location' : 'swords'" :size="14" />
                    {{ m.portee === 'distance' ? m.attaque_distance : m.attaque }}
                </span>
                <span class="st"><MSym n="shield" :size="14" /> {{ m.defense }}</span>
                <span class="st"><MSym n="favorite" fill :size="14" /> {{ m.pv_body }}</span>
            </div>
            <button
                class="recrut-btn"
                :disabled="enCours || !!motif(m)"
                @click="emit('recruter', m.id)"
            >
                <template v-if="motif(m)">{{ motif(m) }}</template>
                <template v-else><MSym n="handshake" :size="16" /> Recruter</template>
            </button>
        </div>

        <template v-if="recrues.length">
            <div class="sect-title"><MSym n="diversity_3" :size="16" /> Alliés recrutés</div>
            <div v-for="r in recrues" :key="r.id" class="recrut-recrue">
                <span class="recrut-ic"><Vignette :src="r.image_url" :icon="TYPE_ICON[r.type] || 'swords'" /></span>
                <div class="recrut-nom"><div class="rn">{{ r.nom }}</div></div>
                <span class="recrut-pv"><MSym n="favorite" fill :size="13" /> {{ r.pv_body }}/{{ r.pv_body_max }}</span>
            </div>
        </template>
        <p v-else class="recrut-vide">Aucun allié recruté pour l'instant.</p>
    </div>
</template>

<style scoped>
.recrut { padding-top: 4px; }
.recrut-bourse {
    display: flex; align-items: center; gap: 6px;
    font-size: 14px; color: var(--gold, #c9a24a); font-weight: 700; margin: 0 0 6px;
}
.recrut-note {
    font-size: 12px; color: var(--ink-500); font-style: italic;
    margin: 0 0 14px;
}
.recrut-rabais {
    display: flex; align-items: center; gap: 6px;
    font-size: 13px; font-weight: 700; color: var(--gold, #c9a24a); margin: 0 0 12px;
}
.recrut-carte {
    border: 1px solid var(--line-soft, oklch(0.4 0.02 70 / 0.4));
    border-radius: 12px; padding: 12px; margin-bottom: 12px;
    background: var(--panel-2, oklch(0.22 0.02 70 / 0.4));
}
.recrut-carte.off { opacity: 0.6; }
.recrut-tete { display: flex; align-items: center; gap: 10px; }
.recrut-ic {
    display: grid; place-items: center; width: 44px; height: 44px; flex: none; overflow: hidden;
    border-radius: 9px; background: var(--panel-3, oklch(0.28 0.02 70 / 0.5));
    color: var(--gold, #c9a24a);
}
/* L'illustration de l'allié (2026-10-01) remplit la pastille ; sans elle,
   l'icône de type reste centrée comme avant. */
.recrut-ic .vignette-img { width: 100%; height: 100%; object-fit: cover; }
.recrut-nom { flex: 1; min-width: 0; }
.recrut-nom .rn { font-weight: 700; font-size: 14px; }
.recrut-nom .rt { font-size: 11px; color: var(--ink-500); text-transform: uppercase; letter-spacing: 0.04em; }
.recrut-prix {
    display: flex; align-items: center; gap: 4px; flex: none;
    font-weight: 800; color: var(--gold, #c9a24a); font-size: 15px;
}
.recrut-prix-catalogue { font-weight: 600; color: var(--ink-500); font-size: 12px; margin-right: 2px; }
.recrut-desc {
    font-family: var(--font-narr); font-style: italic; font-size: 13px;
    color: var(--ink-300, #cfc3ad); margin: 8px 0 10px; line-height: 1.35;
}
.recrut-stats { display: flex; gap: 14px; margin-bottom: 12px; }
.recrut-stats .st {
    display: flex; align-items: center; gap: 4px;
    font-size: 13px; font-weight: 700; color: var(--ink-300, #cfc3ad);
}
.recrut-btn {
    width: 100%; padding: 9px; border: 0; border-radius: 9px;
    display: flex; align-items: center; justify-content: center; gap: 6px;
    font-weight: 700; font-size: 14px; cursor: pointer;
    background: var(--gold, #c9a24a); color: #1a1204;
}
.recrut-btn:disabled {
    background: var(--panel-3, oklch(0.28 0.02 70 / 0.6));
    color: var(--ink-500); cursor: default;
}
.recrut-recrue {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 0; border-bottom: 1px solid var(--line-soft, oklch(0.4 0.02 70 / 0.25));
}
.recrut-pv {
    margin-left: auto; display: flex; align-items: center; gap: 4px;
    font-weight: 700; font-size: 13px; color: var(--body-bright, #e0574a);
}
.recrut-vide { font-size: 13px; color: var(--ink-500); font-style: italic; margin: 4px 0 0; }
</style>
