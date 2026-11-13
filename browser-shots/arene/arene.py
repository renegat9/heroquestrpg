#!/usr/bin/env python3
"""Client d'un JOUEUR d'arène sur les VRAIES routes (mêmes endpoints que le front Vue).

Une session par slot : `session-<slot>.json` (cookies + code du combat), créée par `preparer.py`.

  arene.py <slot> vue                      ← LA commande à relancer : salon, combat, menu déplié, fil
  arene.py <slot> moi | menu | etat | palmares

SALON COOP
  arene.py <slot> allie <mercenaire_id|aucun>
  arene.py <slot> defender <id,id,...>     ← compose le Defender d'un coup (PUT)
  arene.py <slot> defender-ajouter <id>    ← ajoute UNE figure
  arene.py <slot> defender-retirer <rang>  ← retire la figure de ce rang (0 = première)
  arene.py <slot> pret [non]
  arene.py <slot> lancer | rejouer | quitter

COMBAT COOP (ta manette)
  arene.py <slot> choix <option_id> ['<json parametres>']
      ex. choix se_deplacer '{"x":7,"y":3}'   ·   choix attaquer '{"cible_id":1117}'
          choix lancer_sort '{"cle":"sort:12","cible_id":7,"cible_type":"monstre"}'
          choix trophee_elan '{"cle":"crane_blanc"}'
  arene.py <slot> reaction oui|non [cle_soin]

MONSTER VS MONSTER
  arene.py <slot> camp a|b · puissance <n> · tirage · selection <monstre_id> · lancer · rejouer
  arene.py <slot> figures
  arene.py <slot> geste <instance_id> deplacer <x> <y>
  arene.py <slot> geste <instance_id> attaquer <cible_id>
  arene.py <slot> geste <instance_id> terminer
"""
import json
import os
import sys
import urllib.parse

import requests

BASE = 'http://localhost'
D = os.path.dirname(os.path.abspath(__file__))


# ---------------------------------------------------------------- session ----
class Session:
    def __init__(self, slot):
        self.slot = slot
        self.fichier = os.path.join(D, f'session-{slot}.json')
        data = json.load(open(self.fichier))
        self.code = data.get('code')
        self.s = requests.Session()
        for c in data.get('cookies', []):
            self.s.cookies.set(c['name'], c['value'], domain='localhost.local', path='/')

    def sauver(self):
        data = json.load(open(self.fichier))
        dd = {c.name: c.value for c in self.s.cookies}
        data['cookies'] = [{'name': k, 'value': v} for k, v in dd.items()]
        json.dump(data, open(self.fichier, 'w'))

    def r(self, m, p, body=None):
        xs = [c.value for c in self.s.cookies if c.name == 'XSRF-TOKEN']
        h = {'Accept': 'application/json', 'X-XSRF-TOKEN': urllib.parse.unquote(xs[-1]) if xs else ''}
        res = self.s.request(m, BASE + '/api' + p, json=body, headers=h)
        self.sauver()
        try:
            d = res.json()
        except Exception:
            d = res.text
        return res.status_code, d


def sortie(statut, d):
    """Affiche une réponse d'écriture : le statut, puis l'essentiel."""
    if statut >= 400:
        msg = d.get('message') if isinstance(d, dict) else str(d)[:300]
        print(f'REFUSÉ ({statut}) : {msg}')
        if isinstance(d, dict) and d.get('errors'):
            print('  ', json.dumps(d['errors'], ensure_ascii=False)[:600])
        sys.exit(1)
    print(f'OK ({statut})')
    if isinstance(d, dict):
        r = d.get('resultat')
        if r is not None:
            print('resultat :', json.dumps(r, ensure_ascii=False)[:1800])
        elif 'groupe' not in d:
            print(json.dumps(d, ensure_ascii=False)[:1200])


# ------------------------------------------------------------------- vue -----
def dist(a, b):
    return max(abs(a['x'] - b['x']), abs(a['y'] - b['y']))


