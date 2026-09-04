<script setup>
// Onglet Sac (équipement, inventaire) — port de SacTab (manette-app.jsx).
import { computed, ref } from 'vue';
import MSym from '../ui/MSym.vue';
import { RARETE_LABELS, rareteVersCle } from '../../store/game';

const props = defineProps({
    // Équipement réel (/moi.equipement) : armes/armure portent {inventaire_id, nom},
    // chaque objet du sac porte `equipable` (pièce montable dans un slot).
    equipement: { type: Object, default: () => ({ armes: [], armure: null, sac: [] }) },
    // Potions réelles du héros (/moi.consommables).
    potions: { type: Array, default: () => [] },
    // Gérer l'équipement n'est possible qu'au hub (entre deux quêtes).
    auHub: { type: Boolean, default: false },
    // Un appel équiper/déséquiper est en cours (gèle les boutons).
    equipEnCours: { type: Boolean, default: false },
    // Autres héros actifs du groupe : destinataires possibles d'un don.
    compagnons: { type: Array, default: () => [] },
});
const emit = defineEmits(['equiper', 'desequiper', 'donner']);

/* ---- Don d'un objet à un compagnon (hub, doc 01 §7). Le sélecteur s'ouvre
   sous la ligne concernée plutôt que dans une modale : le sac est déjà un
   panneau, en empiler un second sur un écran de téléphone ferait perdre le
   contexte de l'objet qu'on donne. ---- */
const donEnCours = ref(null); // inventaire_id dont le sélecteur est ouvert

function basculerDon(inventaireId) {
    donEnCours.value = donEnCours.value === inventaireId ? null : inventaireId;
}

function confirmerDon(inventaireId, versPersonnageId, quantite = 1) {
    donEnCours.value = null;
    emit('donner', { inventaireId, versPersonnageId, quantite });
}

// Un don n'a de sens qu'au hub, et qu'avec au moins un compagnon.
const peutDonner = computed(() => props.auHub && props.compagnons.length > 0);

// Sac en dépassement : un butin de quête est remis MÊME sac plein (refuser un
// artefact unique le perdrait à jamais). Le héros régularise en équipant la
// pièce — elle quitte le sac — ou en écoulant au marché.
/* Nommer la MAIN : depuis le dual-wielding, `arme_secondaire` peut porter un
   bouclier OU une seconde arme, et « Arme équipée » deux fois de suite ne dirait
   pas laquelle est où. */
function libelleMain(a) {
    if (a.bouclier) return 'Bouclier équipé';
    if (a.deux_mains) return 'Arme à deux mains';
    return a.emplacement === 'arme_secondaire' ? 'Arme — main gauche' : 'Arme — main droite';
}

const deborde = computed(() => {
    const cap = props.equipement?.capacite;
    const occ = props.equipement?.occupation;
    return Number.isFinite(cap) && Number.isFinite(occ) && occ > cap;
});
</script>

