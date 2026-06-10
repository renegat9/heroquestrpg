/* global React, ReactDOM */
const { useState, useEffect, useRef, useCallback } = React;

/* ------------------------------------------------------------------ data */
const HEROES = {
  mage: { key:'mage', name:'Eldra Sombrelune', cls:'Magicienne', crest:'auto_awesome', icon:'auto_fix_high',
    lvl:3, body:{cur:3,max:4}, mind:{cur:5,max:6}, atkAttr:2, mindAttr:5, atk:1, def:2, hasSpells:true,
    conds:[{t:'buff',l:'Renforcé',d:2}],
    gear:{ arme:'Bâton de sorcier', armure:'Robe tissée', sac:'Sacoche runique', conso:'2 fioles' } },
  barb: { key:'barb', name:'Gorrim Tuevague', cls:'Barbare', crest:'swords', icon:'sports_martial_arts',
    lvl:3, body:{cur:5,max:8}, mind:{cur:2,max:2}, atkAttr:5, mindAttr:2, atk:3, def:2, hasSpells:false,
    conds:[{t:'burn',l:'Brûlure',d:1}],
    gear:{ arme:'Épée large', armure:'Cotte de mailles', sac:'Sac de cuir', conso:'1 potion' } },
  dwarf: { key:'dwarf', name:'Durik Forgefer', cls:'Nain', crest:'hardware', icon:'construction',
    lvl:3, body:{cur:7,max:7}, mind:{cur:3,max:3}, atkAttr:4, mindAttr:3, atk:2, def:2, hasSpells:false, isSmith:true,
    conds:[],
    gear:{ arme:'Hache naine', armure:'Plates gravées', sac:'Outils de forge', conso:'—' } },
  elf: { key:'elf', name:'Sylanwë', cls:'Elfe', crest:'park', icon:'forest',
    lvl:3, body:{cur:6,max:6}, mind:{cur:4,max:4}, atkAttr:4, mindAttr:4, atk:2, def:2, hasSpells:true,
    conds:[{t:'poison',l:'Poison',d:2}],
    gear:{ arme:'Arc long', armure:'Cuir clouté', sac:'Carquois', conso:'3 flèches+' } },
};

const SPELLS = [
  { id:'fb',  el:'fire',  name:'Boule de feu',     desc:'2 dés · cible à vue',     target:'foe',  icon:'local_fire_department' },
  { id:'wall',el:'fire',  name:'Mur de flammes',   desc:'Bloque une porte · 3t',   target:'tile', icon:'fireplace' },
  { id:'heal',el:'water', name:'Source de vie',    desc:'+2 Body · allié',          target:'ally', icon:'water_drop' },
  { id:'ice', el:'water', name:'Éclat de givre',   desc:'1 dé + Ralenti',          target:'foe',  icon:'ac_unit' },
  { id:'rock',el:'earth', name:'Peau de roc',      desc:'+2 Défense · allié · 2t', target:'ally', icon:'landscape' },
  { id:'gust',el:'air',   name:'Bourrasque',       desc:'Repousse · 2 cases',      target:'foe',  icon:'air' },
];
const EL_LABEL = { fire:'Feu', water:'Eau', earth:'Terre', air:'Air' };
const EL_CLASS = { fire:'el-fire', water:'el-water', earth:'el-earth', air:'el-air' };

const FOES = [
  { id:'g1', name:'Gobelin', icon:'sentiment_very_dissatisfied', body:1, dist:'à portée' },
  { id:'g2', name:'Gobelin', icon:'sentiment_very_dissatisfied', body:1, dist:'à portée' },
  { id:'orc',name:'Orc capitaine', icon:'crew', body:3, dist:'2 cases' },
];

const BACKPACK = [
  { name:'Potion de soin', qty:2, rar:'common', icon:'science', price:50 },
  { name:'Parchemin de Téléport', qty:1, rar:'rare', icon:'description', price:0 },
  { name:'Torche', qty:3, rar:'common', icon:'local_fire_department', price:0 },
];
const SHOP = [
  { id:'pot', name:'Potion de soin', rar:'common', icon:'science', price:50 },
  { id:'scr', name:'Parchemin de feu', rar:'uncommon', icon:'description', price:120 },
  { id:'dag', name:'Dague enchantée', rar:'uncommon', icon:'colorize', price:200 },
  { id:'arm', name:'Armure de cuir cloutée', rar:'rare', icon:'shield', price:180 },
  { id:'amu', name:'Amulette du Spectre', rar:'unique', icon:'diamond', price:900 },
];
const RAR_LABEL = { common:'Commun', uncommon:'Peu commun', rare:'Rare', unique:'Unique' };
const FORGE_CAT = [
  { id:'f1', name:'Aiguiser la lame', desc:'+1 dé d\'attaque', price:160 },
  { id:'f2', name:'Renforcer l\'armure', desc:'+1 dé de défense', price:200 },
  { id:'f3', name:'Gravure runique', desc:'Ignore 1 bouclier ennemi', price:340 },
];