def vue(S):
    st, moi = S.r('GET', '/moi')
    if st >= 400:
        print('Session invalide :', st, moi); return
    j = moi['joueur']
    st, e = S.r('GET', f'/groupes/{S.code}/etat')
    if st >= 400:
        print(f'Combat {S.code} introuvable ou fermé ({st}).'); return
    g = e.get('groupe') or {}
    ar = g.get('arene') or {}
    q = e.get('quete') or {}
    en_combat = g.get('phase') == 'quete' and q.get('etat') == 'en_cours'
    print(f"== {j['pseudo']} · combat {S.code} · variante {ar.get('variante')} · "
          f"{'COMBAT EN COURS' if en_combat else 'SALON'} ==")
    if ar.get('resultat'):
        print('RÉSULTAT DU DERNIER COMBAT :', json.dumps(ar['resultat'], ensure_ascii=False))

    if ar.get('variante') == 'monstres':
        vue_mvm(S, e, ar, j, en_combat)
    else:
        vue_coop(S, e, ar, j, en_combat)

    fil = e.get('journal_combat') or []
    if fil:
        print('\n-- FIL (derniers) --')
        for l in fil[-8:]:
            print('  ·', l.get('texte', l) if isinstance(l, dict) else l)


def vue_coop(S, e, ar, j, en_combat):
    mien = next((c for c in ar.get('combattants', []) if c.get('joueur_id') == j['id']), None)
    if not en_combat:
        print('\n-- SALON COOP --')
        print(f"Puissance Challenger {ar.get('puissance_challenger')} · Defender {ar.get('puissance_defender')}")
        for c in ar.get('combattants', []):
            print(f"  {'[TOI] ' if c is mien else ''}{c['nom']} ({c['classe']}, joueur {c['joueur']}) "
                  f"puissance {c['puissance']} · prêt={c['pret']} · allié={c['allie']['nom'] + ' (' + str(c['allie']['puissance']) + ')' if c.get('allie') else 'aucun'}")
        print('Defender (rang: nom) :', ', '.join(f"{i}: {m.get('nom_base')}" for i, m in enumerate(ar.get('defender') or [])) or 'vide')
        print('Roster du Defender (id nom puissance, * = abordable) :')
        print('  ' + ' · '.join(f"{m['id']} {m['nom_base']} {m['puissance']}{'*' if m['abordable'] else ''}" for m in ar.get('monstres', [])))
        print('Alliés possibles (id nom puissance) :')
        print('  ' + ' · '.join(f"{a['id']} {a['nom']} {a['puissance']}" for a in ar.get('allies', [])))
        print(f"Lancement possible : {ar.get('lancement_possible')}  {ar.get('lancement_motif') or ''}")
        return

    ents = e.get('entites', [])
    print('\n-- INITIATIVE (a_la_main = peut agir maintenant) --')
    print('  ' + ' | '.join(f"{i['nom']}{' ✓joué' if i.get('a_joue') else ''}{' ★MAIN' if i.get('a_la_main') else ''}{' ✝tombé' if i.get('tombe') else ''}" for i in e.get('initiative', [])))
    st, m = S.r('GET', f'/groupes/{S.code}/menu')
    m = m if isinstance(m, dict) else {}
    pid = m.get('personnage_id') or (mien or {}).get('personnage_id')
    moi_e = next((x for x in ents if x.get('type') == 'heros' and x.get('id') == pid), None)
    print('\n-- TOI --')
    if moi_e:
        print(f"  {moi_e['nom']} en ({moi_e['x']},{moi_e['y']}) · BODY {moi_e.get('pv_body')}/{moi_e.get('pv_body_max')} · "
              f"MIND {moi_e.get('pv_mind')}/{moi_e.get('pv_mind_max')} · tombé={moi_e.get('tombe')} · conditions={[c.get('nom') for c in moi_e.get('conditions') or []]}")
        if moi_e.get('reaction_en_attente'):
            ra = moi_e['reaction_en_attente']
            print('  ⚠ RÉACTION EN ATTENTE (réponds avec `reaction oui|non [cle_soin]`) :')
            for k in ('action', 'nom', 'capacite', 'sort', 'trophee', 'resume', 'description', 'expire_a'):
                if ra.get(k) is not None:
                    print(f"     {k} : {ra[k]}")
            for so in ra.get('soins') or []:
                print(f"     soin possible : cle={so.get('cle')} « {so.get('nom')} » ({so.get('soin')})")
            print('     (brut)', json.dumps(ra, ensure_ascii=False)[:700])
    print('\n-- FIGURES --')
    for x in ents:
        if x.get('type') == 'monstre' and (x.get('pv_body') or 0) <= 0:
            continue
        d = f" · dist {dist(x, moi_e)}" if moi_e and x is not moi_e else ''
        print(f"  {x.get('type'):7} id={x.get('id')} {x.get('nom')} ({x.get('x')},{x.get('y')}) PV {x.get('pv_body')}{d}")
    carte = e.get('carte') or {}
    if carte.get('trophees'):
        print('Tuiles trophées encore face cachée (cases) :', ' '.join(f"({t['x']},{t['y']})" for t in carte['trophees']))
    meubles = carte.get('mobilier') or []
    if meubles:
        print('Mobilier (fouille au contact OU en diagonale pour un coffre) :',
              ' · '.join(f"{o.get('nom')} ({o.get('x')},{o.get('y')})" for o in meubles))
    t = (e.get('quete') or {}).get('trophees')
    if t:
        print('Réserve trophées Challenger :', [x.get('libelle') for x in t.get('challenger', [])], '· Defender :', [x.get('libelle') for x in t.get('defender', [])])
    print('\n-- TON MENU --')
    menu = m.get('menu')
    if not menu:
        print('  (aucun menu : ce n\'est pas à toi — attends `sleep 20` puis relance `vue`)')
        return
    if m.get('allie_id'):
        print(f"  ⚠ C'est le tour de TON ALLIÉ (allie_id {m['allie_id']}), pas de ton héros.")
    print('  situation :', menu.get('situation'))
    for o in menu.get('options', []):
        p = o.get('parametres') or {}
        extra = []
        if p.get('destinations'):
            extra.append('destinations=' + ' '.join(
                f"{d['x']},{d['y']}" if 'x' in d else f"[cle={d.get('cle')} « {d.get('nom')} »]" for d in p['destinations']))
        if p.get('cibles'):
            extra.append('cibles=' + ', '.join(f"{c.get('id')}:{c.get('nom')}" + (f"({c.get('type')})" if c.get('type') else '') for c in p['cibles']))
        for k in ('sorts', 'objets', 'tuiles', 'pieces', 'soins', 'parchemins'):
            for it in p.get(k) or []:
                if not isinstance(it, dict):
                    extra.append(f"{k}: {it}"); continue
                cib = ', '.join(f"{c.get('id')}:{c.get('nom')}({c.get('type')})" for c in it.get('cibles') or [])
                extra.append(f"{k[:-1]} cle={it.get('cle')} « {it.get('nom')} »"
                             + ('' if it.get('disponible', True) else ' [INDISPONIBLE]')
                             + (f" cibles={cib}" if cib else '')
                             + (f" — {it['description'][:140]}" if it.get('description') else ''))
        print(f"  · {o['id']} — {o.get('libelle')} [type {o.get('type')}, créneau {o.get('creneau')}]")
        for x in extra:
            print('      ', x)


