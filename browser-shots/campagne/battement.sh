#!/bin/bash
# Battement de cœur de la TABLE : sans lui (TTL 30 s), aucune quête ne démarre.
# ⚠ À arrêter en fin de test — un guetteur oublié, c'est ce qu'on vient de nettoyer.
S="$(dirname "$0")"
while true; do
  X=$(grep -oP 'XSRF-TOKEN\s+\K[^\s]+' "$S/jar-table.txt" | tail -1 | sed 's/%3D/=/g')
  curl -s -b "$S/jar-table.txt" -c "$S/jar-table.txt" -X POST http://localhost/api/table/ping \
    -H 'Accept: application/json' -H "X-XSRF-TOKEN: $X" -o /dev/null
  sleep 12
done
