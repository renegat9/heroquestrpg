// Voix de l'écran de table : synthèse vocale (Web Speech API) pour la narration
// du MJ + lecture des barks de monstres. Tout est best-effort et dégradable :
//
//  - barks avec `url` → on joue le fichier audio pré-généré (Gemini TTS) ;
//  - sinon → on lit le `texte` via la synthèse du navigateur, avec un timbre
//    (hauteur/débit) approché selon le profil du monstre ;
//  - narration du MJ → toujours lue via la synthèse (aucune clé requise).
//
// Pièges gérés : getVoices() souvent vide au 1er appel (→ voiceschanged), et
// l'autoplay bloqué tant que l'utilisateur n'a pas interagi (→ activer()).
import { ref } from 'vue';

const supporte = typeof window !== 'undefined' && 'speechSynthesis' in window;

// Activé après un geste utilisateur (déblocage autoplay). Persisté pour la session.
const actif = ref(false);
// Vrai pendant qu'une voix parle (pilote l'égaliseur du bandeau de narration).
const speaking = ref(false);

let voixFr = null;

function chargerVoixFr() {
    if (!supporte) return;
    const voix = window.speechSynthesis.getVoices();
    voixFr = voix.find((v) => /^fr(-|_|$)/i.test(v.lang)) || voix.find((v) => v.lang?.toLowerCase().startsWith('fr')) || null;
}

if (supporte) {
    chargerVoixFr();
    window.speechSynthesis.addEventListener('voiceschanged', chargerVoixFr);
}

// Timbre approché par profil pour le repli vocal des barks (hauteur, débit).
const TIMBRE = {
    gobelin: { pitch: 1.8, rate: 1.25 },
    brute: { pitch: 0.4, rate: 0.95 },
    mort_vivant: { pitch: 0.6, rate: 0.8 },
    demon: { pitch: 0.3, rate: 0.95 },
    champion: { pitch: 0.8, rate: 1.0 },
    boss: { pitch: 0.25, rate: 0.85 },
    defaut: { pitch: 0.7, rate: 1.0 },
};

function parler(texte, { pitch = 1, rate = 1, volume = 1 } = {}) {
    if (!supporte || !actif.value || !texte) return;
    const u = new SpeechSynthesisUtterance(String(texte));
    u.lang = 'fr-FR';
    if (voixFr) u.voice = voixFr;
    u.pitch = pitch;
    u.rate = rate;
    u.volume = volume;
    u.onstart = () => { speaking.value = true; };
    u.onend = () => { speaking.value = false; };
    u.onerror = () => { speaking.value = false; };
    window.speechSynthesis.speak(u);
}

// Lit la narration du MJ. Joue le fichier audio (vraie voix de narrateur) si
// `url` est fournie, sinon lit le texte via Web Speech. File à UN SEUL créneau :
// si une narration est déjà en cours (p. ex. la cérémonie de lancement), la
// suivante (narration d'ambiance de l'IA) attend la fin plutôt que de la couper.
let narrAudio = null;
let narrPending = null;
let narrBusy = false;

function narrer(p) {
    const payload = typeof p === 'string' ? { texte: p } : (p || {});
    if (!actif.value || (!payload.texte && !payload.url)) return;
    if (narrBusy) {
        // Narration de JEU (`interrompre`) : elle reflète l'état LE PLUS RÉCENT
        // → on coupe la narration en cours au lieu de l'empiler, ce qui évitait
        // l'accumulation/le retard (B2). La cérémonie de lancement, elle,
        // n'interrompt pas : elle attend son tour (créneau unique remplacé).
        if (payload.interrompre) { stopNarration(); lancerNarration(payload); return; }
        narrPending = payload;
        return;
    }
    lancerNarration(payload);
}

/** Coupe net la narration en cours (audio pré-généré ou voix navigateur). */
function stopNarration() {
    if (narrAudio) { try { narrAudio.pause(); } catch { /* noop */ } narrAudio = null; }
    if (supporte) window.speechSynthesis.cancel();
    narrPending = null;
    narrBusy = false;
    speaking.value = false;
}

function lancerNarration({ texte, url }) {
    narrBusy = true;
    speaking.value = true;
    const fin = () => {
        narrBusy = false;
        speaking.value = false;
        narrAudio = null;
        if (narrPending) { const n = narrPending; narrPending = null; lancerNarration(n); }
    };
    if (url) {
        try {
            narrAudio = new Audio(url);
            narrAudio.onended = fin;
            narrAudio.onerror = () => narrerVocal(texte, fin);
            narrAudio.play().catch(() => narrerVocal(texte, fin));
            return;
        } catch {
            // bascule sur la voix navigateur
        }
    }
    narrerVocal(texte, fin);
}

function narrerVocal(texte, fin) {
    if (!supporte || !texte) { fin(); return; }
    window.speechSynthesis.cancel();
    const u = new SpeechSynthesisUtterance(String(texte));
    u.lang = 'fr-FR';
    if (voixFr) u.voice = voixFr;
    u.onend = fin;
    u.onerror = fin;
    window.speechSynthesis.speak(u);
}

// Joue un bark : fichier audio si présent, sinon repli vocal selon le profil.
function jouerBark({ url = null, texte = null, profil = 'defaut' } = {}) {
    if (!actif.value) return;
    if (url) {
        try {
            const a = new Audio(url);
            a.volume = 0.9;
            a.play().catch(() => repliBark(texte, profil));
            return;
        } catch {
            // bascule sur le repli vocal
        }
    }
    repliBark(texte, profil);
}

function repliBark(texte, profil) {
    if (!texte) return;
    parler(texte, TIMBRE[profil] || TIMBRE.defaut);
}

// À appeler sur un geste utilisateur (clic) pour débloquer l'autoplay/TTS.
function activer() {
    if (!supporte) return;
    actif.value = true;
    chargerVoixFr();
    // Un court énoncé silencieux « réveille » le moteur TTS sur certains navigateurs.
    const u = new SpeechSynthesisUtterance(' ');
    u.volume = 0;
    window.speechSynthesis.speak(u);
}

export function useVoix() {
    return { supporte, actif, speaking, narrer, jouerBark, activer };
}
