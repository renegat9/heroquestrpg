#!/usr/bin/env python3
"""Pilote automatique : joue le héros dont c'est le tour, un tour par appel.

Stratégie volontairement simple mais qui exerce TOUTE la boucle : frapper si
une cible est légale, sinon lancer un sort, sinon ouvrir une porte (c'est ce
qui révèle les salles), sinon fouiller, sinon avancer le plus loin possible,
sinon passer.

⚠ Depuis le 2026-09-01, une option peut PORTER une liste de sous-choix au lieu
d'être elle-même le sort, le parchemin ou l'objet : `lancer_sort` remplace les
neuf options de sort d'un magicien de niveau 1. Un pilote qui ne lit que
`parametres.cibles` ne voit donc plus AUCUN sort — il ne plante pas, il joue un
lanceur muet, et c'est exactement ce que le harnais est censé détecter. Le
protocole est le même que celui du pilote A-à-Z (`browser-shots/aaz/jouer.py`),
volontairement : deux harnais qui divergent sur la forme des menus finissent
par accuser le moteur chacun leur tour.
"""
import json, subprocess, sys, re, random

D = "/home/reneg/heroquestrpg/browser-shots/campagne"

def hq(slot, *args):
    r = subprocess.run([f"{D}/hq.sh", str(slot), *args], capture_output=True, text=True, timeout=60)
    try:
        return json.loads(r.stdout)
    except Exception:
        return None

def choix(slot, oid, params=None):
    args = [str(slot), "choix", oid] + ([json.dumps(params)] if params else [])
    r = subprocess.run([f"{D}/hq.sh", *args], capture_output=True, text=True, timeout=90)
    try:
        return json.loads(r.stdout)
    except Exception:
        return {"brut": r.stdout[:200]}

# Options qui portent une LISTE d'entrées, et la clé où elle se trouve.
LISTES = {"lancer_sort": "sorts", "lire_parchemin": "parchemins",
          "utiliser_objet": "objets", "se_concentrer": "sorts",
          "sacrifier_pour_sort": "sorts"}


def entrees_de(opt):
    """Entrées JOUABLES d'une option à liste ([] si ce n'en est pas une).

    ⚠ Une entrée épuisée reste dans la liste, `disponible: false`, pour être
    grisée par la manette — le résolveur la refuse. La filtrer ici, c'est jouer
    ce qu'un joueur voit jouable.
    """
    cle = LISTES.get(opt.get("id", ""))
    if cle is None:
        return []
    return [e for e in (opt.get("parametres") or {}).get(cle) or []
            if e.get("disponible", True)]


def cible_monstre(source):
    for c in (source or {}).get("cibles") or []:
        if c.get("type") == "monstre":
            return c
    return None


def jouer_liste(slot, oid, opt):
    """Choisit une entrée de la liste, puis sa cible. Rend (libellé, réponse).

    On préfère une entrée qui vise un MONSTRE ; à défaut une entrée sans cible
    (soin sur soi, potion, Traverser la Pierre) qui part telle quelle. Le tir
    ami est légal (doc 02 §5) — c'est au pilote de ne pas se brûler tout seul.
    """
    entrees = entrees_de(opt)
    entree = next((e for e in entrees if cible_monstre(e)), None) \
        or next((e for e in entrees if not e.get("cibles")), None)

    if entree is None:
        return None

    # ⚠ `cle` est la SIXIÈME clé acceptée par ChoixController, ajoutée avec les
    # sous-choix : c'est elle que le serveur revalide contre la liste blanche.
    params = {"cle": entree["cle"]}
    c = cible_monstre(entree)
    if c:
        params.update({"cible_id": c["id"], "cible_type": c["type"]})

    return (f"{oid.upper()} {entree.get('nom', '?')}", choix(slot, oid, params))


def destinations(slot):
    r = subprocess.run(["python3", f"{D}/vue.py", str(slot)], capture_output=True, text=True, timeout=60)
    m = re.findall(r"\((\d+),(\d+)\)/(\d+)", r.stdout)
    return sorted(((int(x), int(y), int(c)) for x, y, c in m), key=lambda t: -t[2])

def jouer(slot):
    px = py = 0
    e = hq(slot, "etat") or {}
    for ent in (e.get("entites") or []):
        if ent.get("type") == "heros" and not ent.get("a_joue"):
            px, py = ent.get("x") or 0, ent.get("y") or 0
            break

    d = hq(slot, "menu")

    # ⚠ Une session expirée rend `{"message":"Unauthenticated."}`, PAS un menu :
    # sans ce cri, le pilote joue zéro tour en silence et on croit le moteur en
    # panne. Payé le 2026-08-23 — 8 « tours joués » qui n'avaient rien joué.
    if (d or {}).get("message") == "Unauthenticated.":
        return ("⚠ SESSION EXPIRÉE — relancer POST /api/connexion", None)

    menu = (d or {}).get("menu")
    if not menu:
        return None
    opts = {o["id"]: o for o in menu.get("options", [])}

    for oid, o in opts.items():
        # `lancer_sort` commence par « lancer » : on écarte d'abord les options à
        # liste, dont le ciblage est d'un niveau plus bas.
        if oid in LISTES:
            continue
        if oid.startswith("attaquer") or oid.startswith("lancer"):
            cibles = (o.get("parametres") or {}).get("cibles") or []
            if cibles:
                c = cibles[0]
                return ("ATTAQUE " + str(c.get("nom", "?")), choix(slot, oid, {"cible_id": c.get("id"), "cible_type": c.get("type", "monstre")}))

    # Sorts et parchemins : une option, une liste, puis une cible.
    for oid in ("lancer_sort", "lire_parchemin"):
        if oid in opts:
            joue = jouer_liste(slot, oid, opts[oid])
            if joue:
                return joue

    for oid in opts:
        if oid.startswith("ouvrir_porte"):
            return ("PORTE " + oid, choix(slot, oid))

    if "fouiller_tresor" in opts:
        return ("FOUILLE", choix(slot, "fouiller_tresor"))

    if "se_deplacer" in opts:
        dests = destinations(slot)
        etat = hq(slot, "etat") or {}
        fermees = [(d["x"], d["y"]) for d in ((etat.get("carte") or {}).get("portes") or [])
                   if d.get("etat") == "fermee"]

        # Viser la PORTE CLOSE la plus proche : au hasard, le groupe tourne dans
        # la salle de départ et la quête ne progresse jamais.
        if fermees:
            dests.sort(key=lambda t: min(abs(t[0] - px) + abs(t[1] - py) for px, py in fermees))
        else:
            random.shuffle(dests)

        # ⚠ Le BFS de vue.py ignore le mobilier : il propose des cases que le
        # serveur refuse (piège n°4 du README). On ESSAIE, et on passe à la
        # suivante — sinon le pilote se bloque sur la même case pour toujours.
        for x, y, _ in dests[:8]:
            rep = choix(slot, "se_deplacer", {"x": x, "y": y})
            if not (rep or {}).get("message"):
                return (f"DEPLACE ({x},{y})", rep)

    if "attendre" in opts:
        return ("PASSE", choix(slot, "attendre"))
    return ("RIEN", None)

if __name__ == "__main__":
    for slot in (1, 2, 3, 4):
        r = jouer(slot)
        if r:
            act, rep = r
            err = (rep or {}).get("message") or (rep or {}).get("brut")
            print(f"  slot {slot} : {act}" + (f"   ⨯ {str(err)[:80]}" if err else ""))
