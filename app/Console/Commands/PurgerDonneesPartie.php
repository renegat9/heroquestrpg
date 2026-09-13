<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Agent\Memoire\BibleQdrant;
use App\Models\Groupe;
use App\Models\Joueur;
use App\Models\Personnage;
use App\Partie\ClotureCampagne;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Remet la base à l'état « aucune partie jouée » — catalogues et réglages
 * intacts.
 *
 * POURQUOI une commande et pas un drapeau `est_test` en base (René, 2026-08-23) :
 * le harnais de campagne joue DÉLIBÉRÉMENT sur les vraies routes, c'est toute
 * la valeur de la méthode. Un drapeau posé par le client, les tests
 * l'oublieraient ; posé par le serveur, il exigerait un chemin de code réservé
 * aux tests — exactement ce que « pas de mode démo » interdit ici. Il
 * deviendrait une clé décorative de plus, lue par ce seul script. Et il n'aurait
 * rien empêché : les campagnes du harnais étaient déjà purgeables, rien ne les
 * purgeait. Ce qui manquait n'était pas une IDENTITÉ, c'était une ÉTAPE.
 *
 * ⚠ Passe par {@see ClotureCampagne::purger()} plutôt que de vider des tables :
 * ce service emporte aussi les caches de phase, la bible Qdrant du groupe et
 * ses illustrations. Un `DELETE` direct laisserait les trois derrière lui.
 *
 *   php artisan partie:purger              # inventaire seul
 *   php artisan partie:purger --supprimer  # groupes, quêtes, héros
 *   php artisan partie:purger --supprimer --tout   # + comptes et télémétrie
 */
final class PurgerDonneesPartie extends Command
{
    protected $signature = 'partie:purger
        {--supprimer : Applique (sans ce drapeau, la commande se contente de lister)}
        {--tout : Emporte AUSSI les comptes joueurs et la télémétrie de consommation IA}
        {--force : Passe outre la confirmation — pour un script, JAMAIS pour un agent}';

    protected $description = 'Remet la base à zéro côté partie (catalogues et réglages conservés)';

    public function handle(ClotureCampagne $cloture, BibleQdrant $bible): int
    {
        // ⚠ Même garde-fou que `images:purger-orphelines`, et pour la même
        // raison payée le 2026-08-22 : sous Pest la base est une sqlite en
        // mémoire, une purge y serait au mieux inutile, au pire trompeuse.
        if (app()->environment('testing')) {
            $this->error('Refusé en environnement `testing`.');

            return self::FAILURE;
        }

        $applique = (bool) $this->option('supprimer');
        $tout = (bool) $this->option('tout');

        $groupes = Groupe::orderBy('id')->get();
        $personnages = Personnage::count();
        $comptes = Joueur::count();
        $telemetrie = DB::table('consommation_ia')->count();

        $orphelinsBible = $this->orphelinsBible($bible, $groupes->pluck('id')->all());

        $this->ligne('groupes', (string) $groupes->count());
        $this->ligne('quêtes', (string) DB::table('quetes')->count());
        $this->ligne('personnages', (string) $personnages);
        $this->ligne('comptes joueurs', $comptes.($tout ? '' : '   (conservés — voir --tout)'));
        $this->ligne('télémétrie IA', $telemetrie.($tout ? '' : '   (conservée — voir --tout)'));
        $this->ligne('bible Qdrant orpheline', count($orphelinsBible).' groupe(s) sans campagne');

        if (! $applique) {
            $this->warn('Inventaire seul. Relancer avec --supprimer pour appliquer.');

            return self::SUCCESS;
        }

        if (! $this->confirmerDestruction($groupes->count(), $personnages, $comptes, $tout)) {
            return self::FAILURE;
        }

        // 1. Les campagnes, par le service : caches, Qdrant et images compris.
        foreach ($groupes as $groupe) {
            $cloture->purger($groupe);
        }

        // 2. Les héros détachés, que la purge d'une campagne rend au roster
        // plutôt que de les supprimer — c'est voulu, mais pas ici.
        // Les dépendants (inventaire, sorts, compétences, conditions,
        // historique) partent en cascade, déclarée dans les migrations.
        foreach (Personnage::all() as $personnage) {
            $personnage->delete();
        }

        // 3. La bible des campagnes déjà disparues : leur purge Qdrant est
        // best-effort et a pu échouer (Qdrant injoignable) — ces points
        // seraient hérités par un futur groupe au même id.
        foreach ($orphelinsBible as $groupeId) {
            try {
                $bible->purgerGroupe($groupeId);
            } catch (Throwable $e) {
                $this->warn("  bible du groupe {$groupeId} : {$e->getMessage()}");
            }
        }

        if ($tout) {
            foreach (Joueur::all() as $joueur) {
                $joueur->delete();
            }

            DB::table('consommation_ia')->delete();
        }

        $this->info(sprintf(
            'Purgé : %d campagne(s), %d héros, %d bible(s) orpheline(s)%s.',
            $groupes->count(),
            $personnages,
            count($orphelinsBible),
            $tout ? sprintf(', %d compte(s), %d ligne(s) de télémétrie', $comptes, $telemetrie) : '',
        ));

        return self::SUCCESS;
    }

