<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Engine\MotsClesQuete;
use App\Events\NarrationDiffusee;
use App\Models\InstanceMonstre;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Bourses;
use App\Partie\CaseLibre;
use App\Partie\FabriqueGrille;
use App\Partie\MoteurDegats;
use App\Partie\RangementObjet;
use App\Partie\TamponAnnonces;
use App\Support\Journal;
use LogicException;

/**
 * LES NOTES D'UNE QUÊTE CONSTRUITE — le point de passage UNIQUE de « quand → alors »
 * (`docs/plan-mode-classique.md` §4, lot K3 ; vocabulaire fermé : `App\Engine\MotsClesQuete`).
 *
 * Les coutures du moteur ne lisent jamais une note : elles SIGNALENT ce qui vient de se produire
 * ({@see signaler()}) — une salle fouillée, une case foulée, une porte ouverte, une figure tombée. Ce lecteur, et lui
 * seul, retrouve les notes concernées, vérifie leurs conditions, les marque jouées, applique chaque effet par le
 * moteur qui le résout déjà (`Bourses`, `RangementObjet`, `MoteurDegats`…) et l'ANNONCE : un effet automatique que rien
 * n'annonce est injouable.
 *
 * Tout ce qu'il lit est FIGÉ sur la quête au départ (`quetes.document_construit`), tout ce qu'il écrit est une colonne
 * (`quetes.notes_jouees`, `quetes.drapeaux`). Rien en cache. Une quête générée n'a pas de notes : sans effet.
 *
 * Décisions écrites :
 * - **une fois** par défaut (`une_fois: true`) : la note marquée jouée ne tire plus. Elle est marquée AVANT ses
 *   effets — un effet qui re-signale (une apparition tuée sur-le-champ) ne la fait pas boucler.
 * - **la fouille** : une salle dont une note tire sur CETTE fouille rend ce que la note dit, et ne pioche pas de carte
 *   de trésor (LR p. 14 : la carte se tire « sans trésor spécial prévu par le livret »). Une fois le trésor spécial
 *   pris, la salle n'en a plus : les fouilles suivantes piochent comme partout. Le livret ne tranche pas ce second
 *   cas ; l'auteur qui veut que chaque héros trouve le sien écrit `une_fois: false`.
 * - **le héros qui déclenche** reçoit l'or et l'objet, subit les dégâts. Une figure qui tombe sans qu'un héros ne
 *   l'ait achevée (un piège) n'a pas de déclencheur : l'or est alors partagé (`Bourses::verser()` sans bénéficiaire),
 *   l'objet va au premier héros dans l'ordre du tour, les dégâts ne touchent personne.
 * - **une apparition** se pose sur la case écrite ; si elle est prise, sur la plus proche (`CaseLibre`).
 */
final class NotesDeQuete
{
    public function __construct(private readonly CatalogueConstructeur $catalogue) {}

    /**
     * Quelque chose vient de se produire : joue les notes que cela déclenche, dans l'ordre où l'auteur les a écrites.
     *
     * @param  string  $declencheur  une clé de `MotsClesQuete::DECLENCHEURS`
     * @param  array<string, mixed>  $contexte  ce qui s'est produit, dans les termes des cibles : `salle` (index de
     *                                          salle du moteur), `case` (`{x, y}`), `porte` (index), `monstre` (index)
     * @return list<array<string, mixed>> les notes jouées, telles qu'annoncées
     */
    public function signaler(Quete $quete, string $declencheur, array $contexte, ?Personnage $heros = null): array
    {
        if (! isset(MotsClesQuete::DECLENCHEURS[$declencheur])) {
            throw new LogicException("Déclencheur de note inconnu : « {$declencheur} » (voir MotsClesQuete::DECLENCHEURS).");
        }

        if (! $quete->estConstruite()) {
            return [];
        }

        $jouees = [];

        foreach ((array) ($quete->documentConstruit()['notes'] ?? []) as $index => $note) {
            if (data_get($note, 'quand.declencheur') !== $declencheur
                || ! $this->cibleAtteinte($quete, (array) data_get($note, 'quand.cible', []), $contexte)) {
                continue;
            }

            $uneFois = (bool) ($note['une_fois'] ?? true);

            if ($uneFois && in_array($index, (array) $quete->notes_jouees, true)) {
                continue;
            }

            $condition = data_get($note, 'quand.si_drapeau');

            if ($condition !== null && ! $quete->aDrapeau((string) $condition)) {
                continue;
            }

            if ($uneFois) {
                $quete->update(['notes_jouees' => [...(array) $quete->notes_jouees, $index]]);
            }

            $jouees[] = $this->jouer($quete, (array) $note, $declencheur, $heros);
        }

        return $jouees;
    }

