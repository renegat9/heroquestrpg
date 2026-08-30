#!/usr/bin/env python3
"""Joue une quête complète sur les VRAIES routes, et journalise tout écart.

Deux héros, deux sessions (les pots de cookies du harnais). À chaque tour :
lire l'état, trouver qui a la main, lire son menu, choisir, poster. Tout code
HTTP inattendu, toute option refusée et tout blocage sont consignés — c'est le
but du test, pas un effet de bord.
"""
import json, re, subprocess, sys, time
from pathlib import Path

D = Path(__file__).resolve().parent.parent / 'campagne'
BASE = 'http://localhost/api'
CODE = (D / 'groupe.txt').read_text().strip()
defauts, journal = [], []


def xsrf(slot):
    m = re.findall(r'XSRF-TOKEN\s+(\S+)', (D / f'jar-{slot}.txt').read_text())
    return m[-1].replace('%3D', '=') if m else ''


def req(slot, method, path, body=None):
    jar = str(D / f'jar-{slot}.txt')
    cmd = ['curl', '-s', '-w', '\n%{http_code}', '-b', jar, '-c', jar, '-X', method,
           BASE + path, '-H', 'Accept: application/json', '-H', 'Content-Type: application/json',
           '-H', f'X-XSRF-TOKEN: {xsrf(slot)}']
    if body is not None:
        cmd += ['-d', json.dumps(body)]
    out = subprocess.run(cmd, capture_output=True, text=True).stdout
    corps, _, code = out.rpartition('\n')
    try:
        return int(code), json.loads(corps or '{}')
    except Exception:
        return int(code or 0), {'brut': corps[:300]}


def note(msg):
    journal.append(msg)
    print(msg, flush=True)


def defaut(msg):
    defauts.append(msg)
    note('  ✗ DÉFAUT : ' + msg)


PRIORITE = [
    'attaquer', 'lancer', 'repousser_',                   # combattre au contact
    'sort_',                                              # magie (si une cible MONSTRE existe)
    'ouvrir_porte', 'actionner_levier',                   # ouvrir le chemin
    'fouiller_tresor', 'fouille_mobilier', 'epreuve_',    # ramasser
    'fouiller',                                           # chercher pièges/portes
    'se_deplacer',                                        # avancer
    'quitter_donjon', 'attendre',
]


def rang(oid):
    for i, p in enumerate(PRIORITE):
        if oid.startswith(p):
            return i
    return len(PRIORITE)


def cible_monstre(opt):
    """Première cible MONSTRE des cibles légales, ou None."""
    for c in (opt.get('parametres') or {}).get('cibles') or []:
        if c.get('type') == 'monstre':
            return c
    return None


def jouable(opt, etat):
    """Écarte ce qui n'a pas de sens : un sort offensif sur soi-même, une
    retraite, un déséquipement. ⚠ Le menu OFFRE bien le sort sur son propre
    lanceur — le tir ami est délibéré (doc 02 §5) — donc c'est au pilote de ne
    pas se brûler tout seul, pas au moteur de l'interdire."""
    oid = opt.get('id', '')
    if oid.startswith(('desequiper', 'equiper', 'battre_en_retraite', 'donner')):
        return False
    if oid.startswith('sort_') and (opt.get('parametres') or {}).get('cibles') is not None:
        return cible_monstre(opt) is not None
    return True


def parametres_pour(opt, etat, cases_libres):
    """Paramètres attendus par le résolveur (contrat §Ciblage en deux temps)."""
    p = opt.get('parametres') or {}
    if opt.get('type') == 'deplacement' and 'cibles' not in p:
        return {'x': cases_libres[0], 'y': cases_libres[1]} if cases_libres else None
    if p.get('cibles'):
        c = cible_monstre(opt) or p['cibles'][0]
        return {'cible_id': c['id'], 'cible_type': c['type']}
    if opt.get('id') == 'se_deplacer':
        return {'x': cases_libres[0], 'y': cases_libres[1]} if cases_libres else None
    return None


def arete(a, b):
    return (a, b) if a <= b else (b, a)


def portes_bloquantes(carte):
    """Arêtes que le héros ne peut pas franchir : toute porte non ouverte."""
    bloquees = set()
    for d in carte.get('portes') or []:
        if d.get('etat') == 'ouverte':
            continue
        a = (d['x'], d['y'])
        b = (d['x'], d['y'] + 1) if d.get('cote') == 's' else (d['x'] + 1, d['y'])
        bloquees.add(arete(a, b))
    return bloquees


