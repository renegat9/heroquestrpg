#!/usr/bin/env python3
"""Extrait les SYMBOLES des livrets officiels Hasbro vers public/images/livrets/{type}/{cle}.webp.

Entrée : manifeste.json (coordonnées seulement — aucun pixel de livret n'entre dans le dépôt).
Sortie : un .webp par symbole extrait, fond TRANSPARENT, trait recoloré en crème avec un liseré
sombre pour rester lisible sur le fond sombre de la carte, rogné au plus près, ramené à la
proportion de l'emprise (un meuble 3×1 garde son 3:1) et à 256 px sur le grand côté.

Lancé par ../symboles-livrets.sh dans un conteneur jetable python:3.12-slim (PyMuPDF + Pillow),
le dossier des PDF monté EN LECTURE SEULE, ce script hors du dossier des données, `python -I`.

Usage : python -I extraire.py --livrets DOSSIER --manifeste FICHIER --sortie DOSSIER [--seul TYPE/CLE]
"""

import argparse
import io
import json
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


def alpha_du_trait(img, generique=False):
    """Force du trait, de 0 (fond) à 255 (trait) : distance au fond le plus proche."""
    refs = references_fond(img, generique)
    raw = img.tobytes()
    sortie = bytearray(len(raw) // 3)
    for i in range(0, len(raw), 3):
        r, g, b = raw[i], raw[i + 1], raw[i + 2]
        d = min(((r - c[0]) ** 2 + (g - c[1]) ** 2 + (b - c[2]) ** 2) ** 0.5 for c in refs)
        if d <= SEUIL_BAS:
            a = 0
        elif d >= SEUIL_HAUT:
            a = 255
        else:
            a = round((d - SEUIL_BAS) / (SEUIL_HAUT - SEUIL_BAS) * 255)
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


def mettre_a_taille(rgba):
    long = max(rgba.size)
    echelle = COTE_PX / long
    cible = (max(1, round(rgba.width * echelle)), max(1, round(rgba.height * echelle)))
    return rgba.resize(cible, Image.LANCZOS)


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
    rotation = int(entree.get('rotation', 0)) % 360
    if rotation:
        img = img.rotate(-rotation, expand=True)   # PIL tourne à gauche ; la rotation du manifeste est horaire
    alpha = alpha_du_trait(img, generique=not entree.get('couleur'))
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
