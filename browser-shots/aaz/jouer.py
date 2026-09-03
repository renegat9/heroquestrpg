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
    'attaquer', 'lancer', 'repousser_',
    'lancer_sort', 'lire_parchemin',
    'ouvrir_porte', 'actionner_levier',
    'fouiller_tresor', 'fouille_mobilier', 'epreuve_', 'fracasser', 'utiliser_objet',
    'fouiller',
    'se_deplacer',
    'quitter_donjon', 'attendre',
]

# ⚠ Jamais tenté par le pilote : la retraite ouvre un vote qui peut RECOMMENCER
# la quête ou ARRÊTER la campagne. On ne la déclenche pas au hasard — mais on
# vérifie qu'elle DISPARAÎT du menu quand un vote est déjà ouvert (c'est le
# correctif du 2026-08-30).
JAMAIS = ('battre_en_retraite',)


def rang(oid):
    for i, p in enumerate(PRIORITE):
        if oid.startswith(p):
            return i
    return len(PRIORITE)


# ⚠ Depuis le 2026-09-01, une option peut porter une LISTE de sous-choix au
# lieu d'être elle-même le sort/parchemin/objet. Le pilote doit donc descendre
# d'un niveau : choisir une entrée, puis sa cible.
LISTES = {'lancer_sort': 'sorts', 'lire_parchemin': 'parchemins',
          'utiliser_objet': 'objets', 'se_concentrer': 'sorts',
          'sacrifier_pour_sort': 'sorts'}


def entrees_de(opt):
    """Entrées jouables d'une option à liste ([] si ce n'en est pas une)."""
    cle = LISTES.get(opt.get('id', ''))
    if cle is None:
        return []
    return [e for e in (opt.get('parametres') or {}).get(cle) or []
            if e.get('disponible', True)]


def cible_monstre(source):
    for c in (source or {}).get('cibles') or []:
        if c.get('type') == 'monstre':
            return c
    return None


def jouable(opt):
    oid = opt.get('id', '')
    if oid.startswith(JAMAIS):
        return False

    # Option à LISTE : jouable s'il existe une entrée sans cible (elle part
    # telle quelle) ou une entrée visant un monstre. Le tir ami est délibéré
    # (doc 02 §5) — c'est au pilote de ne pas se brûler tout seul.
    if oid in LISTES:
        return any(not e.get('cibles') or cible_monstre(e) for e in entrees_de(opt))

    if oid.startswith(('attaquer', 'lancer')) and (opt.get('parametres') or {}).get('cibles') is not None:
        return cible_monstre(opt.get('parametres')) is not None

    return True


