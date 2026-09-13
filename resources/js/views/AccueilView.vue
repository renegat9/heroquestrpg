<script setup>
// ACCUEIL — choix de rôle : Narrateur (table) ou Joueur (compte + roster).
import { onMounted, ref } from 'vue';
import MSym from '../components/ui/MSym.vue';
import { useApi } from '../composables/useApi';
import { useGameStore } from '../store/game';

const api = useApi();
const store = useGameStore();

/* ---- livret de jeu (PDF) ----
 * Le PDF est un asset GÉNÉRÉ (docs/livret/README.md), donc absent d'une
 * installation neuve — au même titre que public/images. On ne montre la carte
 * que si le fichier répond : un lien mort sur l'accueil serait la « promesse
 * faite au joueur et jamais tenue » que CLAUDE.md proscrit. HEAD, donc les
 * 16 Mo ne sont jamais téléchargés pour cette vérification.
 *
 * ⚠ On vérifie le CONTENT-TYPE, pas seulement le statut. Le `try_files` de
 * nginx renvoie la SPA en 200 pour tout ce qui n'existe pas : un statut seul
 * disait donc « présent » quel que soit l'état du disque. `docker/nginx`
 * porte désormais un `location /livret/ { try_files $uri =404; }` qui règle le
 * cas à la source — ce test-ci reste la ceinture, pour que l'écran ne dépende
 * pas d'un fichier de conf qu'il ne contrôle pas. */
const LIVRET_URL = '/livret/HeroQuest-RPG-Livret-de-jeu.pdf';
const livretDispo = ref(false);

onMounted(async () => {
    try {
        const { joueur: moi, personnages } = await api.moi();
        store.setJoueur(moi, personnages ?? []);
    } catch {
        // 401 = simplement pas connecté ; rien à faire, cet écran n'affiche
        // pas de données de session.
    }

    try {
        const r = await fetch(LIVRET_URL, { method: 'HEAD' });
        livretDispo.value = r.ok
            && (r.headers.get('content-type') ?? '').includes('application/pdf');
    } catch {
        livretDispo.value = false;
    }
});
</script>