/* ------------------------------------------------------------------ tweaks */
const TWEAK_DEFAULTS = /*EDITMODE-BEGIN*/{
  "accent": ["oklch(0.76 0.155 65)","oklch(0.83 0.150 80)","oklch(0.62 0.170 42)"],
  "ambiance": 62,
  "ton": "Héroïque",
  "titrage": "Cinzel"
}/*EDITMODE-END*/;
const TON_OPEN = {
  "Héroïque": "Une lueur d'ambre danse sur les murs suintants. Trois ombres trapues se dressent — mais vos lames sont prêtes à écrire une légende !",
  "Sombre": "L'air sent la cendre froide et le sang séché. Dans le noir, trois silhouettes se redressent en grognant. Personne ne vous entendra crier.",
  "Comique": "Trois gobelins se relèvent en ronchonnant ; l'un trébuche sur son propre gourdin. « Encore des héros ? Pfff, soupire le plus gros. »",
};
const TITRAGE_FONT = {
  "Cinzel": "'Cinzel', Georgia, serif",
  "Spectral": "'Spectral', Georgia, serif",
  "Système": "ui-serif, Georgia, serif",
};

/* ------------------------------------------------------------------ utils */
const rng = () => Math.random();
const Sym = ({n, fill, s, style}) => <span className={'msym'+(fill?' fill':'')} style={{fontSize:s, ...style}}>{n}</span>;

function Pips({cur, max, kind}) {
  return <div className="pips">{Array.from({length:max}).map((_,i)=>
    <div key={i} className={'pip '+(i<cur ? (kind==='body'?'full-body':'full-mind') : '')} />)}</div>;
}

/* ------------------------------------------------------------------ choice card */
function Choice({icon, title, meta, onClick, sel, disabled, danger, elClass, chev=true}) {
  return (
    <button className={'choice'+(sel?' sel':'')+(disabled?' disabled':'')+(danger?' danger':'')+(elClass?' '+elClass:'')}
            onClick={disabled?undefined:onClick}>
      <span className="ic"><Sym n={icon} /></span>
      <span style={{flex:1}}><span className="ttl">{title}</span>{meta && <span className="meta">{meta}</span>}</span>
      {chev && <Sym n="chevron_right" style={{}} />}
    </button>
  );
}

