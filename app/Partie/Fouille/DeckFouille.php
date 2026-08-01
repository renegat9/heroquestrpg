<?php

declare(strict_types=1);

namespace App\Partie\Fouille;

use App\Models\GabaritQuete;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Quete;
use App\Partie\Aleatoire\PrngLineaire;

/**
 * Deck de cartes de fouille + coffre à artefact (doc 04 §4/§6, doc 14 §3.2).
 *
 * Reprend le principe du jeu de plateau : fouiller une salle, c'est piocher une
 * carte — or, potion, piège, monstre errant, ou rien. Le deck est **bâti neuf
 * au démarrage de chaque quête** depuis la composition du gabarit, et **pioché
 * sans remise** : la composition est donc GARANTIE, là où l'ancien tirage
 * (`ceil(d6/6 × total)` sur des poids) n'en donnait qu'une espérance, biaisée
 * dès que le total des poids n'était pas 6.
 *
 * À part : **le coffre à artefact**. Une salle — la plus profonde — abrite au
 * plus UNE arme unique. Elle ne consomme aucune carte : c'est un bonus net, et
 * le héros qui l'ouvre la reçoit (ce qui règle la question « à qui va l'objet »
 * sans rituel de partage). Sans arme disponible, ce coffre verse `or_coffre`.
 */
final class DeckFouille
{
    /**
     * Bâtit le deck, désigne le coffre et lui attribue une arme unique.
     *
     * L'ordre des appels au PRNG doit rester STABLE : c'est lui qui garantit
     * qu'une même graine redonne exactement le même deck (testabilité, et
     * reproductibilité d'une partie rejouée depuis son journal).
     *
     * @param  array<string, mixed>  $carte  grille assemblée (salles, aretes)
     * @return array{deck: list<array<string, mixed>>, salle_artefact: int|null, artefact_objet_id: int|null}
     */
    public function construire(GabaritQuete $gabarit, array $carte, Groupe $groupe, int $positionArc): array
    {
        // Graine VOLONTAIREMENT distincte de celle de la carte
        // (crc32("{identifiant}:{positionArc}")) : sans le suffixe, le deck
        // serait corrélé au donjon — même carte, même pioche.
        $prng = new PrngLineaire(crc32($groupe->identifiant.':'.$positionArc.':fouille'));

        $composition = (array) data_get($gabarit->structure, 'deck_fouille', []);
        $salles = (array) data_get($carte, 'salles', []);
        $nbSalles = count($salles);

        $deck = $prng->melanger($this->cartes($composition, $nbSalles));
        $salleArtefact = $this->salleLaPlusProfonde($carte, $prng);
        $artefactId = $salleArtefact === null ? null : $this->choisirArtefact($groupe, $prng);

        return [
            'deck' => $deck,
            'salle_artefact' => $salleArtefact,
            'artefact_objet_id' => $artefactId,
        ];
    }

    /**
     * Carte du coffre désigné : l'artefact, ou son repli en or.
     *
     * @return array<string, mixed>
     */
    public function carteCoffre(Quete $quete): array
    {
        $objet = $quete->artefact_objet_id === null ? null : Objet::find($quete->artefact_objet_id);

        if ($objet !== null) {
            return ['issue' => 'artefact', 'objet_id' => $objet->id, 'coffre' => true];
        }

        $or = (int) data_get($quete->gabarit?->structure, 'deck_fouille.or_coffre', 0);

        // Repli du repli : un coffre désigné ne doit jamais être vide, sinon la
        // salle la plus profonde du donjon serait une déception.
        if ($or <= 0) {
            $or = max(1, (int) data_get($quete->gabarit?->structure, 'deck_fouille.or', 30)) * 3;
        }

        return ['issue' => 'tresor', 'or' => $or, 'coffre' => true];
    }

    /**
     * Pioche la carte suivante. Deck épuisé → « rien », signalé par `deck_vide`
     * (une salle de plus que de cartes reste possible sur une carte généreuse).
     *
     * @return array<string, mixed>
     */
    public function piocher(Quete $quete): array
    {
        return $quete->piocherCarte() ?? ['issue' => 'rien', 'deck_vide' => true];
    }