def vue_mvm(S, e, ar, j, en_combat):
    mon = next((p for p in ar.get('joueurs', []) if p.get('joueur_id') == j['id']), None)
    camp = (mon or {}).get('camp')
    print(f"Ton camp : {camp} · puissance convenue {ar.get('puissance_convenue')} · tirage fait={ar.get('tirage_fait')}")
    for c, v in (ar.get('camps') or {}).items():
        print(f"  camp {c} ({v.get('role')}) : {[m['nom_base'] for m in v.get('monstres', [])]} · puissance {v.get('puissance')} · reste {v.get('restant')}")
    print('  joueurs :', ', '.join(f"{p['pseudo']}→{p['camp']}" for p in ar.get('joueurs', [])))
    if not en_combat:
        print(f"Tour de sélection : camp {ar.get('tour_selection')} · sélection terminée={ar.get('selection_terminee')}")
        print('Roster (id nom puissance, * = abordable pour le camp qui choisit) :')
        print('  ' + ' · '.join(f"{m['id']} {m['nom_base']} {m['puissance']}{'*' if m['abordable'] else ''}" for m in ar.get('monstres', [])))
        print(f"Lancement possible : {ar.get('lancement_possible')}  {ar.get('lancement_motif') or ''}")
        return
    print(f"\nLA MAIN EST AU CAMP : {ar.get('main')}")
    for x in e.get('entites', []):
        if (x.get('pv_body') or 0) > 0:
            print(f"  camp {x.get('camp_arene')} id={x.get('id')} {x.get('nom')} ({x.get('x')},{x.get('y')}) PV {x.get('pv_body')}")
    st, f = S.r('GET', f'/arene/{S.code}/figures')
    figs = (f or {}).get('figures', []) if isinstance(f, dict) else []
    print('\n-- TES FIGURES ACTIVABLES (vide = pas ton tour) --')
    for x in figs:
        print(f"  instance {x['instance_id']} {x['nom']} ({x['x']},{x['y']}) PV {x['pv_body']} · entamée={x['entamee']} · "
              f"peut_deplacer={x['peut_deplacer']} · peut_attaquer={x['peut_attaquer']}")
        if x.get('destinations'):
            print('      destinations=' + ' '.join(f"{d['x']},{d['y']}" for d in x['destinations']))
        for c in x.get('cibles', []):
            print(f"      cible {c['id']} {c['nom']} PV {c['pv_body']} · {'TIR à distance' if c.get('distance') else 'contact'} · {c.get('des_attaque')} dé(s)")