/* ============================================================== APP */
function App() {
  const [t, setTweak] = useTweaks(TWEAK_DEFAULTS);
  const [heroKey, setHeroKey] = useState('mage');
  const [tab, setTab] = useState('action');
  const [scene, setScene] = useState('combat');         // combat | marche
  const [thinking, setThinking] = useState(false);
  const [conn, setConn] = useState('ok');
  const hero = HEROES[heroKey];

  const [body, setBody] = useState(hero.body);
  const [mind, setMind] = useState(hero.mind);
  useEffect(()=>{ setBody(HEROES[heroKey].body); setMind(HEROES[heroKey].mind); }, [heroKey]);

  const [myTurn, setMyTurn] = useState(true);
  const [narr, setNarr] = useState("Une lueur d'ambre danse sur les murs suintants. Trois ombres trapues se redressent en grognant…");

  // flow: {kind:'attack'|'spell', step:'target'|'rolling'|'result', spell, target, atk[], def[]}
  const [flow, setFlow] = useState(null);
  const [vote, setVote] = useState(null);
  const [gold, setGold] = useState(640);
  const [basket, setBasket] = useState([]);

  const think = useCallback((ms=1400)=>{ setThinking(true); setTimeout(()=>setThinking(false), ms); }, []);

  /* ---- attack / spell resolution ---- */
  function rollDice(nAtk, nDef) {
    const atk = Array.from({length:nAtk}, ()=> rng()<0.5 ? 'skull':'blank');
    const def = Array.from({length:nDef}, ()=> rng()<0.34 ? 'shield':'blank');
    return {atk, def};
  }
  function beginAttack() { setFlow({kind:'attack', step:'target'}); }
  function beginSpell(spell) {
    if (spell.target==='tile') { resolveSpell(spell, null); return; }
    setFlow({kind:'spell', step:'target', spell});
  }
  function chooseTarget(target) {
    const f = flow;
    if (f.kind==='attack') {
      const dice = rollDice(hero.atk, target.id==='orc'?3:1);
      setFlow({...f, step:'rolling', target, dice});
      setTimeout(()=>setFlow(p=>p && ({...p, step:'result'})), 950);
    } else {
      resolveSpell(f.spell, target);
    }
  }
  function resolveSpell(spell, target) {
    if (spell.target==='ally' && spell.id==='heal') {
      setFlow({kind:'spell', step:'result', spell, target, heal:true});
      return;
    }
    const dice = rollDice(spell.id==='fb'?2:1, target?(target.id==='orc'?3:1):0);
    setFlow({kind:'spell', step:'rolling', spell, target, dice});
    setTimeout(()=>setFlow(p=>p && ({...p, step:'result'})), 950);
  }
  function confirmResolve() {
    const f = flow;
    let txt = '';
    if (f.kind==='attack') {
      const sk = f.dice.atk.filter(d=>d==='skull').length;
      const sh = f.dice.def.filter(d=>d==='shield').length;
      const dmg = Math.max(0, sk-sh);
      txt = dmg>0 ? `Ton arme s'abat sur ${f.target.name} — ${dmg} blessure${dmg>1?'s':''} !`
                  : `${f.target.name} pare le coup de justesse.`;
    } else if (f.heal) {
      setMind(m=>({...m, cur:Math.max(0,m.cur-1)}));
      txt = `Une eau claire enveloppe ton allié — +2 Body.`;
    } else {
      const sk = f.dice.atk.filter(d=>d==='skull').length;
      setMind(m=>({...m, cur:Math.max(0,m.cur-1)}));
      txt = `${f.spell.name} frappe ${f.target?f.target.name:'la zone'} — ${sk} dégât${sk>1?'s':''} !`;
    }
    setNarr(txt);
    setFlow(null);
    setMyTurn(false);
    think(1600);
    setTimeout(()=>{ setMyTurn(true); setNarr("À toi de jouer. Les gobelins resserrent leur cercle…"); }, 2400);
  }

  /* ---- vote ---- */
  function launchVote() {
    setVote({ q:'Recharger la quête ? (TPK)', opts:[{k:'reload',l:'Recharger',c:1},{k:'quit',l:'Abandonner',c:0}],
              mine:null, missing:2 });
    // simulate other players trickling in
    setTimeout(()=>setVote(v=>v && ({...v, opts:v.opts.map(o=>o.k==='reload'?{...o,c:o.c+1}:o), missing:1})), 1400);
    setTimeout(()=>setVote(v=>v && ({...v, opts:v.opts.map(o=>o.k==='reload'?{...o,c:o.c+1}:o), missing: v.mine!=null?0:1})), 2800);
  }
  function castVote(k) { setVote(v=> v && ({...v, mine:k, opts:v.opts.map(o=>o.k===k?{...o,c:o.c+1}:o), missing:Math.max(0,v.missing-1)})); }

  /* ---- market ---- */
  const projected = basket.reduce((s,id)=> s + (SHOP.find(x=>x.id===id)?.price||0), 0);
  function toggleBasket(id){ setBasket(b=> b.includes(id)? b.filter(x=>x!==id) : [...b,id]); }

  /* scene → default tab */
  useEffect(()=>{ if(scene==='marche') setTab('action'); }, [scene]);

  /* ---- apply tweaks to CSS vars + tone ---- */
  useEffect(()=>{
    const r=document.documentElement.style;
    const [tc, tb, em] = t.accent || TWEAK_DEFAULTS.accent;
    r.setProperty('--torch', tc); r.setProperty('--torch-bright', tb); r.setProperty('--ember', em);
    r.setProperty('--ambiance', (t.ambiance ?? 62)/100);
    r.setProperty('--font-display', TITRAGE_FONT[t.titrage] || TITRAGE_FONT.Cinzel);
  }, [t.accent, t.ambiance, t.titrage]);
  const firstTon = useRef(true);
  useEffect(()=>{ if(firstTon.current){ firstTon.current=false; return; }
    setNarr(TON_OPEN[t.ton] || TON_OPEN.Héroïque); }, [t.ton]);

  const navItems = scene==='marche'
    ? [['action','storefront','Marché'],['fiche','person','Fiche'],['sorts','auto_awesome','Sorts'],['sac','backpack','Sac']]
    : [['action','swords','Action'],['fiche','person','Fiche'],['sorts','auto_awesome','Sorts'],['sac','backpack','Sac']];

  return (
    <React.Fragment>
    <div className="phone">
      <div className="screen tex-vignette" style={{position:'relative'}}>

        {/* top */}
        <div className="topbar">
          <div className="hero-chip">
            <span className="crest"><Sym n={hero.crest} fill s={22}/></span>
            <div><div className="nm">{hero.name}</div><div className="cls">{hero.cls} · Niv. {hero.lvl}</div></div>
          </div>
          {thinking
            ? <div className="think" style={{marginLeft:'auto'}}><span className="dots"><i></i><i></i><i></i></span>MJ réfléchit…</div>
            : <div className={'conn '+conn} style={{marginLeft:'auto'}}><span className="dot"></span>{conn==='ok'?'Connecté':'Reconnexion…'}</div>}
        </div>

        {/* mini pv */}
        <div className="mini-pv">
          <div className="g"><div className="lab" style={{color:'var(--body-bright)'}}><span><Sym n="favorite" fill s={12}/> BODY</span><span>{body.cur}/{body.max}</span></div>
            <div className="pips">{Array.from({length:body.max}).map((_,i)=><div key={i} className={'pip'+(i<body.cur?' b':'')}/>)}</div></div>
          <div className="g"><div className="lab" style={{color:'var(--mind-bright)'}}><span><Sym n="psychology" fill s={12}/> MIND</span><span>{mind.cur}/{mind.max}</span></div>
            <div className="pips">{Array.from({length:mind.max}).map((_,i)=><div key={i} className={'pip'+(i<mind.cur?' m':'')}/>)}</div></div>
        </div>

        {/* narration */}
        <div className="narr-peek">
          <div className="hd"><span className="who">LE MAÎTRE DE JEU</span>
            <span className="bars"><i></i><i></i><i></i></span></div>
          <p>{narr}</p>
        </div>

        {/* body */}
        <div className="body">
          {tab==='action' && scene==='combat' && <ActionTab {...{myTurn, hero, beginAttack, beginSpell, setNarr, setMyTurn, think, setTab}} />}
          {tab==='action' && scene==='marche' && <MarketTab {...{basket, toggleBasket, projected, gold, hero}} />}
          {tab==='fiche' && <FicheTab {...{hero, heroKey, setHeroKey, body, mind}} />}
          {tab==='sorts' && <SpellsTab {...{hero, beginSpell, mind}} />}
          {tab==='sac' && <SacTab {...{hero}} />}
        </div>

        {/* bottom nav */}
        <div className="botnav">
          {navItems.map(([k,ic,l])=>
            <button key={k} className={tab===k?'on':''} onClick={()=>setTab(k)}>
              <Sym n={ic} /><span className="bl">{l}</span>
            </button>)}
        </div>

        {/* overlays */}
        {flow && <FlowSheet {...{flow, hero, chooseTarget, confirmResolve, close:()=>setFlow(null)}} />}
        {vote && <VoteSheet {...{vote, castVote, close:()=>setVote(null)}} />}
      </div>

      {/* contrôle de démo */}
      <div className="scene-ctrl">
        <a href="index.html" title="Hub" style={{display:'grid',placeItems:'center',width:30,height:30,borderRadius:'50%',color:'var(--ink-300)',textDecoration:'none'}}><span className="msym" style={{fontSize:18}}>home</span></a>
        <span className="lbl">Démo</span>
        <button className={scene==='combat'?'on':''} onClick={()=>setScene('combat')}>Combat</button>
        <button className={scene==='marche'?'on':''} onClick={()=>setScene('marche')}>Marché</button>
        <button onClick={launchVote}>Vote</button>
        <button onClick={()=>{ setBody(b=>({...b,cur:Math.max(0,b.cur-1)})); setNarr('Une griffe te lacère — tu encaisses 1 blessure.'); }}>Subir</button>
      </div>
      </div>

      <TweaksPanel title="Tweaks">
        <TweakSection label="Ambiance" />
        <TweakColor label="Accent de torche" value={t.accent}
          options={[
            ["oklch(0.76 0.155 65)","oklch(0.83 0.150 80)","oklch(0.62 0.170 42)"],
            ["oklch(0.64 0.190 38)","oklch(0.72 0.185 42)","oklch(0.52 0.170 30)"],
            ["oklch(0.80 0.135 88)","oklch(0.86 0.130 92)","oklch(0.64 0.130 72)"],
            ["oklch(0.70 0.130 200)","oklch(0.80 0.110 205)","oklch(0.58 0.120 230)"]
          ]}
          onChange={v=>setTweak('accent', v)} />
        <TweakSlider label="Intensité d'ambiance" value={t.ambiance} min={0} max={100} step={5} unit="%"
          onChange={v=>setTweak('ambiance', v)} />
        <TweakSection label="Récit" />
        <TweakRadio label="Ton narratif" value={t.ton} options={["Héroïque","Sombre","Comique"]}
          onChange={v=>setTweak('ton', v)} />
        <TweakSection label="Typographie" />
        <TweakSelect label="Police de titrage" value={t.titrage} options={["Cinzel","Spectral","Système"]}
          onChange={v=>setTweak('titrage', v)} />
      </TweaksPanel>
    </React.Fragment>
  );
}

