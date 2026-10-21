// Musique/ambiance de fond de l'écran de table : joue en BOUCLE la piste
// correspondant à la scène sonore (groupe.ambiance : hub | exploration |
// combat | boss | victoire | defaite — décidée par le serveur,
// `EtatGroupe::sceneAmbiance()`), avec fondu enchaîné au changement de scène.
//
// Les pistes sont générées par audio-tools/lyria-ambiance.mjs dans
// public/audio/ambiance/{scene}/{n}.wav, listées par manifeste.json (repli :
// piste plate {scene}.{ogg|mp3|wav}). Best-effort : piste absente → SILENCE
// (aucune erreur). Comme l'autoplay, le son ne démarre qu'après un geste
// utilisateur (activer()).
import { ref } from 'vue';

const SCENES = ['hub', 'exploration', 'combat', 'boss', 'victoire', 'defaite'];
const EXTS = ['ogg', 'mp3', 'wav']; // formats acceptés (essayés dans l'ordre)
const DUREE_FONDU = 900; // ms

// Manifeste des variantes par scène (généré par audio-tools/lyria-ambiance.mjs) :
// { scene: ["/audio/ambiance/scene/0.wav", ...] }. Chargé une fois ; absent →
// repli sur une piste plate {scene}.{ext}.
// ⚠ On ATTEND le manifeste avant de choisir une piste (2026-10-21) : lu avant
// qu'il n'arrive, `urlsScene()` retombait sur les pistes plates — qui n'existent
// plus, le script les efface — et la scène restait muette jusqu'au changement
// suivant.
let banque = null;
const banquePrete = fetch('/audio/ambiance/manifeste.json', { cache: 'no-cache' })
    .then((r) => (r.ok ? r.json() : null))
    .catch(() => null)
    .then((j) => { banque = j; });

function melanger(liste) {
    const a = [...liste];
    for (let i = a.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [a[i], a[j]] = [a[j], a[i]];
    }
    return a;
}

// URLs candidates pour une scène : variantes du manifeste si présentes
// (mélangées → tirage au hasard), sinon piste plate dans les formats connus.
function urlsScene(scene) {
    if (Array.isArray(banque?.[scene]) && banque[scene].length) return melanger(banque[scene]);
    return EXTS.map((e) => `/audio/ambiance/${scene}.${e}`);
}

// Persistance locale (préférence de l'APPAREIL qui tient la table — ses
// enceintes —, pas de la campagne) : muet + volume, dans localStorage['audio:ambiance'].
const CLE_STOCKAGE = 'audio:ambiance';

function chargerPrefs() {
    try {
        const brut = localStorage.getItem(CLE_STOCKAGE);
        return brut ? JSON.parse(brut) : {};
    } catch { return {}; }
}

function sauverPrefs() {
    try {
        localStorage.setItem(CLE_STOCKAGE, JSON.stringify({ muet: muet.value, volume: volume.value }));
    } catch { /* stockage indisponible (navigation privée…) — best-effort */ }
}

const prefsSauvees = chargerPrefs();
const actif = ref(false);   // débloqué par un geste utilisateur
const muet = ref(prefsSauvees.muet ?? false);
const volume = ref(typeof prefsSauvees.volume === 'number' ? prefsSauvees.volume : 0.32);   // volume « plein » de l'ambiance (sous la narration)

let courant = null;         // { audio, scene }
let sceneVoulue = null;     // dernière scène demandée (jouée dès activation)

function cibleVolume() {
    return muet.value ? 0 : volume.value;
}

// Tente de lire une variante (au hasard) de la scène ; résout avec l'élément
// Audio qui démarre, ou null si aucune piste ne charge.
async function ouvrirPiste(scene) {
    await banquePrete;

    return new Promise((resolve) => {
        const candidats = urlsScene(scene);
        let i = 0;
        const essayer = () => {
            if (i >= candidats.length) { resolve(null); return; }
            const a = new Audio(candidats[i++]);
            a.loop = true;
            a.volume = 0;
            a.play().then(() => resolve(a)).catch(essayer);
        };
        essayer();
    });
}

function fondu(audio, vers, fini) {
    const depart = audio.volume;
    const t0 = performance.now();
    const pas = (now) => {
        const k = Math.min(1, (now - t0) / DUREE_FONDU);
        audio.volume = depart + (vers - depart) * k;
        if (k < 1) requestAnimationFrame(pas);
        else if (fini) fini();
    };
    requestAnimationFrame(pas);
}

// Pistes encore AUDIBLES (en cours de lecture ou de fondu sortant). ⚠ Avant le
// 2026-10-21, seule l'ancienne piste de `courant` était éteinte : deux changements
// de scène pendant un chargement (exploration → combat → exploration) laissaient
// la première jouer SOUS la nouvelle, hors de portée du bouton « couper ».
const pistes = new Set();

function eteindreSauf(garde) {
    for (const a of pistes) {
        if (a === garde) continue;
        pistes.delete(a);
        fondu(a, 0, () => { try { a.pause(); } catch { /* noop */ } });
    }
}

async function jouer(scene) {
    if (courant?.scene === scene) return;

    courant = { audio: null, scene }; // réserve la scène (évite les courses)

    const audio = await ouvrirPiste(scene);
    if (courant.scene !== scene) { // scène a changé entre-temps
        try { audio?.pause(); } catch { /* noop */ }
        return;
    }
    if (audio === null) { // aucune piste pour cette scène → SILENCE, pas l'ancienne
        eteindreSauf(null);
        return;
    }

    courant.audio = audio;
    pistes.add(audio);
    fondu(audio, cibleVolume());
    eteindreSauf(audio);
}

// Définit la scène sonore (appelée sur chaque EtatGroupe). Mémorise la scène
// même avant activation, pour démarrer la bonne piste dès le geste utilisateur.
function definirScene(scene) {
    if (!SCENES.includes(scene)) return;
    sceneVoulue = scene;
    if (actif.value) jouer(scene);
}

function activer() {
    actif.value = true;
    if (sceneVoulue) jouer(sceneVoulue);
}

function basculerMuet() {
    muet.value = !muet.value;
    if (courant?.audio) courant.audio.volume = cibleVolume();
    sauverPrefs();
}

function definirVolume(v) {
    volume.value = Math.max(0, Math.min(1, v));
    if (courant?.audio && !muet.value) courant.audio.volume = volume.value;
    sauverPrefs();
}

export function useAmbiance() {
    return { actif, muet, volume, definirScene, activer, basculerMuet, definirVolume };
}
