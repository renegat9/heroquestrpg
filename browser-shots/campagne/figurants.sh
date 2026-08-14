#!/bin/bash
# Fait jouer les héros SANS agent : ils terminent simplement leur tour. Sans
# eux, le round ne s'achève pas et la phase des monstres — donc les réactions
# hors tour — n'arrive jamais.
#
#   ./figurants.sh 3 4        (les slots à faire patienter)
# Boucle BORNÉE : elle s'arrête d'elle-même, on ne laisse pas de guetteur.
set -u
D="$(cd "$(dirname "$0")" && pwd)"
for i in $(seq 1 90); do
  for slot in "$@"; do
    m=$("$D/hq.sh" "$slot" menu 2>/dev/null)
    echo "$m" | grep -q '"options":\[{' || continue
    "$D/hq.sh" "$slot" choix attendre >/dev/null 2>&1 && echo "slot $slot : tour passé"
  done
  sleep 12
done