    /**
     * Une note tirerait-elle sur cet événement ? Lecture seule — pour décider AVANT d'agir (la fouille choisit entre la
     * note et la carte de trésor) sans rien marquer.
     *
     * @param  array<string, mixed>  $contexte
     */
    public function attend(Quete $quete, string $declencheur, array $contexte): bool
    {
        if (! $quete->estConstruite()) {
            return false;
        }

        foreach ((array) ($quete->documentConstruit()['notes'] ?? []) as $index => $note) {
            if (data_get($note, 'quand.declencheur') !== $declencheur
                || ! $this->cibleAtteinte($quete, (array) data_get($note, 'quand.cible', []), $contexte)) {
                continue;
            }

            if (($note['une_fois'] ?? true) && in_array($index, (array) $quete->notes_jouees, true)) {
                continue;
            }

            $condition = data_get($note, 'quand.si_drapeau');

            if ($condition === null || $quete->aDrapeau((string) $condition)) {
                return true;
            }
        }

        return false;
    }

    /** Le rang d'une figure parmi les monstres POSÉS par l'auteur (`quete.monstres`), ou `null` si elle n'en est pas. */
    public function rangDuMonstre(Quete $quete, InstanceMonstre $instance): ?int
    {
        if (! $quete->estConstruite()) {
            return null;
        }

        // Les figures de l'auteur sont créées les premières, dans l'ordre du document, en une transaction
        // (`DemarreurQueteConstruite`) : les N plus petits identifiants de la quête. Un errant, une apparition, une
        // invocation naissent après.
        $poses = count((array) ($quete->documentConstruit()['monstres'] ?? []));
        $rang = $quete->instancesMonstres()->orderBy('id')->limit($poses)->pluck('id')->search($instance->id);

        return $rang === false ? null : (int) $rang;
    }

