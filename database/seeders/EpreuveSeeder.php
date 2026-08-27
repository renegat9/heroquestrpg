<?php

namespace Database\Seeders;

use App\Models\Epreuve;
use Illuminate\Database\Seeder;

/**
 * Les 7 ÉPREUVES du catalogue — des ancrages posés sur la carte du donjon
 * auxquels un héros à leur contact peut tenter un jet d'attribut (doc 01 §5,
 * bonus d'attribut des talents).
 *
 * Elles existent pour donner enfin du TRAVAIL aux bonus `bonus_attribut_body`/
 * `bonus_attribut_mind`, et surtout aux contextes de jet `savoir` et
 * `social_peur` de `App\Engine\MotsClesTalent::CONTEXTES` : jusqu'ici
 * déclarés sur des talents et jamais déclenchés faute d'un seul jet à
 * filtrer — six talents achetables qui ne s'appliquaient JAMAIS.
 *
 * Chaque `effet.mecanique` est un mot du vocabulaire fermé
 * `App\Engine\MotsClesEpreuve::MECANIQUES` — voir ce fichier pour le lecteur
 * qui l'applique (pas encore écrit : ce lot ne pose que le catalogue et le
 * vocabulaire, l'intégration carte/menu/résolveur est un autre lot).
 *
 * ⚠ CLÉ SUR `nom` (`updateOrCreate`), et on NE PURGE JAMAIS — même
 * justification que `MobilierSeeder` : la grille de carte d'une quête EN VOL
 * stocke un `epreuve_id` (`cartes.grille.epreuves[]`), et une purge
 * réattribue les identifiants. Une quête déjà lancée se retrouverait avec des
 * épreuves qui ne référencent plus rien — plus déclenchables, sans la moindre
 * erreur, exactement le piège déjà payé une fois sur le mobilier
 * (2026-08-17).
 */
class EpreuveSeeder extends Seeder
{
    public function run(): void
    {
        $epreuves = [
            [
                'nom' => 'Fresque en langue morte',
                'description' => "Une fresque murale porte une inscription dans une langue morte : la déchiffrer demande un jet de Mind, et révèle une cache d'or oubliée derrière l'enduit.",
                'attribut' => 'mind',
                'difficulte' => 2,
                'contexte' => 'savoir',
                'effet' => ['mecanique' => 'or', 'valeur' => 75],
            ],
            [
                'nom' => 'Grimoire à demi calciné',
                'description' => "Un grimoire à demi calciné repose sur un lutrin renversé ; en reconstituer le sens, d'un jet de Mind, sauve une formule sous la forme d'un parchemin.",
                'attribut' => 'mind',
                'difficulte' => 2,
                'contexte' => 'savoir',
                'effet' => ['mecanique' => 'parchemin'],
            ],
            [
                // ⚠ `exige_placement` : inutile de proposer de désarmer une salle
                // qui n'a aucun piège à désarmer — voir MotsClesEpreuve::PLACEMENTS.
                'nom' => 'Autel fêlé',
                'description' => "Un autel fêlé domine la pièce, couvert de rouages à demi enterrés ; les comprendre, d'un jet de Mind, désamorce d'un coup tous les pièges encore actifs de la salle.",
                'attribut' => 'mind',
                'difficulte' => 2,
                'contexte' => 'perception',
                'exige_placement' => 'piege_dans_la_salle',
                'effet' => ['mecanique' => 'desarme_pieges_salle'],
            ],
            [
                'nom' => 'Inscription menaçante',
                'description' => "Une inscription menaçante promet malheur à qui s'attarde ici ; lui tenir tête d'un jet de Mind libère l'audacieux d'une condition qui pesait sur lui.",
                'attribut' => 'mind',
                'difficulte' => 2,
                'contexte' => 'social_peur',
                'effet' => ['mecanique' => 'retire_condition'],
            ],
            [
                'nom' => 'Crâne accusateur',
                'description' => "Un crâne posé sur un socle semble vous accuser du fond de son orbite vide ; braver son regard, d'un jet de Mind, redonne du courage à tout le groupe.",
                'attribut' => 'mind',
                'difficulte' => 3,
                'contexte' => 'social_peur',
                'effet' => ['mecanique' => 'soin_groupe', 'valeur' => 2],
            ],
            [
                'nom' => 'Dalle descellée',
                'description' => "Une dalle du sol sonne creux sous le pas ; la desceller d'un jet de Body met au jour une bourse oubliée sous la pierre.",
                'attribut' => 'body',
                'difficulte' => 2,
                'effet' => ['mecanique' => 'or', 'valeur' => 100],
            ],
            [
                'nom' => 'Mécanisme gripé',
                'description' => "Un compartiment dérobé se cache derrière un mécanisme gripé par la rouille ; le forcer d'un jet de Body en libère le contenu prisonnier depuis des années.",
                'attribut' => 'body',
                'difficulte' => 3,
                'effet' => ['mecanique' => 'objet'],
            ],
        ];

        foreach ($epreuves as $epreuve) {
            Epreuve::updateOrCreate(
                ['nom' => $epreuve['nom']],
                $epreuve,
            );
        }
    }
}
