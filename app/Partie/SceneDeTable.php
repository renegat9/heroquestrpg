<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\InstanceMonstre;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Piege;
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
    public const GENRES = ['attaque', 'piege', 'fouille', 'jet'];

    public function __construct(private readonly BibliothequeImages $images) {}

    /**
     * Les scènes d'un tour, dans l'ordre où les actions se sont produites.
     *
     * @param  array<string, mixed>  $resultat
     * @return list<array<string, mixed>>
     */
    public function depuisResultat(array $resultat, Personnage $acteur): array
    {
        $scenes = [];

        foreach (JournalCombat::actionsDuTour($resultat) as $action) {
            foreach ($this->depuisAction($action, $acteur) as $scene) {
                $scenes[] = $scene;
            }
        }

        return $scenes;
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
            'attaque_monstre' => $this->attaqueDuMonstre($a),
            'piege_declenche' => $this->piege($a, $acteur),
            'fouille_tresor', 'fouille_mobilier' => $this->fouille($a, $acteur),
            'jet', 'desamorcage', 'franchissement' => $this->jet($a, $acteur),
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
            'objets' => [],
            'issue' => $this->issueDuCoup($a, $degats, $cibleNom, vaincue: ! empty($a['cible_tombee'])),
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
                    'detail' => ucfirst((string) $objet->categorie),
                ];
                $libelle = $objet->nom;
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

        return [
            'genre' => 'jet',
            'titre' => $acteur->nom.' — '.(string) ($a['libelle'] ?? 'jet'),
            'sous_titre' => "{$succes} succès sur {$difficulte} requis",
            'acteurs' => [$this->acteurHeros($acteur, 'acteur')],
            'jet' => null,
            'objets' => $objets,
            'issue' => [
                'ton' => $reussi ? 'tresor' : 'echec',
                'libelle' => $reussi ? 'réussi' : 'raté',
            ],
        ];
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
    private function issueDuCoup(array $a, int $degats, string $cible, bool $vaincue): array
    {
        if ($vaincue) {
            return ['ton' => 'mort', 'libelle' => "{$cible} est terrassé"];
        }
        if ($degats > 0) {
            return ['ton' => 'degats', 'libelle' => "−{$degats} PV"];
        }

        // ⚠ MANQUÉ ≠ PARÉ, la même distinction que le journal : un joueur
        // concluait que l'armure adverse était trop bonne quand c'étaient ses
        // propres dés qui avaient échoué.
        return (int) ($a['touches'] ?? 0) === 0
            ? ['ton' => 'echec', 'libelle' => 'manqué']
            : ['ton' => 'echec', 'libelle' => 'paré'];
    }

    /**
     * @param  array<string, mixed>  $a
     */
    private function porteeLisible(array $a): ?string
    {
        return ($a['portee'] ?? null) === 'distance' ? 'À distance' : null;
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
