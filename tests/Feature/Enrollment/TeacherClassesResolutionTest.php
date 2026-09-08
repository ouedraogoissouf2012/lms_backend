<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Matiere;
use App\Models\User;
use App\Services\Classe\TeacherClassesQueryService;
use App\Services\Enrollment\KlassciEnrollmentSource;
use App\Services\Enrollment\LocalEnrollmentSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * « Mes Classes » doit rendre les classes de l'enseignant (#712).
 *
 * ## Le défaut, mesuré en local le 2026-09-08
 *
 * L'écran affichait « Aucune classe assignée » pendant que le tableau de bord
 * annonçait 4 classes. Les deux sources de `CompositeEnrollmentSource`
 * interrogeaient la MÊME chose :
 *
 * ```php
 * // LocalEnrollmentSource ET KlassciEnrollmentSource, à l'identique
 * ->whereHas('matieres', fn ($q) => $q->where('classe_matiere.enseignant_id', $teacher->id))
 * ```
 *
 * Or `classe_matiere.enseignant_id` n'est écrite par PERSONNE dans le dépôt :
 * ses deux seules occurrences sont ces deux lectures. Mesure sur la base de
 * dev : colonne présente, toutes les valeurs à `null`, `matiere_enseignant`
 * vide, `user_classes` vide.
 *
 * Le « repli » n'en était donc pas un — c'était un copier-coller de la source
 * locale. Deux chemins, une seule requête, morte de la même façon.
 *
 * ## Le remède : rendre le repli RÉEL
 *
 * Le docblock de `KlassciEnrollmentSource` annonce « cache de sync KLASSCI ».
 * Sa méthode étudiant l'honore (`user_classes`) ; sa méthode enseignant ne
 * l'honorait pas. Elle lit désormais le vrai cache KLASSCI du lien
 * enseignant ↔ matière — `matiere_enseignant` — alimenté à la connexion.
 *
 * `LocalEnrollmentSource` garde `classe_matiere.enseignant_id` : c'est
 * l'affectation LOCALE, celle du mode autonome à venir. Les deux sources
 * répondent enfin à deux questions différentes.
 */
final class TeacherClassesResolutionTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $teacher;

    private Classe $b2com;

    private Classe $bts;

    private Matiere $anglais;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create();
        $this->teacher = User::factory()->teacher()->create([
            'institution_id' => $this->institution->id,
            'klassci_enseignant_id' => 9,
        ]);

        $this->b2com = $this->classe(klassciId: 7001, libelle: 'B2 COM');
        $this->bts = $this->classe(klassciId: 7002, libelle: 'BTS Génie Civil');
        $this->anglais = $this->matiere(klassciId: 3, libelle: 'Anglais');

        // Le miroir classe ↔ matière, écrit par ClasseMatieresSynchronizer.
        $this->link($this->b2com, $this->anglais);
        $this->link($this->bts, $this->anglais);
    }

    /**
     * LE défaut : sans lien enseignant, l'écran est vide.
     */
    public function test_the_klassci_leg_resolves_classes_from_the_teacher_matiere_link(): void
    {
        $this->linkTeacherToMatiere(klassciMatiereId: 3);

        $ids = app(KlassciEnrollmentSource::class)->classeIdsForTeacher($this->teacher);

        sort($ids);
        self::assertSame([$this->b2com->id, $this->bts->id], $ids);
    }

    /**
     * Le service complet, celui que l'écran appelle.
     */
    public function test_the_screen_lists_the_classes_of_the_teacher(): void
    {
        $this->linkTeacherToMatiere(klassciMatiereId: 3);

        $noms = array_column(app(TeacherClassesQueryService::class)->listFor($this->teacher), 'libelle');

        sort($noms);
        self::assertSame(['B2 COM', 'BTS Génie Civil'], $noms);
    }

    /**
     * L'isolation : le lien d'un enseignant d'un AUTRE établissement ne doit
     * jamais faire remonter nos classes. Le `klassci_enseignant_id` n'est
     * unique que par institution.
     */
    public function test_a_link_of_another_institution_never_leaks(): void
    {
        $ailleurs = Institution::factory()->create();
        DB::table('matiere_enseignant')->insert([
            'klassci_matiere_id' => 3,
            'klassci_enseignant_id' => 9,
            'status' => 'active',
            'institution_id' => $ailleurs->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        self::assertSame([], app(KlassciEnrollmentSource::class)->classeIdsForTeacher($this->teacher));
    }

    /**
     * Un lien désactivé ne compte pas : la colonne `status` existe pour ça.
     */
    public function test_an_inactive_link_is_ignored(): void
    {
        $this->linkTeacherToMatiere(klassciMatiereId: 3, status: 'inactive');

        self::assertSame([], app(KlassciEnrollmentSource::class)->classeIdsForTeacher($this->teacher));
    }

    /**
     * La source LOCALE garde sa propre question — l'affectation locale — et
     * n'est pas remplacée par le cache KLASSCI. Les deux doivent DIVERGER,
     * sans quoi le « repli » n'en est pas un.
     */
    public function test_the_local_leg_still_answers_its_own_question(): void
    {
        $this->linkTeacherToMatiere(klassciMatiereId: 3);

        // Rien dans `classe_matiere.enseignant_id` : la source locale ne rend rien…
        self::assertSame([], app(LocalEnrollmentSource::class)->classeIdsForTeacher($this->teacher));

        // …alors que la source KLASSCI, elle, répond.
        self::assertNotSame([], app(KlassciEnrollmentSource::class)->classeIdsForTeacher($this->teacher));
    }

    // ───────────────────── Fixtures ─────────────────────

    private function classe(int $klassciId, string $libelle): Classe
    {
        return Classe::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => $klassciId,
            'libelle' => $libelle,
        ]);
    }

    private function matiere(int $klassciId, string $libelle): Matiere
    {
        return Matiere::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => $klassciId,
            'libelle' => $libelle,
        ]);
    }

    private function link(Classe $classe, Matiere $matiere): void
    {
        DB::table('classe_matiere')->insert([
            'classe_id' => $classe->id,
            'matiere_id' => $matiere->id,
            'institution_id' => $this->institution->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function linkTeacherToMatiere(int $klassciMatiereId, string $status = 'active'): void
    {
        DB::table('matiere_enseignant')->insert([
            'klassci_matiere_id' => $klassciMatiereId,
            'klassci_enseignant_id' => 9,
            'status' => $status,
            'institution_id' => $this->institution->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
