<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Enums\InstitutionMode;
use App\Exceptions\BusinessException;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use App\Services\Enrollment\StudentEnrolmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\PreparesClasseWithCode;
use Tests\TestCase;

/**
 * #1652 (PR B) — une classe LOCALE s'archive et se désarchive.
 *
 * Archiver date la classe et ferme son code ; ses élèves restent (aucune ligne de
 * `classe_etudiant` n'est touchée). Désarchiver efface la date ; le code reste fermé, l'école en
 * tire un nouveau si elle veut rouvrir. Une classe archivée ne reçoit plus d'inscription, par
 * aucune porte : les deux portes par code (même 404 qu'un code inconnu), le service
 * d'inscription (409 `class_archived`, la garde que #1619 emprunte aussi) et l'import.
 *
 * Volet KLASSCI et classe miroir : 403 `catalogue_managed_by_klassci`. Autre école : 404.
 * Vrai jeton (`asTenant`).
 */
final class UneClasseSArchiveTest extends TestCase
{
    use ActsAsTenantUser;
    use PreparesClasseWithCode;
    use RefreshDatabase;

    public function test_archiver_date_la_classe_ferme_son_code_et_garde_ses_eleves(): void
    {
        [$ecole, , $classe] = $this->classeAvecCode();
        $this->adhesion($classe, $this->apprenant($ecole), 'actif');

        $this->archiver($this->gestion($ecole), $classe)->assertOk()
            ->assertJsonPath('data.id', $classe->id)
            ->assertJsonPath('data.code_inscription.etat', 'retire');

        $ligne = DB::table('classes')->where('id', $classe->id)->first();
        self::assertNotNull($ligne?->archivee_le, 'La classe n\'est pas datée.');
        self::assertNotNull($ligne->code_inscription_revoque_le, 'Le code est resté ouvert.');
        self::assertSame(1, DB::table('classe_etudiant')->where('classe_id', $classe->id)->where('statut', 'actif')->count());
    }

    public function test_archiver_deux_fois_garde_la_premiere_date(): void
    {
        [$ecole, , $classe] = $this->classeAvecCode();
        $gestion = $this->gestion($ecole);

        $this->travelTo('2026-11-20 09:00:00');
        $this->archiver($gestion, $classe)->assertOk();
        $this->travelTo('2026-11-25 09:00:00');
        $this->archiver($gestion, $classe)->assertOk();

        self::assertStringStartsWith('2026-11-20', (string) DB::table('classes')->where('id', $classe->id)->value('archivee_le'));
    }

    public function test_desarchiver_efface_la_date_et_le_code_reste_ferme(): void
    {
        [$ecole, , $classe] = $this->classeAvecCode();
        $gestion = $this->gestion($ecole);
        $this->archiver($gestion, $classe)->assertOk();

        $this->asTenant($gestion)->postJson("/api/classes/{$classe->id}/desarchiver")->assertOk()
            ->assertJsonPath('data.archivee_le', null)
            ->assertJsonPath('data.code_inscription.etat', 'retire');

        self::assertNull(DB::table('classes')->where('id', $classe->id)->value('archivee_le'));
    }

    public function test_une_classe_archivee_ne_recoit_pas_de_nouveau_code(): void
    {
        [$ecole, , $classe] = $this->classeAvecCode();
        $gestion = $this->gestion($ecole);
        $this->archiver($gestion, $classe)->assertOk();

        $this->asTenant($gestion)->postJson("/api/classes/{$classe->id}/code-inscription")
            ->assertStatus(409)->assertJsonPath('reason', 'class_archived');
        self::assertNotNull(DB::table('classes')->where('id', $classe->id)->value('code_inscription_revoque_le'));
    }

