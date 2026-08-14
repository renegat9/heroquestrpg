#!/bin/bash
# Monte une partie complète : 4 comptes, 4 héros, groupe, table + battement,
# tout le monde prêt. Rend le code du groupe sur la sortie standard.
#
#   ./preparer.sh "nom du groupe" "thème" classe1:Nom1 classe2:Nom2 …
set -eu
D="$(cd "$(dirname "$0")" && pwd)"
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

R=$(P 1 POST /groupes "{\"nom\":\"$NOM\",\"personnage_id\":$(cat "$D/perso-1.txt"),\"theme\":\"$THEME\",\"longueur\":\"tres_courte\"}")
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
