#!/bin/bash
# Client d'un JOUEUR sur les routes réelles de la manette (mêmes endpoints que
# le front Vue). Un pot de cookies par joueur = une session distincte.
#
#   hq.sh <slot> etat|menu|moi|pret|votes
#   hq.sh <slot> choix <option_id> [json_parametres]
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
#   hq.sh <slot> desequiper <inventaire_id>         ← retire une pièce (au hub) ;
#                                                      un objet équipé refuse le don tant qu'il l'est
#   hq.sh <slot> donner <inventaire_id> <personnage_id>
#
# ⚠ Le verbe `potion` a été RETIRÉ le 2026-09-02, parce que la route qu'il
# appelait n'existe plus : `POST /potions` est supprimée depuis le 2026-09-01,
# on boit par `/choix` comme tout le reste. Il rendait donc un 404 muet, et le
# README du harnais dit exactement ce que ça produit — pas une erreur, un
# joueur paralysé qui croit le serveur en panne. La forme est :
#
#   hq.sh <slot> choix utiliser_objet '{"cle":"objet:<inventaire_id>"}'
#
# la `cle` étant celle que l'entrée porte dans `parametres.objets[]` du menu —
# c'est elle la liste blanche, le serveur refuse tout ce qui n'y est pas.
#
# ⚠ `actionner_levier` (depuis 2026-09-06, un levier est posé dans TOUTE
# quête) n'a PAS de verbe dédié, et n'en a PAS besoin : comme `ouvrir_porte`,
# c'est une option PAR levier adjacent (id `actionner_levier_{x}_{y}`,
# uniquement au CONTACT) dont les `parametres` sont déjà fixés côté serveur
# dans le dernier menu envoyé — `ResolveurTour::resoudreActionnerLevier()` ne
# lit d'ailleurs jamais ceux que le client soumettrait. `choix` SANS troisième
# argument suffit :
#
#   hq.sh <slot> choix actionner_levier_12_7
#
# Jet de Body, RETENTABLE sans limite. `vue.py` liste les leviers visibles et
# leur difficulté ; `EtatGroupe::portes()` ne publie que le TYPE du verrou
# d'une porte (`verrou: "levier"`), jamais le `levier_id` qui l'ouvre — cet
# appariement n'existe nulle part côté API, à découvrir en essayant.
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
  # Compagnon animal OFFERT (groupe de moins de 4 héros, avant la 1re quête) : `etat` publie
  # `groupe.compagnon_offert.animaux` ; on le PREND (id du mercenaire) ou on le REFUSE.
  compagnon) if [ "${2:-}" = "refuser" ]; then req DELETE "/groupes/$GROUPE/compagnon-offert"
             else req POST "/groupes/$GROUPE/compagnon-offert" "{\"mercenaire_id\":$2,\"personnage_id\":$(cat "$(dirname "$0")/perso-$SLOT.txt")}"; fi ;;
  # Faveur Weapon Expert (reçue à la fin d'une quête) : `moi` publie `faveurs[].choix` au hub ;
  # on choisit le type d'arme (objet_id) avant de se déclarer prêt.
  arme-faveur) req PUT "/groupes/$GROUPE/faveurs/weapon-expert" "{\"personnage_id\":$(cat "$(dirname "$0")/perso-$SLOT.txt"),\"objet_id\":$2}" ;;
  # Hopekins Rest (Wizards of Morcar, René 2026-10-16) : au hub, CHAQUE héros choisit le lieu de sa faveur,
  # ou renonce. `moi` publie `visite_hopekins.lieux` (liste blanche : `disponible`, `motif`) ; `/pret` répond
  # 422 « peut visiter un lieu » tant que le héros n'a pas tranché. Ex. : `hq.sh 1 visite dewdrop_inn`,
  # `hq.sh 2 visite aucun`.
  visite) if [ "${2:-}" = "aucun" ]; then LIEU=null; else LIEU="\"${2:-}\""; fi
          req PUT "/groupes/$GROUPE/faveurs/lieu" "{\"personnage_id\":$(cat "$(dirname "$0")/perso-$SLOT.txt"),\"lieu\":$LIEU}" ;;
  marche)  # POST ouvre la phase si elle ne l'est pas ; GET la relit ensuite.
           req POST "/groupes/$GROUPE/marche" >/dev/null 2>&1
           req GET "/groupes/$GROUPE/marche" ;;
  panier)  req PUT "/groupes/$GROUPE/marche/panier" "{\"achats\":${2:-[]},\"ventes\":${3:-[]}}" ;;
  confirmer) req POST "/groupes/$GROUPE/marche/confirmation" ;;
  equiper) req POST "/groupes/$GROUPE/equipement" \
             "{\"personnage_id\":$(cat "$(dirname "$0")/perso-$SLOT.txt"),\"inventaire_id\":$2${3:+,\"emplacement\":\"$3\"}}" ;;
  desequiper) req DELETE "/groupes/$GROUPE/equipement" \
             "{\"personnage_id\":$(cat "$(dirname "$0")/perso-$SLOT.txt"),\"inventaire_id\":$2}" ;;
  # ⚠ La route veut TROIS identifiants : le donneur, la ligne, le receveur.
  # La première version n'en envoyait que deux, sous un mauvais nom
  # (`beneficiaire_id`), et le don échouait en validation — signalé par la
  # joueuse elfe, qui a dû passer par curl à la main (2026-08-17).
  donner)  req POST "/groupes/$GROUPE/dons" \
             "{\"personnage_id\":$(cat "$(dirname "$0")/perso-$SLOT.txt"),\"inventaire_id\":$2,\"vers_personnage_id\":$3}" ;;
  vote)    req POST "/groupes/$GROUPE/votes/bulletin" "{\"option_id\":\"$2\"}" ;;
  choix)   req POST "/groupes/$GROUPE/choix" "{\"option_id\":\"$2\"${3:+,\"parametres\":$3}}" ;;
  reaction) req POST "/groupes/$GROUPE/reaction" "{\"personnage_id\":$(cat "$(dirname "$0")/perso-$SLOT.txt"),\"accepte\":$2${3:+,\"soin\":\"$3\"}}" ;;
  *) echo "usage: hq.sh <slot> etat|menu|moi|pret|choix|reaction|votes|vote|marche|panier|confirmer|equiper|desequiper|donner|compagnon|arme-faveur|visite" >&2; exit 2 ;;
esac
