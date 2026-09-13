#!/usr/bin/env bash
# Sauvegarde COMPLÈTE d'une campagne : MariaDB + la bible Qdrant.
#
# ⚠ Les deux ENSEMBLE, jamais l'un sans l'autre (CLAUDE.md §Architecture) : une
# base restaurée sans sa bible rend le RAG muet, et une bible sans sa base est
# héritée par un groupe qui n'est pas le sien.
#
# Pourquoi ce script existe (René, 2026-09-12 : « il n'y a plus de chance qu'un
# agent vide la base de données réelle ? ») : la réponse était NON, et surtout il
# n'existait AUCUNE sauvegarde — 210 Mo de volume, aucun dump, aucun script.
# Durcir les commandes ne fait que réduire la probabilité d'un événement
# IRRÉVERSIBLE. Ceci le rend réversible ; c'est le seul correctif qui compte.
#
#   ./image-tools/sauvegarder.sh              # sauvegarde + rotation
#   ./image-tools/sauvegarder.sh --verifier   # relit la DERNIÈRE et compte les lignes
#   ./image-tools/sauvegarder.sh --lister
set -euo pipefail

cd "$(dirname "$0")/.."
RACINE="$PWD"
DEST="$RACINE/backups"
GARDER=10
RESEAU="heroquestrpg_game"

# ⚠ Jamais `source .env` : il déclare UID, qui est en lecture seule dans bash et
# fait échouer le script sur une ligne qui n'a rien à voir avec la sauvegarde.
env_de() { grep -E "^$1=" "$RACINE/.env" | head -1 | cut -d= -f2- | tr -d '"'"'"'\r'; }

BASE="$(env_de DB_DATABASE)"
MDP_ROOT="$(env_de DB_ROOT_PASSWORD)"

rouge() { printf '\033[31m%s\033[0m\n' "$*" >&2; }
vert()  { printf '\033[32m%s\033[0m\n' "$*"; }
info()  { printf '  %s\n' "$*"; }

compter() {
  docker compose exec -T mariadb sh -c \
    "MYSQL_PWD='$MDP_ROOT' mariadb -uroot -N -B -e \
     \"SELECT (SELECT COUNT(*) FROM groupes), (SELECT COUNT(*) FROM personnages), (SELECT COUNT(*) FROM joueurs)\" '$BASE'"
}

# ---------------------------------------------------------------- --lister
if [ "${1:-}" = "--lister" ]; then
  [ -d "$DEST" ] || { rouge "Aucune sauvegarde : $DEST n'existe pas."; exit 1; }
  for d in "$DEST"/*/; do
    [ -d "$d" ] || continue
    printf '%-22s %8s  %s\n' "$(basename "$d")" \
      "$(du -sh "$d" 2>/dev/null | cut -f1)" \
      "$(head -1 "$d/MANIFEST.txt" 2>/dev/null || echo '⚠ sans manifeste')"
  done
  exit 0
fi

# ------------------------------------------------------------- --verifier
# ⚠ Une sauvegarde jamais relue n'est PAS une sauvegarde. On restaure dans un
# MariaDB JETABLE — jamais dans le conteneur du jeu, ce serait remplacer ce
# qu'on cherche à protéger par une copie qu'on n'a pas encore vérifiée.
if [ "${1:-}" = "--verifier" ]; then
  DERNIERE="$(ls -1d "$DEST"/*/ 2>/dev/null | sort | tail -1 || true)"
  [ -n "$DERNIERE" ] || { rouge "Aucune sauvegarde à vérifier."; exit 1; }
  info "Vérification de $(basename "$DERNIERE") dans un MariaDB jetable…"

  NOM="hq-verif-$$"
  docker run -d --rm --name "$NOM" -e MARIADB_ROOT_PASSWORD=verif \
    -e MARIADB_DATABASE="$BASE" mariadb:11.4 >/dev/null
  trap 'docker kill "$NOM" >/dev/null 2>&1 || true' EXIT

  for _ in $(seq 1 60); do
    docker exec "$NOM" mariadb-admin --protocol=tcp -uroot -pverif ping >/dev/null 2>&1 && break
    sleep 2
  done

  gzip -dc "$DERNIERE/mariadb.sql.gz" | docker exec -i "$NOM" \
    sh -c "MYSQL_PWD=verif mariadb -uroot '$BASE'"

  LU="$(docker exec "$NOM" sh -c \
     "MYSQL_PWD=verif mariadb -uroot -N -B -e \
      \"SELECT (SELECT COUNT(*) FROM groupes), (SELECT COUNT(*) FROM personnages), (SELECT COUNT(*) FROM joueurs)\" '$BASE'")"
  ATTENDU="$(grep -m1 '^lignes=' "$DERNIERE/MANIFEST.txt" | cut -d= -f2)"

  info "attendu : $ATTENDU"
  info "relu    : $LU"
  [ "$LU" = "$ATTENDU" ] || { rouge "✗ La sauvegarde NE SE RELIT PAS à l'identique."; exit 1; }
  vert "✓ Sauvegarde relue et conforme ($(basename "$DERNIERE"))."
  exit 0
