<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Foundation\Http\FormRequest;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use SplFileInfo;
use Tests\TestCase;

/**
 * #1246 — `users.role` s'écrit sous sa valeur canonique. La seule frontière qui reçoit un
 * alias de l'extérieur est une FormRequest : elle le normalise en `prepareForValidation`
 * (`App\Http\Requests\Concerns\EcritLeRoleCanonique`). Les autres écrivains reçoivent leur
 * valeur de `Role`, ou une valeur canonique.
 *
 * Deux verrous, par jetons (hors commentaires) :
 * 1. toute FormRequest dont une clé est `role` normalise un alias, sauf les lectures
 *    déclarées ;
 * 2. chaque `'role' =>`, `['role'] =` et `->role =` d'`app/` est déclaré, par fichier et
 *    par motif, sans numéro de ligne. Un nouvel écrivain doit se déclarer ici.
 */
final class LeRoleSEcritCanoniqueTest extends TestCase
{
    /** Les FormRequests où `role` est un filtre de lecture, jamais écrit. */
    private const LECTURES = [
        'app/Http/Requests/ListUsersRequest.php',
    ];

    /** Les écrivains de `users.role` : chaque motif est une valeur issue de `Role`, ou canonique. */
    private const ECRIVAINS = [
        // La frontière de la gestion : la seule qui reçoit un alias.
        "app/Http/Requests/Concerns/EcritLeRoleCanonique.php  'role' => \$canonique->value",
        "app/Services/Enrollment/InscriptionParCodeService.php  'role' => Role::Etudiant->value",
        // `RoleField::resolve` rend `$role->value`.
        "app/Services/Import/ImportApplyService.php  'role' => \$role",
        // `KlassciRoleSanitizer::forNewUser` rend `$role?->value`, ou `superAdmin`.
        "app/Services/Klassci/Auth/KlassciUserSynchronizer.php  'role' => \$klassciRole",
        'app/Services/SchoolRegistration/SchoolRequestDecisionService.php  ->role = Role::SuperAdmin->value',
        'app/Services/Sync/Classes/ClasseStudentsSynchronizer.php  \'role\' => Role::Etudiant->value',
    ];

    /** Les occurrences qui n'écrivent pas de compte : réponses, journaux, règles, Jitsi. */
    private const NON_ECRITURES = [
        "app/Http/Middleware/EnsurePlatformSupradmin.php  'role' => \$user->role",
        "app/Http/Presenters/AuthResponsePresenter.php  'role' => \$user->role",
        // #1536 : le bloc d'identité des trois réponses de connexion et de `/auth/me`.
        "app/Http/Presenters/IdentiteDuCompte.php  'role' => \$compte->role",
        "app/Http/Presenters/UserListPresenter.php  'role' => \$user->role",
        "app/Http/Requests/CreateUserRequest.php  'role' => ['required'",
        "app/Http/Requests/ListUsersRequest.php  'role' => ['sometimes'",
        "app/Http/Requests/UpdateUserRequest.php  'role' => ['sometimes'",
        "app/Services/Auth/LocalLmsAuthenticator.php  'role' => \$user->role",
        "app/Services/Import/ImportPreviewService.php  'role' => \$this->roles->resolve",
        "app/Services/Klassci/TeacherDirectory/LocalTeacherDirectory.php  'role' => \$user->role",
        "app/Services/Seances/LigneDuRepliLocal.php  'role' => \$user->isStaff",
        "app/Services/Seances/Mutations/ParticipantValidationService.php  'role' => \$userToValidate->isManager",
        "app/Services/Seances/Mutations/ParticipantValidationService.php  'role' => 'student'",
        "app/Services/Sync/Classes/KlassciClassesFetcher.php  'role' => \$userRole",
        "app/Services/Visio/VisioParticipantSessionService.php  'role' => \$user->role",
    ];

    /** Les jetons qui terminent un motif. */
    private const FIN_DU_MOTIF = [',', ';', ']', ')', '(', '?'];