/* ============================================================== tabs */
function ActionTab({myTurn, hero, beginAttack, beginSpell, setNarr, setMyTurn, think, setTab}) {
  if (!myTurn) return (
    <div>
      <div className="turn-banner wait"><Sym n="hourglass_top"/> En attente du tour des autres héros…</div>
      <Init cur="orc" />
      <div className="empty-note">Les autres agissent. Tu reprendras la main au prochain tour.</div>
    </div>
  );
  return (
    <div>
      <div className="turn-banner mine"><Sym n="bolt" fill/> C'est ton tour — choisis une action</div>
      <Init cur={hero.key.toUpperCase().slice(0,3)} />
      <div className="sect-title"><Sym n="touch_app" s={16}/> Actions</div>
      <div className="choices">
        <Choice icon="swords" title="Attaquer" meta={`${hero.atk} dés de crâne · ennemi à portée`} onClick={beginAttack} />
        {hero.hasSpells && <Choice icon="auto_awesome" title="Lancer un sort" meta="Choisir dans le grimoire" onClick={()=>setTab('sorts')} />}
        <Choice icon="directions_walk" title="Se déplacer" meta="Jusqu'à 5 cases" onClick={()=>{setNarr('Tu avances prudemment entre les colonnes brisées.'); setMyTurn(false); think(); setTimeout(()=>setMyTurn(true),2200);}} />
        <Choice icon="travel_explore" title="Fouiller la pièce" meta="Pièges · trésors · passages" onClick={()=>{setNarr('Tu fouilles les décombres… une fiole roule à tes pieds.'); setMyTurn(false); think(); setTimeout(()=>setMyTurn(true),2200);}} />
        <Choice icon="forum" title="Parler" meta="Interpeller une créature" disabled />
        <Choice icon="skip_next" title="Passer le tour" meta="Garder ses forces" danger chev={false} onClick={()=>{setNarr('Tu restes sur tes gardes, arme levée.'); setMyTurn(false); think(); setTimeout(()=>setMyTurn(true),2000);}} />
      </div>
    </div>
  );
}

