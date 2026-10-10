#!/usr/bin/env python3
"""Vue de jeu d'UN héros : ce qu'il voit, où il peut aller, ce qu'il peut viser.

Les agents décident ; le SERVEUR décide tout le reste, et ce script se contente
de lire ce qu'il publie — sinon les agents passeraient leur tour à deviner.

⚠ DESTINATIONS (2026-10-10) : le serveur PUBLIE les cases atteignables —
`se_deplacer.parametres.destinations = [{x, y, cout}]`, calculées avec le code
même de la résolution (`DeplacementHeros`, app/Partie) : terrain pondéré, mobilier,
bloc tombé, passage par un allié, monstres franchis, embrasure, roche d'un héros
intangible, reliquat. Ce script les LIT et les affiche ; il ne recalcule rien et
n'interroge plus `deplacement/apercu` case par case (c'était juste mais lourd :
une requête par case candidate). `apercu` reste le bon outil pour le TRAJET exact
et les pièges connus d'UNE case choisie — pas pour savoir où aller. La liste est la
liste blanche du résolveur : une case qui n'y figure pas est refusée en 422.
Elle ne révèle rien de caché (piège non détecté, passage secret, salle non
révélée, monstre caché) — c'est testé (`DestinationsDeplacementTest`).

⚠ Depuis le 2026-09-06, un LEVIER est posé dans TOUTE quête (avant, aucun ne
l'avait jamais été) et, sous le thème `horreur_des_glaces`, la carte porte du
TERRAIN (glace glissante, rivière gelée, tunnels…). Un agent ne dispose que de
ce qu'on lui montre : sans ces deux sections, il ne voit ni le levier qui
ouvre une salle scellée, ni le coût réel d'une case de rivière — exactement le
sort qu'ont connu les sept verbes manquants du README jusqu'au 2026-08-17.

⚠ Le DÉPLACEMENT SE COMPTE EN POINTS, PAS EN CASES depuis la Rivière gelée
(coût 2 pour ENTRER dans la case, doc 18 §4) : le coût affiché est celui que le
serveur annonce (`cout`), jamais une distance.

⚠ ESCALIER ET SORTIE (2026-10-05, puis 2026-10-10) : on quitte le donjon par
l'escalier d'entrée, et `quitter_donjon` exige que TOUS les héros debout soient
dans la SALLE DE DÉPART — la salle de l'escalier, pas forcément sur ses cases.
Le serveur publie `quete.sortie` (`absents` = héros debout hors de la salle) :
ce script le lit, il ne compare aucune position de salle.
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

# --- ESCALIER ET SORTIE (2026-10-05, puis 2026-10-10) : décisions publiées par le
# serveur, lues telles quelles. `carte.escalier` : position et taille (2×2).
# `quete.sortie` : `ouverte`, `salle_depart_requise`, `absents` (noms des héros
# DEBOUT hors de la salle de départ) et `consigne` prête à afficher. Aucune
# position de salle n'est comparée ici : la décision est déjà prise côté serveur.
sortie = (etat.get("quete") or {}).get("sortie") or {}
esc = carte.get("escalier")
if esc:
    ex, ey, el, eh = esc["x"], esc["y"], esc["l"], esc["h"]
    d_esc = min(abs(cx - moi["x"]) + abs(cy - moi["y"])
                for cx in range(ex, ex + el) for cy in range(ey, ey + eh))
    print(f"ESCALIER (sortie du donjon) en ({ex},{ey}), taille {el}×{eh} — "
          f"distance du héros : {d_esc}" + (" — TU ES SUR L'ESCALIER" if d_esc == 0 else ""))
else:
    print("ESCALIER : aucun sur cette carte (pas de salle de départ exigée)")
if sortie:
    absents = sortie.get("absents") or []
    requise = bool(sortie.get("salle_depart_requise"))
    print("SORTIE : " + ("OUVERTE" if sortie.get("ouverte") else "fermée")
          + ("" if requise else " (salle de départ non exigée)"))
    if requise:
        if moi.get("tombe"):
            toi = "à terre (la sortie ne compte que les héros debout)"
        elif moi["nom"] in absents:
            toi = "HORS de la salle de départ"
        else:
            toi = "dans la salle de départ"
        print(f"  toi : {toi}" + (f" — absents : {', '.join(absents)}" if absents else ""))
    if sortie.get("consigne"):
        print(f"  consigne : {sortie['consigne']}")

for e in ent:
    if e.get("type") == "heros" and e.get("id") != moi_id:
        print(f"  allié {e['nom']} ({e['x']},{e['y']}) {e.get('pv_body')}pv"
              + (" [À TERRE]" if e.get("tombe") else ""))
# MERCENAIRES de la quête (`type: "allie"`, `EtatGroupe::allies()`, chantier 3a) : des
# figures de l'équipe, jouées par leur joueur. Elles OCCUPENT une case — sans elles
# affichées, l'agent visait des cases que le serveur refusait (verdict Morcar, 2026-10-09).
for e in ent:
    if e.get("type") == "allie":
        d = abs(e["x"] - moi["x"]) + abs(e["y"] - moi["y"])
        print(f"  ALLIÉ {e['nom']} ({e['x']},{e['y']}) {e.get('pv_body')}pv — distance {d}")
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

# PORTE « À PORTÉE » = la case d'EMBRASURE, pas la porte (2026-10-09, verdict Morcar).
# `EtatGroupe::portes()` publie `embrasure` (`Grille::caseEmbrasure()`) : le menu
# n'offre `ouvrir_porte` qu'à distance 1 de CETTE case (`MoteurPortes::porteFermeeAdjacente()`),
# jamais de la porte elle-même — une porte annoncée « à portée » à distance 1 de sa
# seule arête ne l'était pas. Une porte sans `embrasure` publiée est SIGNALÉE, pas devinée.
def distance_embrasure(p):
    e = p.get("embrasure")
    if e is None:
        return None
    return abs(e["x"] - moi["x"]) + abs(e["y"] - moi["y"])

def texte_embrasure(p):
    e = p.get("embrasure")
    return f"embrasure ({e['x']},{e['y']})" if e else "⚠ embrasure NON publiée"

verrouillees = [p for p in portes_carte if p.get("etat") == "verrouillee"]
if verrouillees:
    print("PORTES VERROUILLÉES :")
    for p in verrouillees:
        d = distance_embrasure(p)
        verrou = p.get("verrou")
        piste = ""
        if verrou == "levier" and leviers:
            piste = "  → candidat(s) : " + ", ".join(f"({l['x']},{l['y']})" for l in leviers)
        elif verrou == "levier":
            piste = "  → AUCUN levier visible pour l'instant"
        print(f"  ({p['x']},{p['y']}) verrou={verrou} — {texte_embrasure(p)} à distance {d}{piste}")

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
        d = distance_embrasure(p)
        contact = "  → À PORTÉE (ouvrir_porte au menu)" if d == 1 else ""
        print(f"  ({p['x']},{p['y']}) côté {p.get('cote')} — {texte_embrasure(p)} à distance {d}{contact}")

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

# --- MENU (lu d'abord : c'est lui qui porte la portée du déplacement) ------------
menu = json.loads(subprocess.run(
    ["curl", "-s", "-b", f"{S}/jar-{slot}.txt", f"http://localhost/api/groupes/{code}/menu",
     "-H", "Accept: application/json"], capture_output=True, text=True).stdout or "{}")
opts = (menu.get("menu") or {}).get("options") or []
portee = 0
destinations_serveur = None
for o in opts:
    if o.get("id") == "se_deplacer":
        params_dep = o.get("parametres") or {}
        portee = int(params_dep.get("portee") or 0)
        destinations_serveur = params_dep.get("destinations")

# --- DESTINATIONS : la décision du SERVEUR, lue telle quelle (2026-10-10) --------
# `parametres.destinations` = [{x, y, cout}] (cout en POINTS). Aucun calcul ici.
if destinations_serveur is not None:
    ok = {(d["x"], d["y"]): d["cout"] for d in destinations_serveur}
    print(f"DÉPLACEMENT possible ({portee} POINTS restants, pas cases) — décidé par le serveur :")
    if ok:
        tri = sorted(((cout, c) for c, cout in ok.items()), reverse=True)[:14]
        print("  " + " ".join(f"({x},{y})/{cout}" for cout, (x, y) in tri))
        if len(ok) > len(tri):
            print(f"  … {len(ok)} cases en tout (les plus lointaines ci-dessus)")
    else:
        print("  (aucune case atteignable d'après le serveur)")

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
