// Génère les boucles de musique d'ambiance via Lyria RealTime (Google) :
// PLUSIEURS variantes par scène, capturées en streaming puis écrites en WAV
// (PCM 48 kHz, stéréo, 16-bit) dans public/audio/ambiance/{scene}/{i}.wav,
// plus un manifeste public/audio/ambiance/manifeste.json que la table lit pour
// tirer une variante au hasard.
//
//   VARIANTS=3 CAPTURE_MS=40000 node audio-tools/lyria-ambiance.mjs
import { GoogleGenAI } from '@google/genai';
import { writeFileSync, mkdirSync, rmSync, existsSync } from 'node:fs';

const apiKey = process.env.GEMINI_API_KEY;
if (!apiKey) { console.error('NO_KEY'); process.exit(3); }

const CAPTURE_MS = Number(process.env.CAPTURE_MS || 40000);
const VARIANTS = Number(process.env.VARIANTS || 2);
const OUT = '/work/public/audio/ambiance';

// Plusieurs « teintes » par scène : variées pour diversifier les variantes
// (Lyria est de toute façon stochastique d'une session à l'autre).
const SCENES = [
    { cle: 'hub', bpm: 90, base: 'warm medieval tavern ambience, cozy, peaceful, restful',
      teintes: ['gentle lute and harp, crackling hearth', 'soft flute and strings, distant chatter', 'mellow lyre, calm evening mood'] },
    { cle: 'exploration', bpm: 70, base: 'dark fantasy dungeon ambient, sparse, mysterious, exploration',
      teintes: ['low drone, distant echoes, dripping water', 'eerie strings, faint choir, suspense', 'soft hand drums, creaking stone, unease'] },
    { cle: 'combat', bpm: 132, base: 'epic orchestral battle music, intense, adventurous, driving',
      teintes: ['taiko percussion, heroic brass, strings ostinato', 'fast strings, war horns, cymbals', 'pounding drums, soaring brass, urgent'] },
    { cle: 'boss', bpm: 108, base: 'menacing boss battle, dramatic dark orchestral, dread, climactic',
      teintes: ['ominous low choir, heavy war drums', 'dissonant brass, thunderous timpani, evil organ', 'demonic chant, crushing percussion'] },
    { cle: 'victoire', bpm: 100, base: 'triumphant victory fanfare, heroic, uplifting, celebratory',
      teintes: ['bright brass fanfare, soaring strings, bells', 'joyful choir, major key, triumphant horns', 'warm strings, hopeful, resolving, glorious'] },
    { cle: 'defaite', bpm: 60, base: 'somber defeat, mournful, melancholic, dark',
      teintes: ['slow solo cello, distant choir, grief', 'low strings, mournful piano, despair', 'fading drone, lonely horn, loss'] },
];

const ai = new GoogleGenAI({ apiKey, apiVersion: 'v1alpha' });

function wav(pcm, sr = 48000, ch = 2, bps = 16) {
    const blockAlign = ch * bps / 8;
    const h = Buffer.alloc(44);
    h.write('RIFF', 0); h.writeUInt32LE(36 + pcm.length, 4); h.write('WAVE', 8);
    h.write('fmt ', 12); h.writeUInt32LE(16, 16); h.writeUInt16LE(1, 20);
    h.writeUInt16LE(ch, 22); h.writeUInt32LE(sr, 24); h.writeUInt32LE(sr * blockAlign, 28);
    h.writeUInt16LE(blockAlign, 32); h.writeUInt16LE(bps, 34);
    h.write('data', 36); h.writeUInt32LE(pcm.length, 40);
    return Buffer.concat([h, pcm]);
}

async function genererVariante(prompt, bpm) {
    const morceaux = [];
    let erreur = null;
    const session = await ai.live.music.connect({
        model: 'models/lyria-realtime-exp',
        callbacks: {
            onmessage: (m) => {
                const ac = m?.serverContent?.audioChunks;
                if (Array.isArray(ac)) for (const c of ac) if (c?.data) morceaux.push(Buffer.from(c.data, 'base64'));
            },
            onerror: (e) => { erreur = e?.message || String(e); },
            onclose: () => {},
        },
    });
    await session.setWeightedPrompts({ weightedPrompts: [{ text: prompt, weight: 1.0 }] });
    await session.setMusicGenerationConfig({ musicGenerationConfig: { bpm, temperature: 1.1 } });
    await session.play();
    await new Promise((r) => setTimeout(r, CAPTURE_MS));
    try { await session.stop(); } catch { /* noop */ }
    try { session.close?.(); } catch { /* noop */ }
    if (erreur) throw new Error(erreur);
    const pcm = Buffer.concat(morceaux);
    if (pcm.length === 0) throw new Error('aucun audio reçu');
    return wav(pcm);
}

const manifeste = {};

for (const s of SCENES) {
    if (existsSync(`${OUT}/${s.cle}.wav`)) rmSync(`${OUT}/${s.cle}.wav`); // ancien fichier plat
    mkdirSync(`${OUT}/${s.cle}`, { recursive: true });
    manifeste[s.cle] = [];
    for (let v = 0; v < VARIANTS; v++) {
        const teinte = s.teintes[v % s.teintes.length];
        try {
            const buf = await genererVariante(`${s.base}, ${teinte}`, s.bpm);
            writeFileSync(`${OUT}/${s.cle}/${v}.wav`, buf);
            manifeste[s.cle].push(`/audio/ambiance/${s.cle}/${v}.wav`);
            console.log(`✓ ${s.cle}/${v}.wav  (${(buf.length / 1048576).toFixed(1)} Mo)  « ${teinte.slice(0, 38)}… »`);
        } catch (e) {
            console.error(`✗ ${s.cle}/${v} : ${e.message}`);
        }
    }
}

writeFileSync(`${OUT}/manifeste.json`, JSON.stringify(manifeste, null, 2));
console.log('manifeste :', Object.fromEntries(Object.entries(manifeste).map(([k, v]) => [k, v.length])));
process.exit(0);
