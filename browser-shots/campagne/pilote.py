#!/usr/bin/env python3
"""Pilote automatique : joue le héros dont c'est le tour, un tour par appel.

Stratégie volontairement simple mais qui exerce TOUTE la boucle : frapper si
une cible est légale, sinon ouvrir une porte (c'est ce qui révèle les salles),
sinon fouiller, sinon avancer le plus loin possible, sinon passer.
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
        if oid.startswith("attaquer") or oid.startswith("lancer"):
            cibles = (o.get("parametres") or {}).get("cibles") or []
            if cibles:
                c = cibles[0]
                return ("ATTAQUE " + str(c.get("nom", "?")), choix(slot, oid, {"cible_id": c.get("id"), "cible_type": c.get("type", "monstre")}))

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
