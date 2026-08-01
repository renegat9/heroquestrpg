#!/usr/bin/env bash
# Fin de quête : « Relever » en PREMIÈRE action du tour (sinon le créneau est
# déjà consommé et le moteur refuse), puis attaque du dernier monstre.
set -u
D="$(cd "$(dirname "$0")" && pwd)"

for tour in $(seq 1 "${1:-120}"); do
    agi=0
    for s in p2 p3 p1 p4; do
        ov=$("$D/hq" "$s" els '{"selector":".session-overlay"}' | grep -o '"n":[0-9]*' | cut -d: -f2)
        [ "${ov:-0}" != "0" ] && { echo "[$(date +%H:%M:%S)] $s : reconnexion"; "$D/reconnecter.sh" "$s"; }

        mine=$("$D/hq" "$s" els '{"selector":"div.turn-banner.mine"}' | grep -o '"n":[0-9]*' | cut -d: -f2)
        [ "${mine:-0}" = "0" ] && continue
        agi=1

        opts=$("$D/hq" "$s" els '{"selector":"button.choice"}')

        # 1) Relever un allié à terre — EN PREMIER, c'est une action de tour.
        idx=$(printf '%s' "$opts" | grep -oE '"i":[0-9]+,"texte":"[^"]*Relever[^"]*"' | head -1 | grep -oE '^"i":[0-9]+' | cut -d: -f2)
        if [ -n "${idx:-}" ]; then
            echo "[$(date +%H:%M:%S)] $s : RELÈVE un allié"
            "$D/hq" "$s" click "{\"selector\":\"button.choice\",\"n\":$idx,\"pause\":3500}" >/dev/null
            continue
        fi

        # 2) Attaquer
        idx=$(printf '%s' "$opts" | grep -oE '"i":[0-9]+,"texte":"[^"]*Attaquer[^"]*"' | head -1 | grep -oE '^"i":[0-9]+' | cut -d: -f2)
        if [ -n "${idx:-}" ]; then
            echo "[$(date +%H:%M:%S)] $s : ATTAQUE"
            "$D/hq" "$s" click "{\"selector\":\"button.choice\",\"n\":$idx,\"pause\":3000}" >/dev/null
        fi

        # 3) Clore le tour
        opts=$("$D/hq" "$s" els '{"selector":"button.choice"}')
        idx=$(printf '%s' "$opts" | grep -oE '"i":[0-9]+,"texte":"[^"]*(Terminer le tour|Attendre)[^"]*"' | head -1 | grep -oE '^"i":[0-9]+' | cut -d: -f2)
        [ -n "${idx:-}" ] && "$D/hq" "$s" click "{\"selector\":\"button.choice\",\"n\":$idx,\"pause\":2500}" >/dev/null
    done
    [ "$agi" = "0" ] && sleep 6
done
