#!/bin/bash
# Client d'un JOUEUR sur les routes réelles de la manette (mêmes endpoints que
# le front Vue). Un pot de cookies par joueur = une session distincte.
#
#   hq.sh <slot> etat|menu|moi|pret|votes
#   hq.sh <slot> choix <option_id> [json_parametres]
#   hq.sh <slot> potion <inventaire_id>
#   hq.sh <slot> reaction <true|false> [cle_soin]
#   hq.sh <slot> vote <option_id>       ← déposer son bulletin (oui / non / …)
#
# AU HUB, entre deux quêtes (⚠ `menu` y renvoie toujours null : cette route ne
# sert que pendant un tour de quête) :
#   hq.sh <slot> marche                     ← ouvrir/consulter l'étal et les prix
#   hq.sh <slot> panier <json_achats>       ← poser son panier, ex.
#                                             '[{"objet_id":12,"quantite":1}]'
#   hq.sh <slot> confirmer                  ← confirmer (tous confirmés = achat)
#   hq.sh <slot> equiper <inventaire_id> [emplacement]
#   hq.sh <slot> donner <inventaire_id> <personnage_id>
#
# ⚠ `vote` a manqué au harnais jusqu'au 2026-08-15, et ça a coûté une campagne :
# « Quitter le donjon » ouvre un VOTE de groupe, le proposeur ne vote PAS
# d'office, et le vote tient 6 heures sans s'auto-résoudre. Un joueur sans ce
# verbe ne peut donc jamais conclure une quête — le groupe reste dans un donjon
# vide, à un bulletin près.
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
  pret)    req POST "/groupes/$GROUPE/pret" "{\"personnage_id\":$(cat "$(dirname "$0")/perso-$SLOT.txt"),\"pret\":true}" ;;
  votes)   req GET "/groupes/$GROUPE/votes" ;;
  marche)  # POST ouvre la phase si elle ne l'est pas ; GET la relit ensuite.
           req POST "/groupes/$GROUPE/marche" >/dev/null 2>&1
           req GET "/groupes/$GROUPE/marche" ;;
  panier)  req PUT "/groupes/$GROUPE/marche/panier" "{\"achats\":${2:-[]},\"ventes\":${3:-[]}}" ;;
  confirmer) req POST "/groupes/$GROUPE/marche/confirmation" ;;
  equiper) req POST "/groupes/$GROUPE/equipement" \
             "{\"personnage_id\":$(cat "$(dirname "$0")/perso-$SLOT.txt"),\"inventaire_id\":$2${3:+,\"emplacement\":\"$3\"}}" ;;
  # ⚠ La route veut TROIS identifiants : le donneur, la ligne, le receveur.
  # La première version n'en envoyait que deux, sous un mauvais nom
  # (`beneficiaire_id`), et le don échouait en validation — signalé par la
  # joueuse elfe, qui a dû passer par curl à la main (2026-08-17).
  donner)  req POST "/groupes/$GROUPE/dons" \
             "{\"personnage_id\":$(cat "$(dirname "$0")/perso-$SLOT.txt"),\"inventaire_id\":$2,\"vers_personnage_id\":$3}" ;;
  vote)    req POST "/groupes/$GROUPE/votes/bulletin" "{\"option_id\":\"$2\"}" ;;
  choix)   req POST "/groupes/$GROUPE/choix" "{\"option_id\":\"$2\"${3:+,\"parametres\":$3}}" ;;
  potion)  req POST "/groupes/$GROUPE/potions" "{\"inventaire_id\":$2}" ;;
  reaction) req POST "/groupes/$GROUPE/reaction" "{\"personnage_id\":$(cat "$(dirname "$0")/perso-$SLOT.txt"),\"accepte\":$2${3:+,\"soin\":\"$3\"}}" ;;
  *) echo "usage: hq.sh <slot> etat|menu|moi|pret|choix|potion|reaction|votes|vote|marche|panier|confirmer|equiper|donner" >&2; exit 2 ;;
esac
