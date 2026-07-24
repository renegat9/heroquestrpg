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

// Persistance locale (préférence de l'APPAREIL qui tient la table — ses
// enceintes —, pas de la campagne) : coupure, volume et débit de la voix
// (narration + barks), et « narration par la voix du navigateur » (la
// NARRATION — textes générés par l'IA comme répliques scriptées, même
// narrateur — est lue par Web Speech au lieu de la voix Gemini générée ;
// les BARKS de monstres, eux, gardent leurs fichiers audio), dans
// localStorage['audio:voix'].
const CLE_STOCKAGE = 'audio:voix';

function chargerPrefsVoix() {
    try {
        const brut = localStorage.getItem(CLE_STOCKAGE);
        return brut ? JSON.parse(brut) : {};
    } catch { return {}; }
}

function sauverPrefsVoix() {
    try {
        localStorage.setItem(CLE_STOCKAGE, JSON.stringify({
            muet: muet.value, volume: volume.value, debit: debit.value,
            voixNavigateur: voixNavigateur.value,
        }));
    } catch { /* stockage indisponible (navigation privée…) — best-effort */ }
}

const prefsVoixSauvees = chargerPrefsVoix();
const muet = ref(prefsVoixSauvees.muet ?? false);
const volume = ref(typeof prefsVoixSauvees.volume === 'number' ? prefsVoixSauvees.volume : 1);
const debit = ref(typeof prefsVoixSauvees.debit === 'number' ? prefsVoixSauvees.debit : 1);
const voixNavigateur = ref(prefsVoixSauvees.voixNavigateur ?? false);

function basculerMuet() {
    muet.value = !muet.value;
    sauverPrefsVoix();
}

function definirVolume(v) {
    volume.value = Math.max(0, Math.min(1, v));
    sauverPrefsVoix();
}

function definirDebit(v) {
    debit.value = Math.max(0.5, Math.min(2, v));
    sauverPrefsVoix();
}

function basculerVoixNavigateur() {
    voixNavigateur.value = !voixNavigateur.value;
    sauverPrefsVoix();
}

// Lit une phrase d'exemple avec la voix du NAVIGATEUR (débit/volume réglés) —
// bouton « Écouter (navigateur) » du panneau Réglages. Le clic est un geste
// utilisateur : il débloque la synthèse si besoin. Ignore volontairement la
// coupure (on demande explicitement à entendre).
function testerVoix(texte = 'Les torches vacillent, héros — voici la voix qui guidera votre aventure.') {
    if (!supporte) return;
    if (!actif.value) activer();
    parler(texte, {});
}

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

function parler(texte, { pitch = 1, rate = 1 } = {}) {
    if (!supporte || !actif.value || !texte) return;
    const u = new SpeechSynthesisUtterance(String(texte));
    u.lang = 'fr-FR';
    if (voixFr) u.voice = voixFr;
    u.pitch = pitch;
    u.rate = rate * debit.value;
    u.volume = volume.value;
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
let narApres = null; // callback « lecture terminée » de la narration en cours (B1)

function narrer(p) {
    const payload = typeof p === 'string' ? { texte: p } : (p || {});
    // Voix inactive / COUPÉE (panneau Réglages) / rien à dire : on considère la
    // « lecture » immédiatement finie (le callback `apres` sert au verrou du
    // tour suivant — B1). ⚠ NE JAMAIS omettre `payload.apres?.()` ici : sans
    // lui, couper la voix depuis le panneau bloquerait silencieusement la
    // progression du tour pour tous les joueurs (POST /table/lecture-terminee
    // n'est alors jamais envoyé).
    if (!actif.value || muet.value || (!payload.texte && !payload.url)) { payload.apres?.(); return; }
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
    narApres = null; // la lecture coupée ne déclenche PAS son callback : la nouvelle le fera
    narrBusy = false;
    speaking.value = false;
}

function lancerNarration({ texte, url, apres }) {
    narrBusy = true;
    speaking.value = true;
    narApres = apres ?? null;
    const fin = () => {
        narrBusy = false;
        speaking.value = false;
        narrAudio = null;
        const cb = narApres; narApres = null;
        if (narrPending) { const n = narrPending; narrPending = null; lancerNarration(n); }
        cb?.(); // « lecture terminée » (B1) — après avoir éventuellement enchaîné la suivante
    };
    // « Narration par la voix du navigateur » (réglage de l'appareil) : la
    // voix du NARRATEUR est remplacée par Web Speech — on ignore l'audio
    // généré (IA dynamique comme répliques pré-enregistrées, même narrateur)
    // et on lit toujours le texte. Les barks de monstres ne passent pas ici.
    if (url && !voixNavigateur.value) {
        try {
            narrAudio = new Audio(url);
            narrAudio.volume = volume.value;
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
    u.rate = debit.value;
    u.volume = volume.value;
    u.onend = fin;
    u.onerror = fin;
    window.speechSynthesis.speak(u);
}

// Joue un bark : fichier audio si présent, sinon repli vocal selon le profil.
function jouerBark({ url = null, texte = null, profil = 'defaut' } = {}) {
    if (!actif.value || muet.value) return;
    // (voixNavigateur ne s'applique PAS ici : c'est la voix du NARRATEUR
    // qu'elle remplace — les barks de monstres gardent leurs fichiers audio.)
    if (url) {
        try {
            const a = new Audio(url);
            a.volume = 0.9 * volume.value;
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
    return {
        supporte, actif, speaking, muet, volume, debit, voixNavigateur,
        narrer, jouerBark, activer, basculerMuet, definirVolume, definirDebit,
        basculerVoixNavigateur, testerVoix,
    };
}
