// Serveur de sessions navigateur persistantes pour piloter le jeu à plusieurs agents.
// Tourne DANS le conteneur Playwright officiel. Une session = un onglet nommé qui
// survit aux agents (les cookies de connexion restent chargés).
import http from 'node:http';
import fs from 'node:fs';
import { chromium } from 'playwright';

const PORT = 8970;
const BASE = process.env.HQ_BASE || 'http://localhost';
const SHOTS = '/work/browser-shots';
const ETATS = '/work/browser-shots/pilote/etats';
fs.mkdirSync(ETATS, { recursive: true });

let browser = null;
const sessions = new Map(); // nom -> {context, page, logs: []}

async function navigateur() {
  if (!browser) browser = await chromium.launch({ args: ['--no-sandbox'] });
  return browser;
}

async function ouvrir(nom, opts = {}) {
  if (sessions.has(nom)) return sessions.get(nom);
  const b = await navigateur();
  // Cookies persistés sur disque : le conteneur peut redémarrer sans perdre les connexions.
  const fichierEtat = `${ETATS}/${nom}.json`;
  const context = await b.newContext({
    viewport: opts.viewport || (opts.mobile ? { width: 420, height: 900 } : { width: 1440, height: 900 }),
    locale: 'fr-FR',
    permissions: [],
    ...(fs.existsSync(fichierEtat) ? { storageState: fichierEtat } : {}),
  });
  // L'écran de table sature son thread principal à cause des animations CSS
  // infinies (cf. docs/verdict-test-jeu-2026-07-27.md §2.1) : pour le PILOTAGE
  // on peut les neutraliser, sinon la page ne répond plus aux clics. N'affecte
  // que le rendu, jamais la logique de jeu.
  if (opts.sansAnimations) {
    await context.addInitScript(() => {
      const poser = () => {
        const s = document.createElement('style');
        s.textContent = '*,*::before,*::after{animation:none !important;transition:none !important;}';
        document.head.appendChild(s);
      };
      if (document.head) poser();
      else document.addEventListener('DOMContentLoaded', poser);
    });
  }
  const page = await context.newPage();
  const s = { context, page, logs: [] };
  page.on('console', (m) => {
    s.logs.push(`[${m.type()}] ${m.text()}`);
    if (s.logs.length > 300) s.logs.shift();
  });
  page.on('pageerror', (e) => s.logs.push(`[pageerror] ${e.message}`));
  page.on('requestfailed', (r) => s.logs.push(`[netfail] ${r.method()} ${r.url()} ${r.failure()?.errorText}`));
  sessions.set(nom, s);
  return s;
}

function texteVisible(root) {
  return root.innerText.replace(/\n{3,}/g, '\n\n').trim();
}

