<?php

namespace App\Providers;

use App\Agent\AnthropicClient;
use App\Agent\ClientLLM;
use App\Agent\ClientLLMAvecRepli;
use App\Agent\GeminiClient;
use App\Agent\TraceurConsommation;
use App\Agent\Memoire\Embeddings;
use App\Agent\Memoire\EmbeddingsNuls;
use App\Agent\Memoire\EmbeddingsVoyage;
use App\Engine\Des\LanceurAleatoire;
use App\Engine\Des\LanceurDes;
use App\Models\Parametre;
use Illuminate\Support\ServiceProvider;
use Throwable;
use App\Listeners\ImageMiroir;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Hasard du moteur : aléatoire en prod, remplaçable par le lanceur
        // déterministe en test (le moteur fait autorité sur toute mécanique).
        $this->app->bind(LanceurDes::class, LanceurAleatoire::class);

        // Tampon des scènes de table (App\Partie\TamponScenes) : SINGLETON, et
        // c'est tout son fonctionnement — l'observateur de chute y dépose
        // pendant la résolution, le contrôleur y puise après. Deux instances,
        // et le tampon serait toujours vide au moment de le vider.
        $this->app->singleton(TamponScenes::class);

        // Télémétrie de consommation LLM (App\Agent\TraceurConsommation) :
        // SINGLETON, pas bind() — l'état (contexte annoncé par pourGroupe(),
        // compteur de tentative) doit survivre entre l'annonce du contexte et
        // le(s) appel(s) HTTP qui suivent, au sein d'un même job. Un worker de
        // queue traite ses jobs séquentiellement (voir CLAUDE.md), donc aucune
        // concurrence sur cette instance partagée par process.
        $this->app->singleton(TraceurConsommation::class);

        // Embeddings de la bible RAG (doc 11 §6) : Voyage AI dès que la clé
        // est renseignée, sinon repli lexical factice (dev sans clé).
        // ⚠ Les deux n'ont pas la même dimension : passer de l'un à l'autre
        // impose de recréer la collection Qdrant (bible vide en dev → ok).
        // Fournisseur d'embeddings volontairement NON pilotable depuis le
        // panneau Réglages (contrairement à ClientLLM ci-dessous) : changer
        // de fournisseur en cours de campagne casserait la collection Qdrant
        // existante (dimensions vectorielles différentes).
        $this->app->bind(Embeddings::class, function () {
            return config('services.voyage.api_key')
                ? new EmbeddingsVoyage
                : new EmbeddingsNuls;
        });

        // Fournisseur LLM du MJ IA (histoire + narration) — choix GLOBAL,
        // piloté par le panneau Réglages (Parametre::actuel()->llm_provider,
        // persisté en base) avec repli sur LLM_PROVIDER (.env) si aucune
        // surcharge n'est enregistrée : tous les skills reçoivent le client
        // choisi. `bind()` (pas `singleton()`) : réévalué à CHAQUE résolution,
        // donc à CHAQUE job — un changement de réglage s'applique sans
        // redémarrer aucun conteneur.
        //
        // Repli automatique INTER-FOURNISSEURS À L'EXÉCUTION (pas seulement à
        // la résolution du binding) : si l'appel au fournisseur PRINCIPAL
        // échoue vraiment (panne API, clé révoquée, modèle retiré…), une
        // seule retentative avec l'AUTRE fournisseur avant d'abandonner à
        // l'IA — voir {@see \App\Agent\ClientLLMAvecRepli}.
        $this->app->bind(ClientLLM::class, function () {
            try {
                $parametres = Parametre::actuel();
            } catch (Throwable) {
                // Table absente (migration pas encore jouée) ou base
                // indisponible : repli intégral sur le comportement .env.
                $parametres = null;
            }

            $anthropicDispo = filled(config('services.anthropic.api_key'));
            $geminiDispo = filled(config('services.gemini.api_key'));
            $providerVoulu = $parametres?->llm_provider ?: config('services.llm.provider');

            $principalNom = ($providerVoulu === 'gemini' && $geminiDispo) ? 'gemini' : 'anthropic';
            $principal = $principalNom === 'gemini'
                ? $this->app->make(GeminiClient::class, ['model' => $parametres?->modele_gemini ?: null])
                : $this->app->make(AnthropicClient::class, ['model' => $parametres?->modele_anthropic ?: null]);

            // Repli croisé : l'AUTRE fournisseur, s'il a une clé — construit avec sa propre surcharge.
            $secoursNom = null;
            $secours = null;
            if ($principalNom === 'anthropic' && $geminiDispo) {
                $secoursNom = 'gemini';
                $secours = $this->app->make(GeminiClient::class, ['model' => $parametres?->modele_gemini ?: null]);
            } elseif ($principalNom === 'gemini' && $anthropicDispo) {
                $secoursNom = 'anthropic';
                $secours = $this->app->make(AnthropicClient::class, ['model' => $parametres?->modele_anthropic ?: null]);
            }

            return new ClientLLMAvecRepli($principal, $principalNom, $secours, $secoursNom);
        });
    }

    /**
     * Bootstrap any application services.
     */
    /**
     * Les quatre commandes Laravel qui vident la base. ⚠ `migrate` seul n'y est
     * PAS : il ajoute, il ne détruit pas — l'y mettre rendrait le garde si
     * pénible qu'on le désactiverait.
     */
    private const COMMANDES_DESTRUCTRICES = ['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'db:wipe'];

    /** Code de sortie du refus — distinct de 1 pour se repérer dans un log. */
    private const CODE_REFUS = 9;

    public function boot(): void
    {
        // Écouteurs d'interception des dégâts subis par un héros. Ils doivent
        // être des écouteurs AUTOMATIQUES : `HerosVaSubirDegats` part au milieu
        // de la phase des monstres, là où rien ne peut interroger une manette.
        // Les effets qui exigent un CHOIX du joueur passent par
        // App\Partie\MoteurReactions, qui propose puis défait le coup.
        Event::listen(ImageMiroir::class);

        $this->interdireLesCommandesDestructrices();
    }

    /**
     * Refuse `migrate:fresh` & co. tant que la base porte une vraie campagne.
     *
     * ⚠ René, 2026-09-12 : « il n'y a plus de chance qu'un agent vide la base de
     * données réelle ? ». La réponse était NON. Tout ce qui protégeait ces
     * lignes était de la PROSE — `CLAUDE.md`, les skills, une mémoire — et la
     * prose n'a jamais arrêté personne qui ne l'avait pas lue. Quatre commandes
     * livrées avec Laravel suffisaient à effacer des semaines de campagne
     * **sans poser une seule question**, parce que `ConfirmableTrait` ne demande
     * confirmation QUE si `APP_ENV === 'production'` — et le conteneur tourne en
     * `local`.
     *
     * ⚠ Pourquoi pas simplement `APP_ENV=production` : Laravel exigerait alors
     * `--force` sur `migrate` et `db:seed` AUSSI, ce qui casse `setup.sh` et le
     * cycle de développement, et bascule les pages d'erreur. Le garde ciblé ne
     * change rien d'autre que ce qu'on veut empêcher.
     *
     * ⚠ La sortie de secours est une VARIABLE D'ENVIRONNEMENT, jamais un
     * `--force` : un agent ajoute `--force` par réflexe quand une commande
     * refuse, il n'invente pas `HQ_AUTORISER_DESTRUCTION=1`. Un garde-fou
     * impossible à contourner finit contourné par un chemin qu'on ne voit pas —
     * mieux vaut une porte nommée qu'un mur qu'on escalade.
     *
     * ⚠ `testing` passe librement : la suite Pest reconstruit sa base à chaque
     * fichier, et elle tourne sur une sqlite jetable, jamais sur le conteneur.
     */
    private function interdireLesCommandesDestructrices(): void
    {
        if (app()->environment('testing') || env('HQ_AUTORISER_DESTRUCTION') === '1') {
            return;
        }

        Event::listen(function (CommandStarting $evenement): void {
            if (! in_array($evenement->command, self::COMMANDES_DESTRUCTRICES, true)) {
                return;
            }

            try {
                $groupes = DB::table('groupes')->count();
                $personnages = DB::table('personnages')->count();
            } catch (\Throwable) {
                return; // Base vide ou non migrée : rien à protéger.
            }

            if ($groupes === 0 && $personnages === 0) {
                return;
            }

            $sortie = $evenement->output;
            $sortie->writeln('');
            $sortie->writeln("<error>  REFUSÉ : `{$evenement->command}` détruirait des données de PRODUCTION.  </error>");
            $sortie->writeln("  La base porte {$groupes} groupe(s) et {$personnages} personnage(s) — des campagnes qui durent des semaines.");
            $sortie->writeln('  Sauvegarder  : ./image-tools/sauvegarder.sh');
            $sortie->writeln('  Modifier des lignes existantes : écrire une MIGRATION, pas un re-seed destructif.');
            $sortie->writeln('  Si c\'est vraiment voulu : HQ_AUTORISER_DESTRUCTION=1 php artisan '.$evenement->command);
            $sortie->writeln('');

            exit(self::CODE_REFUS);
        });
    }
}
