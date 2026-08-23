import { chromium } from 'playwright';
const code = process.argv[2];
const b = await chromium.launch();
const page = await (await b.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
const logs = [], ws = [];
page.on('console', (m) => logs.push(`${m.type()}: ${m.text()}`.slice(0, 160)));
page.on('pageerror', (e) => logs.push('pageerror: ' + e.message.slice(0, 160)));
page.on('websocket', (w) => {
    ws.push('OUVERT ' + w.url());
    w.on('close', () => ws.push('FERMÉ  ' + w.url()));
    w.on('socketerror', (e) => ws.push('ERREUR ' + e));
});
await page.goto('http://localhost/narrateur', { waitUntil: 'networkidle' });
await page.fill('#codeTable', code);
await page.click('button:has-text("Ouvrir la table")');
await page.waitForTimeout(12000);
console.log('WEBSOCKETS :', JSON.stringify(ws, null, 1));
console.log('ÉTAT ECHO  :', JSON.stringify(await page.evaluate(() => ({
    echo: typeof window.Echo,
    etat: window.Echo?.connector?.pusher?.connection?.state ?? null,
    canaux: window.Echo ? Object.keys(window.Echo.connector?.channels ?? {}) : null,
}))));
console.log('CONSOLE    :', JSON.stringify(logs.slice(-8), null, 1));
await b.close();
