<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Partie\Arene\Doublures;
use App\Partie\Arene\GroupesArene;
use Illuminate\Console\Command;

/**
 * MODE ARÈNE, lot A2 — le MÉNAGE DE SECOURS (`docs/plan-mode-arene.md` §6) : les combats d'arène abandonnés (onglet
 * fermé, serveur redémarré) et les doublures jamais engagées, plus vieux que `--heures`.
 *
 * Inventaire par défaut, `--supprimer` pour agir — comme `partie:purger`. Tout passe par `GroupesArene::purger()` et
 * `Doublures::supprimer()`, à double verrou : cette commande ne peut emporter ni une campagne ni un vrai héros, quel
 * que soit l'état de la base. Elle peut donc être planifiée.
 *
 *   php artisan arene:nettoyer                 # inventaire seul
 *   php artisan arene:nettoyer --supprimer     # combats abandonnés depuis 6 h et doublures orphelines
 *   php artisan arene:nettoyer --heures=1 --supprimer
 */
class NettoyerArene extends Command
{
    protected $signature = 'arene:nettoyer
        {--heures='.GroupesArene::HEURES_ABANDON.' : Âge minimal d\'un combat ou d\'une doublure pour être tenu pour abandonné}
        {--supprimer : Applique (sans ce drapeau, la commande se contente de lister)}';

    protected $description = 'Supprime les combats d\'arène abandonnés et les doublures orphelines (jamais une campagne ni un héros)';

    public function handle(GroupesArene $arenes, Doublures $doublures): int
    {
        $heures = max(0, (int) $this->option('heures'));
        $combats = $arenes->abandonnes($heures);
        $orphelines = $arenes->doubluresOrphelines($heures);

        $this->line(sprintf('combats d\'arène abandonnés (> %d h) : %d', $heures, $combats->count()));
        $this->line(sprintf('doublures orphelines (> %d h)         : %d', $heures, $orphelines->count()));

        if (! $this->option('supprimer')) {
            $this->warn('Inventaire seul. Relancer avec --supprimer pour appliquer.');

            return self::SUCCESS;
        }

        foreach ($combats as $groupe) {
            $arenes->purger($groupe);
        }

        foreach ($orphelines as $doublure) {
            $doublures->supprimer($doublure);
        }

        $this->info(sprintf('Nettoyé : %d combat(s), %d doublure(s).', $combats->count(), $orphelines->count()));

        return self::SUCCESS;
    }
}