    /**
     * La cible écrite par l'auteur est-elle celle de l'événement ? Une cible n'a qu'UNE clé (la validation l'impose).
     *
     * @param  array<string, mixed>  $cible
     * @param  array<string, mixed>  $contexte
     */
    private function cibleAtteinte(Quete $quete, array $cible, array $contexte): bool
    {
        if (array_key_exists('salle', $cible)) {
            $index = $contexte['salle'] ?? null;

            // L'`id` de l'auteur est porté par la salle de la carte (`CarteConstruite`) : le moteur, lui, compte en index.
            return $index !== null
                && data_get($quete->carte?->grille, "salles.{$index}.id") === $cible['salle'];
        }

        if (array_key_exists('case', $cible)) {
            return isset($contexte['case'])
                && (int) data_get($cible, 'case.x') === (int) $contexte['case']['x']
                && (int) data_get($cible, 'case.y') === (int) $contexte['case']['y'];
        }

        foreach (['porte', 'monstre'] as $cle) {
            if (array_key_exists($cle, $cible)) {
                return isset($contexte[$cle]) && (int) $cible[$cle] === (int) $contexte[$cle];
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $note
     * @return array<string, mixed>
     */
    private function jouer(Quete $quete, array $note, string $declencheur, ?Personnage $heros): array
    {
        $effets = [];

        foreach ((array) ($note['alors'] ?? []) as $effet) {
            $nom = (string) ($effet['effet'] ?? '');
            $lecteur = MotsClesQuete::EFFETS[$nom]['lecteur']
                ?? throw new LogicException("Effet de note inconnu : « {$nom} » (voir MotsClesQuete::EFFETS).");

            $effets[] = ['effet' => $nom, ...$this->{$lecteur}($quete, (array) $effet, $heros)];
        }

        $payload = [
            'type' => 'note_de_quete',
            'repere' => (string) ($note['repere'] ?? ''),
            'declencheur' => $declencheur,
            'personnage' => $heros?->nom,
            'effets' => $effets,
        ];

        app(TamponAnnonces::class)->annoncer(
            $quete->groupe,
            $payload,
            $heros === null ? null : ['type' => 'personnage', 'id' => $heros->id, 'nom' => $heros->nom],
        );

        return $payload;
    }

    // ─── Les effets : un lecteur par mot de `MotsClesQuete::EFFETS` ───────────────────────────────────────────────

    /** E1 — le texte est lu à la table (le bandeau du maître du jeu), et gardé au journal. */
    private function effetTexte(Quete $quete, array $effet, ?Personnage $heros): array
    {
        $texte = trim((string) ($effet['texte'] ?? ''));

        if ($texte !== '') {
            $evenement = Journal::ajouter($quete->groupe, 'narration', ['texte' => $texte]);
            broadcast(new NarrationDiffusee($quete->groupe, $texte, queteId: $quete->id, sequence: $evenement->sequence));
        }

        return ['texte' => $texte];
    }

    /** E2 — l'or va à la bourse du héros qui déclenche (`Bourses`) ; sans lui, il est partagé. */
    private function effetOr(Quete $quete, array $effet, ?Personnage $heros): array
    {
        $or = max(0, (int) ($effet['or'] ?? 0));

        return ['or' => $or, 'parts' => app(Bourses::class)->verser($quete->groupe, $or, $heros)];
    }

    /** E2 — l'objet du catalogue entre dans le sac du héros qui déclenche, même plein : c'est un butin de quête. */
    private function effetObjet(Quete $quete, array $effet, ?Personnage $heros): array
    {
        $objet = $this->catalogue->index('objets')[(string) ($effet['slug'] ?? '')] ?? null;
        $receveur = $heros ?? $quete->groupe->personnages()
            ->wherePivot('actif', true)
            ->orderBy('groupe_personnages.ordre_initiative')
            ->first();

        if (! $objet instanceof Objet || $receveur === null) {
            return ['objet' => null];
        }

        $quantite = max(1, (int) ($effet['quantite'] ?? 1));
        $deborde = ! RangementObjet::peutRanger($receveur, $objet, $quantite);
        RangementObjet::ranger($objet, (int) $receveur->id, $quantite);

        return [
            'objet' => ['id' => $objet->id, 'nom' => $objet->nom],
            'quantite' => $quantite,
            'receveur' => $receveur->nom,
            ...($deborde ? ['sac_deborde' => true] : []),
        ];
    }

    /** E3 — des monstres du catalogue apparaissent, révélés, sur les cases écrites (ou la plus proche si prise). */
    private function effetApparition(Quete $quete, array $effet, ?Personnage $heros): array
    {
        $catalogue = $this->catalogue->index('monstres');
        $apparus = [];

        foreach ((array) ($effet['monstres'] ?? []) as $pose) {
            $monstre = $catalogue[(string) ($pose['slug'] ?? '')] ?? null;

            if ($monstre === null) {
                continue;
            }

            // La grille est relue à chaque figure : celle qui vient de naître occupe sa case.
            $place = CaseLibre::iciOuProche(FabriqueGrille::pour($quete->fresh()), (int) $pose['x'], (int) $pose['y']);

            if ($place === null) {
                // Aucune case libre alentour : la figure ne paraît pas, et l'annonce ne la nomme pas.
                $apparus[] = ['sans_place' => true];

                continue;
            }

            $instance = $quete->instancesMonstres()->create([
                'monstre_id' => $monstre->id,
                'pv_body' => (int) $monstre->pv_body,
                'pv_body_max' => (int) $monstre->pv_body,
                'pv_mind' => (int) $monstre->pv_mind,
                'position_x' => $place['x'],
                'position_y' => $place['y'],
                'etat' => 'actif',
                'elite' => false,
                'revele' => true, // posée sous les yeux des héros
            ]);

            // Le nom AFFICHÉ de la figure (`InstanceMonstre::nomAffiche()`), jamais le nom de catalogue.
            $apparus[] = ['nom' => $instance->nomAffiche(), 'instance_id' => $instance->id, 'x' => $place['x'], 'y' => $place['y']];
        }

        return ['monstres' => $apparus];
    }

    /** E7 — des points de Corps perdus par le héros qui déclenche, par `MoteurDegats` (réductions comprises). */
    private function effetDegats(Quete $quete, array $effet, ?Personnage $heros): array
    {
        $body = max(0, (int) ($effet['body'] ?? 0));

        if ($heros === null || $body === 0) {
            return ['degats' => 0];
        }

        $subis = app(MoteurDegats::class)->infligerAHeros($heros, $body, MoteurDegats::SOURCE_PIEGE, ['note_de_quete' => true]);

        return ['degats' => (int) $subis, 'nom' => $heros->nom];
    }

    /** E10 — le drapeau est posé sur la quête (`quetes.drapeaux`) ; il ne s'annonce pas, il se LIT. */
    private function effetDrapeau(Quete $quete, array $effet, ?Personnage $heros): array
    {
        $nom = (string) ($effet['nom'] ?? '');
        $quete->poserDrapeau($nom);

        return ['nom' => $nom];
    }
}
