<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Matiere;
use App\Models\User;
use App\Services\Classe\TeacherClassesQueryService;
use App\Services\Klassci\Auth\KlassciUserSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Le login enseignant doit ENREGISTRER le lien enseignant ↔ matière (#712).
 *
 * La sync des matières existe depuis #258 : elle miroite les matières que
 * KLASSCI reconnaît à l'utilisateur CONNECTÉ. Mais elle ne gardait aucune
 * trace de « qui » — si bien que `matiere_enseignant` restait vide (0 ligne
 * mesurée le 2026-09-08) et que « Mes Classes » n'avait rien à afficher.
 *
 * Ce test ferme la boucle de bout en bout : un login enseignant doit suffire
 * pour que l'écran liste ses classes, sans aucune autre intervention.
 */
final class TeacherMatieresLinkedAtLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.klassci.url', 'https://klassci.test');
        config()->set('services.klassci.token', 'system-token');
    }

    /**
     * LE défaut corrigé : après le login, le lien existe.
     */
    public function test_teacher_login_records_the_matiere_link(): void
    {
        $institution = $this->loginTeacherWithMatieres([11, 12]);

        $liens = DB::table('matiere_enseignant')
            ->where('institution_id', $institution->id)
            ->where('status', 'active')
            ->pluck('klassci_matiere_id')
            ->all();

        sort($liens);
        self::assertSame([11, 12], $liens);
    }

    /**
     * La boucle complète : login → lien → « Mes Classes » liste les classes.
     *
     * C'est l'assertion qui compte. Les autres vérifient des maillons ; celle-ci
     * vérifie que la chaîne tient de bout en bout, sans intervention manuelle.
     */
    public function test_after_login_the_screen_lists_the_teacher_classes(): void
    {
        $institution = $this->loginTeacherWithMatieres([11]);

        // Le miroir classe ↔ matière, écrit par ailleurs à la synchro des classes.
        $classe = Classe::withoutGlobalScope('institution')->create([
            'institution_id' => $institution->id,
            'klassci_id' => 7001,
            'libelle' => 'B2 COM',
        ]);
        $matiere = Matiere::withoutGlobalScope('institution')
            ->where('institution_id', $institution->id)
            ->where('klassci_id', 11)
            ->firstOrFail();
        DB::table('classe_matiere')->insert([
            'classe_id' => $classe->id,
            'matiere_id' => $matiere->id,
            'institution_id' => $institution->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $teacher = User::withoutGlobalScope('institution')
            ->where('institution_id', $institution->id)
            ->where('role', 'enseignant')
            ->firstOrFail();

        $classes = app(TeacherClassesQueryService::class)->listFor($teacher);

        self::assertSame(['B2 COM'], array_column($classes, 'libelle'));
    }

    /**
     * Un étudiant ne déclenche pas cette sync, et ne doit donc écrire aucun
     * lien d'enseignant.
     */
    public function test_a_student_login_records_no_teacher_link(): void
    {
        Http::fake(['*' => Http::response(['data' => []], 200)]);
        $institution = Institution::factory()->create(['slug' => 'school-b']);

        app(KlassciUserSynchronizer::class)->sync(
            ['id' => 8, 'nom' => 'ETUDIANT', 'email' => 'etu@school.edu', 'role' => 'etudiant'],
            'student-token',
            'https://school-b.klassci.test',
            $institution,
        );

        self::assertSame(0, DB::table('matiere_enseignant')->count());
    }

    /**
     * @param  list<int>  $klassciMatiereIds
     */
    private function loginTeacherWithMatieres(array $klassciMatiereIds): Institution
    {
        Http::fake(['*' => Http::response([
            'data' => array_map(
                static fn (int $id): array => ['id' => $id, 'code' => 'M'.$id, 'libelle' => 'Matière '.$id],
                $klassciMatiereIds,
            ),
        ], 200)]);

        $institution = Institution::factory()->create(['slug' => 'school-a']);

        app(KlassciUserSynchronizer::class)->sync(
            ['id' => 9, 'nom' => 'PROF BEDE', 'email' => 'prof@school.edu', 'role' => 'enseignant'],
            'teacher-token',
            'https://school-a.klassci.test',
            $institution,
        );

        return $institution;
    }
}
