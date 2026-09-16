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
# INCRÉMENTAL : une vignette n'est refaite que si sa source est plus récente.
#   ./docs/livret/vignettes.sh          # puis python3 docs/livret/generer.py …
#   ./docs/livret/vignettes.sh --tout   # tout refaire
set -eu
cd "$(dirname "$0")/../.."
TOUT=0; [ "${1:-}" = "--tout" ] && TOUT=1

docker run --rm -v "$PWD:/w" -w /w -e TOUT="$TOUT" -e HUID="$(id -u)" -e HGID="$(id -g)" alpine:3.20 sh -c '
apk add --no-cache libwebp-tools su-exec >/dev/null && su-exec "$HUID:$HGID" sh -c "
faites=0
vignette() { # source cible largeur qualite
  if [ \"\$TOUT\" = 1 ] || [ ! -e \"\$2\" ] || [ \"\$1\" -nt \"\$2\" ]; then
    cwebp -q \"\$4\" -resize \"\$3\" 0 -quiet \"\$1\" -o \"\$2\" && faites=\$((faites+1))
  fi
}
for d in monstres objets sorts pieges mobiliers terrains epreuves portes leviers; do
  mkdir -p docs/livret/img/catalogue/\$d
  for f in public/images/catalogue/\$d/*.png; do
    [ -e \"\$f\" ] || continue
    vignette \"\$f\" docs/livret/img/catalogue/\$d/\$(basename \"\$f\" .png).webp 200 80
  done
done
mkdir -p docs/livret/img/classes
for f in public/images/catalogue/classes/*.png; do
  [ -e \"\$f\" ] || continue
  vignette \"\$f\" docs/livret/img/classes/\$(basename \"\$f\" .png).webp 420 82
done
vignette public/images/catalogue/monstres/12-liche.png docs/livret/img/couverture.webp 1100 84
echo \"vignettes refaites : \$faites\"
"'
