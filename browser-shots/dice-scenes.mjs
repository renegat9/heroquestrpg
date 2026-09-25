import { chromium } from 'playwright';
import { writeFileSync, existsSync, unlinkSync } from 'fs';

const base = 'http://localhost';
const code = process.env.CODE;
if (!code) throw new Error('CODE manquant');

const READY = '/work/browser-shots/.table-ready';
const noms = ['boule-de-feu', 'sort-dread-mind', 'piege-chute-de-blocs'];
for (const n of noms) {
    const trig = `/work/browser-shots/.trig-${n}`;
    if (existsSync(trig)) unlinkSync(trig);
}
if (existsSync(READY)) unlinkSync(READY);

const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 1440, height: 900 } });
const page = await ctx.newPage();

await page.goto(base + '/narrateur', { waitUntil: 'networkidle' });
await page.fill('#codeTable', code);
await page.click('button:has-text("Ouvrir la table")');
await page.waitForTimeout(3000);

// Signale à l'orchestrateur (bash, côté hôte) que la table est prête : le
// déclencheur PHP peut diffuser sa première scène.
writeFileSync(READY, 'ok');
console.log('table prête');

// Pour chaque scène attendue, on ATTEND un fichier « trigger » écrit par
// l'orchestrateur JUSTE APRÈS avoir diffusé (donc la scène est déjà en
// train de s'afficher côté navigateur), puis on shoote avec une petite
// marge pour laisser l'animation d'entrée se poser.
// ⚠ Pas de clic pour dégager la scène précédente : l'orchestrateur (bash,
// côté hôte) espace ses diffusions de PLUS de 5 s (DUREE_DEFAUT), le temps
// que la scène en cours se ferme TOUTE SEULE et vide la file — la suivante
// s'affiche alors dès son arrivée, sans course avec un clic.
for (const nom of noms) {
    const trig = `/work/browser-shots/.trig-${nom}`;
    const deadline = Date.now() + 30000;
    while (!existsSync(trig) && Date.now() < deadline) {
        await page.waitForTimeout(200);
    }
    // La diffusion passe par la file `temps-reel` (QUEUE_CONNECTION=database,
    // scrutée par défaut toutes les 3 s) : une marge de 900 ms suffisait pour
    // Reverb seul, pas pour un job qui peut dormir jusqu'à 3 s avant d'être
    // pris. 4,5 s couvre ce pire cas plus le rendu.
    await page.waitForTimeout(4500);
    await page.screenshot({ path: `/work/browser-shots/dice-${nom}.png`, animations: 'disabled', timeout: 90000 });
    console.log('capture', nom, existsSync(trig) ? '' : '(timeout, pas de trigger vu)');
}

await b.close();