    public function test_toute_requete_qui_recoit_un_role_l_ecrit_canonique(): void
    {
        $verifiees = [];
        $ecarts = [];

        foreach ($this->fichiersPhp('app/Http/Requests') as $relatif => $jetons) {
            $classe = 'App\\'.str_replace('/', '\\', substr($relatif, 4, -4));
            if (in_array($relatif, self::LECTURES, true) || $this->sites($relatif, $jetons) === []
                || ! is_subclass_of($classe, FormRequest::class)) {
                continue;
            }

            $requete = $classe::create('/', 'POST', ['role' => 'teacher']);
            (new ReflectionMethod($requete, 'prepareForValidation'))->invoke($requete);
            $verifiees[] = $relatif;
            if ($requete->input('role') !== 'enseignant') {
                $ecarts[] = $relatif.' laisse `teacher` tel quel (utiliser EcritLeRoleCanonique)';
            }
        }

        self::assertGreaterThanOrEqual(2, count($verifiees), 'Les requêtes de la gestion ne sont plus trouvées.');
        self::assertSame([], $ecarts, "Une requête écrit un alias de rôle (#1246) :\n".implode("\n", $ecarts));
    }

    public function test_chaque_ecriture_du_role_est_declaree(): void
    {
        $inspectes = 0;
        $sites = [];

        foreach ($this->fichiersPhp('app') as $relatif => $jetons) {
            $inspectes++;
            array_push($sites, ...$this->sites($relatif, $jetons));
        }

        $declares = [...self::ECRIVAINS, ...self::NON_ECRITURES];
        sort($declares);
        sort($sites);

        self::assertGreaterThan(100, $inspectes, "seulement {$inspectes} fichier(s) inspecté(s) : la garde ne regarde rien");
        self::assertSame($declares, $sites, sprintf(
            "{$inspectes} fichier(s) inspecté(s). Non déclarés :\n%s\nDéclarés mais absents :\n%s",
            implode("\n", array_diff($sites, $declares)),
            implode("\n", array_diff($declares, $sites)),
        ));
    }

    /**
     * Les occurrences d'une clé ou d'une propriété `role` suivie d'une affectation.
     *
     * @param  list<string|array{0: int, 1: string, 2: int}>  $jetons
     * @return list<string>
     */
    private function sites(string $relatif, array $jetons): array
    {
        $sites = [];
        foreach ($jetons as $i => $jeton) {
            $forme = match (true) {
                $this->estLaCle($jeton) && $this->texte($jetons[$i + 1] ?? null) === '=>' => "'role' =>",
                $this->estLaCle($jeton) && ($jetons[$i - 1] ?? null) === '['
                    && ($jetons[$i + 1] ?? null) === ']' && ($jetons[$i + 2] ?? null) === '=' => "['role'] =",
                $this->texte($jeton) === '->' && $this->texte($jetons[$i + 1] ?? null) === 'role'
                    && ($jetons[$i + 2] ?? null) === '=' => '->role =',
                default => null,
            };
            if ($forme !== null) {
                $debut = $i + ($forme === "'role' =>" ? 2 : 3);
                $sites[] = $relatif.'  '.$forme.' '.$this->motif($jetons, $debut);
            }
        }

        return $sites;
    }

    /** @param  list<string|array{0: int, 1: string, 2: int}>  $jetons */
    private function motif(array $jetons, int $debut): string
    {
        $motif = '';
        for ($j = $debut; isset($jetons[$j]) && ! in_array($jetons[$j], self::FIN_DU_MOTIF, true); $j++) {
            $motif .= $this->texte($jetons[$j]);
        }

        return $motif;
    }

    private function estLaCle(mixed $jeton): bool
    {
        return is_array($jeton) && $jeton[0] === T_CONSTANT_ENCAPSED_STRING && in_array($jeton[1], ["'role'", '"role"'], true);
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
