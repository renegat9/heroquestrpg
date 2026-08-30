<script setup>
// APERÇU DE SALLE (table, René 2026-08-29) — ce qui se trouve dans la salle où
// se tient le héros dont c'est le tour, avec les illustrations.
//
// Même geste que la légende : un bouton dans un coin de la carte, un panneau
// par-dessus. Mais la légende explique des SYMBOLES ; celle-ci énumère un
// CONTENU, et suit le tour de jeu toute seule — le narrateur n'a rien à
// sélectionner, la salle change quand le héros actif change.
//
// ⚠ La salle est déduite des rectangles publiés (`carte.salles`), qui ne
// couvrent QUE les salles découvertes. Un héros dans un COULOIR n'appartient à
// aucune salle : le panneau le dit plutôt que d'inventer une pièce.
import { computed, ref, watch } from 'vue';
import MSym from '../ui/MSym.vue';
import Vignette from '../ui/Vignette.vue';
import {
    EPREUVE_ICONES, EPREUVE_ICONE_DEFAUT, LEVIER_ICONE, MOBILIER_ICONES,
    MOBILIER_ICONE_DEFAUT, PIEGE_ICONES, PIEGE_ICONE_DEFAUT, icone,
} from './symboles.js';
// ⚠ Le MÊME lecteur que le bandeau d'initiative et que le liseré doré de la
// figurine : une seconde lecture, écrite ici, finirait par désigner un autre
// héros que celui que la table montre comme actif.
import { estCourant } from '../../store/game.js';

const props = defineProps({
    /** Carte du contrat — `salles`, `pieges`, `mobilier`, `epreuves`, `leviers`, `portes`. */
    carte: { type: Object, default: () => ({}) },
    /** Entités BRUTES du contrat (pas les figurines transformées) : il faut le
     *  nom complet, les PV et l'illustration, que `entitesVersFigurines` abrège. */
    entites: { type: Array, default: () => [] },
    /** Ordre d'initiative du contrat : [{entite, id, a_joue, tombe}]. */
    initiative: { type: Array, default: () => [] },
});

const ouvert = ref(false);

const PIEGE_ETATS = { detecte: 'détecté', desarme: 'désarmé', declenche: 'déclenché' };
const PORTE_ETATS = { ouverte: 'ouverte', fermee: 'fermée', verrouillee: 'verrouillée', secrete: 'secrète' };
const PORTE_VERROUS = { cle: 'clé requise', monstres_vaincus: 'gardien à vaincre', levier: 'levier à actionner' };

/** Héros dont c'est le tour, `null` pendant la phase des monstres. */
const herosCourant = computed(() => (props.entites ?? [])
    .find((e) => e.type === 'heros' && estCourant(e, props.initiative)) ?? null);

// ⚠ On RETIENT le dernier héros actif. Pendant la phase des monstres plus aucun
// héros n'a la main, et le panneau se viderait à chaque fin de tour pour se
// remplir au suivant — un clignotement, juste au moment où la table regarde ce
// qui se passe dans la salle. Il continue donc d'afficher la salle du dernier
// héros à avoir joué.
const dernier = ref(null);
watch(herosCourant, (h) => { if (h) { dernier.value = h.id; } }, { immediate: true });

const actif = computed(() => herosCourant.value
    ?? (props.entites ?? []).find((e) => e.type === 'heros' && e.id === dernier.value)
    ?? null);

const dans = (s, x, y) => x >= s.x && x < s.x + s.largeur && y >= s.y && y < s.y + s.hauteur;

/** Rectangle de la salle du héros actif — `null` s'il est en couloir. */
const salle = computed(() => {
    const h = actif.value;
    if (! h) { return null; }

    return (props.carte?.salles ?? []).find((s) => dans(s, h.x, h.y)) ?? null;
});

const ici = (x, y) => salle.value !== null && dans(salle.value, x, y);

