/*
 * SCÈNES DE TABLE POUR LE LIVRET — la capture.
 *
 * Ouvre l'écran de table de la campagne `<code>` et photographie chaque scène
 * que `livret-scenes.php` y déclenche. À lancer AVANT le déclencheur (il
 * attend dix secondes que la table s'ouvre).
 *
 *   node browser-shots/livret-scenes.mjs <code>
 *
 * ⚠ Les fichiers sont nommés d'après le TITRE LU À L'ÉCRAN, jamais d'après
 * l'ordre supposé des diffusions : la série de validation du 2026-09-14 avait
 * été nommée à l'ordre d'envoi, et trois images portaient le nom d'une autre.
 *
 * ⚠ Une seule vue plein écran (la scène posée sur la carte, pour le contexte) ;
 * les autres sont RECADRÉES sur la carte de scène. À la largeur d'une colonne
 * A4, un écran 1600 px entier réduit le texte de la scène à 4 pt.
 */
import { chromium } from 'playwright';

const code = process.argv[2];
if (!code) { console.error('usage : node browser-shots/livret-scenes.mjs <code>'); process.exit(1); }

const ATTENDUES = [
    { re: /^Grom attaque /, nom: '70-scene-attaque', plein: true },
    { re: /Fracasser/, nom: '71-scene-jet' },
    { re: / attaque Grom$/, nom: '72-scene-attaque-monstre' },
    { re: /s'effondre$/, nom: '73-scene-chute' },
    { re: /^La salle/, nom: '74-scene-salle' },
    { re: / fouille$/, nom: '75-scene-fouille' },
    { re: /^Fosse/, nom: '76-scene-piege' },
];

const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 1600, height: 900 }, deviceScaleFactor: 2 });
// La VRAIE préférence d'appareil du narrateur, allongée : chaque scène reste le
// temps d'être photographiée sans course.
await ctx.addInitScript(() => localStorage.setItem('table:scenes',
    JSON.stringify({ actives: true, duree: 8000 })));
const p = await ctx.newPage();
await p.goto('http://localhost/narrateur', { waitUntil: 'networkidle' });
await p.fill('#codeTable', code);
await p.click('button:has-text("Ouvrir la table")');
await p.waitForTimeout(3000);
// Le récit d'ouverture occupe le tiers bas de l'écran : on le replie, comme le
// ferait le narrateur, pour que la vue plein écran montre la carte sous la scène.
await p.locator('.narr-plier').click({ timeout: 3000 }).catch(() => {});
await p.waitForTimeout(500);
console.log('PRÊT');

const prises = new Set();
for (let i = 0; i < 700 && prises.size < ATTENDUES.length; i++) {
    if (await p.locator('.scn-carte').count()) {
        const brut = await p.locator('.scn-titre').innerText({ timeout: 800 }).catch(() => '');
        // le premier mot est la ligature de l'icône Material Symbols
        const titre = brut.replace(/\n/g, ' ').replace(/^\S+\s+/, '').trim();
        const cible = ATTENDUES.find((a) => a.re.test(titre) && !prises.has(a.nom));
        if (process.env.TRACE && titre !== globalThis.dernierTitre) {
            globalThis.dernierTitre = titre;
            console.log('vu :', JSON.stringify(titre), '→', cible?.nom ?? '—');
        }
        if (cible) {
            prises.add(cible.nom);
            await p.waitForTimeout(700); // l'animation d'entrée dure 220 ms
            const chemin = `/work/browser-shots/livret/${cible.nom}.png`;
            if (cible.plein) {
                await p.screenshot({ path: chemin });
            } else {
                const r = await p.locator('.scn-carte').boundingBox();
                const m = 14; // de quoi voir les coins arrondis et l'ombre
                await p.screenshot({ path: chemin, clip: {
                    x: Math.max(0, r.x - m), y: Math.max(0, r.y - m),
                    width: r.width + 2 * m, height: r.height + 2 * m } });
            }
            console.log(`${cible.nom} · ${titre}`);
        }
    }
    await p.waitForTimeout(200);
}
const manquantes = ATTENDUES.filter((a) => !prises.has(a.nom)).map((a) => a.nom);
console.log(manquantes.length ? 'MANQUANTES : ' + manquantes.join(', ') : 'TOUTES PRISES');
await b.close();
