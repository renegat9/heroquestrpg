<?php

declare(strict_types=1);

namespace App\Partie;

use App\Events\JournalCombatDiffuse;
use App\Events\MjReflechit;
use App\Events\NarrationDiffusee;
use App\Events\SceneTable;
use App\Jobs\GenererMenu;
use App\Models\Evenement;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Narration\BibliothequeNarration;
use App\Support\Journal;
use Illuminate\Support\Facades\Cache;

/**
 * RÉSOUDRE UN CHOIX DE MENU ET EN DIFFUSER LES SUITES — extrait de
 * `ChoixController::choisir()` le 2026-10-08, au moment où une SECONDE porte
 * en eut besoin : la réponse à une *Vision du futur* (`MoteurReactions`) REPREND
 * une action suspendue au milieu de son jet, et doit alors faire exactement ce
 * que `/choix` fait — résolution, journal de combat, scènes de table, narration,
 * menus suivants. Deux copies d'une même séquence auraient dérivé : c'est
 * l'unique point de passage (une règle, un point de passage).
 *
 * ⚠ Le JOURNAL du choix (`Journal::ajouter(… 'choix' …)`) et la validation du
 * menu restent au contrôleur : ils n'ont lieu qu'UNE fois par choix, jamais à
 * la reprise.
 *
 * ⚠ SUSPENSION : quand le résolveur rend `type: jet_en_attente`, le héros doit
 * répondre à une relance avant que l'action ne s'applique. Rien n'est consommé
 * ni dispatché — le menu reste en cache (la reprise le consommera), aucune
 * narration, aucun menu suivant : l'action n'a PAS eu lieu.
 */