const figures = computed(() => {
    if (! salle.value) { return []; }

    const rang = { heros: 0, allie: 1, monstre: 2 };

    return (props.entites ?? [])
        .filter((e) => ici(e.x, e.y))
        // Un monstre vaincu a quitté le plateau — même filtre que les figurines.
        .filter((e) => e.type !== 'monstre' || ((e.etat ?? 'actif') === 'actif' && (e.pv_body ?? 1) > 0))
        .map((e) => ({
            cle: `${e.type}-${e.id}`,
            nom: e.nom,
            img: e.image_url ?? null,
            ic: e.type === 'heros' ? 'person' : (e.type === 'allie' ? 'handshake' : 'sentiment_very_dissatisfied'),
            classe: e.type,
            // Le nom de CATALOGUE d'un monstre habillé par l'IA : « Le Noyé de
            // Gorrim » ne dit pas qu'on a un gobelin en face.
            sous: e.type === 'monstre' && e.nom_base && e.nom_base !== e.nom ? e.nom_base : null,
            detail: e.type === 'heros'
                ? `${e.tombe ? 'à terre' : `${e.pv_body}/${e.pv_body_max} Body`}`
                : `${e.pv_body ?? '?'} Body`,
            courant: e.type === 'heros' && estCourant(e, props.initiative),
        }))
        .sort((a, b) => rang[a.classe] - rang[b.classe]);
});

const meubles = computed(() => (props.carte?.mobilier ?? [])
    .filter((m) => ici(m.x, m.y))
    .map((m) => ({
        cle: `m-${m.x}-${m.y}`,
        nom: m.nom,
        img: m.image_url ?? null,
        ic: icone(MOBILIER_ICONES, m.nom, MOBILIER_ICONE_DEFAUT),
        detail: [
            m.bloque_mouvement !== false ? 'bloque le passage' : 'se franchit',
            m.bloque_vue === true ? 'bloque la vue' : 'on voit par-dessus',
        ].join(' · '),
    })));

const epreuves = computed(() => (props.carte?.epreuves ?? [])
    .filter((e) => ici(e.x, e.y))
    .map((e) => ({
        cle: `e-${e.x}-${e.y}`,
        nom: e.nom,
        img: e.image_url ?? null,
        ic: icone(EPREUVE_ICONES, e.nom, EPREUVE_ICONE_DEFAUT),
        detail: `jet de ${e.attribut === 'body' ? 'Body' : 'Mind'}, difficulté ${e.difficulte}`,
        sous: (e.tentee_par ?? []).length > 0 ? `déjà tentée par ${e.tentee_par.length} héros` : null,
        description: e.description ?? null,
    })));

const pieges = computed(() => (props.carte?.pieges ?? [])
    .filter((p) => ici(p.x, p.y) && PIEGE_ETATS[p.etat])
    .map((p) => ({
        cle: `p-${p.x}-${p.y}`,
        nom: p.nom ?? 'Piège',
        img: p.image_url ?? null,
        ic: icone(PIEGE_ICONES, p.nom, PIEGE_ICONE_DEFAUT),
        detail: PIEGE_ETATS[p.etat],
    })));

const leviers = computed(() => (props.carte?.leviers ?? [])
    .filter((l) => ici(l.x, l.y))
    .map((l) => ({
        cle: `l-${l.x}-${l.y}`,
        nom: 'Levier',
        img: l.image_url ?? null,
        ic: LEVIER_ICONE,
        detail: `jet de Body, difficulté ${l.difficulte ?? 2}`,
    })));

// Issues : une porte appartient à la salle si l'UNE de ses deux cases y tombe.
// Une porte est une arête entre deux cases, elle est donc toujours à cheval.
const issues = computed(() => (props.carte?.portes ?? [])
    .filter((d) => {
        const [a, b] = d.cote === 's'
            ? [[d.x, d.y], [d.x, d.y + 1]]
            : [[d.x, d.y], [d.x + 1, d.y]];

        return ici(a[0], a[1]) || ici(b[0], b[1]);
    })
    .filter((d) => PORTE_ETATS[d.etat])
    .map((d) => ({
        cle: `d-${d.x}-${d.y}-${d.cote}`,
        etat: d.etat,
        img: d.image_url ?? null,
        libelle: `Porte ${PORTE_ETATS[d.etat]}`,
        verrou: d.verrou ? (PORTE_VERROUS[d.verrou] ?? d.verrou) : null,
    })));

