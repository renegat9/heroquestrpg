<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Mcp\Outils\OutilApercu;
use App\Mcp\Outils\OutilCampagneEcrire;
use App\Mcp\Outils\OutilCampagneLire;
use App\Mcp\Outils\OutilCampagnes;
use App\Mcp\Outils\OutilCatalogues;
use App\Mcp\Outils\OutilExporter;
use App\Mcp\Outils\OutilImporter;
use App\Mcp\Outils\OutilQueteEcrire;
use App\Mcp\Outils\OutilQueteModifier;
use App\Mcp\Outils\OutilQueteLire;
use App\Mcp\Outils\OutilValider;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

/**
 * Le serveur MCP du constructeur de campagnes (MODE CLASSIQUE, lot P1 — `docs/contrat-api.md` « Serveur MCP du
 * constructeur »). Transport stdio, déclaré dans `routes/ai.php`.
 *
 * Aucun outil n'a sa propre règle : chacun appelle le service que la route HTTP du constructeur appelle. Aucun ne joue,
 * ne touche à un groupe, un personnage ou un joueur, et il n'y a pas d'outil de suppression.
 */
#[Name('Constructeur de campagnes HeroQuest')]
#[Version('0.1.0')]
#[Instructions(
    'Le constructeur de campagnes du mode Classique : vous composez une campagne construite par ces outils. '
    .'Rien ne joue, rien ne touche aux groupes, aux personnages ni aux joueurs. '
    .'Ordre conseillé : `catalogues`, puis `campagne_lire` ou `campagnes`, puis `campagne_ecrire` pour une campagne '
    .'entière ou `quete_ecrire` pour une seule quête, puis `valider` pour contrôler sans écrire. '
    .'Tout slug de mobilier, de monstre, de piège, de terrain, de classe, d\'objet, de sort ou de captif vient de `catalogues` : un slug inventé est '
    .'refusé. `campagne_lire` ne donne pas le détail des quêtes ; `quete_lire` le donne, une quête à la fois. '
    .'Pour une modification ciblée d\'une quête, `quete_modifier` (opérations `ajouter`, `retirer`, `remplacer`) évite de '
    .'réécrire tout son document ; `apercu` rend la carte d\'une quête en texte, pour la relire après une écriture. '
    .'Un refus de validation est une réponse normale : lire chaque `chemin`, corriger, puis réessayer.'
)]
final class ServeurConstructeur extends Server
{
    /** @var list<class-string<Tool>> */
    protected array $tools = [
        OutilCatalogues::class,
        OutilCampagnes::class,
        OutilCampagneLire::class,
        OutilQueteLire::class,
        OutilApercu::class,
        OutilCampagneEcrire::class,
        OutilQueteEcrire::class,
        OutilQueteModifier::class,
        OutilValider::class,
        OutilExporter::class,
        OutilImporter::class,
    ];
}
