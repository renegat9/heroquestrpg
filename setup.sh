#!/usr/bin/env bash
# ============================================================
# setup.sh — Installation du projet HeroQuest MJ IA
# - Génère le .env depuis .env.example (questions interactives)
# - Génère les secrets manquants (APP_KEY, Reverb, mots de passe DB)
# - Build et démarre la stack Docker, migre et seed la base
# Usage :  ./setup.sh
# ============================================================
set -euo pipefail

# ── Helpers ──────────────────────────────────────────────────
bold()  { printf "\033[1m%s\033[0m\n" "$*"; }
info()  { printf "\033[36m→ %s\033[0m\n" "$*"; }
ok()    { printf "\033[32m✔ %s\033[0m\n" "$*"; }
warn()  { printf "\033[33m⚠ %s\033[0m\n" "$*"; }
die()   { printf "\033[31m✘ %s\033[0m\n" "$*" >&2; exit 1; }

# ask VAR "Question" "défaut"
ask() {
  local var="$1" prompt="$2" default="${3:-}" answer
  if [ -n "$default" ]; then
    read -r -p "$prompt [$default] : " answer
    answer="${answer:-$default}"
  else
    read -r -p "$prompt : " answer
  fi
  printf -v "$var" '%s' "$answer"
}

# ask_secret VAR "Question" (saisie masquée, vide = auto-généré)
ask_secret() {
  local var="$1" prompt="$2" answer
  read -r -s -p "$prompt (vide = généré aléatoirement) : " answer
  echo
  if [ -z "$answer" ]; then
    answer="$(openssl rand -hex 16)"
    info "Valeur générée automatiquement."
  fi
  printf -v "$var" '%s' "$answer"
}

# set_env CLE VALEUR — remplace (ou ajoute) CLE=… dans .env
set_env() {
  local key="$1" value="$2"
  # échappe les caractères spéciaux pour sed
  local escaped
  escaped=$(printf '%s' "$value" | sed -e 's/[\/&|]/\\&/g')
  if grep -q "^${key}=" .env; then
    sed -i.bak "s|^${key}=.*|${key}=${escaped}|" .env && rm -f .env.bak
  else
    echo "${key}=${value}" >> .env
  fi
}

# ── Pré-requis ───────────────────────────────────────────────
bold "═══ HeroQuest MJ IA — installation ═══"
echo

command -v docker >/dev/null 2>&1 || die "Docker n'est pas installé : https://docs.docker.com/engine/install/"
docker compose version >/dev/null 2>&1 || die "Docker Compose v2 requis (plugin 'docker compose')."
command -v openssl >/dev/null 2>&1 || die "openssl est requis pour générer les secrets."
[ -f .env.example ] || die "Fichier .env.example introuvable (lancer le script à la racine du projet)."

# ── .env existant ? ──────────────────────────────────────────
if [ -f .env ]; then
  warn "Un fichier .env existe déjà."
  ask OVERWRITE "L'écraser et tout reconfigurer ? (o/N)" "N"
  case "$OVERWRITE" in
    o|O|oui) cp .env ".env.backup.$(date +%Y%m%d%H%M%S)"; ok "Sauvegarde de l'ancien .env créée." ;;
    *) die "Installation annulée — .env conservé." ;;
  esac
fi
cp .env.example .env
ok ".env créé depuis .env.example"
echo

# ── Questions ────────────────────────────────────────────────
bold "── Mode d'exécution ──"
echo "  1) dev   — avec phpMyAdmin + Vite (hot reload)"
echo "  2) lan   — partie entre proches sur le réseau local"
ask MODE "Choix" "1"
echo

bold "── Réseau ──"
if [ "$MODE" = "2" ]; then
  # Suggestion d'IP locale (Linux ; ignorée si indisponible)
  SUGGESTED_IP="$(hostname -I 2>/dev/null | awk '{print $1}' || true)"
  ask LAN_IP "IP LAN de cette machine (celle que tablette et téléphones vont taper)" "${SUGGESTED_IP:-192.168.1.10}"
  APP_URL="http://${LAN_IP}"
  REVERB_HOST="$LAN_IP"
  APP_ENV="production"
  APP_DEBUG="false"
  warn "Pense à autoriser les ports 80 et 8080 dans le pare-feu, et à fixer l'IP (réservation DHCP)."