fi

# ------------------------------------------------------------ sauvegarder
docker compose ps --status running --services 2>/dev/null | grep -qx mariadb \
  || { rouge "Le conteneur mariadb ne tourne pas — 'docker compose up -d' d'abord."; exit 1; }

HORO="$(date +%Y-%m-%d-%H%M)"
DOSSIER="$DEST/$HORO"
mkdir -p "$DOSSIER"

info "MariaDB → mariadb.sql.gz"
# --single-transaction : dump cohérent SANS verrouiller la base, donc sans
# interrompre une partie en cours. --routines/--events/--triggers : le schéma
# complet, pas seulement les lignes.
docker compose exec -T mariadb sh -c \
  "MYSQL_PWD='$MDP_ROOT' mariadb-dump -uroot --single-transaction --routines --events --triggers '$BASE'" \
  | gzip -9 > "$DOSSIER/mariadb.sql.gz"

info "Qdrant → qdrant-storage.snapshot"
# Snapshot par l'API plutôt qu'un tar du volume : un tar pris pendant que Qdrant
# écrit peut être incohérent, le snapshot est atomique côté serveur.
SNAP="$(docker run --rm --network "$RESEAU" curlimages/curl:latest \
        -s -X POST http://qdrant:6333/snapshots | sed -n 's/.*"name":"\([^"]*\)".*/\1/p')"
if [ -n "$SNAP" ]; then
  # ⚠ Par STDOUT, jamais par un montage : `curlimages/curl` tourne en uid 100 et
  # ne peut pas écrire dans un dossier de l'hôte. L'échec d'écriture faisait
  # sortir le script sur `set -e` sans un mot, en laissant un dump orphelin.
  docker run --rm --network "$RESEAU" curlimages/curl:latest \
    -s "http://qdrant:6333/snapshots/$SNAP" > "$DOSSIER/qdrant-storage.snapshot"
  # On retire le snapshot du volume : il y vivrait en double, et le volume est
  # justement ce qu'on sauvegarde.
  docker run --rm --network "$RESEAU" curlimages/curl:latest \
    -s -X DELETE "http://qdrant:6333/snapshots/$SNAP" >/dev/null
else
  rouge "⚠ Snapshot Qdrant ÉCHOUÉ — la bible n'est PAS sauvegardée."
  echo "qdrant=ECHEC" >> "$DOSSIER/MANIFEST.txt"
fi

LIGNES="$(compter)"
{
  echo "$HORO — groupes/personnages/joueurs : $(echo "$LIGNES" | tr '\t' '/')"
  echo "lignes=$LIGNES"
  echo "base=$BASE"
  echo "git=$(git -C "$RACINE" rev-parse --short HEAD 2>/dev/null || echo '?')"
} > "$DOSSIER/MANIFEST.txt"

# Rotation : on garde les $GARDER plus récentes.
ls -1d "$DEST"/*/ 2>/dev/null | sort | head -n -"$GARDER" | while read -r vieux; do
  info "rotation : suppression de $(basename "$vieux")"
  rm -rf "$vieux"
done

vert "✓ $DOSSIER  ($(du -sh "$DOSSIER" | cut -f1))"
info "$(head -1 "$DOSSIER/MANIFEST.txt")"
info "Relire avec : ./image-tools/sauvegarder.sh --verifier"
