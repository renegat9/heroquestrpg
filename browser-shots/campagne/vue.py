#!/usr/bin/env python3
"""Vue de jeu d'UN héros : ce qu'il voit, où il peut aller, ce qu'il peut viser.

Les agents décident ; la géométrie (BFS sur la grille, portes, cases libres)
est faite ici — sinon ils passeraient leur tour à calculer des chemins.
"""
import json, subprocess, sys, os
from collections import deque

S = os.path.dirname(os.path.abspath(__file__))
slot = sys.argv[1]
code = open(f"{S}/groupe.txt").read().strip()
moi_id = int(open(f"{S}/perso-{slot}.txt").read().strip())

etat = json.loads(subprocess.run(
    ["curl", "-s", "-b", f"{S}/jar-{slot}.txt", f"http://localhost/api/groupes/{code}/etat",
     "-H", "Accept: application/json"], capture_output=True, text=True).stdout)

g, carte = etat["groupe"], etat.get("carte") or {}
ent = etat.get("entites", [])
moi = next((e for e in ent if e.get("id") == moi_id and e.get("type") == "heros"), None)

print(f"PHASE {g.get('phase')} | quête: {(etat.get('quete') or {}).get('titre','—')}")
if moi is None:
    print("(héros absent de la quête)"); sys.exit(0)

print(f"MOI {moi['nom']} en ({moi['x']},{moi['y']}) — {moi.get('pv_body')} PV | "
      f"joué={moi.get('a_joue')} déplacé={moi.get('a_deplace')} agi={moi.get('a_agi')} "
      f"tombé={moi.get('tombe')}")
if moi.get("reaction_en_attente"):
    print("⚠ RÉACTION EN ATTENTE :", json.dumps(moi["reaction_en_attente"], ensure_ascii=False)[:300])

for e in ent:
    if e.get("type") == "heros" and e.get("id") != moi_id:
        print(f"  allié {e['nom']} ({e['x']},{e['y']}) {e.get('pv_body')}pv"
              + (" [À TERRE]" if e.get("tombe") else ""))
# ⚠ PAS de filtre `revele` : ce champ N'EXISTE PAS dans le payload. `EtatGroupe`
# n'envoie que les monstres déjà révélés (le brouillard est appliqué côté
# serveur), donc être présent VAUT révélé. Le filtre fantôme a rendu trois
# joueurs aveugles pendant plusieurs tours le 2026-08-13.
mon = [e for e in ent if e.get("type") == "monstre" and e.get("etat") == "actif"]
for e in mon:
    d = abs(e["x"] - moi["x"]) + abs(e["y"] - moi["y"])
    print(f"  MONSTRE {e['nom']} ({e['x']},{e['y']}) {e.get('pv_body')}pv — distance {d}")
if not mon:
    print("  (aucun monstre en vue)")

# --- cases atteignables : BFS orthogonal sur le sol connu, portes closes bloquantes
cases = (carte.get("grille") or {}).get("cases") or carte.get("cases") or []
portes = (carte.get("grille") or {}).get("portes") or carte.get("portes") or []
occupe = {(e["x"], e["y"]) for e in ent if e.get("x") is not None and not e.get("tombe")}

# Le MOBILIER barre le passage (doc 17, `bloque_mouvement`) : sans lui, le BFS
# proposait des cases que le serveur refusait — deux tentatives perdues par
# Borin, une par Krogar.
for m in ((carte.get("grille") or {}).get("mobilier") or carte.get("mobilier") or []):
    if not m.get("bloque_mouvement", True):
        continue
    mx, my = int(m.get("x", -1)), int(m.get("y", -1))
    l, h = int((m.get("emprise") or {}).get("l", 1)), int((m.get("emprise") or {}).get("h", 1))
    for dy in range(h):
        for dx in range(l):
            occupe.add((mx + dx, my + dy))
bloque = set()
for p in portes:
    if p.get("etat") == "ouverte":
        continue
    bloque.add(((p.get("x"), p.get("y")), p.get("cote")))

def sol(x, y):
    try:
        return cases[y][x] in ("s", "p")
    except (IndexError, TypeError):
        return False

def porte_bloque(a, b):
    (x1, y1), (x2, y2) = a, b
    for (px, py), cote in bloque:
        d = {"n": (0, -1), "s": (0, 1), "e": (1, 0), "o": (-1, 0)}.get(cote or "e", (1, 0))
        if (px, py) == (x1, y1) and (x1 + d[0], y1 + d[1]) == (x2, y2): return True
        if (px, py) == (x2, y2) and (x2 + d[0], y2 + d[1]) == (x1, y1): return True
    return False

