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
    /**
     * Talents et capacités de carte acquis, nommés par le catalogue et
     * ACCOMPAGNÉS DE LEUR ÉTAT D'USAGE publié par `/moi` :
     * `[{id, nom, description, statut, libelle, raison, cadence}]`.
     */
    competences: { type: Array, default: () => [] },
});

const condIcon = (t) => (t === 'buff' ? 'shield_with_heart' : t === 'burn' ? 'local_fire_department' : 'coronavirus');

/* ⚠ Icône seulement : le STATUT et son LIBELLÉ viennent du serveur
   (`Talents::STATUTS`). Le client n'en déduit rien — il ne sait pas ce qu'est
   une fenêtre « une fois par quête », et c'est voulu. */
const STATUT_ICONE = {
    permanent: 'all_inclusive',
    disponible: 'check_circle',
    indisponible: 'lock',
};
const statutIcone = (c) => STATUT_ICONE[c.statut] ?? 'workspace_premium';
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
            <!-- Dés EFFECTIFS : base (équipement + talents) + bonus temporaire
                 des buffs actifs. Sans le bonus, un joueur qui venait de lancer
                 Peau de Pierre ou de boire une Potion de force voyait le même
                 chiffre qu'avant et ne pouvait pas vérifier ce qu'il avait payé
                 — alors que le moteur, lui, l'ajoutait bien au jet. -->
            <div class="stat">
                <div class="k">Attaque</div>
                <div class="v" style="color: var(--torch)">
                    {{ hero.atk + hero.bonusAtk }}<span class="die-n">dés crâne</span>
                    <span v-if="hero.bonusAtk" class="buff">+{{ hero.bonusAtk }} temporaire</span>
                </div>
            </div>
            <div class="stat">
                <div class="k">Défense</div>
                <div class="v" style="color: var(--mind-bright)">
                    {{ hero.def + hero.bonusDef }}<span class="die-n">dés bouclier</span>
                    <span v-if="hero.bonusDef" class="buff">+{{ hero.bonusDef }} temporaire</span>
                </div>
            </div>
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
            <div
                v-for="c in competences"
                :key="c.id"
                class="talent-item"
                :class="'talent-' + (c.statut || 'permanent')"
            >
                <span class="ti"><MSym :n="statutIcone(c)" fill :size="16" /></span>
                <div class="tbody">
                    <div class="tn">{{ c.nom }}</div>
                    <div v-if="c.description" class="tdesc">{{ c.description }}</div>
                    <!-- Une capacité épuisée RESTE affichée, grisée, avec la
                         règle qui la ferme : la cacher ferait croire au joueur
                         qu'il l'a perdue. -->
                    <div v-if="c.libelle" class="tstat">
                        <span class="tpuce">{{ c.libelle }}</span>
                        <span v-if="c.cadence" class="tcad">{{ c.cadence }}</span>
                    </div>
                    <div v-if="c.raison" class="traison">
                        <MSym n="info" :size="13" /> {{ c.raison }}
                    </div>
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

/* État d'usage (2026-09-14) : la puce porte le libellé DÉCIDÉ par le serveur,
   la couleur n'est qu'un rappel. ⚠ Classes préfixées `t…` : les blocs <style>
   de ce projet fuient d'une vue à l'autre. */
.talent-item .tstat { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 7px; }
.talent-item .tpuce {
    font-size: 10.5px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase;
    padding: 2px 8px; border-radius: 999px; border: var(--line);
    color: var(--ink-300); background: var(--stone-900);
}
.talent-item .tcad { font-size: 11px; color: var(--ink-500); }
.talent-item .traison {
    display: flex; align-items: flex-start; gap: 5px; margin-top: 5px;
    font-size: 11.5px; line-height: 1.35; color: var(--ink-500);
}
.talent-item .traison .msym { flex: none; margin-top: 1px; }

.talent-disponible .tpuce { color: var(--ok); border-color: currentColor; }
/* Grisé, jamais caché — et le nom reste lisible : le joueur doit pouvoir
   retrouver sa capacité pour comprendre POURQUOI elle est fermée.
   ⚠ C'est le SCEAU qui s'éteint (le dégradé d'or part), pas la couleur du
   glyphe : sur ce dégradé un glyphe gris ne se verrait tout simplement plus. */
.talent-item.talent-indisponible { opacity: .66; border-color: oklch(0.44 0.016 255 / 0.5); }
.talent-item.talent-indisponible .ti { background: var(--stone-800); color: var(--ink-500); }

/* Bonus TEMPORAIRE d'un buff actif : distinct du chiffre de base, pour qu'on
   voie d'où vient la différence — et qu'on la voie disparaître. */
.buff { display: block; font-size: 10.5px; font-weight: 700; letter-spacing: 0.03em;
  color: var(--gold, #d8a23a); text-transform: uppercase; margin-top: 2px; }
</style>
