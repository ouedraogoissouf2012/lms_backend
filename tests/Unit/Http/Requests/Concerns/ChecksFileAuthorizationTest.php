<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\Concerns;

use App\Http\Requests\Concerns\ChecksFileAuthorization;
use App\Models\File;
use App\Models\User;
use App\Services\File\LecteurDeFichier;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * #1768 — la règle de {@see LecteurDeFichier}, case par case, et la modération de
 * {@see ChecksFileAuthorization} qui s'y adosse. En mémoire : aucune base.
 *
 * École 1 : celle du lecteur. Fichier d'id 7 ; le compte 1 en est le propriétaire.
 */
#[CoversTrait(ChecksFileAuthorization::class)]
#[CoversClass(LecteurDeFichier::class)]
final class ChecksFileAuthorizationTest extends TestCase
{
    /**
     * [rôle, école du lecteur, est propriétaire, fichier public, école du fichier, lit, modère]
     *
     * @return array<string, array{0: string, 1: ?int, 2: bool, 3: bool, 4: int, 5: bool, 6: bool}>
     */
    public static function cases(): array
    {
        return [
            'élève, le sien' => ['etudiant', 1, true, false, 1, true, true],
            'élève, public d autrui' => ['etudiant', 1, false, true, 1, true, false],
            'élève, privé d autrui' => ['etudiant', 1, false, false, 1, false, false],
            'enseignant, public d autrui' => ['enseignant', 1, false, true, 1, true, false],
            'enseignant, privé d autrui' => ['enseignant', 1, false, false, 1, false, false],
            'coordinateur, privé d autrui' => ['coordinateur', 1, false, false, 1, true, true],
            'admin d école, privé d autrui' => ['superAdmin', 1, false, false, 1, true, true],
            'admin, privé d autrui' => ['admin', 1, false, false, 1, true, true],
            'admin, autre école' => ['admin', 1, false, true, 2, false, false],
            'coordinateur, autre école' => ['coordinateur', 1, false, true, 2, false, false],
            'compte sans école' => ['supradmin', null, false, true, 1, false, false],
            'rôle de gestion sans école' => ['superAdmin', null, false, true, 1, false, false],
        ];
    }

    #[DataProvider('cases')]
    public function test_la_regle_case_par_case(string $role, ?int $ecole, bool $sien, bool $public, int $ecoleDuFichier, bool $lit, bool $modere): void
    {
        $lecteur = (new User)->forceFill(['id' => $sien ? 1 : 2, 'institution_id' => $ecole, 'role' => $role]);
        $fichier = (new File)->forceFill(['id' => 7, 'user_id' => 1, 'institution_id' => $ecoleDuFichier, 'is_public' => $public]);
        $regle = new LecteurDeFichier;

        self::assertSame($lit, $regle->peutLire($lecteur, $fichier), 'lire');
        self::assertSame($modere, $regle->peutModerer($lecteur, $fichier), 'modérer');
    }

    public function test_moderer_un_fichier_illisible_repond_comme_un_fichier_absent(): void
    {
        $enseignant = (new User)->forceFill(['id' => 2, 'institution_id' => 1, 'role' => 'enseignant']);
        $prive = (new File)->forceFill(['id' => 7, 'user_id' => 1, 'institution_id' => 1, 'is_public' => false]);

        $this->expectException(ModelNotFoundException::class);
        $this->sujet()->autoriserLaModeration(new LecteurDeFichier, $prive, $enseignant);
    }

    public function test_moderer_un_fichier_lisible_sans_droit_est_refuse(): void
    {
        $eleve = (new User)->forceFill(['id' => 2, 'institution_id' => 1, 'role' => 'etudiant']);
        $public = (new File)->forceFill(['id' => 7, 'user_id' => 1, 'institution_id' => 1, 'is_public' => true]);

        self::assertFalse($this->sujet()->autoriserLaModeration(new LecteurDeFichier, $public, $eleve));
    }

    public function test_sans_fichier_ni_appelant_rien_n_est_autorise(): void
    {
        self::assertFalse($this->sujet()->autoriserLaModeration(new LecteurDeFichier, null, null));
    }

    private function sujet(): object
    {
        return new class
        {
            use ChecksFileAuthorization {
                autoriserLaModeration as public;
            }
        };
    }
}