    /** Même archivée avec un code resté ouvert (état antérieur, course), aucune porte par code n'ouvre. */
    public function test_les_portes_par_code_refusent_une_classe_archivee(): void
    {
        [$ecole, $code, $classe] = $this->classeAvecCode();
        DB::table('classes')->where('id', $classe->id)->update(['archivee_le' => now()]);

        $this->postJson('/api/inscriptions', [
            'code' => $code, 'nom' => 'Awa Kabore', 'email' => 'neuf@test.ci',
            'password' => 'mot-de-passe-choisi', 'password_confirmation' => 'mot-de-passe-choisi',
        ], ['X-Institution' => (string) $ecole->slug])->assertNotFound();
        $this->asTenant($this->apprenant($ecole))->postJson('/api/me/inscriptions', ['code' => $code])->assertNotFound();

        self::assertSame(0, DB::table('classe_etudiant')->where('classe_id', $classe->id)->count());
        self::assertFalse(User::query()->withoutGlobalScopes()->where('email', 'neuf@test.ci')->exists());
    }

    /** La garde vit dans le service : toute porte qui l'emprunte (#1619 compris) la reçoit. */
    public function test_le_service_d_inscription_refuse_une_classe_archivee(): void
    {
        [$ecole, , $classe] = $this->classeAvecCode();
        DB::table('classes')->where('id', $classe->id)->update(['archivee_le' => now(), 'code' => '6A']);
        $service = app(StudentEnrolmentService::class);

        try {
            $service->rejoindre($this->apprenant($ecole), $classe->refresh());
            self::fail('Une classe archivée a reçu une adhésion.');
        } catch (BusinessException $refus) {
            self::assertSame([409, 'class_archived'], [$refus->statutHttp(), $refus->reason]);
        }

        $import = $service->inscrireParCodeDeClasse($this->apprenant($ecole, 'import@test.ci'), $ecole->id, '6A');
        self::assertSame('classe_archivee', $import->code);
        self::assertSame(0, DB::table('classe_etudiant')->where('classe_id', $classe->id)->count());
    }

    public function test_une_classe_d_une_autre_ecole_est_introuvable(): void
    {
        [, , $ailleurs] = $this->classeAvecCode();
        $gestion = $this->gestion(Institution::factory()->create(['mode' => InstitutionMode::Standalone]));

        $this->archiver($gestion, $ailleurs)->assertNotFound();
        $this->asTenant($gestion)->postJson("/api/classes/{$ailleurs->id}/desarchiver")->assertNotFound();
        self::assertNull(DB::table('classes')->where('id', $ailleurs->id)->value('archivee_le'));
    }

    public function test_une_classe_miroir_ne_s_archive_pas_ici(): void
    {
        $ecole = Institution::factory()->create(['mode' => InstitutionMode::Standalone]);
        $miroir = Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => 4242]);

        $this->archiver($this->gestion($ecole), $miroir)
            ->assertForbidden()->assertJsonPath('reason', 'catalogue_managed_by_klassci');
        self::assertNull(DB::table('classes')->where('id', $miroir->id)->value('archivee_le'));
    }

    public function test_une_ecole_klassci_est_refusee(): void
    {
        $ecole = Institution::factory()->create(['mode' => InstitutionMode::Klassci]);
        $classe = Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => null]);

        $this->archiver($this->gestion($ecole), $classe)
            ->assertForbidden()->assertJsonPath('reason', 'catalogue_managed_by_klassci');
        self::assertNull(DB::table('classes')->where('id', $classe->id)->value('archivee_le'));
    }

    private function archiver(User $gestion, Classe $classe): TestResponse
    {
        return $this->asTenant($gestion)->postJson("/api/classes/{$classe->id}/archiver");
    }

    private function gestion(Institution $ecole): User
    {
        return User::factory()->create(['institution_id' => $ecole->id, 'role' => 'superAdmin', 'klassci_token' => null]);
    }

    private function adhesion(Classe $classe, User $apprenant, string $statut): void
    {
        DB::table('classe_etudiant')->insert([
            'classe_id' => $classe->id, 'user_id' => $apprenant->id, 'institution_id' => $classe->institution_id,
            'statut' => $statut, 'date_inscription' => '2026-01-10', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
