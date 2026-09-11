#!/usr/bin/env python3
"""Pilote automatique : joue le héros dont c'est le tour, un tour par appel.

Stratégie volontairement simple mais qui exerce TOUTE la boucle : frapper si
une cible est légale, sinon lancer un sort, sinon ouvrir une porte (c'est ce
qui révèle les salles), sinon actionner un levier trouvé au contact, sinon
fouiller, sinon avancer — vers un levier visible si une porte verrouillée par
levier bloque la seule progression connue, sinon vers la porte close la plus
proche —, sinon passer.

⚠ Depuis le 2026-09-01, une option peut PORTER une liste de sous-choix au lieu
d'être elle-même le sort, le parchemin ou l'objet : `lancer_sort` remplace les
neuf options de sort d'un magicien de niveau 1. Un pilote qui ne lit que
`parametres.cibles` ne voit donc plus AUCUN sort — il ne plante pas, il joue un
lanceur muet, et c'est exactement ce que le harnais est censé détecter. Le
protocole est le même que celui du pilote A-à-Z (`browser-shots/aaz/jouer.py`),
volontairement : deux harnais qui divergent sur la forme des menus finissent
par accuser le moteur chacun leur tour.

⚠ Depuis le 2026-09-06, un LEVIER est posé dans TOUTE quête. `actionner_levier`
n'est PAS une option à liste (elle n'est PAS dans `LISTES` ci-dessous) : comme
`ouvrir_porte`, c'est une option PAR levier adjacent (id
`actionner_levier_{x}_{y}`), déjà posée par `MenuMoteur` (~ligne 1508) avec ses
`parametres` FIXÉS CÔTÉ SERVEUR. `ResolveurTour::resoudreActionnerLevier()`
(~ligne 6004) ne lit d'ailleurs JAMAIS les `parametres` soumis par le client —
seulement l'option retrouvée dans le DERNIER MENU envoyé (`ChoixController`,
`->first(fn ($o) => $o['id'] === $option_id)`). `choix(slot, oid)` SANS
troisième argument suffit donc, exactement comme pour une porte — pas de forme
« à plat » façon `lancer_sort` à reproduire ici.

⚠ Sous le thème `horreur_des_glaces`, le déplacement se compte en POINTS, pas
en cases, depuis la Rivière gelée (coût 2/case) : `destinations()` reste un
simple parseur du texte de `vue.py`, qui fait maintenant lui-même un Dijkstra
pondéré — ce pilote ne recalcule JAMAIS de distance en cases, il se contente
des destinations que `vue.py` (miroir du serveur) a déjà validées comme
atteignables dans le budget du tour.
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

    # LEVIER — n'apparaît au menu qu'AU CONTACT (voisin orthogonal). Priorité
    # juste derrière les portes déjà ouvrables : c'est la raison la plus
    # probable d'être venu jusqu'ici, et il est RETENTABLE sans limite (jet de
    # Body), donc jamais pire qu'une tentative perdue. Passe AVANT de se
    # défendre uniquement dans ce sens précis : il ne dépasse jamais l'attaque
    # ni les sorts, testés plus haut.
    for oid in opts:
        if oid.startswith("actionner_levier"):
            return ("LEVIER " + oid, choix(slot, oid))

    if "fouiller_tresor" in opts:
        return ("FOUILLE", choix(slot, "fouiller_tresor"))

    if "se_deplacer" in opts:
        dests = destinations(slot)
        etat = hq(slot, "etat") or {}
        carte = etat.get("carte") or {}
        portes = carte.get("portes") or []
        fermees = [(d["x"], d["y"]) for d in portes if d.get("etat") == "fermee"]

        # LEVIER — cible de repli, PAS la priorité par défaut : on ne quitte
        # pas une porte déjà ouvrable pour un détour. On ne la prend que
        # lorsqu'une porte verrouillée PAR LEVIER reste sans autre porte
        # ouvrable connue — c'est cette situation précise que le README décrit
        # (« une salle peut rester inaccessible et la quête s'enliser »).
        # ⚠ On ne peut PAS savoir quel levier ouvre CETTE porte (l'API ne
        # publie pas l'appariement, voir vue.py) : on vise donc N'IMPORTE quel
        # levier visible — en pratique 1 à 2 par carte (`structure.leviers.
        # min/max`), et l'action est retentable sans limite si ce n'est pas
        # le bon.
        verrouillees_levier = any(
            d.get("etat") == "verrouillee" and d.get("verrou") == "levier" for d in portes
        )
        leviers = carte.get("leviers") or []
        cibles = fermees
        if not cibles and verrouillees_levier and leviers:
            cibles = [(l["x"], l["y"]) for l in leviers]

        # Viser la cible la plus proche (porte close, ou levier à défaut) : au
        # hasard, le groupe tourne dans la salle de départ et la quête ne
        # progresse jamais.
        if cibles:
            dests.sort(key=lambda t: min(abs(t[0] - cx) + abs(t[1] - cy) for cx, cy in cibles))
        else:
            random.shuffle(dests)

        # ⚠ Le BFS de vue.py ignore le mobilier : il propose des cases que le
        # serveur refuse (piège n°4 du README). On ESSAIE, et on passe à la
        # suivante — sinon le pilote se bloque sur la même case pour toujours.
        for x, y, _ in dests[:8]:
            rep = choix(slot, "se_deplacer", {"x": x, "y": y})
            if not (rep or {}).get("message"):
                # ⚠ La case d'ARRIVÉE peut différer de (x, y) demandée — un
                # Tunnel de glace téléporte (`rep.teleportation`), ce n'est
                # PAS une anomalie : `rep.vers` dit la vérité. Une Glace
                # glissante/Glissière peut aussi finir le tour tout de suite
                # (`rep.terrain.fin_tour`) : c'est la règle, pas une erreur —
                # on l'annonce au lieu de la laisser muette (CLAUDE.md : « un
                # effet automatique que rien n'annonce est injouable »).
                arrivee = (rep or {}).get("vers") or {"x": x, "y": y}
                label = f"DEPLACE ({x},{y}) → ({arrivee.get('x')},{arrivee.get('y')})"
                terrain_evt = (rep or {}).get("terrain")
                if terrain_evt:
                    bits = [terrain_evt.get("nom", "terrain")]
                    if terrain_evt.get("chute"):
                        bits.append("CHUTE")
                    if terrain_evt.get("fin_tour"):
                        bits.append("fin de tour")
                    if terrain_evt.get("degats"):
                        bits.append(f"{terrain_evt['degats']} dégâts")
                    label += "  [" + ", ".join(bits) + "]"
                if (rep or {}).get("teleportation"):
                    label += "  [TUNNEL DE GLACE]"
                return (label, rep)

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