<template>
    <div class="acchoix tex-vignette">
        <!-- fond ambre radial discret -->
        <div class="acchoix-glow" aria-hidden="true" />

        <div class="acchoix-inner">
            <!-- logo / titre -->
            <header class="acchoix-hero">
                <div class="acchoix-crest">
                    <MSym n="shield_moon" fill />
                </div>
                <h1 class="acchoix-title">HeroQuest RPG</h1>
                <p class="acchoix-sub">Choisissez votre rôle pour rejoindre la partie.</p>
            </header>

            <!-- deux cartes de rôle -->
            <div class="acchoix-roles">
                <!-- Narrateur -->
                <RouterLink to="/narrateur" class="acchoix-role">
                    <div class="acchoix-role-seal is-narrateur">
                        <MSym n="cast" fill />
                    </div>
                    <div class="acchoix-role-body">
                        <h2>Je suis le Narrateur</h2>
                        <p>
                            Vous tenez la table. Saisissez le code du groupe pour
                            ouvrir l'écran partagé : carte, figurines et narration.
                        </p>
                        <ul class="acchoix-role-bullets">
                            <li><MSym n="map" :size="13" /> Écran de table (paysage)</li>
                            <li><MSym n="wifi" :size="13" /> Signal heartbeat actif</li>
                            <li><MSym n="no_accounts" :size="13" /> Aucun compte requis</li>
                        </ul>
                    </div>
                    <span class="acchoix-role-go">
                        Ouvrir la table <MSym n="arrow_forward" />
                    </span>
                </RouterLink>

                <!-- Joueur -->
                <RouterLink to="/joueur" class="acchoix-role">
                    <div class="acchoix-role-seal is-joueur">
                        <MSym n="smartphone" fill />
                    </div>
                    <div class="acchoix-role-body">
                        <h2>Je suis un Joueur</h2>
                        <p>
                            Connectez-vous ou créez un compte, choisissez votre
                            héros et rejoignez ou créez un groupe.
                        </p>
                        <ul class="acchoix-role-bullets">
                            <li><MSym n="swords" :size="13" /> Manette joueur (portrait)</li>
                            <li><MSym n="group" :size="13" /> Roster de personnages</li>
                            <li><MSym n="auto_awesome" :size="13" /> Sorts, sac et marché</li>
                        </ul>
                    </div>
                    <span class="acchoix-role-go">
                        Accéder au roster <MSym n="arrow_forward" />
                    </span>
                </RouterLink>
            </div>

            <!-- références publiques : consultables sans compte -->
            <div class="acchoix-liens">
                <!-- guide / compendium, servi par l'API -->
                <RouterLink to="/guide" class="acchoix-guide">
                    <MSym n="menu_book" fill :size="20" />
                    <span class="acchoix-guide-txt">
                        <b>Guide de jeu</b>
                        <em>Bestiaire, talents des héros, équipements, sorts et pièges</em>
                    </span>
                    <MSym n="arrow_forward" :size="18" />
                </RouterLink>

                <!-- livret PDF : asset généré, donc affiché seulement s'il est là -->
                <a
                    v-if="livretDispo"
                    :href="LIVRET_URL"
                    target="_blank"
                    rel="noopener"
                    class="acchoix-guide is-livret"
                >
                    <MSym n="picture_as_pdf" fill :size="20" />
                    <span class="acchoix-guide-txt">
                        <b>Livret de jeu (PDF)</b>
                        <em>Les règles complètes en 42 pages — à lire, à imprimer, à poser sur la table</em>
                    </span>
                    <MSym n="download" :size="18" />
                </a>
            </div>
        </div>
    </div>
</template>

<style>
/* Écran d'accueil — choix de rôle */
.acchoix { min-height: 100vh; background: var(--stone-950); color: var(--ink-100);
  display: grid; place-items: center; padding: 32px 20px; position: relative; overflow: hidden; }

.acchoix-glow { position: absolute; inset: 0; pointer-events: none;
  background:
    radial-gradient(60% 55% at 20% 5%, oklch(0.76 0.155 65 / 0.12), transparent 60%),
    radial-gradient(50% 45% at 85% 90%, oklch(0.62 0.17 42 / 0.10), transparent 60%); }

.acchoix-inner { position: relative; width: 100%; max-width: 740px;
  display: flex; flex-direction: column; align-items: center; gap: 40px; }

/* ---- en-tête ---- */
.acchoix-hero { text-align: center; display: flex; flex-direction: column; align-items: center; gap: 14px; }
.acchoix-crest { width: 88px; height: 88px; border-radius: 22px; display: grid; place-items: center;
  background: linear-gradient(150deg, var(--ember), var(--ember-deep));
  color: var(--parch-100); box-shadow: 0 0 40px oklch(0.76 0.155 65 / 0.3), var(--sh-3); }
.acchoix-crest .msym { font-size: 52px; }
.acchoix-title { font-family: var(--font-display); font-size: clamp(32px, 6vw, 52px);
  font-weight: 800; letter-spacing: 0.03em; margin: 0;
  background: linear-gradient(180deg, var(--parch-100), var(--torch-bright) 160%);
  -webkit-background-clip: text; background-clip: text; color: transparent; }
.acchoix-sub { font-family: var(--font-narr); font-style: italic; color: var(--ink-300);
  font-size: 18px; margin: 0; }

/* ---- cartes de rôle ---- */
.acchoix-roles { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; width: 100%; }
@media (max-width: 600px) { .acchoix-roles { grid-template-columns: 1fr; } }

