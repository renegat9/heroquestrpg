<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\MotsClesEquipement;
use App\Models\InstanceMonstre;
use App\Models\Mobilier;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Piege;
use App\Models\Quete;
use App\Partie\Images\BibliothequeImages;

/**
 * Construit les SCÈNES illustrées d'un résultat de tour, pour l'écran de table
 * (`docs/contrat-api.md`, `.table.scene`).
 *
 * Même entrée que {@see JournalCombat} — le résultat structuré du moteur — et
 * même parcours ({@see JournalCombat::actionsDuTour()}), mais une sortie
 * différente : le journal rend une phrase qui défile, on rend ici de quoi
 * MONTRER l'instant. Les illustrations du catalogue existent en 1024×1024 et
 * n'étaient jamais vues à plus de 40 px.
 *
 * ⚠ **Tout est décidé ici, rien côté client.** Les URL d'images sont résolues
 * par {@see BibliothequeImages}, dont chaque résolveur finit sur un emblème SVG :
 * un cadre ne peut donc pas rester vide, même sans clé d'IA. Le titre est écrit,
 * l'issue est nommée. La table affiche, elle n'arbitre pas.
 *
 * ⚠ **Aucune durée.** Le retour à la carte se fait au clic sur l'écran du
 * narrateur ou après un délai réglé dans ses paramètres (défaut 5 s) : c'est une
 * préférence d'appareil, comme le volume, pas une règle de jeu.
 *
 * ⚠ **Une action sans scène rend `null`**, et c'est la bonne réponse : un
 * déplacement est muet par principe (le journal le dit déjà — raconter chaque
 * pas noierait le reste), et une scène vide serait une clé décorative de plus.
 */
final class SceneDeTable
{
    /** Genres rendus par le composant de table. Tout autre type reste muet. */
    public const GENRES = ['attaque', 'piege', 'fouille', 'jet', 'sort', 'salle', 'chute', 'objet', 'deplacement', 'reaction'];

    public function __construct(private readonly BibliothequeImages $images) {}

    /**
     * Les scènes d'un tour, dans l'ordre où les actions se sont produites.
     *
     * @param  array<string, mixed>  $resultat
     * @return list<array<string, mixed>>
     */
    /**
     * @param  list<string>  $figuresEnMarche  `ResolveurTour::figuresEnMarche()` —
     *                                         les figurines dont la scène doit attendre la marche
     */
    public function depuisResultat(array $resultat, Personnage $acteur, array $figuresEnMarche = []): array
    {
        $scenes = [];

        foreach (JournalCombat::actionsDuTour($resultat) as $action) {
            foreach ($this->depuisAction($action, $acteur) as $scene) {
                $figure = $this->figureDe($action, $acteur);
                $scene['figure'] = in_array($figure, $figuresEnMarche, true) ? $figure : null;
                $scenes[] = $scene;
            }
        }

        return $scenes;
    }

    /**
     * La FIGURINE qui agit dans cette action, au format des trajets de l'état
     * (`type:id`, cf. `EtatGroupeDiffuse::$mouvements`).
     *
     * ⚠ C'est ce qui permet à la table d'attendre la fin de la MARCHE avant
     * d'afficher le coup (2026-09-17). Sans ce lien, « Gardien des Cryptes
     * attaque Borin » s'affichait au centre de la carte pendant que le monstre
     * marchait encore vers lui — mesuré : popup à 7 911 ms, arrivée à 9 319 ms.
     * Publiée dans `figure` SEULEMENT si la figurine a marché dans la même
     * résolution : la clé veut dire « attends ce trajet », et la table l'attend
     * même s'il n'est pas encore arrivé.
     *
     * @param  array<string, mixed>  $a
     */
    private function figureDe(array $a, Personnage $acteur): string
    {
        $instanceId = (int) ($a['instance_id'] ?? 0);

        if ($instanceId > 0 && in_array($a['type'] ?? null, ['attaque_monstre', 'deplacement_monstre'], true)) {
            return 'monstre:'.$instanceId;
        }

        return 'heros:'.$acteur->id;
    }

    /**
     * Les scènes d'une RÉACTION hors tour acceptée — jouée depuis une manette,
     * souvent pendant le tour d'un monstre, après son attaque.
     *
     * ⚠ Elles étaient INVISIBLES à la table (René, 2026-09-17 : « ne pas oublier
     * qu'une manette peut aussi réagir durant le tour d'un monstre »). La phase
     * des monstres ne s'arrête pas pendant que le joueur réfléchit : la riposte
     * de *Représailles*, la *Parade au bouclier*, les *Cendres du Phénix*
     * arrivaient après, sans scène — la table montrait le coup, jamais la parade.
     *
     * Une riposte est une vraie frappe : elle réutilise la scène d'attaque, avec
     * le nom de la réaction en sous-titre. Les autres ont leur scène propre.
     *
     * @param  array<string, mixed>  $r  résultat de `MoteurReactions::resoudre()`
     * @return list<array<string, mixed>>
     */
    public function depuisReaction(array $r, Personnage $heros): array
    {
        if (($r['type'] ?? null) !== 'reaction' || empty($r['active'])) {
            return [];
        }

        if (is_array($r['frappe'] ?? null)) {
            return array_map(
                fn (array $scene) => array_replace($scene, ['sous_titre' => (string) ($r['sort'] ?? 'Riposte')]),
                $this->depuisResultat($r['frappe'], $heros),
            );
        }

        $acteurs = [$this->acteurHeros($heros, 'acteur')];
        $victime = isset($r['victime_id']) ? Personnage::find((int) $r['victime_id']) : null;

        if ($victime !== null && $victime->id !== $heros->id) {
            $acteurs[] = $this->acteurHeros($victime, 'cible');
        }

        $objets = [];

        if (isset($r['de_artefact'], $r['artefact'])) {
            $objet = Objet::where('nom', (string) $r['artefact'])->first();
            $objets[] = [
                'nom' => (string) $r['artefact'],
                'image_url' => $this->images->urlObjet($objet?->id, (string) $r['artefact'])
                    ?? $this->images->vignette('objet', $objet?->id ?? 0),
                'detail' => 'dé '.(int) $r['de_artefact'].' — '.(! empty($r['artefact_perdu']) ? 'se consume' : 'conservé'),
            ];
        }

        $nom = (string) ($r['sort'] ?? 'Réaction');

        return [[
            'genre' => 'reaction',
            'titre' => $nom,
            'sous_titre' => $victime !== null && $victime->id !== $heros->id
                ? $heros->nom.' protège '.$victime->nom
                : $heros->nom.' réagit',
            'acteurs' => $acteurs,
            'jet' => null,
            'deplacement' => null,
            'figure' => null, // une réaction ne suit jamais une marche
            'objets' => $objets,
            // Le texte vient du fil de combat : une réaction se dit d'une seule
            // façon, sur la table comme dans le journal.
            'issue' => [
                'ton' => ! empty($r['artefact_perdu']) ? 'degats' : 'tresor',
                'libelle' => app(JournalCombat::class)->issueReaction($r) ?? $nom,
            ],
        ]];
    }

