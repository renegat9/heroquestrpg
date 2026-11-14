<?php

declare(strict_types=1);

use App\Models\Groupe;
use App\Models\Personnage;
use App\Partie\Arene\EtapesCampagne;
use App\Partie\Arene\GroupesArene;
use App\Partie\Arene\RefusArene;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\SortSeeder;
use Illuminate\Http\Request;

/**
 * MODE ARÈNE, lot A2 — chaque étape PROPRE À LA CAMPAGNE est fermée à un groupe d'arène, et le registre qui le dit
 * est tenu DANS LES DEUX SENS (`docs/plan-mode-arene.md` §3 : « un groupe d'arène qui atteint une de ces étapes doit
 * lever une erreur nommée, jamais passer en silence »).
 */
beforeEach(function () {
    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, SortSeeder::class, ObjetSeeder::class]);
});

/** Le nom de constante de `EtapesCampagne` qui porte la clé `$cle`. */
function constanteEtape(string $cle): string
{
    $constantes = array_filter(
        (new ReflectionClass(EtapesCampagne::class))->getConstants(),
        fn ($valeur, $nom) => $valeur === $cle && $nom !== 'ETAPES',
        ARRAY_FILTER_USE_BOTH,
    );

    expect($constantes)->toHaveCount(1);

    return (string) array_key_first($constantes);
}

/** Le texte du CORPS d'une méthode, lu dans son fichier. */
function corpsDeMethode(string $classe, string $methode): string
{
    $reflexion = new ReflectionMethod($classe, $methode);
    $lignes = file((string) $reflexion->getFileName());

    return implode('', array_slice($lignes, $reflexion->getStartLine() - 1, $reflexion->getEndLine() - $reflexion->getStartLine() + 1));
}