// « Salle d'Aldwin », pas « Salle de Aldwin » : les noms de héros sont saisis
// par les joueurs et commencent souvent par une voyelle.
const titre = computed(() => {
    const nom = actif.value?.nom ?? '';

    return /^[aeiouyàâäéèêëîïôöùûüh]/i.test(nom) ? `Salle d'${nom}` : `Salle de ${nom}`;
});

const vide = computed(() => figures.value.length <= 1 && meubles.value.length === 0
    && epreuves.value.length === 0 && pieges.value.length === 0 && leviers.value.length === 0);
</script>

<template>
    <div class="ap-wrap">
        <button class="ap-btn" :class="{ on: ouvert }" type="button" :aria-expanded="ouvert"
                title="Aperçu de la salle du héros actif" @click="ouvert = !ouvert">
            <MSym n="preview" fill />
        </button>

        <div v-if="ouvert" class="ap-panneau">
            <h4 class="ap-titre">
                <MSym n="preview" fill />
                <span v-if="actif">{{ titre }}</span>
                <span v-else>Aperçu de salle</span>
            </h4>

            <p v-if="! actif" class="ap-note">Aucun héros en jeu pour l'instant.</p>
            <p v-else-if="! salle" class="ap-note">
                {{ actif.nom }} est dans un couloir — aucune salle à décrire.
            </p>

            <template v-else>
                <p v-if="vide" class="ap-note">La salle est nue : rien d'autre que {{ actif.nom }}.</p>

                <section v-if="figures.length" class="ap-sect">
                    <div class="ap-sous">Présents</div>
                    <div v-for="f in figures" :key="f.cle" class="ap-ligne" :class="f.classe">
                        <Vignette class="ap-img" :src="f.img" :icon="f.ic" fill />
                        <span class="ap-txt">
                            <b>{{ f.nom }}<MSym v-if="f.courant" class="ap-tour" n="play_arrow" fill /></b>
                            <em v-if="f.sous">{{ f.sous }}</em>
                            <small>{{ f.detail }}</small>
                        </span>
                    </div>
                </section>

                <section v-if="meubles.length" class="ap-sect">
                    <div class="ap-sous">Mobilier</div>
                    <div v-for="m in meubles" :key="m.cle" class="ap-ligne">
                        <Vignette class="ap-img" :src="m.img" :icon="m.ic" fill />
                        <span class="ap-txt"><b>{{ m.nom }}</b><small>{{ m.detail }}</small></span>
                    </div>
                </section>

                <section v-if="epreuves.length" class="ap-sect">
                    <div class="ap-sous">Épreuves</div>
                    <div v-for="e in epreuves" :key="e.cle" class="ap-ligne">
                        <Vignette class="ap-img" :src="e.img" :icon="e.ic" fill />
                        <span class="ap-txt">
                            <b>{{ e.nom }}</b>
                            <small>{{ e.detail }}<template v-if="e.sous"> · {{ e.sous }}</template></small>
                            <i v-if="e.description">{{ e.description }}</i>
                        </span>
                    </div>
                </section>

                <section v-if="pieges.length" class="ap-sect">
                    <div class="ap-sous">Pièges</div>
                    <div v-for="p in pieges" :key="p.cle" class="ap-ligne">
                        <Vignette class="ap-img" :src="p.img" :icon="p.ic" fill />
                        <span class="ap-txt"><b>{{ p.nom }}</b><small>{{ p.detail }}</small></span>
                    </div>
                </section>

                <section v-if="leviers.length" class="ap-sect">
                    <div class="ap-sous">Mécanismes</div>
                    <div v-for="l in leviers" :key="l.cle" class="ap-ligne">
                        <Vignette class="ap-img" :src="l.img" :icon="l.ic" fill />
                        <span class="ap-txt"><b>{{ l.nom }}</b><small>{{ l.detail }}</small></span>
                    </div>
                </section>

                <section v-if="issues.length" class="ap-sect">
                    <div class="ap-sous">Issues</div>
                    <div v-for="d in issues" :key="d.cle" class="ap-ligne">
                        <Vignette class="ap-img" :src="d.img" icon="door_front" fill />
                        <span class="ap-txt">
                            <b>{{ d.libelle }}</b>
                            <small v-if="d.verrou">{{ d.verrou }}</small>
                            <!-- La barre reprend la couleur du battant sur la
                                 carte : l'illustration dit CE QUE C'EST, la
                                 barre fait le lien avec ce qu'on voit dessinée. -->
                            <span class="ap-chip" :class="d.etat" />
                        </span>
                    </div>
                </section>
            </template>
        </div>
    </div>
