<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Groupe;
use App\Models\GroupeMercenaire;
use App\Models\Mercenaire;
use App\Models\Personnage;
use App\Partie\Images\BibliothequeImages;
use App\Support\Journal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Le COMPAGNON ANIMAL OFFERT (*Against the Ogre Horde*, livret F9528 p. 8-9) — la DÉCISION,
 * prise en UN seul point.
 *
 * « Animal allies are faithful companions who can be recruited AT NO COST to accompany a hero
 * on a quest, so long as there are FEWER THAN FOUR hero players. The animal ally must be
 * recruited before the quest begins. » Décision de René : 2026-10-02 (Q2, « proposé au début
 * d'une campagne dont le groupe compte moins de 4 joueurs »), interprétation confirmée le
 * 2026-10-15 — proposé UNE fois, gratuitement, et il accompagne le groupe TOUTE la campagne
 * (le livret, lui, le propose avant chaque quête). Garde notre règle d'UN animal par groupe.
 *
 * ## QUAND — pourquoi pas « à la création du groupe »
 *
 * À la création, le fondateur est SEUL : `POST /groupes` attache un héros, les autres joueurs
 * rejoignent ensuite par le code (`POST /groupes/{id}/joueurs`), jusqu'au lancement. Compter à
 * la création offrirait un animal à tout groupe, y compris à ceux qui finiront à quatre. Le
 * seul instant où la taille du groupe est CONNUE est le lancement de la première quête, et
 * c'est aussi la borne du livret (« before the quest begins »). La proposition est donc
 * OUVERTE au hub tant que le groupe n'a JAMAIS lancé de quête ({@see self::ouvert()}), son
 * existence suivant le nombre de héros du moment : un quatrième joueur qui arrive la retire,
 * un départ la rend. Elle se ferme d'elle-même au premier lancement.
 *
 * ## QUI DÉCIDE — le premier joueur qui la prend
 *
 * Le mécanisme existant le plus proche est le recrutement d'un allié (`MercenaireController`),
 * pas un vote : le héros qui recrute devient son MAÎTRE (`recruteur_personnage_id`, l'allié
 * joue dans le tour de ce héros, depuis sa manette — chantier 3a, 2026-10-04), et le livret dit
 * de même (« controlled by the player that recruited them »). Un `VoteGroupe` (`choix_groupe`)
 * élirait un animal mais ne dirait pas QUI le mène, et il exigerait que tous aient voté avant
 * de pouvoir être prêts — le piège du vote de sortie, où un joueur distrait enferme les autres.
 * Ici, un seul geste, d'un seul joueur, ferme l'offre pour tous : prendre un des animaux du
 * catalogue, ou la refuser. Si René préfère un vote, c'est un autre choix à lui demander.
 *
 * ## Ce qui est durable, et où
 *
 * L'issue vit en base (`groupes.compagnon_offert` : `null` / `pris` / `refuse`), jamais en
 * cache. L'animal est un `GroupeMercenaire` ordinaire marqué `offert` : mêmes tours, mêmes
 * soins, même purge s'il tombe (il ne revient pas : « une fois »), mais EXEMPTÉ de l'entretien
 * de 10 po par quête (`FaveursHopekins::reglerEntretien()`). Il compte, par ailleurs, pour
 * l'animal unique du groupe (`RecrutementHub`), et pour les quatre suivants de son maître.
 *
 * ## La liste est le catalogue
 *
 * Les animaux offerts sont ceux du catalogue (`mercenaires.animal`, hors `octroi_seul`) — la
 * liste publiée EST la liste blanche que {@see self::prendre()} revalide.
 */
final class CompagnonOffert
{
    /** « fewer than four hero players » : l'offre n'existe qu'en dessous de ce nombre de héros. */
    public const SEUIL_HEROS = 4;

    /** Issues durables de `groupes.compagnon_offert` (`null` = pas encore tranchée). */
    public const PRIS = 'pris';

    public const REFUSE = 'refuse';

    /** Animaux du catalogue offerts, du moins cher au plus cher (puis par id). */
    public function animaux(): Collection
    {
        return Mercenaire::query()
            ->where('animal', true)
            ->where('octroi_seul', false)
            ->where('captif', false)
            ->orderBy('prix')
            ->orderBy('id')
            ->get();
    }

    /** Héros ACTIFS du groupe : « hero players » du livret (un héros par siège). */
    public function nbHeros(Groupe $groupe): int
    {
        return (int) $groupe->personnages()->wherePivot('actif', true)->count();
    }

    /**
     * La proposition existe-t-elle MAINTENANT ? Au hub, issue non tranchée, aucune quête
     * jamais lancée, un à trois héros, un animal à offrir.
     */
    public function ouvert(Groupe $groupe): bool
    {
        if ($groupe->phase !== 'hub' || $groupe->compagnon_offert !== null) {
            return false;
        }

        // Une quête qui existe, quel que soit son état, a déjà « commencé » la campagne :
        // les groupes en cours au moment du déploiement n'ont donc jamais cette offre.
        if ($groupe->quetes()->exists()) {
            return false;
        }

        $n = $this->nbHeros($groupe);

        return $n >= 1 && $n < self::SEUIL_HEROS && $this->animaux()->isNotEmpty();
    }

    /**
     * Ce que la manette et la table lisent (`EtatGroupe.groupe.compagnon_offert`) : `null`
     * quand il n'y a rien à proposer. La consigne est écrite ICI — le client n'a ni le seuil
     * ni le nombre de héros à re-dériver.
     *
     * @return array<string, mixed>|null
     */
    public function publier(Groupe $groupe): ?array
    {
        if (! $this->ouvert($groupe)) {
            return null;
        }

        $n = $this->nbHeros($groupe);
        $images = app(BibliothequeImages::class);

        return [
            'nb_heros' => $n,
            'seuil' => self::SEUIL_HEROS,
            'consigne' => "Le groupe compte {$n} héros (moins de ".self::SEUIL_HEROS.') : un compagnon animal vous est offert, gratuitement, '
                .'pour toute la campagne. Un seul choix, une seule fois — le joueur qui le prend le mène.',
            'animaux' => $this->animaux()->map(fn (Mercenaire $m) => [
                'mercenaire_id' => (int) $m->id,
                'nom' => (string) $m->nom,
                'type' => (string) $m->type,
                'deplacement' => (int) $m->deplacement,
                'attaque' => (int) $m->attaque,
                'defense' => (int) $m->defense,
                'pv_body' => (int) $m->pv_body,
                'description' => $m->description,
                'image_url' => $images->urlMercenaire($m->id, $m->nom),
            ])->values()->all(),
        ];
    }

    /**
     * Motif pour lequel un joueur ne peut pas encore se déclarer PRÊT, ou `null`. Appelé par
     * `POST /pret` : la proposition est unique, on s'assure qu'elle a été VUE avant de partir
     * (même patron que le panier de marché non confirmé : un seul geste, d'un seul joueur,
     * suffit à lever le refus pour tous — prendre l'animal OU le refuser).
     */
    public function refusDeDepart(Groupe $groupe): ?string
    {
        return $this->ouvert($groupe)
            ? 'Un compagnon animal est offert au groupe, une seule fois : prenez-en un (ou refusez-le) avant de partir.'
            : null;
    }

    /**
     * Un joueur PREND un des animaux pour son héros `$recruteur`. Revalide tout : l'offre est
     * ouverte, l'animal est dans la liste blanche, personne ne l'a pris entre-temps.
     */
    public function prendre(Groupe $groupe, Personnage $recruteur, int $mercenaireId): GroupeMercenaire
    {
        if (! $this->ouvert($groupe)) {
            throw ValidationException::withMessages([
                'groupe' => 'Aucun compagnon animal n\'est offert à ce groupe (déjà tranché, quête lancée, ou quatre héros).',
            ]);
        }

        $animal = $this->animaux()->firstWhere('id', $mercenaireId);

        if ($animal === null) {
            throw ValidationException::withMessages([
                'mercenaire_id' => 'Ce n\'est pas un des compagnons animaux offerts.',
            ]);
        }

        $recrue = DB::transaction(function () use ($groupe, $recruteur, $animal) {
            // Compare-and-set : deux joueurs qui touchent en même temps ne le prennent pas deux fois.
            $tranche = Groupe::whereKey($groupe->id)->whereNull('compagnon_offert')
                ->update(['compagnon_offert' => self::PRIS]);

            if ($tranche === 0) {
                throw ValidationException::withMessages(['groupe' => 'Un autre joueur vient de trancher l\'offre.']);
            }

            return GroupeMercenaire::create([
                'groupe_id' => $groupe->id,
                'mercenaire_id' => $animal->id,
                'recruteur_personnage_id' => $recruteur->id,
                'pv_body' => (int) $animal->pv_body,
                'etat' => 'actif',
                'offert' => true,
            ]);
        });

        $groupe->compagnon_offert = self::PRIS;

        Journal::ajouter($groupe, 'systeme', [
            'action' => 'compagnon_offert_pris',
            'mercenaire' => $animal->nom,
            'mercenaire_id' => (int) $animal->id,
            'recruteur_personnage_id' => (int) $recruteur->id,
            'recruteur' => $recruteur->nom,
            'texte' => "{$recruteur->nom} prend {$animal->nom} pour compagnon : offert, il accompagnera le groupe toute la campagne.",
        ], ['nom' => $recruteur->nom]);

        return $recrue;
    }

    /** Le groupe REFUSE l'offre (définitif : elle ne revient pas). */
    public function refuser(Groupe $groupe, Personnage $auteur): void
    {
        if (! $this->ouvert($groupe)) {
            throw ValidationException::withMessages([
                'groupe' => 'Aucun compagnon animal n\'est offert à ce groupe (déjà tranché, quête lancée, ou quatre héros).',
            ]);
        }

        $tranche = Groupe::whereKey($groupe->id)->whereNull('compagnon_offert')
            ->update(['compagnon_offert' => self::REFUSE]);

        if ($tranche === 0) {
            throw ValidationException::withMessages(['groupe' => 'Un autre joueur vient de trancher l\'offre.']);
        }

        $groupe->compagnon_offert = self::REFUSE;

        Journal::ajouter($groupe, 'systeme', [
            'action' => 'compagnon_offert_refuse',
            'personnage_id' => (int) $auteur->id,
            'texte' => "{$auteur->nom} décline le compagnon animal offert : l'offre est close.",
        ], ['nom' => $auteur->nom]);
    }
}
