<?php

namespace App\Models;

use App\Engine\DureeEffet;
use App\Engine\RegainEffet;
use App\Partie\MoteurSorts;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Personnage extends Model
{
    protected $table = 'personnages';

    protected $fillable = [
        'joueur_id',
        'groupe_actif_id',
        'nom',
        'classe',
        'niveau',
        'attribut_body',
        'attribut_mind',
        'pv_body_max',
        'pv_body',
        'pv_mind_max',
        'pv_mind',
        'des_attaque',
        'des_defense',
        'deplacement_base',
        'or',
    ];

    /**
     * Point de passage UNIQUE du « premier dégât subi ».
     *
     * Les PV d'un héros baissent depuis une dizaine d'endroits — attaque de
     * monstre, piège, sort de Dread, tir ami. Câbler l'expiration dans chacun,
     * c'est garantir d'en oublier un aujourd'hui et tous les prochains ; on
     * observe donc la BAISSE elle-même, comme `FabriqueGrille` tient l'unique
     * boucle du mobilier.
     *
     * Ne se déclenche que si `pv_body` DIMINUE réellement : un soin, une
     * sauvegarde sans changement ou un jet paré à 0 dégât ne consomment pas
     * Peau de Pierre — c'est le sang versé qui compte, pas le jet.
     */
    protected static function booted(): void
    {
        static::updated(function (Personnage $personnage): void {
            $avant = $personnage->getOriginal('pv_body');
            $apres = $personnage->pv_body;

            if ($avant === null || $apres === null || (int) $apres >= (int) $avant) {
                return;
            }

            app(MoteurSorts::class)
                ->expirerBuffs($personnage, DureeEffet::PREMIER_DEGAT_SUBI);
        });

        // Symétrique du précédent, et pour la même raison : les PV REMONTENT
        // depuis autant d'endroits qu'ils descendent — potion, sort de soin,
        // relèvement, reprise de snapshot, montée de niveau. Le regain
        // `body_au_max` (« Regain this spell when your Body Points return to
        // their starting number », Shapeshift) s'observe donc lui aussi sur la
        // colonne, jamais sur les appelants.
        static::updated(function (Personnage $personnage): void {
            $avant = $personnage->getOriginal('pv_body');
            $apres = $personnage->pv_body;

            if ($avant === null || $apres === null || (int) $apres <= (int) $avant) {
                return;
            }

            if ((int) $apres >= (int) $personnage->pv_body_max) {
                app(MoteurSorts::class)->regagnerSorts($personnage, RegainEffet::BODY_AU_MAX);
            }
        });
    }

    /** Propriétaire (roster). */
    public function joueur(): BelongsTo
    {
        return $this->belongsTo(Joueur::class, 'joueur_id');
    }

    /** Groupe où le personnage est engagé (un seul actif à la fois). */
    public function groupeActif(): BelongsTo
    {
        return $this->belongsTo(Groupe::class, 'groupe_actif_id');
    }

    /** Tous les groupes (composition & initiative). */
    public function groupes(): BelongsToMany
    {
        return $this->belongsToMany(Groupe::class, 'groupe_personnages', 'personnage_id', 'groupe_id')
            ->withPivot(['ordre_initiative', 'actif']);
    }

    /** Nœuds d'arbre acquis. */
    public function competences(): BelongsToMany
    {
        return $this->belongsToMany(Competence::class, 'personnage_competences', 'personnage_id', 'competence_id');
    }

    /**
     * Points de compétence disponibles — JAMAIS stockés, toujours dérivés
     * (contrat) : 1 point par niveau gagné, moins les nœuds ACHETÉS.
     *
     * ⚠ « achetés », pas « acquis » : les capacités de carte `innee` vivent
     * dans le MÊME pivot (`CapacitesInnees::attribuer()` les y attache à la
     * création), mais elles viennent avec la figurine et ne coûtent aucun
     * point — c'est la règle posée avec la grille de talents, qui les laisse
     * justement HORS de la grille (`colonne`/`rang`/`categorie` à NULL).
     *
     * Les compter revenait à faire payer au héros des capacités qu'on lui avait
     * données : au niveau 2, le berserker (3 innées) et le moine (4) avaient
     * `max(0, 1 − 3)` et `max(0, 1 − 4)`, soit ZÉRO point, quand le druide —
     * l'une des deux seules classes sans capacité innée — recevait le sien
     * normalement (constaté par René au terme de la 3e quête, 2026-09-04). Le
     * berserker aurait attendu le niveau 4 et le moine le niveau 5 pour ouvrir
     * leur premier nœud, sans qu'aucun écran ne dise pourquoi.
     */
    public function pointsCompetence(): int
    {
        return max(0, ((int) $this->niveau - 1) - $this->competences()->where('innee', false)->count());
    }

    /** Lignes d'inventaire (équipé + sac + consommables). */
    public function inventaire(): HasMany
    {
        return $this->hasMany(Inventaire::class, 'personnage_id');
    }

    /** Sorts connus, avec disponibilité (épuisé/dispo par quête). */
    public function sorts(): BelongsToMany
    {
        return $this->belongsToMany(Sort::class, 'personnage_sorts', 'personnage_id', 'sort_id')
            ->withPivot('disponible');
    }

    /** États temporaires (durée + source). */
    public function conditions(): BelongsToMany
    {
        return $this->belongsToMany(Condition::class, 'personnage_conditions', 'personnage_id', 'condition_id')
            ->withPivot(['duree', 'source']);
    }

    /**
     * Les conditions encore ACTIVES, c'est-à-dire celles qui portent un
     * COMPTEUR non épuisé.
     *
     * ⚠ `duree = 0` ne veut pas dire « absente » mais « sans compteur » : son
     * expiration vient d'un déclencheur, pas d'un décompte (doc 19, `duree_defaut`).
     * Une *Apeuré* permanente n'est donc pas rendue ici — c'est voulu, et c'est
     * la raison d'être de ce point de passage unique : le filtre `duree > 0`
     * existait en DEUX exemplaires (l'épreuve « Inscription menaçante » qui les
     * dissipe, et le menu qui décide de la proposer). Deux copies d'une règle
     * assez simple pour que personne ne remarque l'une dériver — la leçon que
     * `Salles::indexDe()` a déjà coûtée.
     *
     * @return Collection<int, Condition>
     */
    public function conditionsActives()
    {
        return $this->conditions()->wherePivot('duree', '>', 0)->get();
    }

    /** Résumés des campagnes terminées (survit au nettoyage du groupe). */
    public function historique(): HasMany
    {
        return $this->hasMany(PersonnageHistorique::class, 'personnage_id');
    }

    /** Position & statut de tour par quête (runtime). */
    public function etatsQuete(): HasMany
    {
        return $this->hasMany(EtatPersonnageQuete::class, 'personnage_id');
    }
}