</template>

<style scoped>
.ap-wrap { position: relative; }

.ap-btn { width: 34px; height: 34px; border-radius: 50%; display: grid; place-items: center;
  background: oklch(0.16 0.012 255 / 0.9); border: var(--line); color: var(--ink-300);
  cursor: pointer; backdrop-filter: blur(6px); }
.ap-btn:hover, .ap-btn.on { color: var(--torch); border-color: var(--torch); }
.ap-btn.on { background: oklch(0.76 0.155 65 / 0.16); }
.ap-btn .msym { font-size: 20px; }

/* ⚠ Panneau LARGE et vignettes GRANDES (René, 2026-08-29). Les illustrations
   de catalogue sont générées en 1024×1024 et coûtent des jetons ; les afficher
   en 46 px revenait à payer une image pour n'en montrer qu'un timbre. C'est
   l'écran de TABLE, partagé et grand : autant s'en servir. La légende, elle,
   garde ses petites vignettes — elle explique des symboles, pas un contenu. */
.ap-panneau { position: absolute; right: 0; top: 42px; z-index: 40;
  width: min(460px, calc(100vw - 28px)); max-height: 80vh; overflow-y: auto;
  padding: 14px 16px 16px; border-radius: var(--r-md);
  background: oklch(0.16 0.012 255 / 0.97); border: var(--line-strong); box-shadow: var(--sh-3);
  backdrop-filter: blur(8px); }
.ap-titre { font-family: var(--font-display); color: var(--parch-100); margin: 0 0 12px;
  font-size: 17px; letter-spacing: 0.04em; display: flex; align-items: center; gap: 8px; }
.ap-titre .msym { color: var(--torch); font-size: 19px; }

.ap-sect + .ap-sect { margin-top: 13px; border-top: var(--line); padding-top: 11px; }
.ap-sous { color: var(--torch); font-size: 10.5px; font-weight: 800; letter-spacing: 0.09em;
  text-transform: uppercase; margin-bottom: 8px; }
.ap-note { margin: 0; color: var(--ink-500); font-family: var(--font-narr); font-style: italic; font-size: 13px; }

.ap-ligne { display: flex; align-items: center; gap: 14px; margin-bottom: 12px; }
.ap-img { flex: none; width: 96px; height: 96px; border-radius: 10px; overflow: hidden;
  background: var(--stone-850); box-shadow: inset 0 0 0 1px oklch(0.4 0.02 255 / 0.5); }
/* Chaque famille garde la couleur qu'elle a sur le plateau : le liseré fait le
   lien entre la ligne de la liste et la figurine sur la carte. */
.ap-ligne.heros .ap-img { box-shadow: 0 0 0 2px var(--gold); }
.ap-ligne.monstre .ap-img { box-shadow: 0 0 0 2px oklch(0.55 0.16 25 / 0.9); }
.ap-ligne.allie .ap-img { box-shadow: 0 0 0 2px oklch(0.55 0.14 260 / 0.9); }

.ap-txt { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
.ap-txt b { color: var(--ink-100); font-size: 15.5px; font-weight: 700;
  display: inline-flex; align-items: center; gap: 5px; }
.ap-txt em { color: var(--ink-500); font-style: normal; font-size: 12.5px; }
.ap-txt small { color: var(--ink-400); font-size: 12.5px; }
.ap-txt i { color: var(--ink-500); font-family: var(--font-narr); font-size: 12.5px; margin-top: 4px; }
.ap-tour { color: var(--torch); font-size: 17px; }

.ap-chip { display: block; width: 72px; height: 6px; margin-top: 5px; border-radius: 2px;
  background: linear-gradient(90deg, #d8a23a, #7a531d); }
.ap-chip.ouverte { background: none; box-shadow: inset 0 0 0 1px #f0d79a; }
.ap-chip.verrouillee { background: linear-gradient(90deg, #b98a3a, #6a4a1c); }
.ap-chip.secrete { background: linear-gradient(90deg, #b18ad8, #5b3f7a); }
</style>