final class ExecutionChoix
{
    /**
     * @param  array<string, mixed>  $option  option du dernier menu (déjà validée)
     * @param  array<string, mixed>  $parametres
     * @param  list<array<string, mixed>>|null  $attaquesImposees  jets déjà tombés, rejoués dans l'ordre (reprise)
     * @return array{resultat: array<string, mixed>, des: array<string, mixed>|null}
     */
    public function executer(
        Groupe $groupe,
        Personnage $personnage,
        array $option,
        array $parametres,
        ?array $attaquesImposees = null,
    ): array {
        $resolveur = app(ResolveurTour::class);
        $journalCombat = app(JournalCombat::class);
        $scenesDeTable = app(SceneDeTable::class);
        $cleMenu = GenererMenu::cleMenu($groupe->id, (int) $personnage->joueur_id);

        // Résolution déterministe par le moteur (jamais par l'IA).
        if ($groupe->phase === 'quete') {
            $resultat = $resolveur->resoudreAvecJets($groupe, $personnage, $option, $parametres, $attaquesImposees ?? []);

            // SUSPENDU sur une *Vision du futur* : le jet est tombé, le héros doit
            // répondre. L'action n'a PAS eu lieu — ni journal de combat, ni scène,
            // ni narration, ni menu suivant. Le menu reste en cache : la reprise
            // (`MoteurReactions::reprendreAttaque()`) repasse ici et le consomme.
            if (($resultat['type'] ?? null) === ResolveurTour::TYPE_JET_EN_ATTENTE) {
                return ['resultat' => $resultat, 'des' => null];
            }

            // Journal de combat MÉCANIQUE diffusé à TOUTES les manettes (canal de
            // groupe) : sans ça, en « combat instantané » (pas de narration IA),
            // un joueur ne voit que ses PV bouger — les attaques subies, le tour
            // des monstres, le résultat d'une fouille restaient invisibles.
            $sequence = (int) Evenement::query()->where('groupe_id', $groupe->id)->max('sequence');

            $lignes = $journalCombat->depuisResultat($resultat, $personnage->nom);
            if ($lignes !== []) {
                broadcast(new JournalCombatDiffuse($groupe, $lignes, $sequence));
            }

            // SCÈNES ILLUSTRÉES pour l'écran de TABLE (.table.scene) : le même
            // résultat moteur, mais monté pour être MONTRÉ — portraits de
            // l'attaquant et du défendeur, volée de dés, objet trouvé, piège
            // déclenché. Le journal, lui, aplatit tout cela en texte : les
            // identités y meurent et plus aucune image n'y est résolvable.
            // ⚠ MÊME séquence que le journal : les deux flux racontent le même
            // instant, une scène ne doit jamais s'afficher derrière une plus
            // récente.
            // ⚠ Une scène dont la figurine vient de MARCHER attend la fin de sa
            // marche sur la table (`figure`) — le coup d'un monstre ne s'affiche
            // plus pendant qu'il avance encore vers sa cible.
            foreach ($scenesDeTable->depuisResultat($resultat, $personnage, $resolveur->figuresEnMarche()) as $scene) {
                broadcast(new SceneTable($groupe, $scene, $sequence));
            }

            // ⚠ PUIS ce qui est né PENDANT la résolution — une chute, un
            // relèvement. Ces scènes-là viennent d'un observateur qui se
            // déclenche au moment où les PV touchent zéro, donc AVANT que
            // l'action soit finie : sans ce report, la table montrait le héros à
            // terre avant le coup qui l'y avait mis.
            app(TamponScenes::class)->vider($resolveur->figuresEnMarche());
        } else {
            $resultat = [
                'type' => $option['type'] ?? 'action',
                'option_id' => $option['id'],
                'libelle' => $option['libelle'] ?? null,
            ];
        }

        // Un menu = un choix : il est consommé, un nouveau sera proposé.
        Cache::forget($cleMenu);

        // L'IA n'intervient que sur les actions NOTABLES. Un simple déplacement
        // (ou attente), sans changement de phase, reste 100 % moteur → tour
        // instantané : pas de narration, menus moteur seuls (pas d'appel LLM).
        $groupeFrais = $groupe->fresh();
        $triviale = in_array($resultat['type'] ?? null, ['deplacement', 'attente'], true)
            && $groupeFrais->phase === 'quete';

        // Combat → muet lui aussi : le retour passe par les barks et le journal
        // de combat, pas par le récit.
        //
        // ⚠ Ce silence n'est PLUS une contrainte technique. Il a été institué
        // pour « ne pas attendre le LLM » — or la narration est devenue une
        // pioche dans une table depuis le 2026-08-18 : elle ne coûte rien et
        // n'attend rien. Ce qui reste est un parti pris de RYTHME : narrer
        // chaque coup paré rendrait le combat bavard et lèverait le verrou B1 à
        // chaque échange.
        $quete = $groupeFrais->phase === 'quete' ? $groupeFrais->queteCourante : null;
        $enCombat = $quete !== null && $quete->instancesMonstres()
            ->where('etat', 'actif')->where('revele', true)->exists();

        // …mais le silence cède sur les MOMENTS FORTS. Constaté en campagne
        // réelle (2026-08-20) : 22 minutes de combat, sept monstres tués, une
        // héroïne à terre — et pas une ligne ; puis le BOSS FINAL est tombé,
        // apogée de quatre quêtes, entre deux lignes de log. Un parti pris de
        // rythme ne doit pas taire ce que toute la campagne construisait.
        $instantane = $triviale || ($enCombat && ! $this->momentFort($resultat));

        // Texte de fin déjà narré par le résolveur (quête gagnée en détruisant
        // l'élément-objectif) : le redire ferait entendre deux fois la victoire.
        $dejaNarre = ! empty($resultat['objectif_detruit']);

        if (! $instantane && ! $dejaNarre) {
            // Verrou B1 (délibéré, cf. CLAUDE.md) : le joueur suivant attend que
            // le narrateur ait « parlé » — la TABLE l'éteint une fois la lecture
            // finie (POST /table/lecture-terminee). Depuis la bascule du
            // 2026-08-18 ce n'est plus une génération LLM qui tourne derrière,
            // seulement une pioche dans le pack pré-généré ou le repli scripté :
            // le verrou ne dure donc plus que le temps d'une lecture.
            broadcast(new MjReflechit($groupe, true));
            $this->narrer($groupe, $quete, $resultat, $personnage);
        }

        foreach ($groupe->personnages()->wherePivot('actif', true)->get() as $heros) {
            // Un menu ne coûte plus d'appel LLM (§2, moteur seul) : la boucle
            // reste volontairement sur TOUS les héros actifs (et pas seulement
            // celui dont c'est le tour) — c'est ce qui alimente le rattrapage
            // `GET /menu` de chacun, et restreindre pour un gain nul sur un
            // calcul devenu gratuit serait prendre un risque de régression pour
            // rien.
            GenererMenu::dispatch($groupe->id, (int) $heros->joueur_id, (int) $heros->id);
        }

        // 202 : le moteur a résolu, l'état et la narration arrivent par Reverb.
        // Le résultat moteur est renvoyé en echo (affichage immédiat des dés).
        // `des` (2026-09-24) : un jet UNILATÉRAL (dés rouges d'une Boule de
        // Feu, jet de Mind d'une Berceuse, dés d'un piège) déjà mis en forme
        // par `JournalCombat` — la manette ne lisait que `faces_attaque` /
        // `faces_defense` et restait muette sur le sort que le joueur venait
        // de lancer lui-même. Même formateur que le fil et la table.
        return [
            'resultat' => $resultat,
            'des' => is_array($resultat) ? $this->desUnilateraux($resultat) : null,
        ];
    }


