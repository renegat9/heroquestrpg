<script setup>
// LE PROFIL D'AUTEUR d'un monstre posé ou apparu (lot K5m, R9 — René : « monstre existant avec sous-boss et boss, ou
// créé ») : chaque clé est FACULTATIVE et n'existe que remplie — une case vide retire la clé, jamais une valeur
// décorative. Les bornes sont celles de `ValidationCampagne::profilDeMonstre()`.
import { computed } from 'vue';
import MSym from '../ui/MSym.vue';

const props = defineProps({
    monstre: { type: Object, required: true },
    objets: { type: Array, required: true },
    // 2026-11-20 : « blessé uniquement par » accepte aussi des SORTS (Seigneur Sorcier, q. 10) — `catalogues.sorts`.
    sorts: { type: Array, default: () => [] },
    // 2026-11-20 : un monstre POSÉ peut attendre inactif qu'une note l'éveille (la Légion oubliée) ; pas une apparition.
    inactifPossible: { type: Boolean, default: false },
    // Lot E1 (2026-11-22) : les sorts de Dread de l'auteur (`catalogues.sorts_dread`) et ses capacités, liste fermée
    // publiée par le serveur (`vocabulaires.capacites_de_profil`).
    sortsDread: { type: Array, default: () => [] },
    capacites: { type: Array, default: () => [] },
    // Lot E2 (Wizards of Morcar q. 7 D) : les sorts de Dread qu'une figure posée peut avoir DÉJÀ lancés (`vocabulaires.sorts_d_amelioration`).
    sortsAmelioration: { type: Array, default: () => [] },
    // Le monstre errant surgit : il n'a pas de refuge.
    sansFuite: { type: Boolean, default: false },
    pointer: { type: Function, required: true },
    libelle: { type: String, default: 'le monstre' },
});

const STATS = [
    ['deplacement', 'Déplacement', 0],
    ['attaque', 'Attaque', 0],
    ['defense', 'Défense', 1],
    ['pv_body', 'Corps', 1],
    ['pv_mind', 'Esprit', 0],
];
const LISTES = [
    ['vulnerable_uniquement_a', 'Seuls ces armes ou ces sorts le blessent', true],
    ['invulnerable_casse_arme', 'Invulnérable — l\'arme qui frappe se brise, sauf', false],
];
const nomSource = (slug) => props.objets.find((o) => o.slug === slug)?.nom ?? props.sorts.find((o) => o.slug === slug)?.nom ?? slug;
const LIBELLES_CAPACITE = {
    attaque_diagonale: 'frappe en diagonale',
    deux_sorts_par_tour: 'deux sorts par tour',
    deux_sorts_premier_tour: 'deux sorts au premier tour',
    // Lot E2 (Rise of the Dread Moon q. 2) : la figure qui laisse passer les déguisés.
    ignore_deguises: 'ignore les héros déguisés (dort tant qu\'un non-déguisé ne la voit pas)',
    retire_au_contact_deguise: 'endormie, retirée au contact d\'un héros déguisé',
    rappelable: 'rappelable : un héros qui la voit lui rappelle son passé (une action, un dé ; un bouclier = 1 point d\'Esprit ; à 0, elle s\'efface apaisée)',
    bouclier_noir_pare_tout: 'un bouclier noir à ses dés de défense pare tous les coups (les Golems de Morcar)',
    traverse_murs: 'traverse les murs vers les zones déjà découvertes (les morts-vivants de la Main de la Sorcière)',
    flamme_de_zargon: 'Flamme de Zargon : touchée, un dé contre deux chiffres (épargnée ou consumée, quels que soient ses PV) ; l\'Eau de Guérison la frappe',
};
const nomSortDread = (slug) => props.sortsDread.find((o) => o.slug === slug)?.nom ?? slug;
function ajouterSortDread(slug) {
    if (!slug) return;
    const liste = m.value.sorts_dread ?? [];
    if (!liste.includes(slug)) liste.push(slug);
    m.value.sorts_dread = liste;
}
function retirerSortDread(slug) {
    const liste = (m.value.sorts_dread ?? []).filter((s) => s !== slug);
    if (liste.length) m.value.sorts_dread = liste; else delete m.value.sorts_dread;
}
// Lot E2 (First Light q. 5 A) : un sort tiré parmi plusieurs, à la pose.
function ajouterSortParmi(slug) {
    if (!slug) return;
    const liste = m.value.sort_dread_parmi ?? [];
    if (!liste.includes(slug)) liste.push(slug);
    m.value.sort_dread_parmi = liste;
}
function retirerSortParmi(slug) {
    const liste = (m.value.sort_dread_parmi ?? []).filter((s) => s !== slug);
    if (liste.length) m.value.sort_dread_parmi = liste; else delete m.value.sort_dread_parmi;
}
function basculerCapacite(nom, avec) {
    const liste = (m.value.capacites ?? []).filter((c) => c !== nom);
    if (avec) liste.push(nom);
    if (liste.length) m.value.capacites = liste; else delete m.value.capacites;
}
function basculerInactif(avec) {
    if (avec) m.value.inactif = true; else delete m.value.inactif;
}