const commandes = {
  async init(s, a) { if (a.url) await s.page.goto(a.url.startsWith('http') ? a.url : BASE + a.url, { waitUntil: 'domcontentloaded' }); return { ok: true, url: s.page.url() }; },
  async goto(s, a) {
    await s.page.goto(a.url.startsWith('http') ? a.url : BASE + a.url, { waitUntil: 'domcontentloaded' });
    await s.page.waitForTimeout(a.pause ?? 1200);
    return { ok: true, url: s.page.url() };
  },
  async reload(s, a) { await s.page.reload({ waitUntil: 'domcontentloaded' }); await s.page.waitForTimeout(a.pause ?? 1200); return { ok: true, url: s.page.url() }; },
  async url(s) { return { url: s.page.url(), title: await s.page.title() }; },

  // clique par sélecteur CSS, ou par texte exact/partiel via {texte}
  async click(s, a) {
    let loc;
    if (a.selector) loc = s.page.locator(a.selector);
    else if (a.texte) loc = s.page.getByText(a.texte, { exact: !!a.exact });
    else throw new Error('click: selector ou texte requis');
    if (a.n != null) loc = loc.nth(a.n);
    else loc = loc.first();
    await loc.click({ timeout: a.timeout ?? 10000 });
    await s.page.waitForTimeout(a.pause ?? 900);
    return { ok: true };
  },
  async fill(s, a) {
    await s.page.locator(a.selector).nth(a.n ?? 0).fill(String(a.value), { timeout: a.timeout ?? 10000 });
    return { ok: true };
  },
  async select(s, a) {
    await s.page.locator(a.selector).nth(a.n ?? 0).selectOption(String(a.value));
    await s.page.waitForTimeout(a.pause ?? 500);
    return { ok: true };
  },
  async press(s, a) { await s.page.keyboard.press(a.key); await s.page.waitForTimeout(a.pause ?? 500); return { ok: true }; },

  // texte de la page (ou d'un sélecteur)
  async text(s, a) {
    const sel = a.selector;
    const t = sel
      ? await s.page.locator(sel).first().evaluate(texteVisible).catch(() => null)
      : await s.page.evaluate(() => document.body.innerText.replace(/\n{3,}/g, '\n\n').trim());
    return { texte: (t || '').slice(0, a.max ?? 6000) };
  },

  // liste les éléments qui matchent : index, texte, activé, visible
  async els(s, a) {
    const out = await s.page.locator(a.selector).evaluateAll((ns) =>
      ns.map((n, i) => ({
        i,
        texte: (n.innerText || n.value || '').replace(/\s+/g, ' ').trim().slice(0, 220),
        actif: !n.disabled,
        visible: !!(n.offsetWidth || n.offsetHeight || n.getClientRects().length),
        cls: n.className && typeof n.className === 'string' ? n.className.slice(0, 120) : '',
      }))
    );
    return { n: out.length, els: out };
  },

  async wait(s, a) {
    const t = a.timeout ?? 110000;
    if (a.selector) {
      await s.page.locator(a.selector).first().waitFor({ state: a.state || 'visible', timeout: t });
      return { ok: true };
    }
    if (a.texte) { await s.page.getByText(a.texte).first().waitFor({ state: 'visible', timeout: t }); return { ok: true }; }
    await s.page.waitForTimeout(a.ms ?? 1000);
    return { ok: true };
  },

  async shot(s, a) {
    const f = `${SHOTS}/${a.nom || 'shot'}.png`;
    const o = { path: f, fullPage: !!a.full, animations: 'disabled', caret: 'hide', timeout: a.timeout ?? 25000 };
    try {
      await s.page.screenshot(o);
    } catch (e) {
      // Repli : capture brute via CDP (ne dépend ni des polices ni de la stabilité du compositeur).
      const cdp = await s.context.newCDPSession(s.page);
      const { data } = await cdp.send('Page.captureScreenshot', { format: 'png' });
      fs.writeFileSync(f, Buffer.from(data, 'base64'));
      await cdp.detach().catch(() => {});
      return { fichier: f, repli_cdp: true, cause: String(e.message).slice(0, 120) };
    }
    return { fichier: f };
  },

  async save(s, a, nom) {
    await s.context.storageState({ path: `${ETATS}/${nom}.json` });
    return { ok: true };
  },

  async eval(s, a) { return { valeur: await s.page.evaluate(a.expr) }; },
  async console(s, a) { const l = s.logs.slice(-(a.n ?? 40)); return { logs: l }; },
  async clear(s) { s.logs.length = 0; return { ok: true }; },
  async close(s, a, nom) { await s.context.close(); sessions.delete(nom); return { ok: true }; },
  async list() { return { sessions: [...sessions.keys()] }; },
};

const serveur = http.createServer((req, res) => {
  let body = '';
  req.on('data', (c) => (body += c));
  req.on('end', async () => {
    res.setHeader('Content-Type', 'application/json; charset=utf-8');
    try {
      const { session = 'default', cmd, args = {} } = JSON.parse(body || '{}');
      if (cmd === 'list') { res.end(JSON.stringify(await commandes.list())); return; }
      if (!commandes[cmd]) throw new Error(`commande inconnue: ${cmd}`);
      const s = await ouvrir(session, args);
      const out = await commandes[cmd](s, args, session);
      res.end(JSON.stringify({ session, cmd, ...out }));
    } catch (e) {
      res.statusCode = 200; // on renvoie l'erreur en JSON, plus simple pour les agents
      res.end(JSON.stringify({ erreur: String(e.message || e).slice(0, 800) }));
    }
  });
});

serveur.listen(PORT, () => console.log(`pilote HQ prêt sur :${PORT} (base ${BASE})`));
