<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Enums\Role;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * #1198 — les rôles se lisent par UNE table, `Role::aliases()`. Une liste de rôles recopiée
 * dans `app/` ne suit pas un ajout ou un retrait d'alias, et une valeur canonique seule manque
 * les comptes à alias : un élève `student` n'était ni averti d'un quiz, ni compté au tableau.
 *
 * Par jetons PHP, hors commentaires. Rouge sur :
 * 1. un littéral de rôle (une valeur de la table) dans `app/`, hors `Role.php`, hors clé
 *    `'x' =>` et hors accès d'index `$a['x']`, sauf les faux positifs déclarés ;
 * 2. un `match` dont le sujet est un rôle ;
 * 3. un filtre `'role'` ou `'users.role'` sur une seule `Role::X->value`, qui doit lire
 *    `Role::X->aliases()` ;
 * 4. un paramètre `role:…` dans `routes/` : depuis #1665, une route exige une PERMISSION
 *    (`permission:…`, catalogue `App\Enums\Permission`), jamais une liste de rôles.
 */
final class AucuneListeDeRolesEnDurTest extends TestCase
{
    private const LA_TABLE = 'app/Enums/Role.php';

    /**
     * Des littéraux qui ne sont pas des rôles de compte, par fichier et par motif : des
     * relations Eloquent, une clé de payload KLASSCI, les rôles Jitsi (un autre vocabulaire).
     */
    private const FAUX_POSITIFS = [
        "app/Services/Evaluation/Teacher/EvaluationTeacherGradeService.php  'student'",
        "app/Services/Lesson/LessonListService.php  'enseignant'",
        "app/Services/Matiere/MatiereInfoFetcher.php  'enseignant'",
        "app/Services/Report/ReportGenerationService.php  'student'",
        "app/Services/Seances/KlassciSeanceLookupService.php  'enseignant'",
        "app/Services/Seances/Mutations/ParticipantValidationService.php  'student'",
        "app/Services/Seances/Mutations/ParticipantValidationService.php  'teacher'",
    ];

    public function test_aucun_litteral_de_role_hors_de_la_table(): void
    {
        $roles = array_merge(...array_map(static fn (Role $role): array => $role->aliases(), Role::cases()));
        $toleres = array_count_values(self::FAUX_POSITIFS);
        $inspectes = 0;
        $lignes = [];

        foreach ($this->fichiersPhp('app') as $relatif => $jetons) {
            $inspectes++;
            if ($relatif === self::LA_TABLE) {
                continue;
            }
            foreach ($this->litterauxDeRole($jetons, $roles) as [$litteral, $ligne]) {
                $motif = "{$relatif}  '{$litteral}'";
                if (($toleres[$motif] ?? 0) > 0) {
                    $toleres[$motif]--;

                    continue;
                }
                $lignes["{$relatif}:{$ligne}"][] = $litteral;
            }
        }

        self::assertGreaterThan(100, $inspectes, "seulement {$inspectes} fichier(s) inspecté(s) : la garde ne regarde rien");
        self::assertSame([], array_keys(array_filter($toleres)), 'Faux positifs déclarés mais absents : à retirer de la liste.');
        self::assertSame([], $this->enLignes($lignes), sprintf(
            "%d ligne(s) recopient la table des rôles (#1198) : lire Role::X->aliases(), \$user->isX() ou Role::tryFromString().\n%s",
            count($lignes),
            implode("\n", $this->enLignes($lignes)),
        ));
    }

    public function test_aucun_match_sur_un_role(): void
    {
        $sites = [];
        foreach ($this->fichiersPhp('app') as $relatif => $jetons) {
            foreach ($jetons as $i => $jeton) {
                if (is_array($jeton) && $jeton[0] === T_MATCH && $this->sujetEstUnRole($jetons, $i + 1)) {
                    $sites[] = "{$relatif}:{$jeton[2]}";
                }
            }
        }

        self::assertSame([], $sites, "Un match sur la valeur brute du rôle manque ses alias : lire \$user->asRoleEnum().\n".implode("\n", $sites));
    }

    public function test_aucun_filtre_sur_une_seule_valeur_de_role(): void
    {
        $sites = [];
        foreach ($this->fichiersPhp('app') as $relatif => $jetons) {
            foreach ($jetons as $i => $jeton) {
                $colonne = is_array($jeton) && $jeton[0] === T_CONSTANT_ENCAPSED_STRING
                    && in_array(substr($jeton[1], 1, -1), ['role', 'users.role'], true);
                if ($colonne && ($jetons[$i + 1] ?? null) === ',' && $this->texte($jetons[$i + 2] ?? null) === 'Role'
                    && $this->texte($jetons[$i + 6] ?? null) === 'value') {
                    $sites[] = "{$relatif}:{$jeton[2]}";
                }
            }
        }

        self::assertSame([], $sites, "Un filtre sur une seule valeur manque les alias : whereIn(…, Role::X->aliases()).\n".implode("\n", $sites));
    }