def cap(etat, heros_id):
    """Prochaine case vers la porte fermée la plus proche — sinon vers la case
    de sol connue la plus éloignée (explorer). ⚠ Sans cap, deux héros passent
    la quête à osciller entre deux cases : 381 actions sans ouvrir une porte,
    mesuré."""
    carte = etat.get('carte') or {}
    cases = carte.get('cases') or []
    ent = next((e for e in etat.get('entites') or [] if e.get('id') == heros_id and e.get('type') == 'heros'), None)
    if ent is None:
        return None

    occupees = {(e['x'], e['y']) for e in etat.get('entites') or [] if (e['x'], e['y']) != (ent['x'], ent['y'])}
    for m in carte.get('mobilier') or []:
        if m.get('bloque_mouvement') is not False:
            for dx in range(max(1, m.get('l', 1))):
                for dy in range(max(1, m.get('h', 1))):
                    occupees.add((m['x'] + dx, m['y'] + dy))

    bloquees = portes_bloquantes(carte)
    # Cases adjacentes à une porte non ouverte = ce qu'on cherche à atteindre.
    buts = set()
    for d in carte.get('portes') or []:
        if d.get('etat') != 'ouverte':
            buts.add((d['x'], d['y']))
            buts.add((d['x'], d['y'] + 1) if d.get('cote') == 's' else (d['x'] + 1, d['y']))

    depart = (ent['x'], ent['y'])
    vus_, file, prec = {depart}, [depart], {}
    atteint = None
    while file:
        x, y = file.pop(0)
        if (x, y) in buts and (x, y) != depart:
            atteint = (x, y)
            break
        for dx, dy in ((0, 1), (0, -1), (1, 0), (-1, 0)):
            n = (x + dx, y + dy)
            if n in vus_ or arete((x, y), n) in bloquees:
                continue
            if not (0 <= n[1] < len(cases) and 0 <= n[0] < len(cases[n[1]])):
                continue
            if cases[n[1]][n[0]] != 's' or n in occupees:
                continue
            vus_.add(n)
            prec[n] = (x, y)
            file.append(n)

    if atteint is None:
        # Rien à ouvrir dans la zone connue : la case connue la plus loin.
        atteint = max(vus_ - {depart}, key=lambda c: abs(c[0] - depart[0]) + abs(c[1] - depart[1]), default=None)
        if atteint is None:
            return None

    chemin = [atteint]
    while chemin[-1] in prec:
        chemin.append(prec[chemin[-1]])
    chemin.reverse()
    return chemin[1] if len(chemin) > 1 else None


slots = {1: int((D / 'perso-1.txt').read_text()), 2: int((D / 'perso-2.txt').read_text())}
vus = set()
refuses = set()
tours = 0

for boucle in range(400):
    code, etat = req(1, 'GET', f'/groupes/{CODE}/etat')
    if code != 200:
        defaut(f'/etat rend {code}')
        break

    groupe = etat.get('groupe', {})
    if groupe.get('phase') != 'quete':
        note(f"→ phase « {groupe.get('phase')} » : la quête est terminée")
        break

    # ⚠ Un vote ouvert BLOQUE la quête tant que tout le monde n'a pas voté :
    # il n'y a ni délai ni bulletin par défaut, et le proposeur ne vote pas
    # d'office. Un pilote sans ce verbe laisse le groupe dans un donjon vide,
    # à un bulletin près (leçon du harnais, 2026-08-15).
    code, v = req(1, 'GET', f'/groupes/{CODE}/votes')
    vote = (v or {}).get('vote')
    if vote:
        choix = 'oui' if any(o['id'] == 'oui' for o in vote.get('options') or []) else 'continuer'
        for slot in slots:
            c, r = req(slot, 'POST', f'/groupes/{CODE}/votes/bulletin', {'option_id': choix})
            if c >= 400 and 'déjà' not in json.dumps(r, ensure_ascii=False):
                defaut(f'bulletin slot {slot} → HTTP {c} : {json.dumps(r, ensure_ascii=False)[:120]}')
        note(f"  vote « {vote.get('question', '?')[:40]} » → tout le monde vote {choix}")
        time.sleep(1.5)
        continue

    init = etat.get('initiative') or []
    courant = next((o for o in init if not o.get('a_joue') and not o.get('tombe')), None)
    if courant is None:
        time.sleep(1.5)
        continue

    if courant.get('entite') != 'heros':
        time.sleep(1.5)
        continue

    slot = next((s for s, pid in slots.items() if pid == courant['id']), None)
    if slot is None:
        note(f"  (tour d'un héros non piloté : {courant['id']})")
        time.sleep(1.5)
        continue

    code, menu = req(slot, 'GET', f'/groupes/{CODE}/menu')
    if code != 200:
        defaut(f'/menu rend {code} pour le héros {courant["id"]}')
        break

    options = (menu.get('menu') or {}).get('options') or menu.get('options') or []
    if not options:
        time.sleep(1.5)
        continue

    options = [o for o in options if jouable(o, etat) and o.get('id') not in refuses]
    options.sort(key=lambda o: rang(o.get('id', '')))
    if not options:
        req(slot, 'POST', f'/groupes/{CODE}/choix', {'option_id': 'attendre'})
        refuses.clear()
        continue

    opt = options[0]
    params = parametres_pour(opt, etat, cap(etat, courant['id']))

    code, res = req(slot, 'POST', f'/groupes/{CODE}/choix',
                    {'option_id': opt['id'], **({'parametres': params} if params else {})})
    tours += 1
    etiquette = f"slot {slot} · {opt.get('libelle', opt['id'])[:52]}"
    if code >= 400:
        defaut(f"{etiquette} → HTTP {code} : {json.dumps(res, ensure_ascii=False)[:150]}")
        # ⚠ On BLACKLISTE l'option pour ce tour au lieu de terminer : sinon un
        # seul refus masquerait tout le reste du menu, et le test ne verrait
        # jamais les options suivantes.
        refuses.add(opt['id'])
    else:
        note(f"  {etiquette}")
        vus.add(opt['id'].split('_')[0])
        refuses.clear()

    time.sleep(0.35)

note(f'\n--- {tours} actions jouées · familles d\'options vues : {sorted(vus)} ---')
note(f'DÉFAUTS={len(defauts)}')
for d in defauts:
    note('  ' + d)
