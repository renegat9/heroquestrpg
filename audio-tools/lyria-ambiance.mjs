// Génère les boucles de musique d'ambiance via Lyria RealTime (Google) :
// une piste par scène, capturée en streaming puis écrite en WAV
// (PCM 48 kHz, stéréo, 16-bit) dans public/audio/ambiance/{scene}.wav.
import { GoogleGenAI } from '@google/genai';
import { writeFileSync } from 'node:fs';

const apiKey = process.env.GEMINI_API_KEY;
if (!apiKey) { console.error('NO_KEY'); process.exit(3); }

const CAPTURE_MS = Number(process.env.CAPTURE_MS || 40000);
const OUT = '/work/public/audio/ambiance';

const SCENES = [
    { cle: 'hub',         bpm: 90,  prompt: 'warm medieval tavern ambience, gentle lute and harp, crackling hearth, cozy, peaceful, restful' },
    { cle: 'exploration', bpm: 70,  prompt: 'dark fantasy dungeon ambient, low drone, distant echoes, sparse percussion, suspense, mysterious, exploration' },
    { cle: 'combat',      bpm: 132, prompt: 'epic orchestral battle music, driving taiko percussion, heroic brass, strings ostinato, intense, adventurous' },
    { cle: 'boss',        bpm: 108, prompt: 'menacing boss battle, ominous low choir, heavy war drums, dramatic dark orchestral, dread, climactic' },
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

async function genererScene(s) {
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

    await session.setWeightedPrompts({ weightedPrompts: [{ text: s.prompt, weight: 1.0 }] });
    await session.setMusicGenerationConfig({ musicGenerationConfig: { bpm: s.bpm, temperature: 1.1 } });
    await session.play();

    await new Promise((r) => setTimeout(r, CAPTURE_MS));
    try { await session.stop(); } catch { /* noop */ }
    try { session.close?.(); } catch { /* noop */ }

    if (erreur) throw new Error(erreur);
    const pcm = Buffer.concat(morceaux);
    if (pcm.length === 0) throw new Error('aucun audio reçu');

    writeFileSync(`${OUT}/${s.cle}.wav`, wav(pcm));
    const secs = (pcm.length / (48000 * 2 * 2)).toFixed(1);
    console.log(`✓ ${s.cle}.wav  (${secs}s, ${(pcm.length / 1048576).toFixed(1)} Mo)  « ${s.prompt.slice(0, 42)}… »`);
}

for (const s of SCENES) {
    try { await genererScene(s); }
    catch (e) { console.error(`✗ ${s.cle} : ${e.message}`); }
}
console.log('terminé');
process.exit(0);
