<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\TrainingSessionPhase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * #800 — la phase se DÉRIVE des dates (ADR-711-04).
 *
 * Aucune colonne ne la porte : cette fonction est donc la source unique, et
 * elle n'a aucun filet en base. Un défaut ici afficherait « en cours » sur une
 * Période terminée sans qu'aucune contrainte ne bronche.
 *
 * Le temps est figé à une date connue plutôt que lu de l'horloge : sinon le
 * même test dirait vrai aujourd'hui et faux en novembre.
 */
final class TrainingSessionPhaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function phase(?string $ouvre, ?string $ferme, ?string $debut, ?string $fin): TrainingSessionPhase
    {
        return TrainingSessionPhase::depuisLesDates(
            $ouvre === null ? null : Carbon::parse($ouvre),
            $ferme === null ? null : Carbon::parse($ferme),
            $debut === null ? null : Carbon::parse($debut),
            $fin === null ? null : Carbon::parse($fin),
        );
    }

    public function test_avant_l_ouverture_des_inscriptions_la_periode_est_a_venir(): void
    {
        $this->assertSame(
            TrainingSessionPhase::AVenir,
            $this->phase('2026-12-01', '2026-12-20', '2027-01-05', '2027-05-30')
        );
    }

    public function test_pendant_la_fenetre_les_inscriptions_sont_ouvertes(): void
    {
        $this->assertSame(
            TrainingSessionPhase::InscriptionsOuvertes,
            $this->phase('2026-11-01', '2026-11-30', '2026-12-01', '2027-04-30')
        );
    }

    public function test_apres_la_fermeture_mais_avant_le_debut_on_retourne_a_venir(): void
    {
        // « Inscriptions fermées » n'est pas une phase : les quatre valeurs de
        // l'ADR n'en prévoient pas, et en inventer une ici ferait diverger le
        // code du contrat.
        $this->assertSame(
            TrainingSessionPhase::AVenir,
            $this->phase('2026-10-01', '2026-10-31', '2026-12-01', '2027-04-30')
        );
    }

    public function test_le_jour_du_debut_la_periode_est_en_cours(): void
    {
        $this->assertSame(
            TrainingSessionPhase::EnCours,
            $this->phase('2026-09-01', '2026-10-01', '2026-11-15', '2027-03-01')
        );
    }

    public function test_le_jour_de_la_fin_la_periode_est_encore_en_cours(): void
    {
        // La borne est inclusive : une Période qui s'achève aujourd'hui ne doit
        // pas basculer « terminée » avant que la journée soit passée.
        $this->assertSame(
            TrainingSessionPhase::EnCours,
            $this->phase('2026-09-01', '2026-10-01', '2026-10-01', '2026-11-15')
        );
    }

    public function test_apres_la_fin_la_periode_est_terminee(): void
    {
        $this->assertSame(
            TrainingSessionPhase::Terminee,
            $this->phase('2026-06-01', '2026-07-01', '2026-08-01', '2026-11-14')
        );
    }

    public function test_sans_date_de_fin_une_periode_commencee_reste_en_cours(): void
    {
        // Une date absente ne fait pas basculer de phase : sans `ends_on`, rien
        // ne permet d'affirmer que la Période est terminée.
        $this->assertSame(
            TrainingSessionPhase::EnCours,
            $this->phase(null, null, '2026-10-01', null)
        );
    }

    public function test_une_fenetre_d_inscription_sans_fermeture_reste_ouverte(): void
    {
        $this->assertSame(
            TrainingSessionPhase::InscriptionsOuvertes,
            $this->phase('2026-11-01', null, '2026-12-15', null)
        );
    }

    public function test_une_periode_sans_aucune_date_est_a_venir(): void
    {
        // Le cas d'un brouillon tout juste créé : rien n'est encore décidé.
        $this->assertSame(
            TrainingSessionPhase::AVenir,
            $this->phase(null, null, null, null)
        );
    }
}
