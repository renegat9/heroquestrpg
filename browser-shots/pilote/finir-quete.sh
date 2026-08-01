#!/usr/bin/env bash
# Termine le combat en pilotant les 4 manettes par l'INTERFACE RÉELLE.
# Priorité : attaquer > s'approcher de la cible > terminer le tour.
# Gère l'overlay « session expirée » (verdict §2.15) qui avale les clics.
set -u
D="$(cd "$(dirname "$0")" && pwd)"
CIBLE_X="${CIBLE_X:-29}"
CIBLE_Y="${CIBLE_Y:-16}"

# Se rapproche de (CIBLE_X, CIBLE_Y) : ouvre la feuille et clique la case
# accessible qui minimise la distance de Manhattan à la cible.
approcher() {
    local s="$1" idx_dep="$2"
    "$D/hq" "$s" click "{\"selector\":\"button.choice\",\"n\":$idx_dep,\"pause\":2500}" >/dev/null
    local meilleur
    meilleur=$("$D/hq" "$s" eval "{\"expr\":\"const a=[...document.querySelectorAll('.dg-cell.accessible')]; if(!a.length){'-1'}else{const c=a.map(n=>{const st=getComputedStyle(n);return{i:[...document.querySelectorAll('.dg-cell')].indexOf(n),x:parseInt(st.gridColumnStart)-1,y:parseInt(st.gridRowStart)-1}}); c.sort((p,q)=>(Math.abs(p.x-$CIBLE_X)+Math.abs(p.y-$CIBLE_Y))-(Math.abs(q.x-$CIBLE_X)+Math.abs(q.y-$CIBLE_Y))); String(c[0].i)}\"}" | grep -o '"valeur":"[0-9-]*"' | cut -d'"' -f4)
    if [ -n "${meilleur:-}" ] && [ "$meilleur" != "-1" ]; then
        echo "[$(date +%H:%M:%S)] $s : approche (case $meilleur)"
        "$D/hq" "$s" click "{\"selector\":\".dg-cell\",\"n\":$meilleur,\"pause\":3000}" >/dev/null
        return 0
    fi
    echo "[$(date +%H:%M:%S)] $s : aucune case accessible"
    "$D/hq" "$s" click '{"selector":".dep-ov","n":0,"pause":1200}' >/dev/null 2>&1
    return 1
}

jouer_si_mon_tour() {
    local s="$1"
    local ov
    ov=$("$D/hq" "$s" els '{"selector":".session-overlay"}' | grep -o '"n":[0-9]*' | cut -d: -f2)
    if [ "${ov:-0}" != "0" ]; then
        echo "[$(date +%H:%M:%S)] $s : SESSION EXPIRÉE — reconnexion"
        "$D/reconnecter.sh" "$s"
    fi

    local mine
    mine=$("$D/hq" "$s" els '{"selector":"div.turn-banner.mine"}' | grep -o '"n":[0-9]*' | cut -d: -f2)
    [ "${mine:-0}" = "0" ] && return 1

    local opts idx
    opts=$("$D/hq" "$s" els '{"selector":"button.choice"}')

    idx=$(printf '%s' "$opts" | grep -oE '"i":[0-9]+,"texte":"[^"]*Attaquer[^"]*"' | head -1 | grep -oE '^"i":[0-9]+' | cut -d: -f2)
    if [ -n "${idx:-}" ]; then
        echo "[$(date +%H:%M:%S)] $s : ATTAQUE"
        "$D/hq" "$s" click "{\"selector\":\"button.choice\",\"n\":$idx,\"pause\":3000}" >/dev/null
        return 0
    fi

    idx=$(printf '%s' "$opts" | grep -oE '"i":[0-9]+,"texte":"[^"]*(Se déplacer|Continuer à se déplacer)[^"]*"' | head -1 | grep -oE '^"i":[0-9]+' | cut -d: -f2)
    if [ -n "${idx:-}" ]; then
        approcher "$s" "$idx" && return 0
    fi

    idx=$(printf '%s' "$opts" | grep -oE '"i":[0-9]+,"texte":"[^"]*(Terminer le tour|Attendre)[^"]*"' | head -1 | grep -oE '^"i":[0-9]+' | cut -d: -f2)
    if [ -n "${idx:-}" ]; then
        echo "[$(date +%H:%M:%S)] $s : fin de tour"
        "$D/hq" "$s" click "{\"selector\":\"button.choice\",\"n\":$idx,\"pause\":2500}" >/dev/null
        return 0
    fi
    return 1
}

for tour in $(seq 1 "${1:-200}"); do
    agi=0
    for s in p2 p3 p1 p4; do   # les combattants sains d'abord
        jouer_si_mon_tour "$s" && agi=1
    done
    [ "$agi" = "0" ] && sleep 8
done
