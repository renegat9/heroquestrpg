"""
Optimise les illustrations : PNG 1024×1024 → WebP 640 max, qualité 82.

POURQUOI. Les images générées (Gemini) pèsent ~1,3 Mo pièce et sont affichées
sur quelques dizaines de pixels. Trois d'entre elles suffisaient à faire
télécharger 4 Mo à l'écran de table — la machine la plus faible de la tablée
(signalé en partie réelle, 2026-08-07). Mesuré sur le lot complet :
256,8 Mo → 4,4 Mo, soit 98 % de moins.

Le PNG d'origine est CONSERVÉ : `BibliothequeImages::url()` préfère le jumeau
`.webp` quand il existe et retombe sur le PNG sinon. La conversion est donc
réversible, et peut rester partielle sans rien casser.

Idempotent : un `.webp` déjà présent est laissé tel quel — relancer après
`images:generer` ne convertit que les nouveautés.

USAGE (aucun outil requis sur l'hôte) :

    docker run --rm -u $(id -u):$(id -g) -e HOME=/tmp \
      -v "$PWD/public/images:/img" \
      -v "$PWD/audio-tools/optimiser-images.py:/opt.py:ro" \
      python:3.12-slim \
      sh -c "pip install --quiet --root-user-action=ignore Pillow >/dev/null 2>&1; python /opt.py"

À REFAIRE après chaque `php artisan images:generer` (les jobs écrivent du PNG).
Le durable serait d'ajouter GD+WebP à l'image applicative et de convertir à la
génération — le conteneur n'a aujourd'hui ni GD ni Imagick.
"""
from PIL import Image
import glob, os
fs = sorted(glob.glob('/img/**/*.png', recursive=True))
avant = sum(os.path.getsize(f) for f in fs)
faits = 0
for f in fs:
    out = f[:-4] + '.webp'
    if os.path.exists(out):
        continue
    try:
        im = Image.open(f).convert('RGB')
        im.thumbnail((640, 640), Image.LANCZOS)
        im.save(out, 'WEBP', quality=82, method=6)
        faits += 1
    except Exception as e:
        print('ECHEC', os.path.basename(f), e, flush=True)
apres = sum(os.path.getsize(f[:-4]+'.webp') for f in fs if os.path.exists(f[:-4]+'.webp'))
print('RES convertis', faits, '/', len(fs), flush=True)
print('RES PNG  :', round(avant/1048576,1), 'Mo', flush=True)
print('RES WebP :', round(apres/1048576,1), 'Mo', flush=True)
print('RES gain :', round(100 - apres*100/avant), '%', flush=True)
