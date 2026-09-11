#!/usr/bin/env python3
"""Vue de jeu d'UN héros : ce qu'il voit, où il peut aller, ce qu'il peut viser.

Les agents décident ; la géométrie (BFS sur la grille, portes, cases libres)
est faite ici — sinon ils passeraient leur tour à calculer des chemins.

⚠ Depuis le 2026-09-06, un LEVIER est posé dans TOUTE quête (avant, aucun ne
l'avait jamais été) et, sous le thème `horreur_des_glaces`, la carte porte du
TERRAIN (glace glissante, rivière gelée, tunnels…). Un agent ne dispose que de
ce qu'on lui montre : sans ces deux sections, il ne voit ni le levier qui
ouvre une salle scellée, ni le coût réel d'une case de rivière — exactement le
sort qu'ont connu les sept verbes manquants du README jusqu'au 2026-08-17.

⚠ Le DÉPLACEMENT SE COMPTE EN POINTS, PAS EN CASES depuis la Rivière gelée
(coût 2 pour ENTRER dans la case, doc 18 §4) : la BFS « cases atteignables »
ci-dessous est un Dijkstra PONDÉRÉ, MIROIR de `Grille::casesAtteignables()`
côté serveur et de `DeplacementSheet.vue` côté manette — une BFS à coût
uniforme surbrillancerait des destinations que le serveur refuse ensuite.
"""
import json, subprocess, sys, os

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

# --- LEVIERS (doc 18 §4 / 2026-09-06) : affichés INCONDITIONNELLEMENT, pas
# seulement « proches » — ils sont rares (1-2 par carte, `structure.leviers.
# min/max`) et une salle peut ne tenir qu'à un seul. `actionner_levier`
# n'apparaît au MENU qu'au contact (voisin orthogonal) : c'est cette liste qui
# dit à l'agent où aller AVANT d'y être.
leviers = carte.get("leviers") or []
if leviers:
    print("LEVIERS visibles :")
    for l in leviers:
        d = abs(l["x"] - moi["x"]) + abs(l["y"] - moi["y"])
        print(f"  levier en ({l['x']},{l['y']}) "
              f"— jet de Body, difficulté {l.get('difficulte')} — distance {d}")

# --- PORTES VERROUILLÉES : `verrou` publié par `EtatGroupe::portes()` est le
# TYPE du verrou (cle/monstres_vaincus/levier), PAS un identifiant. ⚠ L'API NE
# PUBLIE NULLE PART quel levier ouvre QUELLE porte — et depuis le 2026-09-11
# (arbitrage de René) le levier ne publie même plus son identifiant, parce que
# la porte n'a jamais publié le sien : donner la moitié d'un appariement, c'est
# afficher des ids qui ne se raccordent à rien. `ResolveurTour::
# resoudreActionnerLevier()` retrouve l'appariement lui-même via
# `verrou.levier_id`, côté serveur seulement. Un joueur (humain ou agent) ne
# peut donc PAS savoir À L'AVANCE lequel des leviers visibles ouvre CETTE
# porte — il le découvre en l'actionnant (retentable sans limite). On liste
# les CANDIDATS plutôt que d'inventer un identifiant que l'API ne donne pas.
portes_carte = carte.get("portes") or []
verrouillees = [p for p in portes_carte if p.get("etat") == "verrouillee"]
if verrouillees:
    print("PORTES VERROUILLÉES :")
    for p in verrouillees:
        d = abs(p["x"] - moi["x"]) + abs(p["y"] - moi["y"])
        verrou = p.get("verrou")
        piste = ""
        if verrou == "levier" and leviers:
            piste = "  → candidat(s) : " + ", ".join(f"({l['x']},{l['y']})" for l in leviers)
        elif verrou == "levier":
            piste = "  → AUCUN levier visible pour l'instant"
        print(f"  ({p['x']},{p['y']}) verrou={verrou} — distance {d}{piste}")