<template>
    <div>
        <div class="sect-title"><MSym n="checkroom" :size="16" /> Équipement</div>

        <!-- pièces équipées : chacune déséquipable au hub -->
        <div v-if="equipement.armes.length" v-for="a in equipement.armes" :key="`arme-${a.inventaire_id}`" class="item">
            <span class="ic"><MSym :n="a.bouclier ? 'shield' : 'swords'" /></span>
            <div>
                <div class="nm">{{ a.nom }}</div>
                <div class="rar">{{ libelleMain(a) }}</div>
            </div>
            <button v-if="auHub" class="sac-btn ghost" :disabled="equipEnCours" @click="emit('desequiper', a.inventaire_id)">Déséquiper</button>
        </div>
        <div v-if="equipement.armure" class="item">
            <span class="ic"><MSym n="shield" /></span>
            <div><div class="nm">{{ equipement.armure.nom }}</div><div class="rar">Armure équipée</div></div>
            <button v-if="auHub" class="sac-btn ghost" :disabled="equipEnCours" @click="emit('desequiper', equipement.armure.inventaire_id)">Déséquiper</button>
        </div>
        <!-- Le casque a son propre slot : il se CUMULE avec l'armure de corps.
             `equipement.casque` peut manquer sur une réponse antérieure au
             slot — d'où l'optionnel, comme partout ailleurs sur cette fiche. -->
        <div v-if="equipement.casque" class="item">
            <span class="ic"><MSym n="sports_martial_arts" /></span>
            <div><div class="nm">{{ equipement.casque.nom }}</div><div class="rar">Casque équipé</div></div>
            <button v-if="auHub" class="sac-btn ghost" :disabled="equipEnCours" @click="emit('desequiper', equipement.casque.inventaire_id)">Déséquiper</button>
        </div>
        <!-- Talisman (artefact de classe) : cinquième emplacement, cumulatif. -->
        <div v-if="equipement.talisman" class="item">
            <span class="ic"><MSym n="diamond" /></span>
            <div><div class="nm">{{ equipement.talisman.nom }}</div><div class="rar">Talisman équipé</div></div>
            <button v-if="auHub" class="sac-btn ghost" :disabled="equipEnCours" @click="emit('desequiper', equipement.talisman.inventaire_id)">Déséquiper</button>
        </div>
        <!-- Bottes : sixième emplacement (2026-09-04), cumulatif lui aussi. -->
        <div v-if="equipement.bottes" class="item">
            <span class="ic"><MSym n="footprint" /></span>
            <div><div class="nm">{{ equipement.bottes.nom }}</div><div class="rar">Bottes équipées</div></div>
            <button v-if="auHub" class="sac-btn ghost" :disabled="equipEnCours" @click="emit('desequiper', equipement.bottes.inventaire_id)">Déséquiper</button>
        </div>
        <div v-if="!equipement.armes.length && !equipement.armure && !equipement.casque && !equipement.talisman && !equipement.bottes" class="slots">
            <div class="slot">
                <span class="ic"><MSym n="swords" /></span>
                <div><div class="sn">Arme</div><div class="iv">Aucune</div></div>
            </div>
            <div class="slot">
                <span class="ic"><MSym n="shield" /></span>
                <div><div class="sn">Armure</div><div class="iv">Aucune</div></div>
            </div>
        </div>

        <!-- Potions réelles : action gratuite jouable À TOUT MOMENT (canon) -->
        <template v-if="potions.length">
            <div class="sect-title"><MSym n="science" :size="16" /> Potions</div>
            <p style="font-size: 12px; color: var(--ink-500); margin: 0 0 10px">
                Se boit depuis « Utiliser un objet », dans tes actions.
            </p>
            <template v-for="p in potions" :key="p.inventaire_id">
                <div class="item">
                    <span class="ic"><MSym n="science" /></span>
                    <div><div class="nm">{{ p.nom }}</div><div class="rar">×{{ p.quantite }}</div></div>
                    <button
                        v-if="peutDonner"
                        class="sac-btn ghost don-ic"
                        :disabled="equipEnCours"
                        title="Donner à un compagnon"
                        @click="basculerDon(p.inventaire_id)"
                    ><MSym n="volunteer_activism" :size="18" /></button>
                    <!--
                        `utilisable === false` : potion réservée à une autre
                        classe (trois au Barbare, deux à l'Elfe sur les cartes
                        officielles). On la laisse VISIBLE et on grise le bouton
                        — le héros a le droit de la porter pour un compagnon, et
                        cacher l'objet ferait croire qu'il a disparu du sac.
                    -->
                    <!-- ⚠ Plus de bouton « Boire » ici (René, 2026-09-01) : boire passe par
                         l'option « Utiliser un objet » du menu d'action, une
                         seule voie et une seule validation. Le Sac garde ce
                         qu'il fait de mieux — le DÉTAIL de l'objet. -->
                    <span v-if="p.utilisable === false" class="sac-note" title="Réservée à une autre classe">
                        <MSym n="block" :size="14" /> Réservée à une autre classe
                    </span>
                </div>
                <div v-if="donEnCours === p.inventaire_id" class="don-cible">
                    <span class="don-lbl">Donner 1 × {{ p.nom }} à</span>
                    <button
                        v-for="c in compagnons"
                        :key="c.id"
                        class="sac-btn ghost"
                        :disabled="equipEnCours"
                        @click="confirmerDon(p.inventaire_id, c.id, 1)"
                    >{{ c.nom }}</button>
                </div>
            </template>
        </template>

        <div class="sect-title">
            <MSym n="inventory_2" :size="16" /> Sac à dos
            <span v-if="Number.isFinite(equipement.capacite)" class="sac-charge" :class="{ trop: deborde }">
                {{ equipement.occupation }}/{{ equipement.capacite }}
            </span>
        </div>
        <p v-if="deborde" class="sac-alerte">
            <MSym n="warning" :size="14" /> Sac en dépassement — équipe une pièce ou écoule-la au marché.
        </p>
        <template v-for="it in equipement.sac" :key="it.inventaire_id">
            <div class="item">
                <span class="ic"><MSym n="inventory_2" /></span>
                <div><div class="nm">{{ it.nom }}</div><div class="rar" :class="'rar-' + rareteVersCle(it.rarete)">{{ RARETE_LABELS[rareteVersCle(it.rarete)] }}</div></div>
                <button
                    v-if="peutDonner"
                    class="sac-btn ghost don-ic"
                    :disabled="equipEnCours"
                    title="Donner à un compagnon"
                    @click="basculerDon(it.inventaire_id)"
                ><MSym n="volunteer_activism" :size="18" /></button>
                <!-- Une arme à UNE main se porte à droite OU à gauche
                     (dual-wielding) : deux boutons, sinon le second slot
                     n'existerait que pour l'API. `slots` peut manquer sur une
                     réponse antérieure — on retombe alors sur le bouton unique. -->
                <template v-if="it.equipable && auHub">
                    <template v-if="(it.slots?.length ?? 1) > 1">
                        <button
                            v-for="slot in it.slots"
                            :key="slot"
                            class="sac-btn gold"
                            :disabled="equipEnCours"
                            @click="emit('equiper', it.inventaire_id, slot)"
                        >{{ slot === 'arme_principale' ? 'Main droite' : 'Main gauche' }}</button>
                    </template>
                    <button
                        v-else
                        class="sac-btn gold"
                        :disabled="equipEnCours"
                        @click="emit('equiper', it.inventaire_id)"
                    >Équiper</button>
                </template>
                <span v-else-if="!peutDonner" class="qty" style="margin-left: auto; font-weight: 700; color: var(--ink-300)">×{{ it.quantite }}</span>
            </div>
            <div v-if="donEnCours === it.inventaire_id" class="don-cible">
                <span class="don-lbl">Donner {{ it.nom }} à</span>
                <button
                    v-for="c in compagnons"
                    :key="c.id"
                    class="sac-btn ghost"
                    :disabled="equipEnCours"
                    @click="confirmerDon(it.inventaire_id, c.id, 1)"
                >{{ c.nom }}</button>
            </div>
        </template>
        <p v-if="!equipement.sac.length" class="empty-note">Le sac est vide.</p>
    </div>
