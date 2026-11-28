<?php

declare(strict_types=1);

namespace Tests\Feature\Suivi;

use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1704 : les seuils de l'alerte de décrochage, réglés par l'administration de l'école
 * (`GET|PUT /api/lms/suivi/decrochage/reglages`). Rangés dans `institutions.settings.decrochage`,
 * sans toucher aux autres réglages de l'école. Vraie route, vrai jeton.
 */
final class ReglagesDuDecrochageTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const CHEMIN = '/lms/suivi/decrochage/reglages';

    private const URL = '/api/lms/suivi/decrochage/reglages';

    private const DEFAUTS = ['jours_sans_connexion' => 7, 'remises_manquees' => 2, 'part_chapitres_non_ouverts' => 50];

    public function test_l_admin_lit_les_defauts_puis_regle_ses_seuils_sans_perdre_ses_autres_reglages(): void
    {
        $ecole = $this->ecole();
        $ecole->forceFill(['settings' => ['seuil_reussite' => 12]])->save();
        $admin = $this->membre($ecole, 'admin');
        $seuils = ['jours_sans_connexion' => 14, 'remises_manquees' => 3, 'part_chapitres_non_ouverts' => 60];

        $this->lire($admin)->assertOk()->assertJsonPath('data', self::DEFAUTS);
        $this->regler($admin, $seuils)->assertOk()->assertJsonPath('data', $seuils);
        $this->lire($admin)->assertOk()->assertJsonPath('data', $seuils);

        self::assertSame(['seuil_reussite' => 12, 'decrochage' => $seuils], Institution::query()->findOrFail($ecole->id)->settings);
        $journal = AuditLog::query()->where('action', 'decrochage_settings_updated')->sole();
        self::assertSame($admin->id, $journal->user_id);
        self::assertSame(['avant' => self::DEFAUTS, 'apres' => $seuils], $journal->after);
    }

    public function test_un_seuil_hors_bornes_ou_absent_est_refuse_et_rien_n_est_ecrit(): void
    {
        $ecole = $this->ecole();
        $admin = $this->membre($ecole, 'admin');
        $avant = Institution::query()->findOrFail($ecole->id)->settings;

        foreach ([
            ['jours_sans_connexion' => 0, 'remises_manquees' => 2, 'part_chapitres_non_ouverts' => 50],
            ['jours_sans_connexion' => 7, 'remises_manquees' => 21, 'part_chapitres_non_ouverts' => 50],
            ['jours_sans_connexion' => 7, 'remises_manquees' => 2, 'part_chapitres_non_ouverts' => 101],
            ['jours_sans_connexion' => 7, 'remises_manquees' => 2],
            ['jours_sans_connexion' => 'sept', 'remises_manquees' => 2, 'part_chapitres_non_ouverts' => 50],
        ] as $saisie) {
            $this->regler($admin, $saisie)->assertUnprocessable();
        }

        self::assertSame($avant, Institution::query()->findOrFail($ecole->id)->settings);
        self::assertSame(0, AuditLog::query()->where('action', 'decrochage_settings_updated')->count());
    }

    public function test_ni_le_coordinateur_ni_l_enseignant_ne_reglent_les_seuils(): void
    {
        $ecole = $this->ecole();

        foreach (['coordinateur', 'enseignant', 'etudiant'] as $role) {
            $membre = $this->membre($ecole, $role);
            $this->lire($membre)->assertForbidden();
            $this->regler($membre, self::DEFAUTS)->assertForbidden();
        }
        $this->anonyme()->getJson(self::URL)->assertUnauthorized();
    }

    private function lire(User $lecteur): TestResponse
    {
        $reponse = $this->asTenant($lecteur)->getJson(self::URL);
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');

        return $reponse;
    }

    /** @param  array<string, mixed>  $seuils */
    private function regler(User $auteur, array $seuils): TestResponse
    {
        $reponse = $this->asTenant($auteur)->putJson(self::URL, $seuils);
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'put');

        return $reponse;
    }
}
