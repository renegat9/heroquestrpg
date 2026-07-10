import { chromium } from 'playwright';
const BASE='http://localhost';
const CODE=process.env.CODE, USER=process.env.USER_ID, PASS=process.env.PASS;
const b=await chromium.launch();

async function shoot(ctx, path, name, waitSel){
  const p=await ctx.newPage();
  try{ await p.goto(BASE+path,{waitUntil:'networkidle',timeout:30000}); }catch{}
  try{ await p.evaluate(()=>document.fonts.ready); }catch{}
  await p.waitForTimeout(2600);
  await p.screenshot({path:`/work/browser-shots/${name}.png`});
  console.log('OK',name);
  await p.close();
}
async function csrf(ctx){
  const cs=await ctx.cookies(BASE);
  const x=cs.find(c=>c.name==='XSRF-TOKEN');
  return {'Accept':'application/json','Content-Type':'application/json', ...(x?{'X-XSRF-TOKEN':decodeURIComponent(x.value)}:{})};
}

// ---- écrans publics (sans compte) ----
// Le mode démo n'existe plus : /cloture/DEMO et /niveau/DEMO affichent
// désormais l'état réel (chargement puis erreur, code de groupe inconnu),
// pas un contenu factice — capture utile pour vérifier ces écrans d'erreur.
const land=await b.newContext({viewport:{width:1280,height:860}});
await shoot(land,'/','s01-accueil');
await shoot(land,'/narrateur','s02-narrateur');
await shoot(land,'/joueur','s03-joueur-login');
await shoot(land,'/cloture/DEMO','s09-cloture');
const port=await b.newContext({viewport:{width:412,height:915}});
await shoot(port,'/niveau/DEMO','s08-montee-niveau');

// ---- écrans LIVE (authentifiés) ----
if(CODE && USER){
  // joueur : login → roster + manette live
  const pj=await b.newContext({viewport:{width:1280,height:860}});
  const pg=await pj.newPage(); await pg.goto(BASE+'/'); await pg.close();
  await pj.request.post(BASE+'/api/connexion',{headers:await csrf(pj),data:{identifiant:USER,mot_de_passe:PASS}});
  await shoot(pj,'/joueur','s03b-joueur-roster');
  const pjp=await b.newContext({viewport:{width:412,height:915}});
  const g2=await pjp.newPage(); await g2.goto(BASE+'/'); await g2.close();
  await pjp.request.post(BASE+'/api/connexion',{headers:await csrf(pjp),data:{identifiant:USER,mot_de_passe:PASS}});
  await shoot(pjp,'/manette/'+CODE,'s07-manette-live');
  // narrateur : ouvre la table par code → table live
  const tt=await b.newContext({viewport:{width:1440,height:900}});
  const tg=await tt.newPage(); await tg.goto(BASE+'/'); await tg.close();
  await tt.request.post(BASE+'/api/table',{headers:await csrf(tt),data:{code:CODE}});
  await shoot(tt,'/table/'+CODE,'s06-table-live');
}
await b.close();
console.log('DONE');
