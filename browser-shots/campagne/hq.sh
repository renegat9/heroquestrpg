#!/bin/bash
# Client d'un JOUEUR sur les routes réelles de la manette (mêmes endpoints que
# le front Vue). Un pot de cookies par joueur = une session distincte.
#
#   hq.sh <slot> etat|menu|moi|pret
#   hq.sh <slot> choix <option_id> [json_parametres]
#   hq.sh <slot> potion <inventaire_id>
#   hq.sh <slot> reaction <true|false> [cle_soin]
set -u
BASE=http://localhost/api
SLOT="$1"; shift
JAR="$(dirname "$0")/jar-$SLOT.txt"
GROUPE="$(cat "$(dirname "$0")/groupe.txt" 2>/dev/null)"

xsrf() { grep -oP 'XSRF-TOKEN\s+\K[^\s]+' "$JAR" 2>/dev/null | tail -1 | sed 's/%3D/=/g'; }
req() { # méthode chemin [json]
  local m="$1" p="$2" body="${3:-}"
  curl -s -b "$JAR" -c "$JAR" -X "$m" "$BASE$p" \
    -H 'Accept: application/json' -H 'Content-Type: application/json' \
    -H "X-XSRF-TOKEN: $(xsrf)" ${body:+-d "$body"}
}

case "${1:-}" in
  etat)    req GET "/groupes/$GROUPE/etat" ;;
  menu)    req GET "/groupes/$GROUPE/menu" ;;
  moi)     req GET "/moi" ;;
  pret)    req POST "/groupes/$GROUPE/pret" '{"pret":true}' ;;
  choix)   req POST "/groupes/$GROUPE/choix" "{\"option_id\":\"$2\"${3:+,\"parametres\":$3}}" ;;
  potion)  req POST "/groupes/$GROUPE/potions" "{\"inventaire_id\":$2}" ;;
  reaction) req POST "/groupes/$GROUPE/reaction" "{\"personnage_id\":$(cat "$(dirname "$0")/perso-$SLOT.txt"),\"accepte\":$2${3:+,\"soin\":\"$3\"}}" ;;
  *) echo "usage: hq.sh <slot> etat|menu|moi|pret|choix|potion|reaction" >&2; exit 2 ;;
esac