    /**
     * Le jet unilatéral de l'action, au sommet du résultat (sort) OU niché
     * dans le piège que le héros a déclenché en marchant (`declenchement`,
     * `pieges_declenches[]`, forme du contrat). Sans cette seconde lecture, la
     * Chute de blocs lançait ses trois dés et la manette n'en montrait aucun
     * (constaté en live, 2026-09-25).
     *
     * @param  array<string, mixed>  $resultat
     * @return array<string, mixed>|null
     */
    public function desUnilateraux(array $resultat): ?array
    {
        $journal = app(JournalCombat::class);
        $nom = isset($resultat['personnage']['nom']) ? (string) $resultat['personnage']['nom'] : null;

        foreach ([$resultat, $resultat['declenchement'] ?? null, ...array_values((array) ($resultat['pieges_declenches'] ?? []))] as $source) {
            if (is_array($source) && ($des = $journal->desJetUnilateral($source, $source === $resultat ? null : $nom)) !== null) {
                return $des;
            }
        }

        return null;
    }

    /**
     * Résolution SYNCHRONE de la narration d'un temps fort — remplace
     * `GenererNarration::dispatch()` depuis la bascule du 2026-08-18 (« l'IA
     * fabrique la quête, elle ne la joue plus ») : plus aucun appel LLM en
     * cours de partie, le texte est PIOCHÉ dans le pack pré-généré de la
     * quête (`BibliothequeNarration::pourQuete()`), avec repli sur les
     * répliques scriptées de config/narration.php. Reprend telle quelle la
     * diffusion (journal + `NarrationDiffusee`) que construisait l'ancien job.
     *
     * ⚠ Filet du verrou B1 : si AUCUN texte n'est trouvé (pack de quête et
     * repli scripté absents tous les deux pour cette clé), on dégèle
     * immédiatement « MJ réfléchit » — c'est exactement ce que faisait le
     * `finally` de `GenererNarration::handle()` sur échec ; le job a disparu
     * mais rien d'autre ne surveille plus le verrou, alors le filet doit
     * rester ICI.
     */
    private function narrer(Groupe $groupe, ?Quete $quete, array $resultat, Personnage $personnage): void
    {
        $cle = $this->cleTempsFort($resultat);
        $recit = app(BibliothequeNarration::class)
            ->pourQuete($quete, $cle, $this->remplacementsNarration($personnage, $resultat));

        if ($recit === null) {
            broadcast(new MjReflechit($groupe, false));

            return;
        }

        $evenement = Journal::ajouter($groupe, 'narration', [
            'texte' => $recit['texte'],
            'ambiance' => $recit['ambiance'],
        ]);

        broadcast(new NarrationDiffusee(
            $groupe,
            $recit['texte'],
            ambiance: $recit['ambiance'],
            queteId: $evenement->quete_id,
            url: $recit['url'],
            sequence: $evenement->sequence,
        ));
    }

    /**
     * Ce résultat contient-il la mort d'un BOSS ou d'un SOUS-BOSS ?
     *
     * C'est la seule chose qui autorise le récit à percer le silence du combat.
     * On teste le `tier` du CATALOGUE et non le nom affiché : l'habillage IA
     * renomme les créatures, et « Le Noyé de Gorrim » ne dit rien de son rang.
     *
     * Couvre les deux formes de frappe : le coup simple (`cible` +
     * `cible_vaincue`) et la frappe balayée, qui abat plusieurs cibles d'un
     * geste et les liste dans `frappes[]` — oublier la seconde tairait
     * précisément la mort la plus spectaculaire du jeu.
     *
     * @param  array<string, mixed>  $resultat
     */
    private function momentFort(array $resultat): bool
    {
        $abattues = [];

        if (($resultat['cible_vaincue'] ?? false) && isset($resultat['cible']['instance_id'])) {
            $abattues[] = (int) $resultat['cible']['instance_id'];
        }

        foreach ($resultat['frappes'] ?? [] as $frappe) {
            if (($frappe['cible_vaincue'] ?? false) && isset($frappe['cible']['instance_id'])) {
                $abattues[] = (int) $frappe['cible']['instance_id'];
            }
        }

        return $abattues !== [] && InstanceMonstre::whereIn('id', $abattues)
            ->whereHas('monstre', fn ($m) => $m->whereIn('tier', ['boss', 'sous_boss']))
            ->exists();
    }

