#!/bin/sh
# Extrait les SYMBOLES des livrets officiels (cartes de quête Hasbro) vers public/images/livrets/.
# Décision de René (2026-10-24) : ces symboles entrent dans le dépôt ; les PDF et les pages entières restent
# hors dépôt. Détail, convention et repli : docs/regles/medias-images-et-audio.md et
# image-tools/symboles-livrets/README.md.
#
#   ./image-tools/symboles-livrets.sh                       # PDF dans ../heroquest-livrets, sortie public/images/livrets
#   CHEMIN_LIVRETS=/chemin/livrets ./image-tools/symboles-livrets.sh
#   SORTIE=/autre/dossier ./image-tools/symboles-livrets.sh
#   ./image-tools/symboles-livrets.sh --seul mobiliers/table   # un seul symbole
#
# Idempotent : chaque symbole est recalculé à partir du PDF et écrasé à l'identique.
# Le conteneur est jetable (python:3.12-slim) ; les PDF y sont montés EN LECTURE SEULE et le script
# tourne hors de ce dossier, sous `python -I` (les livrets sont des fichiers téléchargés, non sûrs).
set -eu

RACINE=$(cd "$(dirname "$0")/.." && pwd)
LIVRETS=${CHEMIN_LIVRETS:-"$RACINE/../heroquest-livrets"}
SORTIE=${SORTIE:-"$RACINE/public/images/livrets"}

if [ ! -d "$LIVRETS" ]; then
    echo "Dossier des livrets introuvable : $LIVRETS (définir CHEMIN_LIVRETS)" >&2
    exit 2
fi
mkdir -p "$SORTIE"
LIVRETS=$(cd "$LIVRETS" && pwd)
SORTIE=$(cd "$SORTIE" && pwd)

# Argument optionnel --seul TYPE/CLE, transmis tel quel au script.
ARG_SEUL=""
if [ "${1:-}" = "--seul" ] && [ -n "${2:-}" ]; then
    ARG_SEUL="--seul $2"
fi

docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp \
    -v "$LIVRETS:/livrets:ro" \
    -v "$RACINE/image-tools/symboles-livrets:/outils:ro" \
    -v "$SORTIE:/sortie" \
    python:3.12-slim sh -c "pip install -q --target /tmp/pylib pymupdf pillow >/dev/null 2>&1 \
        && python -I /outils/extraire.py --livrets /livrets --manifeste /outils/manifeste.json --sortie /sortie $ARG_SEUL"