const m = computed(() => props.monstre);
function changerNom(v) {
    if (v.trim() === '') delete m.value.nom; else m.value.nom = v.trim();
}
function changerStat(cle, v) {
    if (v === '' || v === null) delete m.value[cle]; else m.value[cle] = Number(v);
}
function basculerListe(cle, avec) {
    if (avec) m.value[cle] = []; else delete m.value[cle];
}
function basculerObjet(cle, slug, avec) {
    const liste = m.value[cle];
    if (avec && !liste.includes(slug)) liste.push(slug);
    if (!avec) liste.splice(liste.indexOf(slug), 1);
}
function basculerFuite(avec) {
    if (avec) m.value.fuite = { x: m.value.x ?? 0, y: m.value.y ?? 0 }; else delete m.value.fuite;
}
</script>

<template>
    <div class="pm">
        <div class="cst-ligne">
            <label>Nom propre <input :value="m.nom ?? ''" class="cst-input" placeholder="(celui du catalogue)" @change="changerNom($event.target.value)" /></label>
            <label v-for="[cle, lib, min] in STATS" :key="cle">{{ lib }}
                <input :value="m[cle] ?? ''" type="number" :min="min" class="cst-input cst-num" placeholder="—" @change="changerStat(cle, $event.target.value)" />
            </label>
        </div>
        <div v-for="[cle, lib, avecSorts] in LISTES" :key="cle" class="cst-ligne">
            <label class="cst-case"><input type="checkbox" :checked="Array.isArray(m[cle])" @change="basculerListe(cle, $event.target.checked)" /> {{ lib }}</label>
            <template v-if="Array.isArray(m[cle])">
                <span v-for="slug in m[cle]" :key="slug" class="pm-puce">{{ nomSource(slug) }}
                    <button type="button" class="cst-icone" title="Retirer" @click="basculerObjet(cle, slug, false)"><MSym n="close" :size="12" /></button>
                </span>
                <select class="cst-input" @change="basculerObjet(cle, $event.target.value, true); $event.target.value = ''">
                    <option value="">{{ avecSorts ? '+ arme ou sort…' : '+ arme…' }}</option>
                    <optgroup label="Armes et objets">
                        <option v-for="o in objets.filter((o) => !m[cle].includes(o.slug))" :key="o.slug" :value="o.slug">{{ o.nom }}</option>
                    </optgroup>
                    <optgroup v-if="avecSorts && sorts.length" label="Sorts">
                        <option v-for="o in sorts.filter((o) => !m[cle].includes(o.slug))" :key="o.slug" :value="o.slug">{{ o.nom }}</option>
                    </optgroup>
                </select>
            </template>
        </div>
        <div v-if="sortsDread.length" class="cst-ligne">
            <span class="cst-sous">Sorts de Dread :</span>
            <span v-for="slug in (m.sorts_dread ?? [])" :key="slug" class="pm-puce">{{ nomSortDread(slug) }}
                <button type="button" class="cst-icone" title="Retirer" @click="retirerSortDread(slug)"><MSym n="close" :size="12" /></button>
            </span>
            <select class="cst-input" @change="ajouterSortDread($event.target.value); $event.target.value = ''">
                <option value="">+ sort de Dread…</option>
                <option v-for="o in sortsDread.filter((o) => !(m.sorts_dread ?? []).includes(o.slug))" :key="o.slug" :value="o.slug">{{ o.nom }}</option>
            </select>
            <span v-if="(m.sorts_dread ?? []).length" class="cst-sous">remplacent ceux de la fiche ; chacun une fois par quête</span>
        </div>
        <div v-if="sortsDread.length" class="cst-ligne">
            <span class="cst-sous">Un sort tiré parmi :</span>
            <span v-for="slug in (m.sort_dread_parmi ?? [])" :key="`p${slug}`" class="pm-puce">{{ nomSortDread(slug) }}
                <button type="button" class="cst-icone" title="Retirer" @click="retirerSortParmi(slug)"><MSym n="close" :size="12" /></button>
            </span>
            <select class="cst-input" @change="ajouterSortParmi($event.target.value); $event.target.value = ''">
                <option value="">+ sort à mélanger…</option>
                <option v-for="o in sortsDread.filter((o) => !(m.sort_dread_parmi ?? []).includes(o.slug))" :key="`p${o.slug}`" :value="o.slug">{{ o.nom }}</option>
            </select>
            <span v-if="(m.sort_dread_parmi ?? []).length" class="cst-sous">un seul est donné à la créature, tiré au départ (au moins deux)</span>
        </div>
        <div v-if="capacites.length" class="cst-ligne">
            <label v-for="nom in capacites" :key="nom" class="cst-case">
                <input type="checkbox" :checked="(m.capacites ?? []).includes(nom)" @change="basculerCapacite(nom, $event.target.checked)" /> {{ LIBELLES_CAPACITE[nom] ?? nom }}
            </label>
        </div>
        <!-- Lot E2 : attaquée au contact, la figure se rend, s'enfuit et laisse un objet (l'Arbalétrier, F5815 q. 1). -->
        <div v-if="!sansFuite" class="cst-ligne">
            <label class="cst-case"><input type="checkbox" :checked="!!m.se_rend" @change="$event.target.checked ? (m.se_rend = {}) : delete m.se_rend" /> Se rend quand on l'attaque au contact, et s'enfuit</label>
            <select v-if="m.se_rend" :value="m.se_rend.objet ?? ''" class="cst-input" @change="$event.target.value ? (m.se_rend.objet = $event.target.value) : delete m.se_rend.objet">
                <option value="">sans rien laisser</option>
                <option v-for="o in objets" :key="o.slug" :value="o.slug">laisse : {{ o.nom }}</option>
            </select>
        </div>
        <!-- Lot E2 (Wizards of Morcar q. 2 D, q. 7 B) : jamais sous 1 PV — jusqu'à un drapeau (le cœur détruit), si l'auteur le veut. -->
        <div class="cst-ligne">
            <label class="cst-case"><input type="checkbox" :checked="m.increvable !== undefined" @change="$event.target.checked ? (m.increvable = true) : delete m.increvable" /> Increvable : jamais sous 1 PV</label>
            <input v-if="m.increvable !== undefined" :value="m.increvable?.sauf_drapeau ?? ''" class="cst-input" placeholder="jusqu'au drapeau… (toujours)"
                @change="$event.target.value.trim() ? (m.increvable = { sauf_drapeau: $event.target.value.trim() }) : (m.increvable = true)" />
        </div>
        <!-- Lot E2 (Wizards of Morcar q. 7 D) : « with Hammer of Ruin and Scrolls of Morcar already cast on her ». -->
        <div v-if="inactifPossible && sortsAmelioration.length" class="cst-ligne">
            <span class="cst-sous">sorts déjà lancés :</span>
            <label v-for="sl in sortsAmelioration" :key="`sa${sl}`" class="cst-case">
                <input type="checkbox" :checked="(m.sorts_actifs ?? []).includes(sl)"
                    @change="(() => { const l = (m.sorts_actifs ?? []).filter((x) => x !== sl); if ($event.target.checked) l.push(sl); if (l.length) m.sorts_actifs = l; else delete m.sorts_actifs; })()" /> {{ nomSortDread(sl) }}
            </label>
        </div>
        <!-- Lot E2 (Wizards of Morcar p. 4) : un monstre à embuscade (le Dreadshifter) posé déguisé en coffre, ou en porte sur un bord. -->
        <div v-if="inactifPossible" class="cst-ligne">
            <label>Déguisé en
                <select :value="m.deguise === 'coffre' ? 'coffre' : (m.deguise?.porte ? `porte-${m.deguise.porte}` : '')" class="cst-input"
                    title="Seul un monstre à embuscade (le Dreadshifter) se déguise ; il se révèle quand un héros approche, fouille, cherche le trésor de sa salle, ou au tour de Zargon."
                    @change="(v => v === '' ? delete m.deguise : (m.deguise = v === 'coffre' ? 'coffre' : { porte: v.slice(6) }))($event.target.value)">
                    <option value="">rien (visible)</option>
                    <option value="coffre">coffre</option>
                    <option value="porte-n">porte, bord nord</option>
                    <option value="porte-s">porte, bord sud</option>
                    <option value="porte-e">porte, bord est</option>
                    <option value="porte-o">porte, bord ouest</option>
                </select>
            </label>
        </div>
        <div v-if="inactifPossible" class="cst-ligne">
            <label class="cst-case" title="Wizards of Morcar q. 1 : neutre, elle ne bouge ni n'attaque tant qu'on ne l'a pas attaquée ; une note « éveiller » la retourne aussi."><input type="checkbox" :checked="m.neutre === true" @change="$event.target.checked ? (m.neutre = true) : delete m.neutre" /> Neutre — ne bouge ni n'attaque avant d'être attaquée</label>
        </div>
        <div v-if="inactifPossible" class="cst-ligne">
            <label class="cst-case"><input type="checkbox" :checked="m.inactif === true" @change="basculerInactif($event.target.checked)" /> Inactif — ne bouge pas, n'attaque pas, ne peut être blessé tant qu'une note « éveiller » ne l'a pas réveillé</label>
        </div>
        <div v-if="!sansFuite" class="cst-ligne">
            <label class="cst-case"><input type="checkbox" :checked="!!m.fuite" @change="basculerFuite($event.target.checked)" /> Fuit vers un refuge</label>
            <template v-if="m.fuite">
                <input v-model.number="m.fuite.x" type="number" class="cst-input cst-num" title="x" />
                <input v-model.number="m.fuite.y" type="number" class="cst-input cst-num" title="y" />
                <button type="button" class="cst-icone" title="Choisir sur la carte" @click="pointer(m.fuite, `le refuge de ${libelle}`)"><MSym n="pin_drop" :size="16" /></button>
            </template>
        </div>
    </div>
</template>

<style scoped>
.pm { display: flex; flex-direction: column; gap: 4px; padding: 6px 0 2px 14px; border-left: 2px solid var(--stone-700); }
.pm-puce { display: inline-flex; align-items: center; gap: 2px; font-size: 12px; padding: 1px 6px; border-radius: 10px; background: var(--stone-800); }
</style>