else
  APP_URL="http://localhost"
  REVERB_HOST="localhost"
  APP_ENV="local"
  APP_DEBUG="true"
fi
echo

bold "── Base de données ──"
ask DB_DATABASE "Nom de la base" "heroquest"
ask DB_USERNAME "Utilisateur" "heroquest"
ask_secret DB_PASSWORD "Mot de passe utilisateur"
ask_secret DB_ROOT_PASSWORD "Mot de passe root MariaDB"
echo

bold "── LLM (agent MJ) ──"
read -r -s -p "Clé API Anthropic (vide = à renseigner plus tard) : " ANTHROPIC_API_KEY
echo
[ -z "$ANTHROPIC_API_KEY" ] && warn "Sans clé, le MJ IA ne fonctionnera pas — à ajouter dans .env (ANTHROPIC_API_KEY)."
echo

# ── Écriture du .env ─────────────────────────────────────────
info "Écriture du .env…"
set_env APP_ENV    "$APP_ENV"
set_env APP_DEBUG  "$APP_DEBUG"
set_env APP_URL    "$APP_URL"
set_env DB_DATABASE      "$DB_DATABASE"
set_env DB_USERNAME      "$DB_USERNAME"
set_env DB_PASSWORD      "$DB_PASSWORD"
set_env DB_ROOT_PASSWORD "$DB_ROOT_PASSWORD"
set_env REVERB_HOST      "$REVERB_HOST"
set_env REVERB_APP_KEY    "$(openssl rand -hex 16)"
set_env REVERB_APP_SECRET "$(openssl rand -hex 16)"
[ -n "$ANTHROPIC_API_KEY" ] && set_env ANTHROPIC_API_KEY "$ANTHROPIC_API_KEY"
set_env UID "$(id -u)"
set_env GID "$(id -g)"
ok ".env configuré"
echo

# ── Docker : build + démarrage ───────────────────────────────
COMPOSE="docker compose"
[ "$MODE" = "1" ] && COMPOSE="docker compose --profile dev"

info "Build des images (premier build : quelques minutes)…"
$COMPOSE build --build-arg UID="$(id -u)" --build-arg GID="$(id -g)"

info "Démarrage des conteneurs…"
$COMPOSE up -d

info "Attente de MariaDB (healthcheck)…"
for i in $(seq 1 30); do
  state="$(docker compose ps --format '{{.Health}}' mariadb 2>/dev/null || true)"
  [ "$state" = "healthy" ] && break
  sleep 2
done
[ "$state" = "healthy" ] || die "MariaDB n'est pas devenue healthy — vérifier :  docker compose logs mariadb"
ok "MariaDB prête"
echo

# ── Laravel : dépendances, clé, migrations ───────────────────
info "Installation des dépendances PHP (composer)…"
docker compose exec -T app composer install --no-interaction

# APP_KEY : générée seulement si absente
if grep -q '^APP_KEY=$' .env || ! grep -q '^APP_KEY=' .env; then
  info "Génération de APP_KEY…"
  docker compose exec -T app php artisan key:generate --force
  ok "APP_KEY générée"
else
  info "APP_KEY déjà définie — conservée."
fi

info "Migrations + seed des catalogues (bestiaire, objets, sorts, tuiles, pièges)…"
docker compose exec -T app php artisan migrate --seed --force

if [ "$MODE" = "2" ]; then
  info "Build du front (production)…"
  docker compose run --rm vite sh -c "npm install && npm run build" 2>/dev/null \
    || warn "Build front non effectué (profil dev non chargé) — lancer :  npm install && npm run build"
fi
echo

# ── Récap ────────────────────────────────────────────────────
bold "═══ Installation terminée ═══"
echo
ok "Application : ${APP_URL}"
ok "WebSocket Reverb : ${REVERB_HOST}:8080"
if [ "$MODE" = "1" ]; then
  ok "phpMyAdmin : http://127.0.0.1:8081"
  ok "Vite (hot reload) : http://localhost:5173"
fi
echo
info "Commandes utiles :"
echo "    docker compose logs -f app queue     # suivre l'app et les jobs IA"
echo "    docker compose down                  # arrêter (les volumes sont conservés)"
echo "    docker compose exec app php artisan  # commandes artisan"
