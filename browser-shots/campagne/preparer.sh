#!/bin/bash
# Monte une partie complète : 4 comptes, 4 héros, groupe, table + battement,
# tout le monde prêt. Rend le code du groupe sur la sortie standard.
#
#   ./preparer.sh "nom du groupe" "thème" classe1:Nom1 classe2:Nom2 …
#
# LONGUEUR (variable d'environnement) : tres_courte (1 quête, défaut) · courte
# (3-5) · normale (7-10) · longue · tres_longue. Une campagne de plusieurs
# quêtes est le SEUL moyen d'éprouver le marché : la première ouvre toujours
# avec 0 or, et rien n'y est achetable.
set -eu
D="$(cd "$(dirname "$0")" && pwd)"

# GARDE — UN SEUL BATTEMENT DE TABLE À LA FOIS (verdict Morcar, 2026-10-09, §6). Un battement
# encore vivant écrit dans le MÊME jar-table.txt que celui qu'on lance : la table passe alors à
# « narrateur inactif » pendant la partie (c'est ce qui s'est produit vers 20:05, un battement
# oublié depuis le 2026-10-05 ; son PID avait été perdu, `battement.pid` ayant été réécrit).
# On REFUSE donc ici, AVANT toute écriture (ni compte, ni table, ni sauvegarde), en nommant le PID.
pids_battement=$(pgrep -f "$D/battement.sh" | paste -sd' ' - || true)
if [ -n "$pids_battement" ]; then
  echo "✗ Un battement de table tourne déjà (PID $pids_battement)." >&2
  echo "  Il écrit dans jar-table.txt : la table passerait « narrateur inactif »." >&2
  echo "  → ./nettoyer.sh arrête le battement de la campagne précédente, puis relance preparer.sh." >&2
  echo "  → Ou tue ce PID toi-même (kill $(echo "$pids_battement" | head -1)) si tu sais ce qu'il fait." >&2
  exit 1
fi

# ⚠ SAUVEGARDE D'ABORD (René, 2026-09-12). Ce script écrit dans la VRAIE base :
# c'est exactement le moment où l'on veut un filet. Une campagne de harnais mal
# nettoyée est un ennui ; une campagne réelle perdue est irrattrapable.
# Non bloquant : si la sauvegarde échoue, on le DIT et on continue — un harnais
# qui refuse de démarrer parce que le disque est plein n'aide personne.
if [ -x "$D/../../image-tools/sauvegarder.sh" ]; then
  "$D/../../image-tools/sauvegarder.sh" >/dev/null 2>&1 \
    && echo "  ✓ sauvegarde prise avant la campagne" >&2 \
    || echo "  ⚠ SAUVEGARDE ÉCHOUÉE — on continue, mais sans filet" >&2
fi

NOM="$1"; THEME="$2"; shift 2
SUF=$(date +%H%M%S)

X() { grep -oP 'XSRF-TOKEN\s+\K[^\s]+' "$D/jar-$1.txt" | tail -1 | sed 's/%3D/=/g'; }
P() { curl -s -b "$D/jar-$1.txt" -c "$D/jar-$1.txt" -X "$2" "http://localhost/api$3" \
        -H 'Accept: application/json' -H 'Content-Type: application/json' \
        -H "X-XSRF-TOKEN: $(X "$1")" ${4:+-d "$4"}; }

slot=0
for spec in "$@"; do
  slot=$((slot+1)); CL="${spec%%:*}"; NH="${spec##*:}"
  rm -f "$D/jar-$slot.txt"
  curl -s -c "$D/jar-$slot.txt" http://localhost/ >/dev/null
  P "$slot" POST /inscription "{\"pseudo\":\"$NH\",\"identifiant\":\"${CL:0:3}$slot$SUF\"}" >/dev/null
  R=$(P "$slot" POST /personnages "{\"nom\":\"$NH\",\"classe\":\"$CL\"}")
  echo "$R" | python3 -c "import json,sys; print(json.load(sys.stdin)['personnage']['id'])" > "$D/perso-$slot.txt"
  echo "  slot $slot : $NH ($CL) id=$(cat "$D/perso-$slot.txt")" >&2
done

# BOITES (variable d'environnement, 2026-10-09) : impose le bestiaire en mode
# MANUEL — liste de boîtes séparées par des virgules, p. ex.
# BOITES=wizards_of_morcar ou BOITES=jungles_delthrak. Absente : rotation
# automatique, comme avant. Sert à éprouver UN thème précis.
BOITES_JSON=""
if [ -n "${BOITES:-}" ]; then
  BOITES_JSON=",\"bestiaire_boites\":[\"$(echo "$BOITES" | sed 's/,/\",\"/g')\"]"
fi
R=$(P 1 POST /groupes "{\"nom\":\"$NOM\",\"personnage_id\":$(cat "$D/perso-1.txt"),\"theme\":\"$THEME\",\"longueur\":\"${LONGUEUR:-tres_courte}\"$BOITES_JSON}")
CODE=$(echo "$R" | python3 -c "import json,sys; print(json.load(sys.stdin)['groupe']['identifiant'])")
echo "$CODE" > "$D/groupe.txt"

for s in $(seq 2 $slot); do P "$s" POST "/groupes/$CODE/joueurs" "{\"personnage_id\":$(cat "$D/perso-$s.txt")}" >/dev/null; done

# Table + battement de cœur : sans lui (TTL 30 s), aucune quête ne démarre.
rm -f "$D/jar-table.txt"; curl -s -c "$D/jar-table.txt" http://localhost/ >/dev/null
XT=$(grep -oP 'XSRF-TOKEN\s+\K[^\s]+' "$D/jar-table.txt" | tail -1 | sed 's/%3D/=/g')
curl -s -b "$D/jar-table.txt" -c "$D/jar-table.txt" -X POST http://localhost/api/table \
  -H 'Accept: application/json' -H 'Content-Type: application/json' -H "X-XSRF-TOKEN: $XT" \
  -d "{\"code\":\"$CODE\"}" >/dev/null
nohup "$D/battement.sh" >/dev/null 2>&1 & echo $! > "$D/battement.pid"
sleep 3
for s in $(seq 1 $slot); do P "$s" POST "/groupes/$CODE/pret" "{\"personnage_id\":$(cat "$D/perso-$s.txt"),\"pret\":true}" >/dev/null; done
echo "$CODE"