function Init({cur}) {
  const order = [{k:'MAG',foe:false},{k:'BAR',foe:false},{k:'G1',foe:true},{k:'ELF',foe:false},{k:'G2',foe:true}];
  const norm = s => s==='MAGE'?'MAG':s==='BARB'?'BAR':s==='DWARF'?'NAI':s==='ELF'?'ELF':s;
  const c = norm(cur);
  return (
    <div style={{display:'flex',gap:6,alignItems:'center',marginBottom:16,overflowX:'auto',paddingBottom:4}}>
      {order.map((o,i)=>(
        <React.Fragment key={i}>
          <div style={{flex:'none',width:42,height:42,borderRadius:'50%',display:'grid',placeItems:'center',
            fontWeight:800,fontSize:12,
            border:'2px solid '+(o.k===c?'var(--torch)':o.foe?'var(--body)':'var(--stone-600)'),
            background:o.k===c?'var(--torch)':'var(--stone-800)',
            color:o.k===c?'var(--stone-950)':o.foe?'var(--body-bright)':'var(--ink-300)',
            boxShadow:o.k===c?'var(--glow-torch)':'none', transform:o.k===c?'scale(1.1)':'none'}}>{o.k}</div>
          {i<order.length-1 && <Sym n="chevron_right" style={{color:'var(--ink-700)'}}/>}
        </React.Fragment>
      ))}
    </div>
  );
}