    public function test_les_routes_ne_citent_aucun_role(): void
    {
        // Migration déclarée (#1665 lot 1) : ce test vérifiait que les listes `role:` ne citaient que des valeurs
        // canoniques ; il n'en reste aucune. Les permissions citées sont vérifiées par PermissionsDesRoutesTest.
        $permissions = 0;
        $ecarts = [];
        foreach ($this->fichiersPhp('routes') as $relatif => $jetons) {
            foreach ($jetons as $jeton) {
                if (! is_array($jeton) || $jeton[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }
                $valeur = substr($jeton[1], 1, -1);
                if (str_starts_with($valeur, 'permission:')) {
                    $permissions++;
                } elseif (str_starts_with($valeur, 'role:')) {
                    $ecarts[] = "{$relatif}:{$jeton[2]}  {$valeur}";
                }
            }
        }

        self::assertGreaterThan(0, $permissions, 'Aucun paramètre permission: trouvé : chemin faux ?');
        self::assertSame([], $ecarts, "Une route exige une liste de rôles au lieu d'une permission :\n".implode("\n", $ecarts));
    }

    /**
     * @param  list<string|array{0: int, 1: string, 2: int}>  $jetons
     * @param  list<string>  $roles
     * @return list<array{0: string, 1: int}> littéral et ligne
     */
    private function litterauxDeRole(array $jetons, array $roles): array
    {
        $trouves = [];
        foreach ($jetons as $i => $jeton) {
            if (! is_array($jeton) || $jeton[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $valeur = substr($jeton[1], 1, -1);
            $cle = $this->texte($jetons[$i + 1] ?? null) === '=>';
            $avant = $jetons[$i - 2] ?? null;
            $index = ($jetons[$i - 1] ?? null) === '['
                && ((is_array($avant) && $avant[0] === T_VARIABLE) || $avant === ']' || $avant === ')');
            if (in_array($valeur, $roles, true) && ! $cle && ! $index) {
                $trouves[] = [$valeur, $jeton[2]];
            }
        }

        return $trouves;
    }

    /** @param  list<string|array{0: int, 1: string, 2: int}>  $jetons */
    private function sujetEstUnRole(array $jetons, int $ouvrante): bool
    {
        if (($jetons[$ouvrante] ?? null) !== '(') {
            return false;
        }
        for ($j = $ouvrante + 1, $profondeur = 1; isset($jetons[$j]) && $profondeur > 0; $j++) {
            $profondeur += match ($jetons[$j]) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };
            $propriete = in_array($this->texte($jetons[$j - 1]), ['->', '?->'], true) && $this->texte($jetons[$j]) === 'role';
            if ($propriete || (is_array($jetons[$j]) && $jetons[$j][0] === T_CONSTANT_ENCAPSED_STRING && $jetons[$j][1] === "'role'")) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, list<string>>  $lignes
     * @return list<string>
     */
    private function enLignes(array $lignes): array
    {
        $rendu = [];
        foreach ($lignes as $site => $litteraux) {
            $rendu[] = $site.'  '.implode(', ', $litteraux);
        }
        sort($rendu);

        return $rendu;
    }

    private function texte(mixed $jeton): ?string
    {
        return is_array($jeton) ? $jeton[1] : (is_string($jeton) ? $jeton : null);
    }

    /** @return iterable<string, list<string|array{0: int, 1: string, 2: int}>> chemin relatif => jetons utiles */
    private function fichiersPhp(string $dossier): iterable
    {
        $racine = base_path($dossier);
        $fichiers = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, RecursiveDirectoryIterator::SKIP_DOTS));

        /** @var SplFileInfo $fichier */
        foreach ($fichiers as $fichier) {
            if ($fichier->getExtension() !== 'php') {
                continue;
            }

            $relatif = $dossier.'/'.str_replace('\\', '/', substr($fichier->getPathname(), strlen($racine) + 1));
            $jetons = array_values(array_filter(
                token_get_all((string) file_get_contents($fichier->getPathname())),
                static fn (mixed $jeton): bool => ! (is_array($jeton) && in_array($jeton[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)),
            ));

            yield $relatif => $jetons;
        }
    }
}
