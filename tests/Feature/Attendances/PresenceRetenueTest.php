<?php

declare(strict_types=1);

namespace Tests\Feature\Attendances;

use App\Enums\AttendanceMark;
use App\Models\ESBTPAttendance;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\Attendances\PresenceRetenue;
use App\Services\Report\AttendanceReportContextBuilder;
use App\Services\TenantManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #1649 — UNE règle dit si une présence compte (décision de la superviseure, 27/11) :
 * - la MARQUE de l'appel l'emporte sur le statut visio quand elle existe : un élève marqué absent est absent, même
 *   « connecté » ; présent ou en retard comptent présents ;
 * - sans marque, le statut visio s'applique : connecté ou déconnecté compte présent, exclu compte absent ;
 * - un statut nul n'est JAMAIS compté présent.
 *
 * Deux formes, une règle : sur une ligne ({@see PresenceRetenue::de()}) et en requête
 * ({@see PresenceRetenue::presentes()}), liées par un test d'équivalence.
 */
final class PresenceRetenueTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{?string, ?string, ?AttendanceMark}>
     */
    public static function lignes(): array
    {
        return [
            'marqué absent mais connecté' => ['absent', 'connected', AttendanceMark::Absent],
            'marqué présent mais exclu' => ['present', 'kicked', AttendanceMark::Present],
            'marqué en retard, sans visio' => ['late', null, AttendanceMark::Late],
            'marqué excusé' => ['excused', 'disconnected', AttendanceMark::Excused],
            'sans marque, connecté' => [null, 'connected', AttendanceMark::Present],
            'sans marque, déconnecté' => [null, 'disconnected', AttendanceMark::Present],
            'sans marque, exclu' => [null, 'kicked', AttendanceMark::Absent],
            'sans marque ni statut' => [null, null, null],
        ];
    }

    #[DataProvider('lignes')]
    public function test_la_presence_retenue_d_une_ligne(?string $marque, ?string $statut, ?AttendanceMark $attendue): void
    {
        $ligne = new ESBTPAttendance(['mark' => $marque, 'status' => $statut]);

        self::assertSame($attendue, PresenceRetenue::de($ligne));
    }

    public function test_les_deux_formes_comptent_les_memes_presents(): void
    {
        $ecole = $this->ecole();
        $seance = Seance::factory()->for($ecole)->create(['date_seance' => '2026-12-02 08:00:00']);
        foreach (self::lignes() as [$marque, $statut]) {
            $this->ligne($seance, $ecole, $marque, $statut);
        }

        $parLigne = ESBTPAttendance::query()->get()->filter(fn (ESBTPAttendance $l): bool => PresenceRetenue::estPresente($l))->pluck('id')->sort()->values()->all();
        $parRequete = PresenceRetenue::presentes(ESBTPAttendance::query())->pluck('id')->sort()->values()->all();

        self::assertSame($parLigne, $parRequete);
        // Présent malgré l'exclusion, en retard, connecté sans marque, déconnecté sans marque.
        self::assertCount(4, $parRequete);
    }

    public function test_le_rapport_compte_un_absent_marque_absent_meme_connecte(): void
    {
        Carbon::setTestNow('2026-12-02 12:00:00');
        $ecole = $this->ecole();
        $seance = Seance::factory()->for($ecole)->create(['date_seance' => '2026-12-02 08:00:00']);
        $this->ligne($seance, $ecole, 'absent', 'connected');
        $this->ligne($seance, $ecole, 'late', null);
        $this->ligne($seance, $ecole, null, null);

        $stats = app(AttendanceReportContextBuilder::class)->build(['date_start' => '2026-12-02', 'date_end' => '2026-12-02'], $ecole->id)['stats'];

        self::assertSame(['total' => 3, 'presents' => 1, 'absents' => 1, 'retards' => 1], array_intersect_key($stats, array_flip(['total', 'presents', 'absents', 'retards'])));
    }

    /** La migration rend `status` nullable sans toucher une ligne existante (ARRÊT 1, Q1). */
    public function test_la_migration_ne_touche_aucune_ligne_existante(): void
    {
        $ecole = $this->ecole();
        $seance = Seance::factory()->for($ecole)->create();
        foreach (['connected', 'disconnected', 'kicked'] as $statut) {
            $this->ligne($seance, $ecole, null, $statut);
        }
        $migration = require database_path('migrations/2026_11_27_150000_make_status_nullable_on_esbtp_attendance.php');
        $migration->down();
        $avant = DB::table('esbtp_attendance')->orderBy('id')->get()->map(fn ($l) => (array) $l)->all();

        $migration->up();

        self::assertSame($avant, DB::table('esbtp_attendance')->orderBy('id')->get()->map(fn ($l) => (array) $l)->all());
        $this->ligne($seance, $ecole, 'absent', null);
        self::assertSame(1, DB::table('esbtp_attendance')->whereNull('status')->count(), 'Le statut nul est désormais admis.');
    }

    private function ecole(): Institution
    {
        $ecole = Institution::factory()->standalone()->create();
        app(TenantManager::class)->set($ecole);

        return $ecole;
    }

    private function ligne(Seance $seance, Institution $ecole, ?string $marque, ?string $statut): void
    {
        $ligne = ESBTPAttendance::factory()->create([
            'seance_id' => $seance->id,
            'user_id' => User::factory()->student()->create(['institution_id' => $ecole->id])->id,
            'institution_id' => $ecole->id,
            'mark' => $marque,
        ]);
        // Le statut est posé après la création : la fabrique en pose un par défaut.
        DB::table('esbtp_attendance')->where('id', $ligne->id)->update(['status' => $statut]);
    }
}