function FicheTab({hero, heroKey, setHeroKey, body, mind}) {
  return (
    <div>
      <div className="sect-title"><Sym n="groups" s={16}/> Roster du joueur</div>
      <div className="roster">
        {Object.values(HEROES).map(h=>(
          <div key={h.key} className={'r'+(h.key===heroKey?' on':'')} onClick={()=>setHeroKey(h.key)}>
            <Sym n={h.crest} fill={h.key===heroKey} /><span className="rn">{h.cls}</span>
          </div>
        ))}
      </div>

      <div className="fiche-head">
        <div className="portrait"><Sym n={hero.icon} fill s={44}/><span className="ph-tag">portrait classe</span></div>
        <div><h2>{hero.name}</h2><div className="lvl">{hero.cls} · Niveau {hero.lvl}</div></div>
      </div>

      <div className="sect-title"><Sym n="casino" s={16}/> Attributs (dés de jet)</div>
      <div className="stat-grid">
        <div className="stat"><div className="k">Attaque</div><div className="v" style={{color:'var(--torch)'}}>{hero.atk}<span className="die-n">dés crâne</span></div></div>
        <div className="stat"><div className="k">Défense</div><div className="v" style={{color:'var(--mind-bright)'}}>{hero.def}<span className="die-n">dés bouclier</span></div></div>
        <div className="stat"><div className="k">Body (attr.)</div><div className="v" style={{color:'var(--body-bright)'}}>{hero.atkAttr}</div></div>
        <div className="stat"><div className="k">Mind (attr.)</div><div className="v" style={{color:'var(--mind-bright)'}}>{hero.mindAttr}</div></div>
      </div>

      <div className="sect-title"><Sym n="ecg_heart" s={16}/> Points de vie</div>
      <div className="gauge">
        <div className="top"><span className="nm" style={{color:'var(--body-bright)'}}><Sym n="favorite" fill s={18}/> Body</span><span className="val" style={{color:'var(--body-bright)'}}>{body.cur} / {body.max}</span></div>
        <Pips cur={body.cur} max={body.max} kind="body" />
      </div>
      <div className="gauge">
        <div className="top"><span className="nm" style={{color:'var(--mind-bright)'}}><Sym n="psychology" fill s={18}/> Mind</span><span className="val" style={{color:'var(--mind-bright)'}}>{mind.cur} / {mind.max}</span></div>
        <Pips cur={mind.cur} max={mind.max} kind="mind" />
      </div>

      <div className="sect-title" style={{marginTop:18}}><Sym n="emergency_heat" s={16}/> Conditions</div>
      {hero.conds.length ? <div className="badges">
        {hero.conds.map((c,i)=> <span key={i} className={'badge b-'+c.t}>
          <Sym n={c.t==='buff'?'shield_with_heart':c.t==='burn'?'local_fire_department':'coronavirus'} fill s={16}/>
          {c.l} <span className="dur">{c.d}t</span></span>)}
      </div> : <div className="empty-note" style={{padding:'12px'}}>Aucune condition active.</div>}
    </div>
  );
}

function SpellsTab({hero, beginSpell, mind}) {
  if (!hero.hasSpells) return (
    <div className="empty-note"><Sym n="auto_awesome" s={36} style={{display:'block',margin:'0 auto 12px',color:'var(--ink-700)'}}/>
      Le {hero.cls} ne manie pas la magie. Sa puissance est dans l'acier.</div>
  );
  const byEl = {};
  SPELLS.forEach(s=>{ (byEl[s.el]=byEl[s.el]||[]).push(s); });
  return (
    <div>
      <div className="turn-banner mine" style={{justifyContent:'space-between'}}>
        <span><Sym n="psychology" fill/> Grimoire</span>
        <span style={{fontSize:12}}>Mind {mind.cur}/{mind.max}</span>
      </div>
      {Object.keys(byEl).map(el=>(
        <div key={el}>
          <div className="sect-title"><span style={{width:9,height:9,borderRadius:'50%',background:`var(--elem-${el})`}}/> {EL_LABEL[el]}</div>
          <div className="choices" style={{marginBottom:16}}>
            {byEl[el].map(sp=>
              <Choice key={sp.id} icon={sp.icon} title={sp.name} meta={sp.desc} elClass={EL_CLASS[el]}
                disabled={mind.cur<1} onClick={()=>beginSpell(sp)} />)}
          </div>
        </div>
      ))}
    </div>
  );
}

