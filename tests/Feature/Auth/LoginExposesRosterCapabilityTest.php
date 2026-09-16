<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #805 — le client apprend ce qu'il a le DROIT de faire, jamais la nature de
 * l'établissement.
 *
 * Sans cela, l'écran ne peut que deviner : il affiche une carte « Import
 * apprenants » à tout enseignant, et la route répond 403. Une promesse que
 * l'interface ne peut pas tenir.
 *
 * La clé porte la capacité, pas le mode : le jour où l'inscription locale
 * s'ouvrira à d'autres cas, aucune vue ne changera.
 */
final class LoginExposesRosterCapabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_login_local_dans_une_ecole_autonome_annonce_la_capacite(): void
    {
        $user = $this->compteLocalDe(InstitutionMode::Standalone);

        $this->postJson('/api/auth/login', [
            'username' => $user->email,
            'password' => 'Motdepasse1!',
        ])
            ->assertOk()
            ->assertJsonPath('meta.peut_inscrire_localement', true);
    }

    public function test_un_login_local_dans_une_ecole_klassci_ne_l_annonce_pas(): void
    {
        $user = $this->compteLocalDe(InstitutionMode::Klassci);

        $this->postJson('/api/auth/login', [
            'username' => $user->email,
            'password' => 'Motdepasse1!',
        ])
            ->assertOk()
            ->assertJsonPath('meta.peut_inscrire_localement', false);
    }

    public function test_la_cle_est_toujours_presente(): void
    {
        // Une clé absente se lit « false » côté client par accident. Elle doit
        // être explicite, pour que l'écran distingue « interdit » de « je ne
        // sais pas ».
        $user = $this->compteLocalDe(InstitutionMode::Standalone);

        $reponse = $this->postJson('/api/auth/login', [
            'username' => $user->email,
            'password' => 'Motdepasse1!',
        ])->assertOk();

        self::assertArrayHasKey('peut_inscrire_localement', $reponse->json('meta'));
    }

    public function test_la_reponse_n_expose_jamais_le_mode_lui_meme(): void
    {
        // Le client reçoit un DROIT. Lui donner le mode replacerait la décision
        // chez lui, et rouvrirait la porte que la garde OCP ferme.
        $user = $this->compteLocalDe(InstitutionMode::Standalone);

        $corps = $this->postJson('/api/auth/login', [
            'username' => $user->email,
            'password' => 'Motdepasse1!',
        ])->assertOk()->getContent();

        self::assertIsString($corps);
        self::assertStringNotContainsString('standalone', $corps);
        self::assertStringNotContainsString('"mode"', $corps);
    }

    private function compteLocalDe(InstitutionMode $mode): User
    {
        $school = Institution::factory()->create(['mode' => $mode]);
        app(TenantManager::class)->set($school);

        return User::factory()->teacher()->create([
            'institution_id' => $school->id,
            'password' => bcrypt('Motdepasse1!'),
        ]);
    }
}
