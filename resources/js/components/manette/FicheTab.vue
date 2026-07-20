<script setup>
// Onglet Fiche perso — port de FicheTab (manette-app.jsx).
import MSym from '../ui/MSym.vue';
import Vignette from '../ui/Vignette.vue';
import PipsGauge from './PipsGauge.vue';

defineProps({
    hero: { type: Object, required: true },
    body: { type: Object, required: true },
    mind: { type: Object, required: true },
    /** Niveau réel (EtatGroupe.entites héros). */
    niveau: { type: Number, default: null },
    /** Points de compétence disponibles (/moi : points_competence). */
    points: { type: Number, default: 0 },
    /** Identifiant du groupe (lien vers l'écran montée de niveau). */
    groupe: { type: String, default: null },
    /** Nœuds d'arbre acquis, nommés ([{id, nom, type}]) — /moi + catalogue. */
    competences: { type: Array, default: () => [] },
});

const condIcon = (t) => (t === 'buff' ? 'shield_with_heart' : t === 'burn' ? 'local_fire_department' : 'coronavirus');
</script>

<template>
    <div>
        <div class="fiche-head">
            <div class="portrait"><Vignette :src="hero.img" :icon="hero.icon" fill :size="44" /><span v-if="!hero.img" class="ph-tag">portrait classe</span></div>
            <div>
                <h2>{{ hero.name }}</h2>
                <div class="lvl">{{ hero.cls }} · Niveau {{ niveau ?? hero.lvl }}</div>
                <RouterLink
                    v-if="points > 0 && groupe"
                    class="pts-badge"
                    :to="{ name: 'montee-niveau', params: { groupe } }"
                >
                    <MSym n="hub" fill :size="14" />
                    +{{ points }} point{{ points > 1 ? 's' : '' }} de compétence
                    <MSym n="chevron_right" :size="14" />
                </RouterLink>
            </div>
        </div>

        <div class="sect-title"><MSym n="casino" :size="16" /> Attributs (dés de jet)</div>
        <div class="stat-grid">
            <div class="stat"><div class="k">Attaque</div><div class="v" style="color: var(--torch)">{{ hero.atk }}<span class="die-n">dés crâne</span></div></div>
            <div class="stat"><div class="k">Défense</div><div class="v" style="color: var(--mind-bright)">{{ hero.def }}<span class="die-n">dés bouclier</span></div></div>
            <div class="stat"><div class="k">Body (attr.)</div><div class="v" style="color: var(--body-bright)">{{ hero.atkAttr }}</div></div>
            <div class="stat"><div class="k">Mind (attr.)</div><div class="v" style="color: var(--mind-bright)">{{ hero.mindAttr }}</div></div>
        </div>

        <div class="sect-title"><MSym n="ecg_heart" :size="16" /> Points de vie</div>
        <div class="gauge">
            <div class="top">
                <span class="nm" style="color: var(--body-bright)"><MSym n="favorite" fill :size="18" /> Body</span>
                <span class="val" style="color: var(--body-bright)">{{ body.cur }} / {{ body.max }}</span>
            </div>
            <PipsGauge :cur="body.cur" :max="body.max" kind="body" />
        </div>
        <div class="gauge">
            <div class="top">
                <span class="nm" style="color: var(--mind-bright)"><MSym n="psychology" fill :size="18" /> Mind</span>
                <span class="val" style="color: var(--mind-bright)">{{ mind.cur }} / {{ mind.max }}</span>
            </div>
            <PipsGauge :cur="mind.cur" :max="mind.max" kind="mind" />
        </div>

        <div class="sect-title" style="margin-top: 18px"><MSym n="emergency_heat" :size="16" /> Conditions</div>
        <div v-if="hero.conds.length" class="badges">
            <span v-for="(c, i) in hero.conds" :key="i" class="badge" :class="'b-' + c.t">
                <MSym :n="condIcon(c.t)" fill :size="16" />
                {{ c.l }} <span class="dur">{{ c.d }}t</span>
            </span>
        </div>
        <div v-else class="empty-note" style="padding: 12px">Aucune condition active.</div>

        <div class="sect-title" style="margin-top: 18px"><MSym n="hub" :size="16" /> Talents acquis</div>
        <div v-if="competences.length" class="talent-list">
            <div v-for="c in competences" :key="c.id" class="talent-item">
                <span class="ti"><MSym n="workspace_premium" fill :size="16" /></span>
                <div class="tbody">
                    <div class="tn">{{ c.nom }}</div>
                    <div v-if="c.description" class="tdesc">{{ c.description }}</div>
                </div>
            </div>
        </div>
        <div v-else class="empty-note" style="padding: 12px">Aucun talent acquis pour l'instant.</div>
    </div>
</template>

<style scoped>
/* Talents acquis (fiche) : nom + description lisible (doc 01 §6). */
.talent-list { display: flex; flex-direction: column; gap: 8px; }
.talent-item { display: flex; align-items: flex-start; gap: 11px; padding: 11px 13px; border-radius: var(--r-md, 10px);
  background: linear-gradient(180deg, oklch(0.24 0.02 90 / 0.35), var(--stone-850)); border: 1px solid oklch(0.62 0.08 80 / 0.35); }
.talent-item .ti { width: 30px; height: 30px; border-radius: 9px; display: grid; place-items: center; flex: none;
  background: linear-gradient(150deg, var(--gold), var(--ember-deep)); color: var(--stone-950); }
.talent-item .tbody { min-width: 0; }
.talent-item .tn { font-size: 14px; font-weight: 700; color: var(--parch-100); }
.talent-item .tdesc { font-size: 12px; color: var(--ink-300); margin-top: 2px; line-height: 1.4; }
</style>