/** Tous les appels de la garde dans `app/` : [classe, méthode englobante, constante]. */
function appelsDeLaGarde(): array
{
    $appels = [];
    $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($iterateur as $fichier) {
        if ($fichier->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($fichier->getPathname());
        if (! preg_match_all('/exigerCampagne\(\\\\App\\\\Partie\\\\Arene\\\\EtapesCampagne::(\w+)\)/', $source, $m, PREG_OFFSET_CAPTURE)) {
            continue;
        }

        preg_match('/^namespace\s+([\w\\\\]+);/m', $source, $ns);
        preg_match('/^(?:final\s+)?class\s+(\w+)/m', $source, $cl);

        foreach ($m[1] as [$constante, $position]) {
            preg_match_all('/function\s+(\w+)\s*\(/', substr($source, 0, $position), $fonctions);
            $appels[] = [$ns[1].'\\'.$cl[1], (string) end($fonctions[1]), $constante];
        }
    }

    return $appels;
}

it('chaque site déclaré appelle la garde avec SA clé', function () {
    foreach (EtapesCampagne::ETAPES as $cle => $etape) {
        $attendu = 'exigerCampagne(\\App\\Partie\\Arene\\EtapesCampagne::'.constanteEtape($cle).')';

        foreach ($etape['sites'] as [$classe, $methode]) {
            expect(str_contains(corpsDeMethode($classe, $methode), $attendu))
                ->toBeTrue("{$classe}::{$methode} n'appelle pas la garde de « {$cle} »");
        }
    }
});

it('chaque appel de la garde dans app/ est déclaré au registre, à son site', function () {
    $appels = appelsDeLaGarde();

    expect($appels)->not->toBeEmpty();

    foreach ($appels as [$classe, $methode, $constante]) {
        $cle = constant(EtapesCampagne::class.'::'.$constante);

        expect(EtapesCampagne::ETAPES)->toHaveKey($cle)
            ->and(in_array([$classe, $methode], EtapesCampagne::ETAPES[$cle]['sites'], true))
            ->toBeTrue("{$classe}::{$methode} appelle la garde de « {$cle} » sans être déclaré");
    }
});

it('chaque étape porte un libellé et dit ce qui la remplace en arène', function () {
    foreach (EtapesCampagne::ETAPES as $cle => $etape) {
        expect($etape['libelle'])->not->toBeEmpty()
            ->and($etape['remplacee_par'])->not->toBeEmpty()
            ->and($etape['sites'])->not->toBeEmpty("« {$cle} » n'a aucun site");
    }
});

/**
 * Appelle un site comme le ferait le jeu, avec un groupe d'ARÈNE : le groupe pour tout paramètre `Groupe` (ou son
 * identifiant / son id), la doublure engagée pour tout `Personnage`, un modèle vide pour le reste. La garde vient
 * avant tout le reste, donc ces arguments factices ne sont jamais lus.
 */
function appelerSite(string $classe, string $methode, Groupe $arene, Personnage $doublure): void
{
    $valeur = function (ReflectionParameter $p) use ($arene, $doublure) {
        $type = $p->getType() instanceof ReflectionNamedType ? $p->getType()->getName() : null;

        return match (true) {
            $type === Groupe::class => $arene,
            $type === Personnage::class => $doublure,
            $type === Request::class => Request::create('/', 'POST'),
            $p->getName() === 'identifiant' => $arene->identifiant,
            str_contains(strtolower($p->getName()), 'groupe') && $type === 'int' => $arene->id,
            $p->isDefaultValueAvailable() => $p->getDefaultValue(),
            $p->allowsNull() => null,
            $type === 'int' => 0,
            $type === 'string' => 'scene',
            $type === 'bool' => false,
            $type === 'array' => [],
            $type !== null && class_exists($type) => app($type),
            default => null,
        };
    };

    $arguments = [];
    foreach ((new ReflectionMethod($classe, $methode))->getParameters() as $p) {
        $arguments[$p->getName()] = $valeur($p);
    }

    if (str_starts_with($classe, 'App\\Jobs\\')) {
        $constructeur = (new ReflectionClass($classe))->getConstructor();
        $args = [];
        foreach ($constructeur?->getParameters() ?? [] as $p) {
            $args[] = $valeur($p);
        }

        app()->call([new $classe(...$args), $methode]);

        return;
    }

    app()->call([app($classe), $methode], $arguments);
}

it('chaque étape de campagne lève l\'erreur NOMMÉE sur un groupe d\'arène', function () {
    $joueur = connecterJoueur();
    $heros = Personnage::create([
        'joueur_id' => $joueur->id, 'nom' => 'Champion', 'classe' => 'barbare', 'niveau' => 1, 'mode' => 'arene',
        'attribut_body' => 8, 'attribut_mind' => 2, 'pv_body_max' => 8, 'pv_body' => 8, 'pv_mind_max' => 2, 'pv_mind' => 2,
        'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 8,
    ]);
    $arenes = app(GroupesArene::class);
    $arene = $arenes->ouvrir();
    $doublure = $arenes->engager($arene, $heros);

    foreach (EtapesCampagne::ETAPES as $cle => $etape) {
        foreach ($etape['sites'] as [$classe, $methode]) {
            $leve = null;

            try {
                appelerSite($classe, $methode, $arene, $doublure);
            } catch (Throwable $e) {
                $leve = $e;
            }

            expect($leve)->toBeInstanceOf(RefusArene::class, "{$classe}::{$methode} (« {$cle} ») n'a pas levé RefusArene : "
                .($leve === null ? 'aucune erreur' : $leve::class.' — '.$leve->getMessage()));
        }
    }

    // Rien n'a été écrit : le groupe et sa doublure sont toujours là, toujours en arène.
    expect($arene->refresh()->mode)->toBe(Groupe::MODE_ARENE)
        ->and(Personnage::whereKey($doublure->id)->exists())->toBeTrue();
});

it('une campagne passe la garde, quelle que soit l\'étape', function () {
    $campagne = creerGroupe();

    foreach (array_keys(EtapesCampagne::ETAPES) as $cle) {
        $campagne->exigerCampagne($cle);
    }

    expect($campagne->estArene())->toBeFalse()
        ->and($campagne->refresh()->mode)->toBe(Groupe::MODE_CAMPAGNE);
});

it('rejoindre un combat d\'arène par la route de campagne est un refus NOMMÉ (422), pas une panne', function () {
    connecterJoueur();
    $arene = app(GroupesArene::class)->ouvrir();
    $id = $this->postJson('/api/personnages', ['nom' => 'Aventurier', 'classe' => 'barbare'])->json('personnage.id');

    $this->postJson("/api/groupes/{$arene->identifiant}/joueurs", ['personnage_id' => $id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['arene' => 'combat d\'arène']);

    expect(Personnage::find($id)->groupe_actif_id)->toBeNull();
});

/*
 * MODE CLASSIQUE (`docs/plan-mode-classique.md` §7). Le registre répond pour lui aussi : chaque étape de campagne est
 * OUVERTE (`null`) ou FERMÉE (sa raison) à un groupe classique. Dans les deux sens : aucune étape sans réponse, aucune
 * réponse pour une étape qui n'existe pas — c'est ce qui oblige la prochaine étape ajoutée à se prononcer.
 */
it('chaque étape de campagne dit ce qu\'elle fait à un groupe classique, et rien d\'autre', function () {
    expect(array_keys(EtapesCampagne::CLASSIQUE))->toEqualCanonicalizing(array_keys(EtapesCampagne::ETAPES));

    foreach (EtapesCampagne::CLASSIQUE as $cle => $raison) {
        expect($raison === null || (is_string($raison) && trim($raison) !== ''))
            ->toBeTrue("« {$cle} » : la réponse est null (ouverte) ou une raison de fermeture non vide.");
    }
});

it('la garde ferme à un groupe classique exactement les étapes que le registre ferme, par une erreur NOMMÉE', function () {
    $classique = creerGroupe('table-classique');
    $classique->update(['mode' => Groupe::MODE_CLASSIQUE]);

    foreach (EtapesCampagne::CLASSIQUE as $cle => $raison) {
        $leve = null;

        try {
            $classique->exigerCampagne($cle);
        } catch (Throwable $e) {
            $leve = $e;
        }

        if ($raison === null) {
            expect($leve)->toBeNull("« {$cle} » est ouverte au classique, mais la garde a levé : ".$leve?->getMessage());
        } else {
            expect($leve)->toBeInstanceOf(\App\Partie\Classique\RefusClassique::class, "« {$cle} » devait être fermée au classique.")
                ->and($leve->getMessage())->toContain($raison);
        }
    }

    expect($classique->refresh()->estClassique())->toBeTrue()
        ->and($classique->estArene())->toBeFalse();
});
