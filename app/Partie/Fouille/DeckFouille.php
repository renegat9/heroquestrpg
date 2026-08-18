<?php

declare(strict_types=1);

namespace App\Partie\Fouille;

use App\Partie\Equipement;
use App\Models\ClasseHeros;
use App\Models\Competence;
use App\Models\GabaritQuete;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Quete;
use App\Partie\Aleatoire\PrngLineaire;
use App\Partie\MoteurPortes;

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
     * @param  array<string, mixed>  $carte  grille assemblée (salles, aretes)
     * @return array{deck: list<array<string, mixed>>, salle_artefact: int|null, artefact_objet_id: int|null}
     */
    public function construire(GabaritQuete $gabarit, array $carte, Groupe $groupe, int $positionArc): array
    {
        // Graine TIRÉE AU SORT (décision de René, 2026-08-05) : la pioche ne
        // doit JAMAIS être reproductible. Elle dérivait de
        // crc32("{identifiant}:{positionArc}:fouille"), donc un même groupe
        // rejouant la même quête — « Recommencer la quête » du menu d'urgence,
        // reprise après TPK — retrouvait le deck dans le MÊME ordre : tout le
        // butin, les pièges et les errants étaient connus d'avance dès la
        // seconde tentative. On rebrasse à chaque partie, reprises comprises
        // (Sauvegarde remélange aussi à la restauration).
        //
        // L'ordre des APPELS au PRNG reste stable — ce n'est plus la
        // reproductibilité qui l'exige, mais la lisibilité : deck, puis salle
        // du coffre, puis arme unique.
        $prng = new PrngLineaire(random_int(0, 0x7FFFFFFF));

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
            'salles_coffre' => $this->sallesACoffre($carte, $salleArtefact),
        ];
    }

    /**
     * Salles abritant un coffre : la salle-artefact, plus toute salle située
     * DERRIÈRE une porte secrète.
     *
     * Trouver un passage caché ne rapportait rien — juste un raccourci. Le
     * coffre est ce qui paie la fouille. « Derrière » = celle des deux salles
     * de la jonction la plus éloignée du départ.
     *
     * @param  array<string, mixed>  $carte
     * @return list<int>
     */
    private function sallesACoffre(array $carte, ?int $salleArtefact): array
    {
        $profondeurs = $this->profondeurs($carte);
        $aretes = (array) data_get($carte, 'aretes', []);
        $salles = $salleArtefact === null ? [] : [$salleArtefact];

        foreach ((array) data_get($carte, 'portes', []) as $porte) {
            if (($porte['etat'] ?? '') !== MoteurPortes::ETAT_SECRETE) {
                continue;
            }

            $arete = $aretes[$porte['jonction'] ?? -1] ?? null;
            if ($arete === null) {
                continue;
            }

            $a = (int) $arete['a'];
            $b = (int) $arete['b'];
            $salles[] = ($profondeurs[$a] ?? 0) >= ($profondeurs[$b] ?? 0) ? $a : $b;
        }

        // Jamais la salle de départ : on ne cache pas un coffre là où le groupe
        // commence, et une porte secrète y menant en ferait un faux trésor.
        return array_values(array_filter(array_unique($salles), fn (int $s) => $s !== 0));
    }

    /**
     * Profondeur de chaque salle depuis le départ (parcours en largeur sur les
     * arêtes de l'arbre).
     *
     * @param  array<string, mixed>  $carte
     * @return array<int, int>
     */
    private function profondeurs(array $carte): array
    {
        $voisins = [];
        foreach ((array) data_get($carte, 'aretes', []) as $arete) {
            $voisins[(int) $arete['a']][] = (int) $arete['b'];
            $voisins[(int) $arete['b']][] = (int) $arete['a'];
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

        return $profondeur;
    }

    /**
     * Carte du coffre désigné : l'artefact, ou son repli en or.
     *
     * @return array<string, mixed>
     */
    public function carteCoffre(Quete $quete, ?int $salle = null): array
    {
        // L'ARME UNIQUE est réservée au coffre du fond (la salle-artefact) :
        // c'est la récompense de la progression, pas d'un raccourci trouvé.
        $estArtefact = $salle === null || $quete->estSalleArtefact($salle);
        $objet = $estArtefact && $quete->artefact_objet_id !== null && ! $this->artefactDejaPris($quete)
            ? Objet::find($quete->artefact_objet_id)
            : null;

        if ($objet !== null) {
            return ['issue' => 'artefact', 'objet_id' => $objet->id, 'coffre' => true];
        }

        // Coffre ORDINAIRE (derrière une porte secrète) : une potion une fois
        // sur deux, sinon de l'or. Un coffre ne rend jamais rien — le trouver
        // est déjà l'épreuve.
        if (! $estArtefact) {
            $potions = Objet::query()
                ->whereIn('nom', (array) data_get($quete->gabarit?->structure, 'deck_fouille.potions', []))
                ->orderBy('id')->pluck('id')->all();

            if ($potions !== [] && $salle % 2 === 0) {
                return ['issue' => 'potion', 'objet_id' => $potions[$salle % count($potions)], 'coffre' => true];
            }

            $or = max(1, (int) data_get($quete->gabarit?->structure, 'deck_fouille.or', 30)) * 2;

            return ['issue' => 'tresor', 'or' => $or, 'coffre' => true];
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
        $orDefaut = max(1, (int) data_get($composition, 'or', 25));

        // Montants d'or des cartes du plateau. `tresor` reste le nom d'issue :
        // les journaux des campagnes en cours le portent déjà.
        $montants = ['gemme' => 35, 'or_25' => 25, 'or_15' => 15, 'bijoux' => 50, 'tresor' => $orDefaut];

        // Potions du deck, résolues UNE fois. Absente du catalogue → la carte
        // se rabat sur de l'or plutôt que de disparaître.
        $parNom = fn (string $nom) => Objet::where('nom', $nom)->value('id');
        $potions = [
            // La fiole du deck soigne 1d6 ; la « Potion de soin » du marché
            // rend un montant fixe. Deux objets distincts, à dessein.
            'potion_soin' => $parNom('Fiole de soin'),
            'potion_heroisme' => $parNom("Potion d'héroïsme"),
            'potion_force' => $parNom('Potion de force'),
            'potion_defense' => $parNom('Potion de défense'),
        ];

        $deck = [];

        foreach ($nombres as $type => $nombre) {
            for ($i = 0; $i < (int) $nombre; $i++) {
                $deck[] = $this->carte((string) $type, $montants, $potions, $orDefaut);
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
     * Une carte du deck, AUTO-SUFFISANTE : montant et identité figés ici.
     *
     * @param  array<string, int>  $montants
     * @param  array<string, int|null>  $potions
     * @return array<string, mixed>
     */
    private function carte(string $type, array $montants, array $potions, int $orDefaut): array
    {
        if (isset($montants[$type])) {
            return ['issue' => 'tresor', 'or' => $montants[$type], 'carte' => $type];
        }

        if (array_key_exists($type, $potions)) {
            return $potions[$type] === null
                ? ['issue' => 'tresor', 'or' => $orDefaut]
                : ['issue' => 'potion', 'objet_id' => $potions[$type], 'carte' => $type];
        }

        // Deux pièges distincts au plateau (trou / flèches) : même effet
        // mécanique ici, la variante sert à la narration et au journal.
        if ($type === 'piege_trou' || $type === 'piege_fleches') {
            return ['issue' => 'piege', 'variante' => $type === 'piege_trou' ? 'trou' : 'fleches'];
        }

        return ['issue' => $type];
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
     * L'arme unique de cette quête est-elle DÉJÀ dans les mains du groupe ?
     *
     * L'unicité n'était vérifiée qu'à la CONSTRUCTION du deck (choisirArtefact
     * écarte ce que le groupe possède déjà), donc une seule fois par quête. Or
     * la fouille est passée à « une par héros et par salle » : les deux héros
     * qui fouillent la salle-artefact repartaient chacun avec le MÊME objet
     * unique (constaté en test de jeu 2026-08-05 — Arbalète des Murmures en
     * double dans le même groupe). L'unicité est par GROUPE : au second
     * fouilleur, le coffre verse son or.
     */
    private function artefactDejaPris(Quete $quete): bool
    {
        $idsHeros = $quete->groupe?->personnages()->pluck('personnages.id') ?? collect();

        return Inventaire::query()
            ->whereIn('personnage_id', $idsHeros)
            ->where('objet_id', $quete->artefact_objet_id)
            ->exists();
    }

    /**
     * Un artefact PORTABLE que le GROUPE ne possède pas déjà.
     *
     * Portée volontairement limitée au groupe : deux campagnes parallèles
     * peuvent posséder la même Lame des Esprits, comme deux tablées voisines. On
     * inclut les héros INACTIFS — un personnage au banc garde son artefact
     * dans son inventaire, l'oublier créerait un doublon.
     *
     * Armes ET armures : depuis la conversion du paquet d'artefacts
     * (reference/16 §9), le coffre peut rendre l'Armure de Borin ou un talisman
     * de classe, pas seulement une arme. Un artefact CONSOMMABLE ne vient qu'en
     * repli, quand tout le portable est déjà détenu — un coffre de fin de donjon
     * ne doit pas verser un parchemin à usage unique tant qu'il reste une pièce
     * permanente à donner, mais un parchemin vaut mieux que de l'or.
     */
    private function choisirArtefact(Groupe $groupe, PrngLineaire $prng): ?int
    {
        $idsHeros = $groupe->personnages()->pluck('personnages.id');

        $possedes = Inventaire::query()->whereIn('personnage_id', $idsHeros)->pluck('objet_id');

        // Maîtrises que le groupe pourra atteindre : celles des classes ACTIVES,
        // plus celles que leurs arbres de compétences ouvrent. On teste la
        // classe, pas les nœuds acquis — un barbare de niveau 1 n'a pas encore
        // Maîtrise lourde, mais il pourra la prendre.
        //
        // Cette règle remplace un test codé en dur sur le seul barbare. Elle
        // couvre désormais tous les artefacts verrouillés d'un coup : les quatre
        // talismans de classe (Amulette du Nord, Brassards elfiques, Capuche du
        // Magister, Runes naines) seraient sinon du BUTIN MORT — un groupe sans
        // elfe pouvait perdre son unique artefact de quête sur des brassards
        // que personne ne porterait jamais.
        $accessibles = $this->tagsAccessiblesAuGroupe($groupe);

        // Aucune maîtrise déclarée (catalogue de classes non semé) : on
        // n'applique AUCUN filtre — même repli « fail open » que
        // `Equipement::verifierAccesEquipement()`. Une donnée de référence
        // manquante ne doit pas transformer tous les artefacts en butin mort.
        $filtrer = $accessibles !== [];

        $eligibles = fn (array $categories) => Objet::query()
            ->where('rarete', 'unique')
            ->whereIn('categorie', $categories)
            ->whereNotIn('id', $possedes)
            ->orderBy('id')
            ->get(['id', 'tag_equipement'])
            ->reject(fn (Objet $o) => $filtrer
                && $o->tag_equipement !== null
                && $o->tag_equipement !== ''
                && ! in_array($o->tag_equipement, $accessibles, true))
            ->pluck('id')
            ->all();

        $candidats = $eligibles(['arme', 'armure']);

        // REPLI : un artefact consommable (Parchemin de Sorts) plutôt que de
        // l'or quand tout le portable est déjà détenu. La Fiole de soin en est
        // exclue — c'est une carte du deck de TRÉSOR, pas un artefact de coffre,
        // et elle reviendrait sans fin. L'ordre compte : un coffre de fin de
        // donjon doit rendre une pièce permanente s'il en reste une.
        if ($candidats === []) {
            $candidats = array_values(array_diff(
                $eligibles(['consommable']),
                Objet::where('nom', 'Fiole de soin')->pluck('id')->all(),
            ));
        }

        if ($candidats === []) {
            return null; // tous déjà trouvés → le coffre versera de l'or
        }

        return (int) $candidats[$prng->suivant() % count($candidats)];
    }

    /**
     * Tags de maîtrise qu'au moins un héros ACTIF du groupe peut atteindre :
     * ceux de sa classe, plus ceux qu'ouvrent les nœuds `acces_equipement` de
     * son arbre.
     *
     * @return list<string>
     */
    private function tagsAccessiblesAuGroupe(Groupe $groupe): array
    {
        // Délègue depuis le 2026-08-17 : la même question — « que peut porter ce
        // groupe ? » — se pose désormais aussi au butin de mobilier, et deux
        // implémentations auraient fini par répondre différemment.
        return app(Equipement::class)->tagsAccessiblesAux(
            $groupe->personnages()->wherePivot('actif', true)->get(),
        );
    }
}