function SacTab({hero}) {
  return (
    <div>
      <div className="sect-title"><Sym n="checkroom" s={16}/> Équipement</div>
      <div className="slots">
        <div className="slot"><span className="ic"><Sym n="swords"/></span><div><div className="sn">Arme</div><div className="iv">{hero.gear.arme}</div></div></div>
        <div className="slot"><span className="ic"><Sym n="shield"/></span><div><div className="sn">Armure</div><div className="iv">{hero.gear.armure}</div></div></div>
        <div className="slot"><span className="ic"><Sym n="backpack"/></span><div><div className="sn">Sac</div><div className="iv">{hero.gear.sac}</div></div></div>
        <div className="slot"><span className="ic"><Sym n="science"/></span><div><div className="sn">Consommables</div><div className="iv">{hero.gear.conso}</div></div></div>
      </div>

      <div className="sect-title"><Sym n="inventory_2" s={16}/> Sac à dos</div>
      {BACKPACK.map((it,i)=>(
        <div key={i} className="item">
          <span className="ic"><Sym n={it.icon}/></span>
          <div><div className="nm">{it.name}</div><div className={'rar rar-'+it.rar}>{RAR_LABEL[it.rar]}</div></div>
          <span className="qty" style={{marginLeft:'auto',fontWeight:700,color:'var(--ink-300)'}}>×{it.qty}</span>
        </div>
      ))}

      {hero.isSmith && <>
        <div className="sect-title" style={{marginTop:18}}><Sym n="hardware" s={16}/> Forge du Nain</div>
        <p style={{fontSize:12.5,color:'var(--ink-500)',margin:'0 0 12px'}}>Choisis une amélioration du catalogue.</p>
        <div className="choices">
          {FORGE_CAT.map(f=>
            <Choice key={f.id} icon="build" title={f.name} meta={f.desc + ' · ' + f.price + ' or'} />)}
        </div>
      </>}
    </div>
  );
}

function MarketTab({basket, toggleBasket, projected, gold, hero}) {
  return (
    <div>
      <div className="turn-banner mine" style={{justifyContent:'space-between'}}>
        <span className="phase-pill"><Sym n="storefront" s={16} fill/> Phase de marché</span>
        <span style={{display:'flex',alignItems:'center',gap:5,color:'var(--gold)'}}><Sym n="paid" s={16}/>{gold} or</span>
      </div>
      <div className="sect-title"><Sym n="sell" s={16}/> Échoppe</div>
      {SHOP.map(it=>{
        const inB = basket.includes(it.id);
        return (
          <div key={it.id} className="item">
            <span className="ic" style={it.rar==='unique'?{color:'var(--rar-unique)'}:{}}><Sym n={it.icon} fill={it.rar==='unique'}/></span>
            <div><div className="nm">{it.name}</div><div className={'rar rar-'+it.rar}>{RAR_LABEL[it.rar]}</div></div>
            <span className="price"><Sym n="paid" s={15}/>{it.price}</span>
            <button className={'btn btn-sm '+(inB?'btn-torch':'btn-ghost')} style={{marginLeft:10}} onClick={()=>toggleBasket(it.id)}>
              <Sym n={inB?'check':'add'} s={16}/>
            </button>
          </div>
        );
      })}

      <div className="basket-foot">
        <div className="row"><span><span className="tag-name">{hero.name.split(' ')[0]}</span> · panier ({basket.length})</span><span>{projected} or</span></div>
        <div className="row"><span>Marchandage du Nain</span><span style={{color:'var(--ok)'}}>−10%</span></div>
        <div className="row total"><span>Total projeté</span><span>{Math.round(projected*0.9)} or</span></div>
        <button className="btn btn-torch btn-block" style={{marginTop:12}} disabled={!basket.length}>
          <Sym n="shopping_cart_checkout"/> Confirmer l'achat
        </button>
      </div>
    </div>
  );
}

/* ============================================================== overlays */
function Die({face, rolling, reveal}) {
  const cls = face==='skull'?'skull':face==='shield'?'shield':'blank';
  const ic = face==='skull'?'skull':face==='shield'?'shield':'remove';
  return <div className={'die '+cls+(rolling?' rolling':'')+(reveal?' reveal':'')}><Sym n={rolling?'casino':ic} fill={!rolling}/></div>;
}

