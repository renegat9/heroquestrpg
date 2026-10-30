#!/usr/bin/env python3
"""Extrait les SYMBOLES des livrets officiels Hasbro vers public/images/livrets/{type}/{cle}.webp.

Entrée : manifeste.json (coordonnées seulement — aucun pixel de livret n'entre dans le dépôt).
Sortie : un .webp par symbole extrait, fond TRANSPARENT, trait recoloré en crème avec un liseré
sombre pour rester lisible sur le fond sombre de la carte, rogné au plus près, ramené à la
proportion de l'emprise (un meuble 3×1 garde son 3:1) et à 256 px sur le grand côté.
Un JETON DE MONSTRE (`disque`, phase 2) n'est pas un trait : c'est une tête dans un anneau, gardée
en couleurs, détourée en rond (voir `mettre_en_disque`). Un jeton OVALE (`ovale`, phase 3 : figure 1×2)
est le même traitement sur une ellipse, ramené à l'horizontale 2:1 et à 512 px (voir `extraire_ovale`). Un jeton CARRÉ
Une TUILE (`tuile`, la tuile d'une figure) est prise bord à bord, sans détourage, carrée ou 2:1.

Lancé par ../symboles-livrets.sh dans un conteneur jetable python:3.12-slim (PyMuPDF + Pillow),
le dossier des PDF monté EN LECTURE SEULE, ce script hors du dossier des données, `python -I`.

Usage : python -I extraire.py --livrets DOSSIER --manifeste FICHIER --sortie DOSSIER [--seul TYPE/CLE]
"""

import argparse
import io
import json
import math
import os
import sys

# Installé par le lanceur dans /tmp/pylib (conteneur jetable). Sans effet ailleurs.
sys.path.insert(0, '/tmp/pylib')

import pymupdf  # noqa: E402  (PyMuPDF)
from PIL import Image, ImageChops, ImageFilter  # noqa: E402

TYPES = ['mobiliers', 'terrains', 'pieges', 'portes', 'leviers', 'escalier', 'dangers', 'monstres']
CREME = (239, 230, 216)        # #efe6d8 — le trait, comme convenu avec l'affichage
LISERE = (26, 20, 16)          # liseré sombre autour du trait
COTE_PX = 256                  # grand côté de sortie
SEUIL_BAS, SEUIL_HAUT = 28.0, 68.0   # distance au papier : en dessous, transparent ; au-dessus, trait plein
COURONNE_JETON = 1.15          # jeton de monstre : la couronne va jusqu'à 1,15 R (une oreille dépasse l'anneau)
COTE_OVALE = 512               # jeton OVALE (figure 1×2, phase 3, 2026-10-29) : 512 × 256, soit 2:1
TOLERANCE_PARCHEMIN = 40       # couronne seulement : au-delà de cette distance RVB au parchemin, c'est du dessin


def charger_manifeste(chemin):
    with open(chemin, encoding='utf-8') as f:
        return json.load(f)


def dpi_pour(cadre):
    """Assez de pixels pour que le symbole soit net : ~900 px sur son grand côté, 300 dpi au minimum."""
    long_pt = max(cadre[2] - cadre[0], cadre[3] - cadre[1])
    return int(min(1200, max(300, 900 * 72.0 / long_pt)))


def rendre_cadre(doc, page_no, cadre, dpi):
    """Rend le cadre (repère d'affichage, points PDF) en RGB."""
    page = doc[page_no - 1]
    clip = pymupdf.Rect(*cadre)
    pix = page.get_pixmap(dpi=dpi, clip=clip, alpha=False)
    return Image.frombytes('RGB', (pix.width, pix.height), pix.samples)