    /**
     * Mappe un résultat moteur vers la clé de temps fort narratif — portage
     * DIRECT de l'ancien `App\Agent\Skills\Narration::cleRepli()` : c'était le
     * repli pour quand le LLM était indisponible, c'est désormais la SEULE
     * route (plus de skill, plus de job IA), donc la seule autorité qui reste
     * sur cette correspondance.
     *
     * @param  array<string, mixed>  $resultat
     */
    private function cleTempsFort(array $resultat): string
    {
        return match ($resultat['type'] ?? null) {
            'quete_demarree' => 'quete_demarree',
            'salle_decouverte' => 'salle_decouverte',
            'piege_declenche' => 'piege_declenche',
            // Fouille — chaque ISSUE a son temps fort. Elles retombaient
            // toutes sur « progression » (« une salle de plus »), héritage du
            // temps où l'ancien repli n'avait que ce mot-là : trouver 25 pièces
            // d'or, réveiller un errant ou ne rien trouver du tout se
            // racontaient à l'identique. Constaté en partie réelle le
            // 2026-08-18 — les dix clés que la pré-génération produit
            // (`App\Partie\Narration\TempsFort`) n'étaient LUES par
            // personne, donc payées et jamais entendues.
            //
            // Vocabulaire d'issue commun au deck et au mobilier (CLAUDE.md) ;
            // une issue inconnue reste sur « progression » plutôt que
            // d'affirmer à tort qu'on n'a rien trouvé.
            'fouille_tresor' => match ($resultat['issue'] ?? null) {
                'tresor' => 'fouille_tresor',
                'potion' => 'fouille_potion',
                'artefact' => 'fouille_artefact',
                'errant' => 'fouille_errant',
                'piege' => 'fouille_piege',
                'rien' => 'fouille_rien',
                default => 'progression',
            },
            'fouille_mobilier' => match ($resultat['issue'] ?? null) {
                // Un meuble qui paie en or raconte la même chose qu'un coffre :
                // pas de `mobilier_tresor` à inventer pour ça.
                'tresor' => 'fouille_tresor',
                'objet' => 'mobilier_objet',
                'artefact' => 'fouille_artefact',
                'piege' => 'mobilier_piege',
                'rien' => 'mobilier_rien',
                default => 'progression',
            },
            'ouvrir_porte' => 'porte_ouverte',
            // ⚠ ON REGARDE LE JET. Dispatché inconditionnellement jusqu'au
            // 2026-09-11, `levier_actionne` racontait « la pierre gronde au
            // loin » même sur un échec — le joueur croyait le mécanisme
            // déclenché et s'éloignait. La porte, elle, est souvent hors de vue
            // (levier de couloir, porte de la salle suivante) : la narration est
            // alors la SEULE chose qui dise ce qui s'est passé.
            'actionner_levier' => (($resultat['jet']['issue'] ?? null) === 'echec')
                ? 'levier_echoue'
                : 'levier_actionne',
            'reprise' => 'reprise',
            'deplacement' => 'deplacement',
            // Inatteignable en pratique via ce contrôleur (une attaque cible
            // toujours un monstre actif+révélé, donc $enCombat est vrai et
            // narrer() n'est jamais appelé) — conservé pour fidélité au
            // portage et au cas où un appelant futur réutilise cleTempsFort().
            // Un boss abattu passe avant tout : c'est le seul cas où le récit
            // perce le silence du combat, et il nomme la créature.
            'attaque' => $this->momentFort($resultat) ? 'boss_vaincu'
                : (($resultat['degats'] ?? 0) > 0
                    ? (($resultat['cible_vaincue'] ?? false) ? 'attaque_mort' : 'attaque_touche')
                    : 'attaque_pare'),
            default => ($resultat['quete']['etat'] ?? null) === 'terminee'
                ? 'victoire_quete'
                : match ($resultat['issue'] ?? null) {
                    'reussite' => 'reussite',
                    'reussite_mixte' => 'reussite_mixte',
                    'echec' => 'echec',
                    default => 'progression',
                },
        };
    }

    /**
     * Placeholders `{heros}`/`{monstre}`/`{objet}`/`{or}` déduits du résultat
     * moteur, au mieux — un placeholder sans valeur trouvée reste tel quel
     * (BibliothequeNarration::substituer), donc on n'inclut une clé QUE
     * quand le résultat la connaît vraiment plutôt que de la vider.
     *
     * @param  array<string, mixed>  $resultat
     * @return array<string, string|int>
     */
    private function remplacementsNarration(Personnage $personnage, array $resultat): array
    {
        $remplacements = ['heros' => $personnage->nom];

        $monstre = $resultat['cible']['nom'] ?? $resultat['monstre']['nom'] ?? null;
        if (is_string($monstre) && $monstre !== '') {
            $remplacements['monstre'] = $monstre;
        }

        $objet = $resultat['objet']['nom'] ?? null;
        if (is_string($objet) && $objet !== '') {
            $remplacements['objet'] = $objet;
        }

        if ((int) ($resultat['or'] ?? 0) > 0) {
            $remplacements['or'] = (int) $resultat['or'];
        }

        return $remplacements;
    }
}