</template>

<style scoped>
.sac-btn {
    margin-left: auto;
    padding: 7px 16px;
    border: 0;
    border-radius: 9px;
    font-weight: 700;
    font-size: 13px;
    cursor: pointer;
}
.sac-btn.gold { background: var(--gold, #c9a24a); color: #1a1204; }
.sac-btn.ghost {
    background: transparent;
    color: var(--ink-300, #cfc3ad);
    border: 1px solid var(--line-soft, oklch(0.4 0.02 70 / 0.5));
}
.sac-btn:disabled { opacity: 0.5; cursor: default; }
.sac-charge { margin-left: auto; font-size: 12px; font-weight: 700; color: var(--ink-500, #8d8574); }
/* Don : le bouton icône reste collé à droite, l'éventuel « Équiper » le suit. */
.don-ic { padding: 7px 10px; display: inline-flex; align-items: center; }
.don-ic + .sac-btn { margin-left: 8px; }
.don-cible {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin: -4px 0 10px;
    padding: 8px 10px;
    border-radius: 9px;
    background: var(--panel-2, oklch(0.28 0.02 70 / 0.5));
}
.don-cible .sac-btn { margin-left: 0; }
.don-lbl { font-size: 12px; color: var(--ink-300, #cfc3ad); }
.sac-charge.trop { color: oklch(0.75 0.16 30); }
.sac-alerte {
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 0 0 10px;
    font-size: 12px;
    color: oklch(0.78 0.14 30);
}
.sac-note { display: inline-flex; align-items: center; gap: 5px;
  color: var(--ink-500); font-size: 11.5px; font-weight: 600; }
</style>
