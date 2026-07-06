#!/usr/bin/env bash
# ============================================================
# maj-ip-lan.sh — Détecte l'IP LAN de la machine et la répercute
# dans .env (APP_URL, REVERB_HOST) + recrée les conteneurs concernés.
#
# À lancer après un changement de réseau (routeur, DHCP, autre Wi-Fi…)
# quand les téléphones n'arrivent plus à rejoindre la partie.
#
# Usage : ./scripts/maj-ip-lan.sh
# ============================================================
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

bold()  { printf "\033[1m%s\033[0m\n" "$*"; }
info()  { printf "\033[36m→ %s\033[0m\n" "$*"; }
ok()    { printf "\033[32m✔ %s\033[0m\n" "$*"; }
warn()  { printf "\033[33m⚠ %s\033[0m\n" "$*"; }
die()   { printf "\033[31m✘ %s\033[0m\n" "$*" >&2; exit 1; }

[ -f .env ] || die "Fichier .env introuvable — lance d'abord ./setup.sh."

# ── Détection de l'IP LAN ────────────────────────────────────
# Sous WSL2/Docker Desktop, l'IP réseau de la carte de la machine (le PC
# Windows) n'est PAS celle vue par `ip addr` côté WSL (adresse interne
# 172.x sans intérêt pour un téléphone sur le LAN) : on interroge Windows
# via ipconfig.exe (interop WSL) et on garde une IP privée plausible
# (192.168.x.x / 10.x.x.x), en excluant les plages virtuelles
# (172.16-31.x.x = Hyper-V/WSL, 169.254.x.x = APIPA).
detecter_ip() {
  if command -v ipconfig.exe >/dev/null 2>&1; then
    ipconfig.exe 2>/dev/null \
      | grep -oE 'IPv4 Address[. ]*: [0-9.]+' \
      | grep -oE '[0-9.]+$' \
      | grep -E '^(192\.168\.|10\.)' \
      | grep -vE '^169\.254\.' \
      | head -n1
    return
  fi
  # Linux natif (pas de WSL) : 1ère IP non-loopback/non-docker.
  ip -4 addr show scope global 2>/dev/null \
    | grep -oE 'inet [0-9.]+' | grep -oE '[0-9.]+' \
    | grep -vE '^(172\.1[7-9]|172\.2[0-9]|172\.3[0-1])\.' \
    | head -n1
}

IP="$(detecter_ip || true)"
[ -n "$IP" ] || die "Impossible de détecter une IP LAN (192.168.x.x / 10.x.x.x). Renseigne-la manuellement dans .env."

ACTUELLE="$(grep -E '^REVERB_HOST=' .env | cut -d= -f2- || true)"

bold "═══ IP LAN — HeroQuest MJ IA ═══"
info "IP détectée   : $IP"
info "IP en .env    : ${ACTUELLE:-<absente>}"
echo

if [ "$IP" = "$ACTUELLE" ]; then
  ok "Déjà à jour — rien à faire."
  exit 0
fi

# set_env CLE VALEUR — remplace (ou ajoute) CLE=… dans .env (cf. setup.sh)
set_env() {
  local key="$1" value="$2" escaped
  escaped=$(printf '%s' "$value" | sed -e 's/[\/&|]/\\&/g')
  if grep -q "^${key}=" .env; then
    sed -i.bak "s|^${key}=.*|${key}=${escaped}|" .env && rm -f .env.bak
  else
    echo "${key}=${value}" >> .env
  fi
}

set_env APP_URL "http://$IP"
set_env REVERB_HOST "$IP"
ok ".env mis à jour."

# ── Recréation des conteneurs concernés ──────────────────────
# (app/queue/queue-jeu/reverb doivent relire le nouveau .env ; web/nginx
# doit être redémarré ensuite car il cache l'IP upstream de app — sinon
# 502 après recréation de app tant que web n'est pas relancé.)
if docker compose ps --status running -q app >/dev/null 2>&1 && [ -n "$(docker compose ps --status running -q app)" ]; then
  info "Recréation des conteneurs app/queue/queue-jeu/reverb…"
  docker compose up -d --force-recreate app queue queue-jeu reverb
  info "Redémarrage de web (nginx)…"
  docker compose restart web
  ok "Stack à jour sur $IP."
else
  warn "La stack ne semble pas démarrée — relance-la avec : docker compose up -d"
fi

echo
bold "Adresse à utiliser sur les téléphones : http://$IP"
