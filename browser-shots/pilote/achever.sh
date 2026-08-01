#!/usr/bin/env bash
# Achève le combat : chaque héros dont c'est le tour attaque s'il le peut,
# sinon fait UN SEUL pas vers la cible, sinon termine son tour.
# (La version précédente rejouait « Continuer à se déplacer » et oscillait.)
set -u
D="$(cd "$(dirname "$0")" && pwd)"
CX="${CIBLE_X:-29}"; CY="${CIBLE_Y:-16}"

fin_de_tour() {
    local s="$1" opts idx
    opts=$("$D/hq" "$s" els '{"selector":"button.choice"}')
    idx=$(printf '%s' "$opts" | grep -oE '"i":[0-9]+,"texte":"[^"]*(Terminer le tour|Attendre)[^"]*"' | head -1 | grep -oE '^"i":[0-9]+' | cut -d: -f2)
    [ -n "${idx:-}" ] && "$D/hq" "$s" click "{\"selector\":\"button.choice\",\"n\":$idx,\"pause\":2500}" >/dev/null
}

for tour in $(seq 1 "${1:-150}"); do
    agi=0
    for s in p3 p2 p1 p4; do
        ov=$("$D/hq" "$s" els '{"selector":".session-overlay"}' | grep -o '"n":[0-9]*' | cut -d: -f2)
        [ "${ov:-0}" != "0" ] && { echo "[$(date +%H:%M:%S)] $s : reconnexion"; "$D/reconnecter.sh" "$s"; }

        mine=$("$D/hq" "$s" els '{"selector":"div.turn-banner.mine"}' | grep -o '"n":[0-9]*' | cut -d: -f2)
        [ "${mine:-0}" = "0" ] && continue
        agi=1

        opts=$("$D/hq" "$s" els '{"selector":"button.choice"}')
        idx=$(printf '%s' "$opts" | grep -oE '"i":[0-9]+,"texte":"[^"]*Attaquer[^"]*"' | head -1 | grep -oE '^"i":[0-9]+' | cut -d: -f2)
        if [ -n "${idx:-}" ]; then
            echo "[$(date +%H:%M:%S)] $s : ATTAQUE"
            "$D/hq" "$s" click "{\"selector\":\"button.choice\",\"n\":$idx,\"pause\":3000}" >/dev/null
            fin_de_tour "$s"
            continue
        fi

        idx=$(printf '%s' "$opts" | grep -oE '"i":[0-9]+,"texte":"[^"]*(Se déplacer|Continuer à se déplacer)[^"]*"' | head -1 | grep -oE '^"i":[0-9]+' | cut -d: -f2)
        if [ -n "${idx:-}" ]; then
            "$D/hq" "$s" click "{\"selector\":\"button.choice\",\"n\":$idx,\"pause\":2500}" >/dev/null
            best=$("$D/hq" "$s" eval "{\"expr\":\"const a=[...document.querySelectorAll('.dg-cell.accessible')]; if(!a.length){'-1'}else{const all=[...document.querySelectorAll('.dg-cell')]; const c=a.map(n=>{const st=getComputedStyle(n);return{i:all.indexOf(n),x:parseInt(st.gridColumnStart)-1,y:parseInt(st.gridRowStart)-1}}); c.sort((p,q)=>(Math.abs(p.x-$CX)+Math.abs(p.y-$CY))-(Math.abs(q.x-$CX)+Math.abs(q.y-$CY))); String(c[0].i)}\"}" | grep -o '"valeur":"[0-9-]*"' | cut -d'"' -f4)
            if [ -n "${best:-}" ] && [ "$best" != "-1" ]; then
                echo "[$(date +%H:%M:%S)] $s : un pas vers ($CX,$CY)"
                "$D/hq" "$s" click "{\"selector\":\".dg-cell\",\"n\":$best,\"pause\":3000}" >/dev/null
            else
                "$D/hq" "$s" click '{"selector":".dep-ov","n":0,"pause":1200}' >/dev/null 2>&1
            fi
        fi
        # Un seul créneau de mouvement par tour : on clôt immédiatement.
        echo "[$(date +%H:%M:%S)] $s : fin de tour"
        fin_de_tour "$s"
    done
    [ "$agi" = "0" ] && sleep 6
done