portee = 0
menu = json.loads(subprocess.run(
    ["curl", "-s", "-b", f"{S}/jar-{slot}.txt", f"http://localhost/api/groupes/{code}/menu",
     "-H", "Accept: application/json"], capture_output=True, text=True).stdout or "{}")
opts = (menu.get("menu") or {}).get("options") or []
for o in opts:
    if o.get("id") == "se_deplacer":
        portee = int((o.get("parametres") or {}).get("portee") or 0)

if portee:
    depart = (moi["x"], moi["y"])
    vus, file = {depart: 0}, deque([depart])
    while file:
        c = file.popleft()
        if vus[c] >= portee: continue
        for dx, dy in ((1,0),(-1,0),(0,1),(0,-1)):
            n = (c[0]+dx, c[1]+dy)
            if n in vus or not sol(*n) or n in occupe or porte_bloque(c, n): continue
            vus[n] = vus[c] + 1; file.append(n)
    dest = sorted(((v, k) for k, v in vus.items() if k != depart), reverse=True)[:14]
    print(f"DÉPLACEMENT possible ({portee} cases) — quelques destinations :")
    print("  " + " ".join(f"({x},{y})/{d}" for d, (x, y) in dest))

# Fiche des sorts du héros (dés, durée) : sans elle, impossible de savoir si un
# sort à usage unique suffira à tuer une cible — reproché par le magicien.
moi_sorts = {}
try:
    r = json.loads(subprocess.run(
        ["curl", "-s", "-b", f"{S}/jar-{slot}.txt", "http://localhost/api/moi",
         "-H", "Accept: application/json"], capture_output=True, text=True).stdout)
    for p_ in r.get("personnages", []):
        if p_.get("id") == moi_id:
            moi_sorts = {s_["sort_id"]: s_ for s_ in p_.get("sorts", [])}
except Exception:
    pass

if moi.get("conditions"):
    print("CONDITIONS :", ", ".join(
        f"{c['nom']}"
        + (f" ({str(c['source']).split(':', 1)[-1]})" if c.get("source") else "")
        + (f" [{c['duree']} tours]" if c.get("duree") else "")
        for c in moi["conditions"]))

# ⚠ Depuis le 2026-09-01 une option PORTE une liste de sous-choix au lieu
# d'être elle-même le sort / le parchemin / l'objet (doc 13 §3.1 : « 2 à 5
# options claires »). Ne montrer que la ligne de l'option, c'est cacher à
# l'agent l'intégralité de son répertoire — il joue alors un lanceur muet.
LISTES = {"lancer_sort": "sorts", "lire_parchemin": "parchemins",
          "utiliser_objet": "objets", "se_concentrer": "sorts",
          "sacrifier_pour_sort": "sorts"}


def detail_sort(sort_id):
    """Dés / soin / durée d'un sort, lus sur la fiche du héros."""
    fiche = moi_sorts.get(sort_id)
    if not fiche:
        return ""
    eff = fiche.get("effet") or {}
    det = [fiche.get("type") or ""]
    if eff.get("des_degats"): det.append(f"{eff['des_degats']} dés")
    if eff.get("soin_pv_body"): det.append(f"soin {eff['soin_pv_body']}")
    if eff.get("duree"): det.append(f"durée {eff['duree']}")
    return "  {" + ", ".join(x for x in det if x) + "}"


def ligne_cibles(source):
    c = (source or {}).get("cibles")
    return (" → cibles: " + ", ".join(f"{x['nom']}#{x['id']}" for x in c)) if c else ""


print("MENU :")
for o in opts:
    p = o.get("parametres") or {}
    extra = ligne_cibles(p) + detail_sort(p.get("sort_id"))
    print(f"  [{o['id']}] {o.get('libelle')} ({o.get('type')}){extra}")

    # Sous-choix : on répond `{"cle": …}` (+ la cible si l'entrée en porte).
    # Une entrée `disponible: false` est GRISÉE, pas absente — le résolveur la
    # refuse, et la cacher ferait croire à l'agent qu'il a perdu son sort.
    liste = (p.get(LISTES[o["id"]]) or []) if o.get("id") in LISTES else []
    for e in liste:
        etat_e = "" if e.get("disponible", True) else "  ⌀ ÉPUISÉ"
        detail = f" — {e['detail']}" if e.get("detail") else ""
        print(f"      cle={e.get('cle')}  {e.get('nom')}{detail}"
              f"{ligne_cibles(e)}{detail_sort(e.get('sort_id'))}{etat_e}")

if not opts:
    print("  (pas ton tour — attends)")