def parametres_pour(opt, cap_suivant):
    """Les SIX seules clés que `ChoixController` valide : x, y, cible_id,
    cible_type, sort_id, cle (cette dernière arrivée avec les sous-choix). Tout le reste (index d'épreuve, de meuble, de levier)
    voyage dans l'option elle-même, que le serveur relit de son CACHE — donc un
    422 après ça est une NON-CONFORMITÉ du menu, pas une erreur du client."""
    p = opt.get('parametres') or {}
    if opt.get('type') == 'deplacement':
        return {'x': cap_suivant[0], 'y': cap_suivant[1]} if cap_suivant else None

    # Option à liste : on choisit une entrée, puis sa cible. La `cle` est ce
    # que le serveur revalide contre la liste blanche.
    if opt.get('id') in LISTES:
        entrees = entrees_de(opt)
        entree = next((e for e in entrees if e.get('cibles') and cible_monstre(e)), None) \
            or next((e for e in entrees if not e.get('cibles')), None)
        if entree is None:
            return None
        params = {'cle': entree['cle']}
        c = cible_monstre(entree)
        if c:
            params |= {'cible_id': c['id'], 'cible_type': c['type']}
        return params

    if p.get('cibles'):
        c = cible_monstre(p) or p['cibles'][0]
        return {'cible_id': c['id'], 'cible_type': c['type']}
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
essayees = set()
offertes = set()
familles = {}
refuses_types = {}
sans_destination = [0]
course = [0]
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
        # ⚠ LE contrôle du correctif du 2026-08-30, et il doit se faire ICI :
        # une fois les bulletins déposés le vote disparaît, et la boucle ne
        # relit jamais de menu tant qu'il est ouvert. Placé ailleurs, le test
        # ne s'exécutait tout simplement pas.
        for slot in slots:
            c, m = req(slot, 'GET', f'/groupes/{CODE}/menu')
            ids = [o['id'] for o in ((m.get('menu') or m or {}).get('options') or [])]
            interdites = [i for i in ids if i in ('quitter_donjon', 'battre_en_retraite')]
            if interdites:
                defaut(f'menu du slot {slot} : {interdites} offertes alors qu\'un vote est OUVERT')
            else:
                note(f'  ✓ vote ouvert : aucune option de vote dans le menu du slot {slot}')

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

    offertes.update(o.get('type', '?') for o in options)
    for o in options:
        familles[o.get('id', '').split('_')[0]] = o.get('type', '?')

    options = [o for o in options if jouable(o) and o.get('id') not in refuses]
    # Couverture d'abord : une option jamais essayée passe devant.
    options.sort(key=lambda o: (o.get('id') in essayees, rang(o.get('id', ''))))
    if not options:
        req(slot, 'POST', f'/groupes/{CODE}/choix', {'option_id': 'attendre'})
        refuses.clear()
        continue

    opt = options[0]
    essayees.add(opt['id'])
    params = parametres_pour(opt, cap(etat, courant['id']))

    code, res = req(slot, 'POST', f'/groupes/{CODE}/choix',
                    {'option_id': opt['id'], **({'parametres': params} if params else {})})
    tours += 1
    etiquette = f"slot {slot} · {opt.get('libelle', opt['id'])[:52]}"
    if code >= 400:
        msg = json.dumps(res, ensure_ascii=False)
        # ⚠ « Choisis d'abord une case » n'est PAS une non-conformité : le menu
        # offre légitimement « Se déplacer » même quand rien n'est accessible —
        # la manette ouvre alors sa feuille avec « tu es bloqué ». C'est le
        # pilote qui n'a pas de destination à envoyer, pas le moteur qui ment.
        # ⚠ « Destination inaccessible » : avant d'accuser le menu, on RELIT
        # l'état. Le pilote calcule sa destination sur un instantané, puis la
        # phase des monstres se joue dans la requête d'un AUTRE joueur — une
        # créature peut s'être posée sur la case entre-temps. Si la case est
        # occupée maintenant, c'est une course du pilote, pas un menu qui ment.
        if 'Destination inaccessible' in msg and params:
            _, frais = req(slot, 'GET', f'/groupes/{CODE}/etat')
            prise = any(e.get('x') == params.get('x') and e.get('y') == params.get('y')
                        for e in frais.get('entites') or [])
            if prise:
                note(f"  (course du pilote : ({params['x']},{params['y']}) occupée depuis) {etiquette}")
                course[0] += 1
                refuses.add(opt['id'])
                time.sleep(0.35)
                continue

        if "case de destination" in msg:
            note(f"  (pilote sans destination) {etiquette}")
            sans_destination[0] += 1
        else:
            defaut(f"NON CONFORME · {etiquette} → HTTP {code} : {msg[:140]}")
            refuses_types[opt.get('type', '?')] = refuses_types.get(opt.get('type', '?'), 0) + 1
        # ⚠ On BLACKLISTE l'option pour ce tour au lieu de terminer : sinon un
        # seul refus masquerait tout le reste du menu, et le test ne verrait
        # jamais les options suivantes.
        refuses.add(opt['id'])
    else:
        note(f"  {etiquette}")
        vus.add(opt['id'].split('_')[0])
        refuses.clear()

    time.sleep(0.35)

note('\n=== CONFORMITÉ DU MENU ===')
note(f'  {tours} actions jouées · {len(essayees)} options distinctes exercées')
note(f'  types d\'option rencontrés : {sorted(offertes)}')
note(f'  familles : {json.dumps(familles, ensure_ascii=False, sort_keys=True)}')
note(f'  refus par type : {refuses_types or "aucun"}')
note(f'  déplacements sans destination (limite du pilote) : {sans_destination[0]}')
note(f'  destinations prises entre-temps (course du pilote) : {course[0]}')
note(f'DÉFAUTS={len(defauts)}')
for d in defauts:
    note('  ' + d)