    /**
     * Une action → 0..n scènes : la sienne, puis les pièges qu'elle a
     * déclenchés. Un piège marché PENDANT un déplacement est sa propre scène —
     * c'est même le cas que René a nommé en premier.
     *
     * @param  array<string, mixed>  $a
     * @return list<array<string, mixed>>
     */
    private function depuisAction(array $a, Personnage $acteur): array
    {
        $scenes = [];
        $principale = $this->scenePrincipale($a, $acteur);

        if ($principale !== null) {
            $scenes[] = $principale;
        }

        // Les deux clés d'imbrication (coffre piégé, désamorçage raté, fosse
        // marchée en chemin) — voir JournalCombat::CLES_PIEGE.
        foreach (JournalCombat::CLES_PIEGE as $cle) {
            $brut = $a[$cle] ?? null;
            $liste = isset($brut['type']) ? [$brut] : (array) $brut;

            foreach ($liste as $declenchement) {
                if (is_array($declenchement) && ($scene = $this->piege($declenchement, $acteur)) !== null) {
                    $scenes[] = $scene;
                }
            }
        }

        return $scenes;
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>|null
     */
    private function scenePrincipale(array $a, Personnage $acteur): ?array
    {
        return match ($a['type'] ?? null) {
            'attaque', 'attaque_allie' => $this->attaqueDuGroupe($a, $acteur),
            'attaque_balayee' => $this->attaqueBalayee($a, $acteur),
            'attaque_monstre' => $this->attaqueDuMonstre($a),
            'piege_declenche' => $this->piege($a, $acteur),
            'fouille_tresor', 'fouille_mobilier' => $this->fouille($a, $acteur),
            'jet', 'desamorcage', 'franchissement' => $this->jet($a, $acteur),
            'actionner_levier' => $this->levier($a, $acteur),
            'potion' => $this->potion($a, $acteur),
            'sort', 'parchemin' => $this->sort($a, $acteur),
            default => null,
        };
    }

    // ---- genres -----------------------------------------------------------

    /**
     * Héros (ou allié) contre monstre.
     *
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>|null
     */
    private function attaqueDuGroupe(array $a, Personnage $acteur): ?array
    {
        $instanceId = (int) ($a['cible']['instance_id'] ?? 0);

        if ($instanceId === 0) {
            return null;
        }

        $attaquant = (string) ($a['allie'] ?? $acteur->nom);
        $cibleNom = (string) ($a['cible']['nom'] ?? 'la cible');
        $degats = (int) ($a['degats'] ?? 0);

        return [
            'genre' => 'attaque',
            'titre' => "{$attaquant} attaque {$cibleNom}",
            'sous_titre' => $this->porteeLisible($a),
            'acteurs' => [
                $this->acteurHeros($acteur, 'attaquant', $a['allie'] ?? null),
                $this->acteurMonstre($instanceId, $cibleNom, 'defenseur'),
            ],
            'jet' => $this->jetDesDes($a, $attaquant, $cibleNom),
            'deplacement' => null,
            'figure' => null,
            'objets' => [],
            'issue' => $this->issueDuCoup($a, $degats, $cibleNom, vaincue: ! empty($a['cible_vaincue'])),
        ];
    }

    /**
     * Monstre contre héros — l'autre côté du même coup.
     *
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>|null
     */
    private function attaqueDuMonstre(array $a): ?array
    {
        $instanceId = (int) ($a['instance_id'] ?? 0);
        $cibleId = (int) ($a['cible']['personnage_id'] ?? 0);

        if ($instanceId === 0 || $cibleId === 0) {
            return null;
        }

        $monstre = (string) ($a['monstre'] ?? 'Le monstre');
        $cibleNom = (string) ($a['cible']['nom'] ?? 'un héros');
        $degats = (int) ($a['degats'] ?? 0);
        $cible = Personnage::find($cibleId);

        return [
            'genre' => 'attaque',
            'titre' => "{$monstre} attaque {$cibleNom}",
            'sous_titre' => $this->porteeLisible($a),
            'acteurs' => array_values(array_filter([
                $this->acteurMonstre($instanceId, $monstre, 'attaquant'),
                $cible === null ? null : $this->acteurHeros($cible, 'defenseur'),
            ])),
            'jet' => $this->jetDesDes($a, $monstre, $cibleNom),
            'deplacement' => null,
            'figure' => null,
            'objets' => [],
            'issue' => $this->issueDuCoup($a, $degats, $cibleNom, vaincue: ! empty($a['cible_tombee']), heros: true),
        ];
    }

    /**
     * Piège déclenché — le héros et l'illustration du piège, avec son effet.
     *
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>|null
     */
    private function piege(array $a, Personnage $acteur): ?array
    {
        if (($a['type'] ?? 'piege_declenche') !== 'piege_declenche') {
            return null;
        }

        $nomPiege = (string) ($a['piege']['nom'] ?? 'Piège');
        $victimeId = (int) ($a['personnage']['id'] ?? 0);
        $victime = $victimeId > 0 ? Personnage::find($victimeId) : null;
        $victime ??= $acteur;
        $degats = (int) ($a['degats'] ?? 0);

        $detail = array_values(array_filter([
            $degats > 0 ? "−{$degats} PV de Body" : null,
            ! empty($a['immobilise']) ? 'immobilise' : null,
            $a['condition_appliquee'] ?? null,
        ]));

        return [
            'genre' => 'piege',
            'titre' => "{$nomPiege} !",
            'sous_titre' => $victime->nom.' vient de le déclencher',
            'acteurs' => [$this->acteurHeros($victime, 'acteur')],
            'jet' => null,
            'deplacement' => null,
            'figure' => null,
            'objets' => [[
                'nom' => $nomPiege,
                'image_url' => $this->imagePiege($nomPiege),
                'detail' => $detail === [] ? 'sans une égratignure' : implode(' · ', $detail),
            ]],
            'issue' => [
                'ton' => ! empty($a['tombe']) ? 'mort' : ($degats > 0 ? 'degats' : 'info'),
                'libelle' => ! empty($a['tombe'])
                    ? $victime->nom.' tombe'
                    : ($degats > 0 ? "−{$degats} PV" : 'aucun dégât'),
            ],
        ];
    }

    /**
     * Fouille — ce qui sort du paquet : objet, or, piège de coffre ou errant.
     *
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>|null
     */
    private function fouille(array $a, Personnage $acteur): ?array
    {
        $issue = (string) ($a['issue'] ?? '');
        $objets = [];
        $libelle = '';
        $ton = 'info';

        if (($objetId = (int) ($a['objet']['id'] ?? $a['objet_id'] ?? 0)) > 0) {
            $objet = Objet::find($objetId);

            if ($objet !== null) {
                $objets[] = [
                    'nom' => $objet->nom,
                    'image_url' => $this->images->urlObjet($objet->id, $objet->nom)
                        ?? $this->images->vignette('objet', $objet->id),
                    // ⚠ `MotsClesEquipement::avantages()` est le point de passage
                    // DÉJÀ existant du vocabulaire d'équipement (étal, sac, menu
                    // d'action). Réécrire ces phrases ici les ferait dériver du
                    // jour où un mot-clé change — et le projet a déjà payé ça
                    // avec une table de libellés tenue côté client.
                    'detail' => $this->effetsLisibles($objet),
                ];
                // ⚠ PAS le nom de l'objet : il est déjà écrit sous son
                // illustration, deux lignes plus haut. Une issue doit dire ce
                // qui s'est PASSÉ, pas répéter ce qu'on voit.
                $libelle = $acteur->nom.' l\'empoche';
                $ton = 'tresor';
            }
        }

        if ($issue === 'tresor' && ($or = (int) ($a['or'] ?? 0)) > 0) {
            $libelle = "{$or} pièces d'or";
            $ton = 'tresor';
        }

        if ($issue === 'errant') {
            $libelle = 'un monstre errant surgit';
            $ton = 'degats';

            if (($instanceId = (int) ($a['errant']['instance_id'] ?? 0)) > 0) {
                $objets[] = $this->objetDepuisMonstre($instanceId, (string) ($a['errant']['nom'] ?? 'Errant'));
            }
        }

        if ($libelle === '' && $objets === []) {
            $libelle = 'rien à prendre ici';
            $ton = 'echec';
        }

        return [
            'genre' => 'fouille',
            'titre' => $acteur->nom.' fouille',
            'sous_titre' => $a['coffre'] ?? false ? 'Un coffre' : null,
            'acteurs' => [$this->acteurHeros($acteur, 'acteur')],
            'jet' => null,
            'deplacement' => null,
            'figure' => null,
            'objets' => $objets,
            'issue' => ['ton' => $ton, 'libelle' => $libelle],
        ];
    }

    /**
     * Jet d'attribut : épreuve, désamorçage, franchissement de fosse.
     *
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>|null
     */
    private function jet(array $a, Personnage $acteur): ?array
    {
        $jet = (array) ($a['jet'] ?? []);
        $succes = (int) ($jet['succes'] ?? $a['succes'] ?? 0);
        $difficulte = (int) ($jet['difficulte'] ?? $a['difficulte'] ?? 0);

        if ($difficulte === 0) {
            return null; // pas un vrai jet : rien à montrer
        }

        $reussi = $succes >= $difficulte;
        $objets = [];

        if (($nomPiege = (string) ($a['piege']['nom'] ?? '')) !== '') {
            $objets[] = [
                'nom' => $nomPiege,
                'image_url' => $this->imagePiege($nomPiege),
                'detail' => null,
            ];
        }

        // Le MEUBLE fracassé : c'est lui le sujet du jet, il mérite son image.
        if (($nomMeuble = (string) ($a['mobilier'] ?? '')) !== '') {
            $type = Mobilier::where('nom', $nomMeuble)->first();
            $objets[] = [
                'nom' => $nomMeuble,
                'image_url' => $this->images->urlMobilier($type?->id, $nomMeuble)
                    ?? $this->images->vignette('mobilier', $type?->id ?? 0),
                // ⚠ « en morceaux », pas « fracassé » : le catalogue mêle Table,
                // Armoire, Coffre et Trône, et n'a pas de genre à lire. La légende
                // « Table · fracassé » est sortie telle quelle dans le livret.
                'detail' => empty($a['detruit']) ? null : 'en morceaux',
            ];
        }

        // ⚠ Le butin d'un meuble est NICHÉ sous `butin`, jamais à plat : le
        // payload d'un jet porte déjà son propre `issue` (le résultat du DÉ), et
        // les fusionner écrasait l'un par l'autre. On lit donc les deux niveaux.
        $butin = (array) ($a['butin'] ?? []);

        if (($objetId = (int) ($butin['objet']['id'] ?? $a['objet']['id'] ?? 0)) > 0
            && ($objet = Objet::find($objetId)) !== null) {
            $objets[] = [
                'nom' => $objet->nom,
                'image_url' => $this->images->urlObjet($objet->id, $objet->nom)
                    ?? $this->images->vignette('objet', $objet->id),
                'detail' => $this->effetsLisibles($objet),
            ];
        }

        return [
            'genre' => 'jet',
            'titre' => $acteur->nom.' — '.(string) ($a['libelle'] ?? 'jet'),
            'sous_titre' => "{$succes} succès sur {$difficulte} requis",
            'acteurs' => [$this->acteurHeros($acteur, 'acteur')],
            'jet' => null,
            'deplacement' => null,
            'figure' => null,
            'objets' => $objets,
            'issue' => [
                'ton' => $reussi ? 'tresor' : 'echec',
                'libelle' => $reussi ? $this->gainDuJet($a + $butin) : 'raté',
            ],
        ];
    }

    /**
     * POTION ou consommable bu.
     *
     * ⚠ Deux cas, et l'écran doit les distinguer : boire SA potion, ou en tendre
     * une à un voisin. Le moteur le dit déjà — `porteur_id` n'est présent que
     * lorsque la potion change de main, et il vaut null sur soi. On ne redéduit
     * donc rien : c'est le payload qui tranche.
     *
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>|null
     */
    private function potion(array $a, Personnage $acteur): ?array
    {
        $nom = (string) ($a['objet'] ?? '');

        if ($nom === '') {
            return null;
        }

        $buveur = Personnage::find((int) ($a['personnage_id'] ?? 0)) ?? $acteur;
        $porteur = ($id = (int) ($a['porteur_id'] ?? 0)) > 0 ? Personnage::find($id) : null;
        $objet = Objet::where('nom', $nom)->first();
        $effets = (array) ($a['effets'] ?? []);

        $acteurs = $porteur === null
            ? [$this->acteurHeros($buveur, 'acteur')]
            : [$this->acteurHeros($porteur, 'acteur'), $this->acteurHeros($buveur, 'cible')];

        return [
            'genre' => 'objet',
            'titre' => $porteur === null
                ? $buveur->nom.' boit '.$nom
                : $porteur->nom.' tend '.$nom.' à '.$buveur->nom,
            'sous_titre' => $porteur === null ? null : 'À un héros adjacent',
            'acteurs' => $acteurs,
            'jet' => null,
            'deplacement' => null,
            'figure' => null,
            'objets' => [[
                'nom' => $nom,
                'image_url' => $this->images->urlObjet($objet?->id, $nom)
                    ?? $this->images->vignette('objet', $objet?->id ?? $nom),
                'detail' => $objet === null ? null : $this->effetsLisibles($objet),
            ]],
            'issue' => $this->effetPotion($effets, $buveur),
        ];
    }

    /**
     * Ce que la potion a RÉELLEMENT fait — le soin effectif, pas celui promis
     * par la carte : boire une potion de 4 PV à 1 PV du maximum n'en rend qu'un.
     *
     * @param  array<string, mixed>  $effets
     * @return array{ton: string, libelle: string}
     */
    private function effetPotion(array $effets, Personnage $buveur): array
    {
        $bouts = [];

        if (($body = (int) ($effets['soin_pv_body'] ?? 0)) > 0) {
            $bouts[] = "+{$body} PV de Body";
        }
        if (($mind = (int) ($effets['soin_pv_mind'] ?? 0)) > 0) {
            $bouts[] = "+{$mind} PV de Mind";
        }
        if (! empty($effets['buff'])) {
            $bouts[] = (string) $effets['buff'];
        }
        if (! empty($effets['attaque_supplementaire'])) {
            $bouts[] = 'une attaque supplémentaire';
        }
        if (($sorts = (int) ($effets['sorts_restaures'] ?? 0)) > 0) {
            $bouts[] = $sorts.' sort'.($sorts > 1 ? 's' : '').' retrouvé'.($sorts > 1 ? 's' : '');
        }
        if (! empty($effets['retire_condition'])) {
            $bouts[] = 'plus '.mb_strtolower((string) $effets['retire_condition']);
        }

        if ($bouts === []) {
            return ['ton' => 'info', 'libelle' => 'aucun effet — les jauges étaient pleines'];
        }

        return [
            'ton' => 'tresor',
            'libelle' => implode(' · ', $bouts)
                .' — '.(int) $buveur->pv_body.'/'.(int) $buveur->pv_body_max.' PV',
        ];
    }

    /**
     * LEVIER actionné — le geste qui ouvre une porte hors de vue.
     *
     * ⚠ Le forçage est RETENTABLE SANS LIMITE, et le dire fait partie du
     * message : sans cela un échec se lit comme un cul-de-sac, et le groupe
     * s'éloigne d'un mécanisme qu'il aurait pu retenter. Même arbitrage que la
     * ligne du fil de combat.
     *
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>|null
     */
    private function levier(array $a, Personnage $acteur): ?array
    {
        $jet = (array) ($a['jet'] ?? []);
        $ouvertes = count((array) ($a['portes_ouvertes'] ?? []));
        $reussi = ! empty($a['force']);

        return [
            'genre' => 'jet',
            'titre' => $acteur->nom.' actionne le levier',
            'sous_titre' => (int) ($jet['succes'] ?? 0).' succès sur '
                .(int) ($jet['difficulte'] ?? 0).' requis',
            'acteurs' => [$this->acteurHeros($acteur, 'acteur')],
            'jet' => null,
            'deplacement' => null,
            'figure' => null,
            'objets' => [[
                'nom' => 'Levier',
                'image_url' => $this->images->urlLevier() ?? $this->images->vignette('levier', 'levier'),
                'detail' => $reussi ? 'actionné' : 'il résiste',
            ]],
            'issue' => $reussi
                ? ['ton' => 'tresor', 'libelle' => $ouvertes > 0
                    ? $ouvertes.' porte'.($ouvertes > 1 ? 's' : '').' s\'ouvre'.($ouvertes > 1 ? 'nt' : '')
                    : 'le passage était déjà ouvert']
                : ['ton' => 'echec', 'libelle' => 'sans succès — on peut réessayer'],
        ];
    }

    /**
     * FRAPPE BALAYÉE — une attaque, plusieurs cibles (la *Fauchaison* du
     * barbare, la *Frénésie* du berserker).
     *
     * ⚠ Une scène, pas une par cible : trois popups d'affilée pour un seul geste
     * noieraient la table, et la file n'en garde de toute façon qu'une en
     * attente. Chaque cible est une vignette avec SON issue.
     *
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>|null
     */
    private function attaqueBalayee(array $a, Personnage $acteur): ?array
    {
        $frappes = array_values(array_filter((array) ($a['frappes'] ?? []), 'is_array'));

        if ($frappes === []) {
            return null;
        }

        $objets = [];
        $touches = 0;

        foreach ($frappes as $f) {
            $degats = (int) ($f['degats'] ?? 0);
            $touches += $degats > 0 ? 1 : 0;
            $instanceId = (int) ($f['cible']['instance_id'] ?? 0);
            $nom = (string) ($f['cible']['nom'] ?? 'cible');

            $objets[] = [
                'nom' => $nom,
                'image_url' => $instanceId > 0
                    ? $this->acteurMonstre($instanceId, $nom, 'cible')['image_url']
                    : $this->images->vignette('monstre', $nom),
                'detail' => $this->issueDuCoup($f, $degats, $nom, ! empty($f['cible_vaincue']))['libelle'],
            ];
        }

        $vaincus = (int) ($a['vaincus'] ?? 0);

        return [
            'genre' => 'attaque',
            'titre' => $acteur->nom.' — '.(string) ($a['capacite'] ?? 'frappe balayée'),
            'sous_titre' => count($frappes).' ennemis au contact',
            'acteurs' => [$this->acteurHeros($acteur, 'acteur')],
            'jet' => null, // une volée PAR cible : elles sont dans les vignettes
            'deplacement' => null,
            'figure' => null,
            'objets' => array_slice($objets, 0, 6),
            'issue' => $vaincus > 0
                ? ['ton' => 'mort', 'libelle' => $vaincus.' abattu'.($vaincus > 1 ? 's' : '')]
                : ($touches > 0
                    ? ['ton' => 'degats', 'libelle' => $touches.' touché'.($touches > 1 ? 's' : '')]
                    : ['ton' => 'echec', 'libelle' => 'aucun coup ne porte']),
        ];
    }

    /**
     * Sort ou parchemin : le lanceur, la carte du sort, et la cible s'il y en a
     * une. C'est le seul genre où l'illustration du CATALOGUE DE SORTS sert —
     * 31 cartes générées qu'aucun écran ne montrait.
     *
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>|null
     */
    private function sort(array $a, Personnage $acteur): ?array
    {
        $nomSort = (string) ($a['sort']['nom'] ?? '');

        if ($nomSort === '') {
            return null;
        }

        $sortId = (int) ($a['sort']['id'] ?? 0);
        $cibleNom = $a['cible']['nom'] ?? null;
        $degats = (int) ($a['degats'] ?? 0);
        $soin = (int) ($a['soin'] ?? 0);
        $parchemin = ($a['type'] ?? null) === 'parchemin';

        $acteurs = [$this->acteurHeros($acteur, 'acteur')];
        $objets = [[
            'nom' => $nomSort,
            'image_url' => $this->images->urlSort($sortId ?: null, $nomSort)
                ?? $this->images->vignette('sort', $sortId ?: $nomSort),
            'detail' => $a['sort']['type'] ?? null,
        ]];

        // ⚠ SORT DE ZONE : il touche toute une salle, ou tous les héros en vue
        // (Flamme hypnotique, Chant de guérison). Une seule `cible` ne dirait
        // rien de ce qui vient de se passer — on aligne donc toutes les figures
        // atteintes, chacune avec ce qu'elle a pris.
        $zone = $this->ciblesDeZone($a);

        if ($zone !== []) {
            foreach ($zone as $touchee) {
                $objets[] = $touchee;
            }

            return [
                'genre' => 'sort',
                'titre' => $acteur->nom.' lance '.$nomSort,
                'sous_titre' => count($zone).' figure'.(count($zone) > 1 ? 's' : '').' atteinte'
                    .(count($zone) > 1 ? 's' : ''),
                'acteurs' => $acteurs,
                'jet' => null,
                'deplacement' => null,
                'figure' => null,
                'objets' => array_slice($objets, 0, 6),
                'issue' => ['ton' => isset($a['soignes']) ? 'tresor' : 'degats',
                    'libelle' => $nomSort.' balaie la salle'],
            ];
        }

        // La cible, quand il y en a une : un monstre visé, ou un héros soigné.
        if (($instanceId = (int) ($a['cible']['instance_id'] ?? 0)) > 0) {
            $acteurs[] = $this->acteurMonstre($instanceId, (string) $cibleNom, 'cible');
        } elseif (($persoId = (int) ($a['cible']['personnage_id'] ?? 0)) > 0
            && ($autre = Personnage::find($persoId)) !== null) {
            $acteurs[] = $this->acteurHeros($autre, 'cible');
        }

        return [
            'genre' => 'sort',
            'titre' => $acteur->nom.' lance '.$nomSort,
            'sous_titre' => $parchemin
                ? 'Parchemin — usage unique'
                : (($e = (string) ($a['sort']['element'] ?? '')) !== '' ? ucfirst($e) : null),
            'acteurs' => $acteurs,
            'jet' => $this->jetDesDes($a, $nomSort, (string) ($cibleNom ?? 'la cible')),
            'deplacement' => null,
            'figure' => null,
            'objets' => $objets,
            'issue' => match (true) {
                ! empty($a['cible_vaincue']) => ['ton' => 'mort', 'libelle' => $cibleNom.' est foudroyé'],
                $degats > 0 => ['ton' => 'degats', 'libelle' => "−{$degats} PV"],
                $soin > 0 => ['ton' => 'tresor', 'libelle' => "+{$soin} PV rendus"],
                default => ['ton' => 'info', 'libelle' => $nomSort.' opère'],
            },
        ];
    }

    /**
     * Les figures atteintes par un sort de ZONE, chacune avec ce qu'elle a pris.
     *
     * Deux formes selon le sort : `touches` (monstres — Flamme hypnotique) et
     * `soignes` (héros — Chant de guérison). Le moteur les publie telles, on ne
     * fait que les habiller.
     *
     * @param  array<string, mixed>  $a
     * @return list<array<string, mixed>>
     */
    private function ciblesDeZone(array $a): array
    {
        $out = [];

        foreach ((array) ($a['touches'] ?? []) as $t) {
            if (! is_array($t)) {
                continue;
            }
            $id = (int) ($t['instance_id'] ?? 0);
            $nom = (string) ($t['nom'] ?? 'cible');
            $out[] = [
                'nom' => $nom,
                'image_url' => $id > 0
                    ? $this->acteurMonstre($id, $nom, 'cible')['image_url']
                    : $this->images->vignette('monstre', $nom),
                'detail' => 'atteint',
            ];
        }

        foreach ((array) ($a['soignes'] ?? []) as $s) {
            if (! is_array($s) || ($heros = Personnage::find((int) ($s['personnage_id'] ?? 0))) === null) {
                continue;
            }
            $out[] = [
                'nom' => (string) ($s['nom'] ?? $heros->nom),
                'image_url' => $this->images->urlHeros($heros->id, $heros->classe),
                'detail' => '+'.(int) ($s['soin'] ?? 0).' PV',
            ];
        }

        return $out;
    }

    /**
     * SALLE RÉVÉLÉE — la bande de ce qu'elle contient, au moment où la porte
     * s'ouvre. C'est le genre qui change le plus l'écran : la narration disait
     * « un Ossement de la Forge erre entre les débris » et l'on ne voyait qu'un
     * pion.
     *
     * ⚠ Uniquement ce que l'ouverture RÉVÈLE réellement — monstres et mobilier.
     * Jamais les pièges : ils restent cachés jusqu'à la fouille, et les montrer
     * ici retournerait la règle.
     *
     * @param  list<InstanceMonstre>  $monstres  ceux qu'on vient de révéler
     * @return array<string, mixed>|null
     */
    public function salle(Quete $quete, int $salle, array $monstres): ?array
    {
        $objets = [];

        foreach ($monstres as $instance) {
            $objets[] = [
                'nom' => $instance->nomAffiche(),
                'image_url' => $this->images->urlMonstre(
                    (int) $instance->id,
                    $instance->monstre_id,
                    $instance->monstre?->nom_base,
                ),
                // Le bloc de stats : c'est ce qu'un joueur regarde avant de
                // décider s'il charge ou s'il recule.
                'detail' => $this->statsMonstre($instance),
            ];
        }

        foreach ($this->mobilierDe($quete, $salle) as $meuble) {
            $objets[] = $meuble;
        }

        if ($objets === []) {
            return null; // une salle vide n'a rien à montrer : le récit suffit
        }

        $n = count($monstres);

        return [
            'genre' => 'salle',
            'titre' => "La salle s'ouvre",
            'sous_titre' => $n > 0 ? $n.' créature'.($n > 1 ? 's' : '').' à l\'intérieur' : null,
            'acteurs' => [],
            'jet' => null,
            'deplacement' => null,
            'figure' => null,
            'objets' => array_slice($objets, 0, 6), // au-delà, la bande déborde
            'issue' => [
                'ton' => $n > 0 ? 'degats' : 'info',
                // ⚠ L'accord suit le compte : « 1 créature à l'intérieur » puis
                // « Elles vous ont vus » se contredisaient à l'écran.
                'libelle' => match (true) {
                    $n > 1 => 'Elles vous ont vus',
                    $n === 1 => 'Elle vous a vus',
                    default => "Personne, pour l'instant",
                },
            ],
        ];
    }

    /**
     * CHUTE ou RELÈVEMENT d'un héros — la figure en grand.
     *
     * ⚠ À 0 PV de Body un héros est TOMBÉ, pas mort : il occupe sa case et reste
     * relevable jusqu'à la fin du combat (P1/C4). L'écran doit le dire, sinon la
     * table croit la partie finie pour lui.
     *
     * @return array<string, mixed>
     */
    /**
     * DÉBUT DU TOUR d'un héros : son portrait et son jet de déplacement (René,
     * 2026-09-16 : « un popup pour afficher le dé de déplacement au début d'un
     * tour de joueur »).
     *
     * ⚠ Les chiffres arrivent DÉCIDÉS par `MenuMoteur`, qui vient de lancer les
     * dés : ce constructeur met en phrase, il ne recalcule rien. Surtout pas le
     * dé à partir du total — c'est ce que fait encore l'option `se_deplacer`
     * (`total − base`), et c'est faux dès qu'il y a un malus d'armure, des
     * Raquettes ou deux dés.
     *
     * @param  array{base: int, des: list<int>, bonus_equipement: int, malus: int, total_jet: int, multiplicateur: int, bonus_potion: int, portee: int}  $d
     */
    public function deplacement(Personnage $heros, array $d): array
    {
        // ⚠ Chaque nombre NOMMÉ : « 3 + 5 = 8 » ne disait pas lequel était le
        // dé (constaté sur la première capture en partie réelle).
        $termes = ['base '.$d['base']];

        if ($d['bonus_equipement'] > 0) {
            $termes[] = $d['bonus_equipement'].' (équipement)';
        }

        $termes[] = (count($d['des']) > 1 ? 'dés ' : 'dé ').implode(' + ', $d['des']);

        $calcul = implode(' + ', $termes);

        if ($d['malus'] > 0) {
            $calcul .= ' − '.$d['malus'].' (armure)';
        }

        $brut = $d['base'] + $d['bonus_equipement'] + array_sum($d['des']) - $d['malus'];

        // « le total ne descend jamais sous une case » : la phrase ne doit pas
        // afficher une soustraction qui tombe à 0 et un total de 1.
        $plancher = $brut !== $d['total_jet'] ? ' → au moins '.$d['total_jet'] : '';

        if ($d['multiplicateur'] > 1) {
            $calcul = '('.$calcul.$plancher.') × '.$d['multiplicateur'];
            $plancher = '';
        }

        if ($d['bonus_potion'] > 0) {
            $calcul .= $plancher.' + '.$d['bonus_potion'].' (potion)';
            $plancher = '';
        }

        $cases = $d['portee'] > 1 ? 'cases' : 'case';

        return [
            'genre' => 'deplacement',
            // « Au tour d'Aldric », pas « de Aldric » (vu sur la capture réelle).
            // Voyelles seulement : devant un h, l'élision dépend du prénom.
            'titre' => (preg_match('/^[aeiouyàâäéèêëîïôöùûü]/iu', (string) $heros->nom) ? "Au tour d'" : 'Au tour de ').$heros->nom,
            'sous_titre' => count($d['des']) > 1 ? 'Jet de déplacement — '.count($d['des']).' dés' : 'Jet de déplacement',
            'acteurs' => [$this->acteurHeros($heros, 'acteur')],
            'jet' => null,
            'deplacement' => [
                'des' => array_values(array_map('intval', $d['des'])),
                'calcul' => $calcul.$plancher.' = '.$d['portee'],
            ],
            'figure' => null, // le tour commence : aucune marche à attendre
            'objets' => [],
            'issue' => ['ton' => 'info', 'libelle' => $d['portee'].' '.$cases.' ce tour'],
        ];
    }

    public function chute(Personnage $heros, bool $tombe): array
    {
        return [
            'genre' => 'chute',
            'titre' => $tombe ? $heros->nom." s'effondre" : $heros->nom.' se relève',
            'sous_titre' => $tombe ? "Relevable jusqu'à la fin du combat" : null,
            'acteurs' => [$this->acteurHeros($heros, 'acteur')],
            'jet' => null,
            'deplacement' => null,
            // Posée par `TamponScenes::vider()` si le héros a marché (un piège
            // marché en chemin) : c'est lui qui connaît la résolution.
            'figure' => null,
            'objets' => [],
            'issue' => $tombe
                ? ['ton' => 'mort', 'libelle' => '0 PV de Body']
                : ['ton' => 'tresor', 'libelle' => 'de nouveau debout'],
        ];
    }

    /**
     * Le mobilier d'une salle, lu dans la grille et borné à ses limites.
     *
     * @return list<array<string, mixed>>
     */
    private function mobilierDe(Quete $quete, int $salle): array
    {
        $s = (array) data_get($quete->carte?->grille, "salles.{$salle}");

        if ($s === []) {
            return [];
        }

        $x0 = (int) ($s['x'] ?? 0);
        $y0 = (int) ($s['y'] ?? 0);
        $x1 = $x0 + (int) ($s['largeur'] ?? 0) - 1;
        $y1 = $y0 + (int) ($s['hauteur'] ?? 0) - 1;

        $entrees = collect((array) data_get($quete->carte?->grille, 'mobiliers', []))
            ->filter(fn ($m) => is_array($m)
                && (int) ($m['x'] ?? -1) >= $x0 && (int) ($m['x'] ?? -1) <= $x1
                && (int) ($m['y'] ?? -1) >= $y0 && (int) ($m['y'] ?? -1) <= $y1);

        $types = Mobilier::query()
            ->whereIn('id', $entrees->pluck('mobilier_id')->filter()->unique())
            ->get()->keyBy('id');

        return $entrees->map(function (array $m) use ($types) {
            $type = $types[$m['mobilier_id'] ?? null] ?? null;

            return [
                'nom' => $type?->nom ?? 'Meuble',
                'image_url' => $this->images->urlMobilier($type?->id, $type?->nom)
                    ?? $this->images->vignette('mobilier', $type?->id ?? 0),
                'detail' => $type?->fouillable ? 'fouillable' : null,
            ];
        })->values()->all();
    }

    // ---- briques ----------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function acteurHeros(Personnage $personnage, string $role, ?string $nomForce = null): array
    {
        return [
            'role' => $role,
            'nom' => $nomForce ?? $personnage->nom,
            'image_url' => $this->images->urlHeros($personnage->id, $personnage->classe),
            'pv' => [
                'courant' => (int) $personnage->pv_body,
                'max' => (int) $personnage->pv_body_max,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function acteurMonstre(int $instanceId, string $nom, string $role): array
    {
        $instance = InstanceMonstre::with('monstre')->find($instanceId);

        return [
            'role' => $role,
            'nom' => $nom,
            'image_url' => $this->images->urlMonstre(
                $instanceId,
                $instance?->monstre_id,
                $instance?->monstre?->nom_base,
            ),
            'pv' => $instance === null ? null : [
                'courant' => (int) $instance->pv_body,
                'max' => (int) ($instance->pv_body_max ?: $instance->pv_body),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function objetDepuisMonstre(int $instanceId, string $nom): array
    {
        $instance = InstanceMonstre::with('monstre')->find($instanceId);

        return [
            'nom' => $nom,
            'image_url' => $this->images->urlMonstre(
                $instanceId,
                $instance?->monstre_id,
                $instance?->monstre?->nom_base,
            ),
            'detail' => 'monstre errant',
        ];
    }

    /**
     * La volée complète, dans la forme EXACTE que `JetDes.vue` sait déjà rendre.
     *
     * ⚠ `touchante`/`defensive` viennent du moteur et ne sont jamais redéduites :
     * un bouclier blanc pare pour un héros et rien du tout pour un monstre.
     *
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>|null
     */
    private function jetDesDes(array $a, string $attaquant, string $defenseur): ?array
    {
        $atk = (array) ($a['faces_attaque'] ?? []);
        $def = (array) ($a['faces_defense'] ?? []);

        if ($atk === [] && $def === []) {
            return null; // dégâts fixes : aucun dé n'a été lancé, on n'en invente pas
        }

        return [
            'atk' => array_values($atk),
            'def' => array_values($def),
            'touchante' => $a['face_touchante'] ?? 'crane',
            'defensive' => $a['face_defensive'] ?? 'bouclier_blanc',
            'attaquant' => $attaquant,
            'defenseur' => $defenseur,
            'touches' => (int) ($a['touches'] ?? 0),
            'boucliers' => (int) ($a['boucliers'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array{ton: string, libelle: string}
     */
    private function issueDuCoup(array $a, int $degats, string $cible, bool $vaincue, bool $heros = false): array
    {
        // ⚠ Les DÉGÂTS **et** la mise hors de combat (René, 2026-09-14 : « tu
        // affiches −2 PV, mais faudrait-il pas afficher −2 PV, l'adversaire est
        // défait ? »). Un coup fatal disait seulement « est terrassé » : on
        // perdait le chiffre, qui est la moitié de l'information.
        if ($vaincue) {
            $chute = $heros ? "{$cible} tombe" : "{$cible} est terrassé";

            return ['ton' => 'mort', 'libelle' => $degats > 0 ? "−{$degats} PV · {$chute}" : $chute];
        }
        if ($degats > 0) {
            return ['ton' => 'degats', 'libelle' => "−{$degats} PV"];
        }

        // ⚠ MANQUÉ ≠ PARÉ, la même distinction que le journal : un joueur
        // concluait que l'armure adverse était trop bonne quand c'étaient ses
        // propres dés qui avaient échoué. Et on DIT POURQUOI : sans le compte,
        // « paré » et « manqué » se ressemblent trop pour qu'on apprenne quoi
        // que ce soit du jet qu'on vient de voir.
        $boucliers = (int) ($a['boucliers'] ?? 0);

        return (int) ($a['touches'] ?? 0) === 0
            ? ['ton' => 'echec', 'libelle' => 'manqué — aucun crâne']
            : ['ton' => 'echec', 'libelle' => $boucliers > 0
                ? "paré — {$boucliers} bouclier".($boucliers > 1 ? 's' : '')
                : 'paré'];
    }

    /**
     * @param  array<string, mixed>  $a
     */
    private function porteeLisible(array $a): ?string
    {
        return ($a['portee'] ?? null) === 'distance' ? 'À distance' : null;
    }

    /**
     * CE QUE LE JET RAPPORTE — « réussi » ne dit pas ce qu'on gagne (René,
     * 2026-09-14 : « pour les jets d'attribut, il faudrait aussi dire ce que
     * donne le résultat »).
     *
     * ⚠ Les gains sont FUSIONNÉS dans le payload par `resoudreEpreuve()` : on
     * les lit là où le moteur les pose, sans rien recalculer. Une épreuve dont
     * la mécanique n'aurait pas de lecteur ici retombe sur « réussi » — jamais
     * sur une phrase inventée.
     *
     * @param  array<string, mixed>  $a
     */
    private function gainDuJet(array $a): string
    {
        $bouts = [];

        if (($or = (int) ($a['or'] ?? 0)) > 0) {
            $bouts[] = "+{$or} pièces d'or pour le groupe";
        }
        if (($nom = (string) ($a['objet']['nom'] ?? '')) !== '') {
            $bouts[] = $nom;
        }
        if (($soignes = (int) ($a['soin_groupe'] ?? 0)) > 0) {
            $bouts[] = $soignes.' héros soigné'.($soignes > 1 ? 's' : '');
        }
        if (($desarmes = (int) ($a['desarme_pieges_salle'] ?? 0)) > 0) {
            $bouts[] = $desarmes.' piège'.($desarmes > 1 ? 's' : '').' désarmé'.($desarmes > 1 ? 's' : '');
        }
        foreach ((array) ($a['retire_condition'] ?? []) as $condition) {
            $bouts[] = 'plus '.mb_strtolower((string) $condition);
        }
        // Fouille de zone : ce qu'un jet de Mind a mis au jour.
        if (($pieges = count((array) ($a['pieges_reveles'] ?? []))) > 0) {
            $bouts[] = $pieges.' piège'.($pieges > 1 ? 's' : '').' repéré'.($pieges > 1 ? 's' : '');
        }
        if (($portes = count((array) ($a['portes_revelees'] ?? []))) > 0) {
            $bouts[] = $portes.' passage'.($portes > 1 ? 's' : '').' secret'.($portes > 1 ? 's' : '');
        }
        if (! empty($a['objet_indisponible'])) {
            $bouts[] = 'mais rien que ce groupe puisse utiliser';
        }

        return $bouts === [] ? 'réussi' : implode(' · ', $bouts);
    }

    /**
     * Les effets d'un objet, en clair — « 3 dés d'attaque · frappe en diagonale ».
     *
     * ⚠ Passe par `MotsClesEquipement::avantages()`, le vocabulaire déjà utilisé
     * par l'étal, le sac et le menu d'action : c'est LUI la source des phrases.
     * En réécrire ici ferait dériver l'écran de table du reste du jeu au premier
     * mot-clé qui change, et le projet a déjà payé exactement ça avec une table
     * de libellés tenue côté client — aucun talent n'affichait le moindre
     * chiffre, et personne ne l'avait vu.
     */
    private function effetsLisibles(Objet $objet): ?string
    {
        $avantages = MotsClesEquipement::avantages((array) $objet->effet);

        // La catégorie seule quand l'objet n'a aucun effet lisible : mieux vaut
        // « Consommable » qu'un vide sous l'illustration.
        return $avantages === []
            ? ucfirst((string) $objet->categorie)
            : implode(' · ', array_slice($avantages, 0, 3));
    }

    /**
     * Le bloc de stats d'une créature — « Att 3 · Déf 2 · 1 PV · dépl. 8 ».
     *
     * ⚠ Les PV viennent de l'INSTANCE (elle a pu être blessée), le reste du
     * catalogue : c'est l'archétype qui porte attaque, défense et déplacement,
     * et l'habillage de l'IA n'y touche jamais.
     */
    private function statsMonstre(InstanceMonstre $instance): ?string
    {
        $m = $instance->monstre;

        if ($m === null) {
            return null;
        }

        $bouts = [
            'Att '.(int) $m->attaque,
            'Déf '.(int) $m->defense,
            (int) $instance->pv_body.' PV',
            'dépl. '.(int) $m->deplacement,
        ];

        if ($m->portee !== 'corps_a_corps') {
            $bouts[] = 'à distance';
        }

        return implode(' · ', $bouts);
    }

    /**
     * ⚠ Le payload d'un piège ne porte que son NOM (`MoteurPieges`), jamais son
     * id — et les pièges ne sont pas habillés par l'IA, donc le nom EST celui du
     * catalogue. La jointure par nom est ici exacte, pas approximative.
     */
    private function imagePiege(string $nom): string
    {
        $piege = Piege::where('nom', $nom)->first();

        return $this->images->urlPiege($piege?->id, $nom)
            ?? $this->images->vignette('piege', $piege?->id ?? $nom);
    }
}
