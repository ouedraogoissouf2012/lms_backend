<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Enums\TrainingSessionStatus;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\PreparesClasseWithCode;
use Tests\TestCase;

/**
 * Une Période close ferme les codes de ses classes (#897).
 *
 * La règle est LUE au résolveur partagé par les deux portes
 * (`ClasseOuverteParCode`, ADR-803-03) : une Période annulée, clôturée, archivée
 * ou purgée rend le MÊME 404 qu'un code inconnu. Elle couvre aussi les Périodes
 * déjà closes, dont le code est resté posé.
 *
 * Les Périodes sont créées DANS leur fenêtre d'inscription : seul le statut décide
 * ici. La fenêtre et le brouillon (#1208) : `FenetreDInscriptionParCodeTest`.
 */
final class PeriodeCloseFermeLesCodesTest extends TestCase
{
    use ActsAsTenantUser;
    use PreparesClasseWithCode;
    use RefreshDatabase;

    /** @return iterable<string, array{0: TrainingSessionStatus}> */
    public static function periodesCloses(): iterable
    {
        yield 'annulée' => [TrainingSessionStatus::Annulee];
        yield 'clôturée' => [TrainingSessionStatus::Cloturee];
        yield 'archivée' => [TrainingSessionStatus::Archivee];
        yield 'purgée' => [TrainingSessionStatus::Purgee];
    }

    /** @return iterable<string, array{0: TrainingSessionStatus}> */
    public static function periodesOuvertes(): iterable
    {
        yield 'publiée' => [TrainingSessionStatus::Publiee];
    }

    #[DataProvider('periodesCloses')]
    public function test_la_porte_anonyme_refuse_le_code_d_une_periode_close(TrainingSessionStatus $statut): void
    {
        [$ecole, $code, $classe] = $this->classeAvecCode();
        $this->rattacher($classe, $ecole, $statut);

        $this->postJson('/api/inscriptions', $this->charge($code), ['X-Institution' => (string) $ecole->slug])
            ->assertNotFound();
        self::assertSame(0, $classe->etudiants()->count());
    }

    #[DataProvider('periodesCloses')]
    public function test_la_porte_authentifiee_refuse_le_code_d_une_periode_close(TrainingSessionStatus $statut): void
    {
        [$ecole, $code, $classe] = $this->classeAvecCode();
        $this->rattacher($classe, $ecole, $statut);
        $apprenant = $this->apprenant($ecole);

        $this->asTenant($apprenant)->postJson('/api/me/inscriptions', ['code' => $code])->assertNotFound();
        self::assertSame(0, $classe->etudiants()->count());
    }

    /** Le refus est celui d'un code inconnu : rien ne dit que la Période est close. */
    public function test_le_refus_est_celui_d_un_code_inconnu(): void
    {
        [$ecole, $code, $classe] = $this->classeAvecCode();
        $this->rattacher($classe, $ecole, TrainingSessionStatus::Cloturee);
        $entete = ['X-Institution' => (string) $ecole->slug];

        $close = $this->postJson('/api/inscriptions', $this->charge($code), $entete)->json();
        $inconnu = $this->postJson('/api/inscriptions', $this->charge('ZZZZZZ'), $entete)->json();

        self::assertSame($inconnu, $close);
    }

    #[DataProvider('periodesOuvertes')]
    public function test_une_periode_ouverte_garde_ses_codes(TrainingSessionStatus $statut): void
    {
        [$ecole, $code, $classe] = $this->classeAvecCode();
        $this->rattacher($classe, $ecole, $statut);

        $this->postJson('/api/inscriptions', $this->charge($code), ['X-Institution' => (string) $ecole->slug])
            ->assertSuccessful();
        self::assertSame(1, $classe->etudiants()->count());
    }

    public function test_une_classe_sans_periode_garde_son_code(): void
    {
        [$ecole, $code, $classe] = $this->classeAvecCode();

        $this->postJson('/api/inscriptions', $this->charge($code), ['X-Institution' => (string) $ecole->slug])
            ->assertSuccessful();
        self::assertSame(1, $classe->etudiants()->count());
    }

    // ───────────────────────── fixtures

    private function rattacher(Classe $classe, Institution $ecole, TrainingSessionStatus $statut): void
    {
        $periode = TrainingSession::factory()->inscriptionsOuvertes()
            ->create(['institution_id' => $ecole->id, 'status' => $statut]);
        $classe->forceFill(['training_session_id' => $periode->id])->save();
    }

    /** @return array<string, string> */
    private function charge(string $code): array
    {
        return [
            'code' => $code,
            'nom' => 'Awa Kabore',
            'email' => 'awa@test.ci',
            'password' => 'mot-de-passe-choisi',
            'password_confirmation' => 'mot-de-passe-choisi',
        ];
    }
}