.acchoix-role { position: relative; overflow: hidden; border-radius: var(--r-xl); border: var(--line);
  background: linear-gradient(180deg, var(--stone-850), var(--stone-900));
  padding: 28px 26px; color: var(--ink-100); text-decoration: none;
  display: flex; flex-direction: column; gap: 18px;
  transition: transform .15s, border-color .15s, box-shadow .15s; }
.acchoix-role:hover { transform: translateY(-4px); border-color: var(--torch);
  box-shadow: 0 0 30px oklch(0.76 0.155 65 / 0.18), var(--sh-3); }

.acchoix-role-seal { width: 64px; height: 64px; border-radius: 16px; display: grid; place-items: center;
  color: var(--parch-100); box-shadow: var(--sh-2); flex: none; }
.acchoix-role-seal .msym { font-size: 36px; }
.acchoix-role-seal.is-narrateur { background: linear-gradient(150deg, oklch(0.55 0.14 260), oklch(0.4 0.12 260)); }
.acchoix-role-seal.is-joueur { background: linear-gradient(150deg, var(--ember), var(--ember-deep)); }

.acchoix-role-body { display: flex; flex-direction: column; gap: 8px; flex: 1; }
.acchoix-role-body h2 { font-family: var(--font-display); font-size: 22px; color: var(--parch-100);
  margin: 0; letter-spacing: 0.02em; font-weight: 800; }
.acchoix-role-body p { font-size: 14px; color: var(--ink-400); line-height: 1.5; margin: 0; }

.acchoix-role-bullets { list-style: none; margin: 4px 0 0; padding: 0;
  display: flex; flex-direction: column; gap: 5px; }
.acchoix-role-bullets li { display: flex; align-items: center; gap: 7px;
  font-size: 12.5px; color: var(--ink-500); font-weight: 600; }
.acchoix-role-bullets li .msym { color: var(--torch); flex: none; }

.acchoix-role-go { margin-top: auto; display: flex; align-items: center; gap: 6px;
  font-size: 13px; font-weight: 700; color: var(--torch); }

/* ---- lien guide / compendium ---- */
/* ⚠ .acchoix-inner a un gap de 40px : sans ce conteneur, les deux références
   publiques se retrouveraient aussi éloignées l'une de l'autre que des cartes
   de rôle, alors qu'elles forment un bloc. */
.acchoix-liens { width: 100%; display: flex; flex-direction: column; gap: 12px; }

.acchoix-guide { width: 100%; display: flex; align-items: center; gap: 14px; text-decoration: none;
  padding: 16px 20px; border-radius: var(--r-lg, 14px); border: var(--line);
  background: linear-gradient(180deg, var(--stone-850), var(--stone-900)); color: var(--ink-200, #e7dcc6);
  transition: transform .15s, border-color .15s, box-shadow .15s; }
.acchoix-guide:hover { transform: translateY(-3px); border-color: var(--gold);
  box-shadow: 0 0 24px oklch(0.80 0.135 88 / 0.15), var(--sh-2); }
.acchoix-guide > .msym:first-child { color: var(--gold); flex: none; }
.acchoix-guide > .msym:last-child { color: var(--ink-500); flex: none; margin-left: auto; }
.acchoix-guide-txt { display: flex; flex-direction: column; gap: 2px; }
.acchoix-guide-txt b { font-family: var(--font-display); font-size: 16px; font-weight: 800; color: var(--parch-100); letter-spacing: 0.02em; }
.acchoix-guide-txt em { font-style: normal; font-size: 12.5px; color: var(--ink-400); }

/* Le livret est un document à emporter, pas un écran : sceau ambre plutôt
   qu'or, pour qu'on ne le confonde pas d'un coup d'œil avec le guide. */
.acchoix-guide.is-livret > .msym:first-child { color: var(--ember, #c2571d); }
.acchoix-guide.is-livret:hover { border-color: var(--ember, #c2571d);
  box-shadow: 0 0 24px oklch(0.62 0.17 42 / 0.18), var(--sh-2); }
</style>