def references_fond(img, generique=False):
    """Couleurs du FOND autour du trait : le blanc de la case, et le parchemin du livret (la teinte
    beige, pâle et chaude, la plus fréquente du cadre). Le parchemin n'est pas du blanc : sans lui,
    il devient de l'encre."""
    refs = [(255, 255, 255)]
    raw = img.tobytes()
    compte = {}
    for i in range(0, len(raw), 3):
        r, g, b = raw[i], raw[i + 1], raw[i + 2]
        mx, mn = max(r, g, b), min(r, g, b)
        lum = (r + g + b) / 3
        if 170 <= lum <= 245 and 12 <= mx - mn <= 90 and r >= g >= b:
            cle = (r // 8, g // 8, b // 8)
            compte[cle] = compte.get(cle, 0) + 1
    if compte:
        cle = max(compte, key=compte.get)
        refs.append((cle[0] * 8 + 4, cle[1] * 8 + 4, cle[2] * 8 + 4))
    if generique:
        # trait monochrome : le fond peut être n'importe quelle teinte claire (sol peint, papier gris).
        clair = {}
        for i in range(0, len(raw), 3):
            r, g, b = raw[i], raw[i + 1], raw[i + 2]
            if (r + g + b) / 3 >= 150:
                cle = (r // 8, g // 8, b // 8)
                clair[cle] = clair.get(cle, 0) + 1
        if clair:
            cle = max(clair, key=clair.get)
            teinte = (cle[0] * 8 + 4, cle[1] * 8 + 4, cle[2] * 8 + 4)
            if min(((teinte[0] - c[0]) ** 2 + (teinte[1] - c[1]) ** 2 + (teinte[2] - c[2]) ** 2) ** 0.5 for c in refs) > 30:
                refs.append(teinte)
    return refs


POUSSIERE_RELATIVE = 0.002   # une composante de moins de 0,2 % de la plus grande est de la poussière, pas un dessin


def retirer_poussiere(alpha):
    """Efface les COMPOSANTES CONNEXES minuscules du trait : la poussière du papier et les éclats d'encre que le rognage
    prendrait pour du dessin (secrete : quinze taches de moins de 8 px à l'intérieur de la porte, 2026-10-24).

    OPT-IN par symbole (`"poussiere": true` dans le manifeste), jamais global : un pointillé ou une hachure faite de petites
    composantes y perdrait son dessin (`trone`, `mare-dacide`, `escalier` changeaient entièrement, mesuré le 2026-10-24).
    Une composante est comptée à la même force que `rogner()` (> 13). Le seuil est RELATIF à la plus grande composante,
    donc indépendant de la résolution. Un morceau d'un AUTRE symbole qui touche le cadre n'est pas de la poussière : il se
    coupe dans le cadre (manifeste), jamais ici — il pèse 18 % d'un battant."""
    largeur, hauteur = alpha.size
    donnees = bytearray(alpha.tobytes())
    vus = bytearray(largeur * hauteur)
    composantes = []
    for i0 in range(largeur * hauteur):
        if vus[i0] or donnees[i0] <= 13:
            continue
        vus[i0] = 1
        pile, membres = [i0], []
        while pile:
            j = pile.pop()
            membres.append(j)
            y, x = divmod(j, largeur)
            for ny in (y - 1, y, y + 1):
                if ny < 0 or ny >= hauteur:
                    continue
                for nx in (x - 1, x, x + 1):
                    if nx < 0 or nx >= largeur:
                        continue
                    k = ny * largeur + nx
                    if not vus[k] and donnees[k] > 13:
                        vus[k] = 1
                        pile.append(k)
        composantes.append(membres)
    if not composantes:
        return alpha
    plus_grande = max(len(membres) for membres in composantes)
    for membres in composantes:
        if len(membres) < plus_grande * POUSSIERE_RELATIVE:
            for j in membres:
                donnees[j] = 0
    return Image.frombytes('L', alpha.size, bytes(donnees))


def alpha_du_trait(img, generique=False, seuil=None):
    """Force du trait, de 0 (fond) à 255 (trait) : distance au fond le plus proche.

    `seuil` (manifeste, par symbole) remplace le seuil bas. Sur un papier MARBRÉ, la marbrure reste sous ce seuil et devient
    du papier ; le trait, lui, est loin du fond et reste plein. C'est ce qui a effacé la tache grise de la bibliothèque."""
    bas = SEUIL_BAS if seuil is None else seuil
    haut = SEUIL_HAUT if seuil is None else seuil + (SEUIL_HAUT - SEUIL_BAS)
    refs = references_fond(img, generique)
    raw = img.tobytes()
    sortie = bytearray(len(raw) // 3)
    for i in range(0, len(raw), 3):
        r, g, b = raw[i], raw[i + 1], raw[i + 2]
        d = min(((r - c[0]) ** 2 + (g - c[1]) ** 2 + (b - c[2]) ** 2) ** 0.5 for c in refs)
        if d <= bas:
            a = 0
        elif d >= haut:
            a = 255
        else:
            a = round((d - bas) / (haut - bas) * 255)
        sortie[i // 3] = a
    return Image.frombytes('L', img.size, bytes(sortie))


def mettre_en_creme(alpha):
    """RGBA : trait crème plein + liseré sombre décalé d'un pixel (lisible sur la carte sombre)."""
    dilate = alpha.filter(ImageFilter.MaxFilter(5))
    halo = dilate.point(lambda v: int(v * 0.85))
    couche_halo = Image.new('RGBA', alpha.size, LISERE + (0,))
    couche_halo.putalpha(halo)
    couche_trait = Image.new('RGBA', alpha.size, CREME + (0,))
    couche_trait.putalpha(alpha)
    fond = Image.new('RGBA', alpha.size, (0, 0, 0, 0))
    return Image.alpha_composite(Image.alpha_composite(fond, couche_halo), couche_trait)


def mettre_en_couleur(img, alpha):
    """RGBA aux couleurs du livret : pour un symbole en couleur (tuile peinte, icône photographique).
    Le papier devient transparent ; aucun trait n'est recoloré, puisqu'il n'y a pas de trait à part."""
    rgba = img.convert('RGBA')
    rgba.putalpha(alpha)
    return rgba


def rogner(rgba, marge=2):
    boite = rgba.getchannel('A').point(lambda v: 255 if v > 13 else 0).getbbox()
    if boite is None:
        return None
    x0, y0, x1, y1 = boite
    x0, y0 = max(0, x0 - marge), max(0, y0 - marge)
    x1, y1 = min(rgba.width, x1 + marge), min(rgba.height, y1 + marge)
    return rgba.crop((x0, y0, x1, y1))


def ajuster_emprise(rgba, emprise):
    """Pose le symbole, centré, sur un cadre de même proportion que l'emprise (transparent autour)."""
    if not emprise:
        largeur = hauteur = max(rgba.width, rgba.height)
        cadre = (largeur, hauteur)
    else:
        ratio = emprise[0] / emprise[1]
        if rgba.width / rgba.height < ratio:
            cadre = (round(rgba.height * ratio), rgba.height)
        else:
            cadre = (rgba.width, round(rgba.width / ratio))
    toile = Image.new('RGBA', cadre, (0, 0, 0, 0))
    toile.paste(rgba, ((cadre[0] - rgba.width) // 2, (cadre[1] - rgba.height) // 2), rgba)
    return toile


def mettre_a_taille(rgba, cote=COTE_PX):
    long = max(rgba.size)
    echelle = cote / long
    cible = (max(1, round(rgba.width * echelle)), max(1, round(rgba.height * echelle)))
    return rgba.resize(cible, Image.LANCZOS)


def mettre_en_jeton(img, cadre, centre, rx, ry):
    """Jeton de monstre : une tête dans un anneau, ROND (rx = ry) ou OVALE (figure 1×2). `centre`, `rx` et `ry` (demi-axes)
    sont en points d'affichage. La distance se mesure en RAYONS NORMALISÉS (1 = le bord de l'anneau) : le même code sert au
    disque et à l'ellipse, un seul point de passage pour le fond, la couronne et le détourage.

    Le disque de rayon R garde TOUT, couleurs du livret comprises (anneau vert, tête). La couronne jusqu'à 1,15 R ne
    garde que ce qui s'écarte du fond : les oreilles de la tête dépassent l'anneau, un masque strictement circulaire
    les couperait. Hors de la couronne, rien — le fond et le texte voisin disparaissent.
    Fond : la teinte claire la plus fréquente hors de la couronne (parchemin beige, papier pâle du Frozen Horror ;
    le texte voisin, sombre et minoritaire, n'emporte pas le compte). Dans la couronne, un pixel proche du fond part,
    sauf s'il est nettement plus sombre (le contour des oreilles, sur un fond clair)."""
    cx, cy = centre
    x0, y0, x1, y1 = cadre
    w, h = img.size
    sx, sy = w / (x1 - x0), h / (y1 - y0)
    couronne = COURONNE_JETON
    raw = img.tobytes()

    def distance(x, y):
        return math.hypot((x - cx) / rx, (y - cy) / ry)

    def fond_clair(seuil):
        compte = {}
        for j in range(h):
            y = y0 + (j + 0.5) / sy
            for i in range(w):
                x = x0 + (i + 0.5) / sx
                if distance(x, y) <= couronne:
                    continue
                k = 3 * (j * w + i)
                r, g, b = raw[k], raw[k + 1], raw[k + 2]
                if (r + g + b) / 3 >= seuil:
                    cle = (r // 8, g // 8, b // 8)
                    compte[cle] = compte.get(cle, 0) + 1
        return compte

    # Le fond : la teinte claire la plus fréquente hors de la couronne (parchemin, papier pâle du Frozen Horror).
    compte = fond_clair(150) or fond_clair(110)
    if not compte:
        raise ValueError('aucun fond autour du jeton : cadre trop serré')
    teinte = max(compte, key=compte.get)
    fond = (teinte[0] * 8 + 4, teinte[1] * 8 + 4, teinte[2] * 8 + 4)
    lum_fond = sum(fond) / 3

    alpha = bytearray(w * h)
    couronne_gardee = []
    for j in range(h):
        y = y0 + (j + 0.5) / sy
        for i in range(w):
            x = x0 + (i + 0.5) / sx
            d = distance(x, y)
            if d > couronne:
                continue
            k = 3 * (j * w + i)
            r, g, b = raw[k], raw[k + 1], raw[k + 2]
            # Un rouge franc n'est jamais le dessin d'un jeton : c'est une paroi ou un sceau de la carte voisine, qui
            # tombe parfois dans le disque même (un pion sous la lettre B tenait une paroi bordeaux ; mesuré le 2026-10-29).
            if r - g > 40 and r - b > 40:
                continue
            if d <= 1.0:
                alpha[j * w + i] = 255
                continue
            ecart = math.sqrt((r - fond[0]) ** 2 + (g - fond[1]) ** 2 + (b - fond[2]) ** 2)
            # Du dessin dans la couronne : ce qui s'écarte du fond, ou ce qui est nettement plus sombre (contour des oreilles).
            if ecart > TOLERANCE_PARCHEMIN or (r + g + b) / 3 < lum_fond - 40:
                couronne_gardee.append(j * w + i)
    # Seul le dessin qui TOUCHE le disque reste (la tête, ses oreilles) : une poussière du fond, isolée, part.
    voisins_gardes = set(couronne_gardee)
    pile = [j * w + i for j in range(h) for i in range(w) if alpha[j * w + i] == 255]
    while pile:
        p = pile.pop()
        y, x = divmod(p, w)
        for ny in (y - 1, y, y + 1):
            for nx in (x - 1, x, x + 1):
                q = ny * w + nx
                if 0 <= ny < h and 0 <= nx < w and q in voisins_gardes and alpha[q] == 0:
                    alpha[q] = 255
                    pile.append(q)
    rgba = img.convert('RGBA')
    rgba.putalpha(Image.frombytes('L', img.size, bytes(alpha)))
    return rgba


def mettre_en_disque(img, cadre, disque):
    """Jeton ROND : `disque` = [cx, cy, R] en points (centre et rayon de l'anneau). Le même traitement que l'ovale."""
    cx, cy, rayon = disque
    return mettre_en_jeton(img, cadre, (cx, cy), rayon, rayon)


def extraire_ovale(img, cadre, entree):
    """Jeton OVALE d'une figure 1×2 : `ovale` = [cx, cy, rx, ry] en points d'affichage (demi-axes de l'anneau).

    Le détourage se fait dans le repère d'affichage de la page (là où l'on a mesuré l'ovale), puis le jeton est tourné
    (`rotation`, horaire : une page couchée) pour que son grand axe soit horizontal, rogné au plus près et posé dans un
    cadre 2:1 (transparent autour). Le fichier est toujours horizontal : le serveur décide s'il faut le tourner à
    l'affichage (`symbole_rotation`), jamais le client.
    """
    cx, cy, rx, ry = entree['ovale']
    rgba = mettre_en_jeton(img, cadre, (cx, cy), rx, ry)
    rotation = int(entree.get('rotation', 0)) % 360
    if rotation:
        rgba = rgba.rotate(-rotation, expand=True)   # rotation horaire, comme le reste du manifeste
    rogne = rogner(rgba)
    if rogne is None:
        raise ValueError('aucun jeton détecté dans le cadre')
    return mettre_a_taille(ajuster_emprise(rogne, [2, 1]), COTE_OVALE)


def extraire_natif(doc, entree):
    """Raster EMBARQUÉ dans le PDF, à sa résolution native : une case carrée prise dans l'image."""
    natif = entree['natif']
    d = doc.extract_image(natif['xref'])
    img = Image.open(io.BytesIO(d['image'])).convert('RGB')
    img = img.crop(tuple(natif['boite_px']))
    return img.resize((COTE_PX, COTE_PX), Image.LANCZOS).convert('RGBA')


def extraire_un(doc, entree, racine_livrets):
    if 'natif' in entree:
        return extraire_natif(doc, entree)
    cadre = entree['cadre']
    dpi = dpi_pour(cadre)
    img = rendre_cadre(doc, entree['page'], cadre, dpi)
    if 'ovale' in entree:
        return extraire_ovale(img, cadre, entree)
    if 'disque' in entree:
        # Jeton rond : le cadre est carré et le disque est centré dedans, donc la rotation (une étiquette verticale
        # tourne sa tête avec son texte) ne déplace pas le disque. Ni rognage, ni emprise.
        rotation = int(entree.get('rotation', 0)) % 360
        if rotation:
            img = img.rotate(-rotation)
        return mettre_a_taille(mettre_en_disque(img, cadre, entree['disque']))
    if entree.get('tuile'):
        # TUILE de figure (2026-10-29 : le Spawnling, le Golem de Delthrak), pas une tête dans un anneau. Le cadre EST la
        # tuile, bord à bord : gardée telle quelle, en couleurs, sans détourage. Une tuile d'une case donne un carré EXACT
        # (le rendu d'un cadre de 20 pt tombe parfois à un pixel près) ; une tuile de deux cases, ramenée à l'horizontale
        # comme un ovale, donne un 2:1 où elle est CENTRÉE sans déformation (une tuile imprimée n'est jamais tout à fait 2:1).
        rotation = int(entree.get('rotation', 0)) % 360
        if rotation:
            img = img.rotate(-rotation, expand=True)
        img = img.convert('RGBA')
        if img.width < 1.5 * img.height:
            return img.resize((COTE_PX, COTE_PX), Image.LANCZOS)
        largeur, hauteur = 2 * COTE_PX, COTE_PX
        echelle = min(largeur / img.width, hauteur / img.height)
        dessin = img.resize((max(1, round(img.width * echelle)), max(1, round(img.height * echelle))), Image.LANCZOS)
        toile = Image.new('RGBA', (largeur, hauteur), (0, 0, 0, 0))
        toile.paste(dessin, ((largeur - dessin.width) // 2, (hauteur - dessin.height) // 2))
        return toile
    rotation = int(entree.get('rotation', 0)) % 360
    if rotation:
        img = img.rotate(-rotation, expand=True)   # PIL tourne à gauche ; la rotation du manifeste est horaire
    alpha = alpha_du_trait(img, generique=not entree.get('couleur'), seuil=entree.get('seuil'))
    if entree.get('poussiere'):
        alpha = retirer_poussiere(alpha)
    if entree.get('couleur'):
        rgba = mettre_en_couleur(img, alpha)
    else:
        rgba = mettre_en_creme(alpha)
    rogne = rogner(rgba)
    if rogne is None:
        raise ValueError('aucun trait détecté dans le cadre')
    cadre_final = ajuster_emprise(rogne, entree.get('emprise'))
    return mettre_a_taille(cadre_final)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--livrets', required=True)
    ap.add_argument('--manifeste', required=True)
    ap.add_argument('--sortie', required=True)
    ap.add_argument('--seul', default=None, help='type/cle : n’extraire que ce symbole')
    args = ap.parse_args()

    manifeste = charger_manifeste(args.manifeste)
    extraits, absents, erreurs = [], [], []
    ouverts = {}
    for entree in manifeste['symboles']:
        cle_complete = f"{entree['type']}/{entree['cle']}"
        if args.seul and cle_complete != args.seul:
            continue
        if 'absent' in entree:
            absents.append(f"{cle_complete} ({entree['absent']})")
            continue
        try:
            chemin_pdf = os.path.join(args.livrets, entree['livret'])
            if chemin_pdf not in ouverts:
                ouverts[chemin_pdf] = pymupdf.open(chemin_pdf)
            image = extraire_un(ouverts[chemin_pdf], entree, args.livrets)
            dossier = os.path.join(args.sortie, entree['type'])
            os.makedirs(dossier, exist_ok=True)
            image.save(os.path.join(dossier, f"{entree['cle']}.webp"), 'WEBP', lossless=True)
            extraits.append(cle_complete)
        except Exception as exc:  # une erreur ne bloque pas les autres symboles
            erreurs.append(f'{cle_complete} : {exc}')

    print(f"{len(extraits)} extraits, {len(absents)} absents déclarés, {len(erreurs)} erreurs")
    for a in absents:
        print(f'  absent : {a}')
    for e in erreurs:
        print(f'  ERREUR : {e}')
    return 1 if erreurs else 0


if __name__ == '__main__':
    sys.exit(main())