    /**
     * Développe la composition du gabarit en cartes AUTO-SUFFISANTES : montant
     * d'or et identité de la potion sont figés ici. Piocher devient un simple
     * `array_shift`, sans dé ni relecture du gabarit — et un snapshot restaure
     * exactement le futur de la quête.
     *
     * @param  array<string, mixed>  $composition
     * @return list<array<string, mixed>>
     */
    private function cartes(array $composition, int $nbSalles): array
    {
        $nombres = (array) data_get($composition, 'cartes', []);
        $or = max(1, (int) data_get($composition, 'or', 30));

        // Vivier de potions résolu une fois. Vide (catalogue non semé) : les
        // cartes potion se rabattent sur de l'or plutôt que de disparaître.
        $potions = Objet::query()
            ->whereIn('nom', (array) data_get($composition, 'potions', []))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $deck = [];

        foreach ($nombres as $issue => $nombre) {
            for ($i = 0; $i < (int) $nombre; $i++) {
                if ($issue === 'potion') {
                    $deck[] = $potions === []
                        ? ['issue' => 'tresor', 'or' => $or]
                        : ['issue' => 'potion', 'objet_id' => $potions[count($deck) % count($potions)]];

                    continue;
                }

                $deck[] = $issue === 'tresor'
                    ? ['issue' => 'tresor', 'or' => $or]
                    : ['issue' => (string) $issue];
            }
        }

        // Le deck doit rester PLUS GRAND que le nombre de salles : sinon la
        // dernière fouille est déductible (« il ne reste qu'une carte, c'est
        // forcément le piège »). On complète en « rien ».
        while (count($deck) <= $nbSalles) {
            $deck[] = ['issue' => 'rien'];
        }

        return $deck;
    }

    /**
     * Salle la plus éloignée du départ dans l'arbre des couloirs — parcours en
     * largeur sur `carte.aretes` depuis la salle 0.
     *
     * C'est le canon HeroQuest : l'artefact récompense la progression. Le tirer
     * au sort le ferait parfois tomber dans la première salle ouverte, au tour
     * 2, avant le moindre combat. Égalité de profondeur tranchée par le PRNG.
     */
    private function salleLaPlusProfonde(array $carte, PrngLineaire $prng): ?int
    {
        $salles = (array) data_get($carte, 'salles', []);

        if (count($salles) < 2) {
            return null; // pas de coffre désigné : toutes les salles piochent
        }

        $voisins = [];
        foreach ((array) data_get($carte, 'aretes', []) as $arete) {
            $a = (int) ($arete['a'] ?? -1);
            $b = (int) ($arete['b'] ?? -1);
            if ($a < 0 || $b < 0) {
                continue;
            }
            $voisins[$a][] = $b;
            $voisins[$b][] = $a;
        }

        $profondeur = [0 => 0];
        $file = [0];

        while ($file !== []) {
            $courant = array_shift($file);
            foreach ($voisins[$courant] ?? [] as $voisin) {
                if (! isset($profondeur[$voisin])) {
                    $profondeur[$voisin] = $profondeur[$courant] + 1;
                    $file[] = $voisin;
                }
            }
        }

        unset($profondeur[0]); // jamais la salle de départ

        if ($profondeur === []) {
            // Arbre absent ou salles isolées : on retombe sur la dernière salle
            // posée, qui est la feuille finale côté assembleur.
            return count($salles) - 1;
        }

        $max = max($profondeur);
        $candidats = array_keys($profondeur, $max, true);
        sort($candidats);

        return (int) $candidats[$prng->suivant() % count($candidats)];
    }

    /**
     * Une arme unique que le GROUPE ne possède pas déjà.
     *
     * Portée volontairement limitée au groupe : deux campagnes parallèles
     * peuvent posséder la même Lame d'Aube, comme deux tablées voisines. On
     * inclut les héros INACTIFS — un personnage au banc garde son artefact
     * dans son inventaire, l'oublier créerait un doublon.
     */
    private function choisirArtefact(Groupe $groupe, PrngLineaire $prng): ?int
    {
        $idsHeros = $groupe->personnages()->pluck('personnages.id');

        $possedes = Inventaire::query()->whereIn('personnage_id', $idsHeros)->pluck('objet_id');

        // Le tag `arme_deux_mains` n'est ouvert que par Maîtrise lourde, nœud de
        // l'arbre BARBARE : sans barbare actif, une telle arme serait du butin
        // mort — personne ne pourrait jamais l'équiper, et elle occuperait la
        // place du seul artefact de la quête. On teste la CLASSE, pas la
        // possession du nœud : un barbare de niveau 1 ne l'a pas encore, mais il
        // pourra le prendre.
        $barbareActif = $groupe->personnages()
            ->wherePivot('actif', true)
            ->where('classe', 'barbare')
            ->exists();

        $candidats = Objet::query()
            ->where('rarete', 'unique')
            ->where('categorie', 'arme')
            ->whereNotIn('id', $possedes)
            ->orderBy('id')
            ->get(['id', 'tag_equipement'])
            ->reject(fn (Objet $o) => ! $barbareActif && $o->tag_equipement === 'arme_deux_mains')
            ->pluck('id')
            ->all();

        if ($candidats === []) {
            return null; // toutes déjà trouvées → le coffre versera de l'or
        }

        return (int) $candidats[$prng->suivant() % count($candidats)];
    }
}
