#!/bin/bash
# Vignettes D'IMPRESSION du livret, tirées de public/images.
#
# ⚠ Pourquoi elles existent : Chromium embarque dans le PDF le bitmap DÉCODÉ, pas
# le fichier. Les sources 1024×1024 donnaient un PDF de 104 Mo ; à 200 px (420
# pour les portraits de classe) il en fait 17.
#
# ⚠ Pourquoi ce script existe (2026-09-16) : cette étape n'était écrite nulle
# part. Elle avait été lancée une fois, à la main, et tout objet illustré
# ensuite restait absent du livret sans que rien ne le signale — 21 objets
# l'étaient déjà quand les 26 artefacts des cartes officielles ont reçu leur
# image.
#
# SOURCE (2026-10-03) : le dépôt ne suit que les .webp de public/images, les PNG
# d'origine restent sur le serveur qui les a générés. Chaque image part donc de
# son .webp VERSIONNÉ — présent sur toute copie du dépôt — et du PNG d'origine
# quand il est là (meilleure qualité). Partir des seuls PNG laissait un livret
# sans aucune illustration sur une machine qui n'a que le dépôt.
#
# INCRÉMENTAL : une vignette n'est refaite que si sa source est plus récente.
#   ./docs/livret/vignettes.sh          # puis python3 docs/livret/generer.py …
#   ./docs/livret/vignettes.sh --tout   # tout refaire
set -eu
cd "$(dirname "$0")/../.."
TOUT=0; [ "${1:-}" = "--tout" ] && TOUT=1

docker run --rm -v "$PWD:/w" -w /w -e TOUT="$TOUT" -e HUID="$(id -u)" -e HGID="$(id -g)" alpine:3.20 sh -c '
apk add --no-cache libwebp-tools su-exec >/dev/null && su-exec "$HUID:$HGID" sh -c "
faites=0
vignette() { # image(.webp versionné) cible largeur qualite — le PNG original prime quand il existe
  src=\"\$1\"; png=\"\${1%.webp}.png\"; [ -e \"\$png\" ] && src=\"\$png\"
  if [ \"\$TOUT\" = 1 ] || [ ! -e \"\$2\" ] || [ \"\$src\" -nt \"\$2\" ]; then
    cwebp -q \"\$4\" -resize \"\$3\" 0 -quiet \"\$src\" -o \"\$2\" && faites=\$((faites+1))
  fi
}
for d in monstres objets sorts pieges mobiliers terrains epreuves portes leviers; do
  mkdir -p docs/livret/img/catalogue/\$d
  for f in public/images/catalogue/\$d/*.webp; do
    [ -e \"\$f\" ] || continue
    vignette \"\$f\" docs/livret/img/catalogue/\$d/\$(basename \"\$f\") 200 80
  done
done
mkdir -p docs/livret/img/classes
for f in public/images/catalogue/classes/*.webp; do
  [ -e \"\$f\" ] || continue
  vignette \"\$f\" docs/livret/img/classes/\$(basename \"\$f\") 420 82
done
vignette public/images/catalogue/monstres/12-liche.webp docs/livret/img/couverture.webp 1100 84
echo \"vignettes refaites : \$faites\"
"'
