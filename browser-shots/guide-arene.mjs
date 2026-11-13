// Captures de l'onglet « Arène » du guide (/guide?onglet=arene), à 1280 px et à 412 px, sur la stack locale.
// Page publique : aucun compte, aucun personnage n'est créé. Lancé depuis le conteneur Playwright (voir le brief) :
// playwright est installé dans /tmp, d'où le createRequire (un import nu ne le chercherait pas là).
//
// Sorties : guide-arene-1280.png / guide-arene-412.png (pleine page), guide-arene-plan-*.png (le plan du hall),
// et à 412 px une capture PAR SECTION (guide-arene-412-<id>.png), pour lire le texte sans qu'une pleine page
// de 7 000 px ne soit réduite à l'illisible.
import { createRequire } from 'node:module';

const require = createRequire('/tmp/guide-arene.cjs');
const { chromium } = require('playwright');

const base = 'http://localhost';
const cible = `${base}/guide?onglet=arene`;
const sortie = '/work/browser-shots';

const b = await chromium.launch();

async function passer(largeur, hauteur, nom, { sections = false } = {}) {
  const ctx = await b.newContext({ viewport: { width: largeur, height: hauteur } });
  const page = await ctx.newPage();
  const erreurs = [];
  page.on('pageerror', (e) => erreurs.push(`pageerror: ${e.message}`));
  page.on('console', (m) => { if (m.type() === 'error') erreurs.push(`console: ${m.text()}`); });

  await page.goto(cible, { waitUntil: 'networkidle', timeout: 30000 });
  await page.waitForSelector('.ga-plan', { state: 'visible', timeout: 15000 });
  await page.waitForTimeout(600);

  // Débordement horizontal de la PAGE (et non d'un tableau, qui défile dans son conteneur).
  const mesure = await page.evaluate(() => {
    const d = document.documentElement;
    const tables = [...document.querySelectorAll('.ga-table-zone')].map((z) => ({
      visible: z.scrollWidth - z.clientWidth,
    }));
    return {
      pageScrollWidth: d.scrollWidth,
      pageClientWidth: d.clientWidth,
      tablesDebordantes: tables.filter((t) => t.visible > 0).length,
      tablesTotal: tables.length,
    };
  });
  console.log(nom, JSON.stringify(mesure));

  await page.screenshot({ path: `${sortie}/guide-arene-${nom}.png`, fullPage: true });
  await page.locator('.ga-plan-zone').screenshot({ path: `${sortie}/guide-arene-plan-${nom}.png` });

  // L'écran tel qu'on l'ouvre (en-tête, barre d'onglets défilée jusqu'à « Arène », début du texte). Les captures
  // d'élément ci-dessus font défiler la page : on remonte d'abord.
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.waitForTimeout(300);
  await page.screenshot({ path: `${sortie}/guide-arene-${nom}-haut.png` });

  if (sections) {
    const ids = await page.$$eval('section[id^="arene-"]', (els) => els.map((e) => e.id));
    for (const id of ids) {
      await page.locator(`#${id}`).screenshot({ path: `${sortie}/guide-arene-${nom}-${id}.png` });
    }
    console.log('sections', ids.join(', '));
  }

  console.log('OK', nom, erreurs.length ? erreurs : '(aucune erreur console)');
  await ctx.close();
}

await passer(1280, 900, '1280');
await passer(412, 915, '412', { sections: true });
await b.close();
