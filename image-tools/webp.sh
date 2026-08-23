#!/bin/sh
# Produit le jumeau .webp de chaque PNG de public/images qui n'en a pas.
#
# POURQUOI : `BibliothequeImages::url()` sert le .webp quand il existe et
# retombe sur le PNG sinon. Les PNG générés font 1024×1024 pour ~1,3 Mo pièce ;
# le webp équivalent en fait ~50 Ko, soit 2 à 4 % — c'est ce qui a été mis en
# place après le signalement du 2026-08-07 (« la tablette prend vraiment du
# temps à charger »). Une image sans jumeau annule ce gain en silence : rien ne
# casse, l'écran est simplement 30 fois plus lourd. Après une session
# `images:generer`, RELANCER CE SCRIPT.
#
# Le PNG reste la SOURCE et n'est jamais touché : la conversion est répétable
# et réversible, et `--force` permet de rejouer une qualité différente.
#
# Qualité 85 : calibrée le 2026-08-22 contre les jumeaux déjà acceptés
# (21-41 Ko en 1024×1024). En dessous de 80 les aplats se dégradent, au-dessus
# de 90 le fichier triple sans gain visible à la taille d'affichage.
#
# PHP n'a ici NI gd NI imagick : la conversion ne peut pas être une commande
# artisan, elle passe par un conteneur jetable comme les outils audio.
#
# ⚠ `apk add` exige root, mais les fichiers doivent appartenir à l'hôte : on
# installe en root puis on redescend avec su-exec. Un simple `-u $(id -u)`
# échoue sur « Unable to lock database ».
#
#   docker run --rm -v "$PWD:/w" -w /w -e HUID="$(id -u)" -e HGID="$(id -g)" \
#     alpine:3.20 sh -c 'apk add --no-cache libwebp-tools su-exec >/dev/null \
#       && su-exec "$HUID:$HGID" ./image-tools/webp.sh'
#
#   ./image-tools/webp.sh --force    # réencode même les jumeaux existants
set -eu

QUALITE="${QUALITE:-85}"
FORCE=0
[ "${1:-}" = "--force" ] && FORCE=1

faits=0
sautes=0
avant=0
apres=0

for png in $(find public/images -type f -name '*.png' | sort); do
    webp="${png%.png}.webp"

    if [ -f "$webp" ] && [ "$FORCE" -eq 0 ]; then
        sautes=$((sautes + 1))
        continue
    fi

    cwebp -q "$QUALITE" -quiet "$png" -o "$webp"

    avant=$((avant + $(wc -c < "$png")))
    apres=$((apres + $(wc -c < "$webp")))
    faits=$((faits + 1))
done

if [ "$faits" -eq 0 ]; then
    echo "Aucun PNG sans jumeau : les $sautes images ont déjà leur webp."
    exit 0
fi

echo "$faits converti(s) en qualité $QUALITE, $sautes déjà à jour."
echo "  $((avant / 1048576)) Mo de PNG → $((apres / 1024)) Ko de webp"