# --- PORTES FERMÉES (ouvrables à la main, sans clé) : depuis l'arbitrage du
# 2026-09-10, une fouille réussie ne fait plus qu'AFFICHER un passage secret
# comme une porte fermée ORDINAIRE (`etat: "fermee"`) — il faut ensuite
# `ouvrir_porte_{x}_{y}_{cote}`, une option qui n'apparaît qu'AU CONTACT.
# L'API ne distingue PAS une porte fermée d'origine d'une porte secrète tout
# juste révélée : les deux ont exactement `etat: "fermee"`, par construction
# (`MoteurPortes::fouiller()` réécrit l'état en base, `EtatGroupe::portes()`
# ne republie donc jamais `secrete` pour une porte révélée). On les liste
# donc TOUTES ensemble, sans essayer de deviner laquelle vient d'apparaître.
# ⚠ Un passage NON trouvé est publié `etat: "mur"` (ni porte, ni `revele`,
# ni `verrou`) : il n'apparaît PAS ici, et ne doit jamais être visé.
fermees = [p for p in portes_carte if p.get("etat") == "fermee"]
if fermees:
    print("PORTES FERMÉES (ouvrables, sans clé) :")
    for p in fermees:
        d = abs(p["x"] - moi["x"]) + abs(p["y"] - moi["y"])
        contact = "  → À PORTÉE (ouvrir_porte au menu)" if d <= 1 else ""
        print(f"  ({p['x']},{p['y']}) côté {p.get('cote')} — distance {d}{contact}")

# --- TERRAIN (doc 18 §4, thème horreur_des_glaces UNIQUEMENT) : cases proches
# avec leur coût et leur effet. L'API ne publie PAS `effet` (seulement `nom`,
# `cout_deplacement`, `paire_id`) — le texte d'effet ci-dessous est du texte
# FIXE tiré du catalogue (`TerrainSeeder`), pas une donnée relue à chaque
# appel : s'il divergeait du moteur, seul `App\Engine\MotsClesTerrain` fait foi.
EFFETS_TERRAIN = {
    "Glace glissante": "jet au CONTACT — bouclier blanc = chute + fin de tour immédiate",
    "Glissière de glace": "fin de tour INCONDITIONNELLE en l'empruntant — bouclier blanc = 1 PV Body en plus",
    "Rivière gelée": "SANS arrêt — bouclier blanc = 1 PV Body de froid à CHAQUE case entrée",
    "Tunnel de glace": "téléporte instantanément vers son jumeau (même paire_id) — normal, pas une anomalie",
    "Chambre forte de glace": "1 PV Body de froid (sur crâne) À CHAQUE tour passé dessus, pas qu'au contact",
    "Glace magique": "décor — ancrage de sort du boss (Mur/Pont de glace), aucun effet sur un héros",
    "Rebord de crevasse": "décor — aucun effet mécanique",
}
terrain_carte = carte.get("terrain") or []
terrain_proche = sorted(
    ((t, abs(t["x"] - moi["x"]) + abs(t["y"] - moi["y"])) for t in terrain_carte),
    key=lambda p: p[1],
)[:20]
if terrain_proche:
    print("TERRAIN proche :")
    for t, d in terrain_proche:
        effet = EFFETS_TERRAIN.get(t.get("nom"), "")
        paire = f" — jumeau paire_id={t['paire_id']}" if t.get("paire_id") else ""
        print(f"  ({t['x']},{t['y']}) {t.get('nom')} — coût {t.get('cout_deplacement')} pt(s) pour ENTRER"
              f" — distance {d}{paire}" + (f" — {effet}" if effet else ""))

# --- cases atteignables : Dijkstra pondéré sur le sol connu, portes closes bloquantes
cases = (carte.get("grille") or {}).get("cases") or carte.get("cases") or []
portes = (carte.get("grille") or {}).get("portes") or carte.get("portes") or []
occupe = {(e["x"], e["y"]) for e in ent if e.get("x") is not None and not e.get("tombe")}

# Le MOBILIER barre le passage (doc 17, `bloque_mouvement`) : sans lui, le BFS
# proposait des cases que le serveur refusait — deux tentatives perdues par
# Borin, une par Krogar.
# ⚠ Bug corrigé au passage (2026-09-10) : `EtatGroupe::mobilier()` publie `l`/
# `h` en clés PLATES, PAS nichées sous `emprise` — cette boucle lisait
# `m.get("emprise")` (toujours absent) et retombait donc silencieusement sur
# 1×1 pour CHAQUE meuble, même un meuble 2×2 qui n'en bloquait alors qu'un
# quart. Jamais remarqué en partie réelle (juste des destinations en plus
# tentées et refusées, absorbées par la boucle d'essai de `pilote.py`), mais
# faux depuis l'origine de ce fichier.
for m in ((carte.get("grille") or {}).get("mobilier") or carte.get("mobilier") or []):
    if not m.get("bloque_mouvement", True):
        continue
    mx, my = int(m.get("x", -1)), int(m.get("y", -1))
    l, h = int(m.get("l", 1)), int(m.get("h", 1))
    for dy in range(h):
        for dx in range(l):
            occupe.add((mx + dx, my + dy))

