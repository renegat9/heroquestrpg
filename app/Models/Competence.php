<?php

namespace App\Models;

use App\Engine\MotsClesTalent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Competence extends Model
{
    protected $table = 'competences';

    protected $fillable = [
        'classe',
        'nom',
        'description',
        'type',
        // Capacité de CARTE, acquise d'emblée et gratuitement (Stalwart,
        // Enrage, Ambidextrous…) : au plateau la carte vient avec la figurine.
        // Un nœud `innee` est attaché à la création et ne coûte aucun point.
        'innee',
        'effet',
        // Position dans la GRILLE de talents (3 colonnes × 3 lignes, 2026-08-23).
        // `categorie` est le libellé de la colonne, PROPRE À LA CLASSE — il n'y
        // a pas de vocabulaire commun : le berserker a « Rage », l'explorateur
        // « Traque ». Nul pour un nœud `innee`, qui n'est pas dans la grille.
        'categorie',
        'categorie_icone',
        'colonne',
        'rang',
        'prerequis_id',
    ];

    protected function casts(): array
    {
        return [
            'effet' => 'array',
            'innee' => 'boolean',
            'colonne' => 'integer',
            'rang' => 'integer',
        ];
    }

    /**
     * Ce nœud est-il un bonus PERMANENT et chiffré — c'est-à-dire quelque chose
     * qui doit vivre dans une colonne du personnage, et non être résolu en
     * situation ?
     *
     * Deux conditions, et la seconde est celle qui compte : un passif portant
     * une `condition` (Frénésie « sous la moitié des PV », Garde tenace « à la
     * première attaque du combat ») n'est PAS permanent — il est lu au moment
     * où il s'applique. L'ajouter à la colonne le compterait deux fois.
     *
     * ⚠ Point de passage UNIQUE de cette règle : `CompetenceController`
     * l'applique à l'acquisition, `Equipement::recalculerCombat()` la rejoue à
     * chaque changement d'équipement. Les deux DOIVENT trancher pareil — sinon
     * un bonus est perdu d'un côté ou doublé de l'autre.
     */
    public function estBonusPermanent(): bool
    {
        return $this->type === 'passif'
            && ! isset($this->effet['condition'])
            && (int) ($this->effet['valeur'] ?? 0) !== 0;
    }

    /**
     * L'AVANTAGE chiffré du nœud, dérivé de `effet` — « +1 dé de défense, contre
     * la première attaque du combat ».
     *
     * Jamais saisi à la main, à la différence de `description` : c'est ce qui
     * garantit qu'un talent ne promette pas autre chose que ce qu'il fait. Les
     * deux textes sont publiés côte à côte (`/api/competences`, `/api/guide`) et
     * affichés ensemble sur la feuille de talent.
     */
    public function avantage(): string
    {
        return MotsClesTalent::avantage((array) $this->effet);
    }

    /** Icône Material Symbols de la mécanique portée par ce nœud. */
    public function avantageIcone(): string
    {
        return MotsClesTalent::icone((array) $this->effet);
    }

    /** Nœud parent dans l'arbre. */
    public function prerequis(): BelongsTo
    {
        return $this->belongsTo(Competence::class, 'prerequis_id');
    }

    /** Nœuds débloqués par celui-ci. */
    public function suivantes(): HasMany
    {
        return $this->hasMany(Competence::class, 'prerequis_id');
    }

    public function personnages(): BelongsToMany
    {
        return $this->belongsToMany(Personnage::class, 'personnage_competences', 'competence_id', 'personnage_id');
    }

    /**
     * Résistance de condition (mécanique `resistance_condition`, ex. Sang
     * robuste du Nain vs Empoisonné, CompetenceSeeder) : le personnage a-t-il
     * acquis un nœud qui résiste nommément à `$nomCondition` ?
     */
    public static function resisteA(Personnage $personnage, string $nomCondition): bool
    {
        return $personnage->competences()
            ->get(['competences.id', 'competences.effet'])
            ->contains(function (self $c) use ($nomCondition) {
                if (($c->effet['mecanique'] ?? null) !== 'resistance_condition') {
                    return false;
                }

                // `condition_nom` accepte une chaîne OU une liste : un même
                // talent peut couvrir plusieurs poisons. Sang robuste, qui ne
                // nommait que « Empoisonné », laissait passer « Envenimé » —
                // un serpent paralysait donc le nain « au sang robuste »
                // (audit des talents, 2026-08-10).
                $couvertes = (array) ($c->effet['condition_nom'] ?? []);

                return in_array($nomCondition, $couvertes, true);
            });
    }
}