# ------------------------------------------------------------------ main -----
def main():
    if len(sys.argv) < 3:
        print(__doc__); sys.exit(2)
    S = Session(sys.argv[1])
    v, a = sys.argv[2], sys.argv[3:]
    c = S.code
    if v == 'vue':
        vue(S)
    elif v == 'moi':
        print(json.dumps(S.r('GET', '/moi')[1], ensure_ascii=False, indent=1)[:20000])
    elif v == 'menu':
        print(json.dumps(S.r('GET', f'/groupes/{c}/menu')[1], ensure_ascii=False, indent=1)[:6000])
    elif v == 'etat':
        print(json.dumps(S.r('GET', f'/groupes/{c}/etat')[1], ensure_ascii=False)[:8000])
    elif v == 'palmares':
        print(json.dumps(S.r('GET', '/arene/palmares')[1], ensure_ascii=False, indent=1)[:3000])
    elif v == 'allie':
        sortie(*S.r('PUT', f'/arene/{c}/allie', {'mercenaire_id': None if a[0] == 'aucun' else int(a[0])}))
    elif v == 'defender':
        sortie(*S.r('PUT', f'/arene/{c}/defender', {'monstre_ids': [int(x) for x in a[0].split(',')]}))
    elif v == 'defender-ajouter':
        sortie(*S.r('POST', f'/arene/{c}/defender', {'monstre_id': int(a[0])}))
    elif v == 'defender-retirer':
        sortie(*S.r('DELETE', f'/arene/{c}/defender/{int(a[0])}'))
    elif v == 'pret':
        sortie(*S.r('POST', f'/arene/{c}/pret', {'pret': not (a and a[0] == 'non')}))
    elif v in ('lancer', 'rejouer', 'tirage'):
        sortie(*S.r('POST', f'/arene/{c}/{v}'))
    elif v == 'quitter':
        sortie(*S.r('DELETE', f'/arene/{c}'))
    elif v == 'choix':
        b = {'option_id': a[0]}
        if len(a) > 1:
            b['parametres'] = json.loads(a[1])
        sortie(*S.r('POST', f'/groupes/{c}/choix', b))
    elif v == 'reaction':
        st, m = S.r('GET', f'/groupes/{c}/etat')
        moi_id = S.r('GET', '/moi')[1]['joueur']['id']
        pid = next((x['personnage_id'] for x in (m.get('groupe') or {}).get('arene', {}).get('combattants', []) if x.get('joueur_id') == moi_id), None)
        b = {'personnage_id': pid, 'accepte': a[0] in ('oui', 'true', '1')}
        if len(a) > 1:
            b['soin'] = a[1]
        sortie(*S.r('POST', f'/groupes/{c}/reaction', b))
    elif v == 'camp':
        sortie(*S.r('POST', f'/arene/{c}/camp', {'camp': a[0]}))
    elif v == 'puissance':
        sortie(*S.r('PUT', f'/arene/{c}/puissance', {'puissance': int(a[0])}))
    elif v == 'selection':
        sortie(*S.r('POST', f'/arene/{c}/selection', {'monstre_id': int(a[0])}))
    elif v == 'figures':
        print(json.dumps(S.r('GET', f'/arene/{c}/figures')[1], ensure_ascii=False, indent=1)[:5000])
    elif v == 'geste':
        iid, g = int(a[0]), a[1]
        b = {'instance_id': iid, 'geste': g}
        if g == 'deplacer':
            b['parametres'] = {'x': int(a[2]), 'y': int(a[3])}
        elif g == 'attaquer':
            b['parametres'] = {'cible_id': int(a[2])}
        sortie(*S.r('POST', f'/arene/{c}/geste', b))
    else:
        print('verbe inconnu :', v); print(__doc__); sys.exit(2)


if __name__ == '__main__':
    main()