    /** Aligne sur la LARGEUR AFFICHÉE : `sprintf` compte des octets, et « quêtes » en fait plus qu'il n'affiche de signes. */
    /**
     * Dernier rempart AVANT la destruction — et il est délibérément pénible.
     *
     * ⚠ Pourquoi (René, 2026-09-12 : « il n'y a plus de chance qu'un agent vide
     * la base de données réelle ? ») : la réponse était NON. Tout ce qui
     * protégeait ces lignes était de la PROSE — CLAUDE.md, les skills, une
     * mémoire. Un agent qui ne les a pas lues, ou qui les a lues et a jugé que
     * « purger » n'était pas « détruire », lançait la commande sans rencontrer
     * la moindre résistance. Le seul garde-fou existant refusait
     * l'environnement `testing` : l'exact inverse de ce qui protège des données
     * réelles.
     *
     * ⚠ `--tout` exige de RECOPIER le nombre de groupes, pas de répondre « oui ».
     * Un agent répond « oui » à n'importe quelle question fermée ; il ne peut
     * pas recopier un nombre qu'il n'a pas lu dans l'inventaire juste au-dessus.
     * C'est la différence entre une confirmation et un péage.
     *
     * ⚠ `--force` existe pour les scripts, et c'est assumé : un garde-fou
     * impossible à contourner finit contourné par un chemin qu'on ne voit pas.
     * Mieux vaut une porte nommée qu'un mur qu'on escalade.
     */
    private function confirmerDestruction(int $groupes, int $personnages, int $comptes, bool $tout): bool
    {
        if ($this->option('force')) {
            $this->warn('--force : confirmation passée outre.');

            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Refusé : pas de terminal pour confirmer. Utilisez --force en connaissance de cause.');

            return false;
        }

        $this->newLine();
        $this->error('⚠ DESTRUCTION IRRÉVERSIBLE de données de PRODUCTION.');
        $this->line("  {$groupes} groupe(s), {$personnages} personnage(s)".($tout ? ", {$comptes} compte(s)" : '').' vont disparaître.');
        $this->line('  Les campagnes durent des semaines. Sauvegardez d\'abord : ./image-tools/sauvegarder.sh');
        $this->newLine();

        if ($tout) {
            $saisi = (string) $this->ask("Pour confirmer, recopiez le nombre de groupes à détruire ({$groupes})");

            if (trim($saisi) !== (string) $groupes) {
                $this->error('Annulé : le nombre ne correspond pas.');

                return false;
            }

            return true;
        }

        return (bool) $this->confirm('Confirmer la suppression ?', false);
    }

    private function ligne(string $libelle, string $valeur): void
    {
        $this->line('  '.$libelle.str_repeat(' ', max(1, 26 - mb_strlen($libelle))).$valeur);
    }

    /**
     * Groupes indexés dans la bible dont la campagne n'existe plus.
     *
     * Best-effort : Qdrant injoignable ne doit pas empêcher la purge
     * relationnelle, exactement comme dans `ClotureCampagne::purger()`.
     *
     * @param  list<int>  $vivants
     * @return list<int>
     */
    private function orphelinsBible(BibleQdrant $bible, array $vivants): array
    {
        try {
            $indexes = $bible->groupesIndexes();
        } catch (Throwable $e) {
            $this->warn('  bible Qdrant illisible ('.$e->getMessage().') — ignorée.');

            return [];
        }

        return array_values(array_filter(
            $indexes,
            fn (int $id) => ! in_array($id, array_map(intval(...), $vivants), true),
        ));
    }
}