function FlowSheet({flow, hero, chooseTarget, confirmResolve, close}) {
  const isSpell = flow.kind==='spell';
  const allies = [
    {id:'bar',name:'Gorrim (Barbare)',icon:'swords'},
    {id:'elf',name:'Sylanwë (Elfe)',icon:'park'},
  ];
  return (
    <div className="overlay" onClick={e=>{ if(e.target.classList.contains('overlay')) close(); }}>
      <div className="sheet">
        <div className="grip"></div>

        {flow.step==='target' && <>
          <h3>{isSpell ? flow.spell.name : 'Attaquer'}</h3>
          <p className="sh-sub">{isSpell ? flow.spell.desc + ' — choisis une cible' : `${hero.atk} dés de crâne — choisis un ennemi à portée`}</p>
          <div className="choices">
            {(isSpell && flow.spell.target==='ally' ? allies : FOES).map(t=>
              <Choice key={t.id} icon={t.icon} title={t.name} meta={t.dist || 'allié'} onClick={()=>chooseTarget(t)} />)}
          </div>
        </>}

        {flow.step==='rolling' && <>
          <h3>Résolution…</h3>
          <p className="sh-sub">Les dés roulent</p>
          <div className="dice-arena">
            <div className="dice-grp"><div className="gl">Attaque</div>
              <div className="dice-row">{flow.dice.atk.map((_,i)=><Die key={i} rolling/>)}</div></div>
            {flow.dice.def.length>0 && <div className="dice-grp"><div className="gl">Défense {flow.target?.name}</div>
              <div className="dice-row">{flow.dice.def.map((_,i)=><Die key={i} rolling/>)}</div></div>}
          </div>
        </>}

        {flow.step==='result' && <ResultBlock {...{flow, isSpell, confirmResolve}} />}
      </div>
    </div>
  );
}

function ResultBlock({flow, isSpell, confirmResolve}) {
  if (flow.heal) return (
    <>
      <h3>Source de vie</h3>
      <div className="dice-arena">
        <div style={{textAlign:'center'}}><Sym n="water_drop" fill s={56} style={{color:'var(--elem-water)'}}/></div>
        <div className="result-line"><div className="big">+2 Body</div><div className="sub">{flow.target?.name} retrouve des forces · coûte 1 Mind</div></div>
      </div>
      <button className="btn btn-torch btn-block" style={{marginTop:18}} onClick={confirmResolve}>Continuer</button>
    </>
  );
  const sk = flow.dice.atk.filter(d=>d==='skull').length;
  const sh = flow.dice.def.filter(d=>d==='shield').length;
  const dmg = Math.max(0, sk-sh);
  return (
    <>
      <h3>{isSpell ? flow.spell.name : 'Résultat'}</h3>
      <div className="dice-arena">
        <div className="dice-row" style={{alignItems:'center'}}>
          <div className="dice-grp"><div className="gl">Crânes</div>
            <div className="dice-row">{flow.dice.atk.map((d,i)=><Die key={i} face={d} reveal/>)}</div></div>
          {flow.dice.def.length>0 && <><span className="vs">vs</span>
          <div className="dice-grp"><div className="gl">Boucliers</div>
            <div className="dice-row">{flow.dice.def.map((d,i)=><Die key={i} face={d} reveal/>)}</div></div></>}
        </div>
        <div className="result-line">
          {dmg>0 ? <div className="big">Touché · <span className="dmg">{dmg} blessure{dmg>1?'s':''}</span></div>
                 : <div className="big">Coup paré</div>}
          <div className="sub">{sk} crâne{sk>1?'s':''} {flow.dice.def.length?`− ${sh} bouclier${sh>1?'s':''}`:''}{isSpell?' · coûte 1 Mind':''}</div>
        </div>
      </div>
      <button className="btn btn-torch btn-block" style={{marginTop:18}} onClick={confirmResolve}>Continuer</button>
    </>
  );
}

function VoteSheet({vote, castVote, close}) {
  const total = vote.opts.reduce((s,o)=>s+o.c,0) || 1;
  return (
    <div className="overlay">
      <div className="sheet">
        <div className="grip"></div>
        <h3><Sym n="how_to_vote" s={20} style={{color:'var(--torch)',marginRight:6}}/>Vote du groupe</h3>
        <p className="sh-sub">{vote.q}</p>
        {vote.opts.map(o=>(
          <div key={o.k} className={'vote-opt'+(vote.mine==null?' choose':'')} onClick={()=>vote.mine==null && castVote(o.k)}>
            <div className="vote-bar"><div className="fillb" style={{width:(o.c/total*100)+'%'}}></div>
              <span className="vtxt">{o.l}{vote.mine===o.k?' · toi':''}</span></div>
            <span className="ct">{o.c}</span>
          </div>
        ))}
        {vote.missing>0
          ? <div className="waiting"><Sym n="hourglass_top" s={16}/> En attente de {vote.missing} joueur{vote.missing>1?'s':''}…</div>
          : <button className="btn btn-torch btn-block" style={{marginTop:10}} onClick={close}>
              <Sym n="check"/> Décision prise — Recharger</button>}
        {vote.mine==null && <p style={{fontSize:11.5,color:'var(--ink-700)',textAlign:'center',marginTop:10}}>Touche une option pour voter</p>}
      </div>
    </div>
  );
}

ReactDOM.createRoot(document.getElementById('root')).render(<App/>);