# Coût de déplacement du TERRAIN (doc 18 §4 — Rivière gelée : 2 points pour
# ENTRER dans la case, au lieu de 1) — MIROIR de `Terrain::cout_deplacement`
# et de `coutParCase`/`coutDe()` dans `DeplacementSheet.vue`.
cout_case = {}
for t in terrain_carte:
    cout_case[(t["x"], t["y"])] = max(1, int(t.get("cout_deplacement", 1)))

def cout_de(x, y):
    return cout_case.get((x, y), 1)

# Portes indexées par ARÊTE (MIROIR de `portesParArete` dans
# `DeplacementSheet.vue`) : une porte non-ouverte bloque le pas, une porte
# OUVERTE garantit du sol juste derrière même si le brouillard n'a pas encore
# révélé la case (on continue son mouvement à travers une porte qu'on vient
# d'ouvrir, comme le permet le moteur serveur).
def arete_cle(a, b):
    return (a, b) if a <= b else (b, a)

portes_par_arete = {}
for p in portes:
    x, y = p.get("x"), p.get("y")
    a = (x, y)
    b = (x, y + 1) if p.get("cote") == "s" else (x + 1, y)
    portes_par_arete[arete_cle(a, b)] = p

def porte_fermee_entre(a, b):
    p = portes_par_arete.get(arete_cle(a, b))
    return p is not None and p.get("etat") != "ouverte"

def porte_ouverte_entre(a, b):
    p = portes_par_arete.get(arete_cle(a, b))
    return p is not None and p.get("etat") == "ouverte"

def case_brute(x, y):
    if x < 0 or y < 0:
        return "b"
    try:
        return cases[y][x]
    except (IndexError, TypeError):
        return "b"

def sol(x, y):
    return case_brute(x, y) in ("s", "p")

portee = 0
menu = json.loads(subprocess.run(
    ["curl", "-s", "-b", f"{S}/jar-{slot}.txt", f"http://localhost/api/groupes/{code}/menu",
     "-H", "Accept: application/json"], capture_output=True, text=True).stdout or "{}")
opts = (menu.get("menu") or {}).get("options") or []
for o in opts:
    if o.get("id") == "se_deplacer":
        portee = int((o.get("parametres") or {}).get("portee") or 0)

if portee:
    # Dijkstra pondéré à la main (scan linéaire — MIROIR de
    # `DeplacementSheet.vue` : la zone qu'un déplacement de héros peut
    # explorer tient en quelques dizaines de cases, pas besoin d'un tas pour
    # rester instantané). Chaque pas coûte `cout_de()` de la case d'ARRIVÉE,
    # PAS 1 uniformément — c'est ce qui rend la Rivière gelée possible et ce
    # que `Grille::casesAtteignables()` fait déjà côté serveur.
    depart = (moi["x"], moi["y"])
    dist = {depart: 0}
    frontiere = [(0, depart)]
    while frontiere:
        frontiere.sort(key=lambda p: p[0])
        d, c = frontiere.pop(0)
        if d > dist.get(c, float("inf")):
            continue  # entrée dépassée (suppression paresseuse)
        for dx, dy in ((1, 0), (-1, 0), (0, 1), (0, -1)):
            n = (c[0] + dx, c[1] + dy)
            if porte_fermee_entre(c, n):
                continue
            case_connue = sol(*n)
            # Filet de sécurité (§2.16) : une case VOISINE IMMÉDIATE reste
            # proposée même si la carte connue est incomplète, sauf mur
            # explicite — sans lui, une carte partielle peut rendre la liste
            # de destinations VIDE et figer le héros.
            voisin_immediat = d == 0 and case_brute(*n) != "m"
            if not case_connue and not porte_ouverte_entre(c, n) and not voisin_immediat:
                continue
            if n in occupe:
                continue
            nd = d + cout_de(*n)
            if nd > portee:
                continue  # hors budget : jamais une destination possible
            if nd < dist.get(n, float("inf")):
                dist[n] = nd
                if case_connue:
                    frontiere.append((nd, n))
    dest = sorted(((v, k) for k, v in dist.items() if k != depart), reverse=True)[:14]
    print(f"DÉPLACEMENT possible ({portee} POINTS, pas cases) — quelques destinations :")
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
