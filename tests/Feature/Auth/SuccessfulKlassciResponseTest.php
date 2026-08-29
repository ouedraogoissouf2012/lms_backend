<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Http\Presenters\AuthResponsePresenter;
use App\Models\User;
use Tests\TestCase;

/**
 * Issue #504 — 3e voie live oubliée par #477 : la réponse de `POST /auth/login`
 * (KLASSCI) exposait `is_admin`/`permissions`/`admin_data` BRUTS depuis le payload
 * KLASSCI, alors que `/auth/me` et le stockage les filtrent déjà.
 *
 * Ce test verrouille le presenter (lieu exact du fix) : un payload KLASSCI
 * compromis (`is_admin=true`, `permissions=['*']`, `admin_data` arbitraire) ne
 * doit PAS ré-apparaître dans la réponse de login. Les champs d'affichage
 * légitimes restent présents, et `institution_name` (dérivé en interne d'
 * `admin_data.etablissement`) reste exposé en `meta`.
 *
 * Test unitaire pur (aucune DB) : le presenter est sans état.
 *
 * @see app/Http/Presenters/AuthResponsePresenter.php
 */
final class SuccessfulKlassciResponseTest extends TestCase
{
    /**
     * @return array<string, mixed>  Le bloc `data.user` de la réponse de login.
     */
    private function loginUserPayload(): array
    {
        $user = new User();
        $user->forceFill([
            'id'         => 42,
            'klassci_id' => 'k-42',
            'name'       => 'Dupont',
            'email'      => 'dupont@demo.test',
            'role'       => 'enseignant',
        ]);

        // Payload KLASSCI (potentiellement compromis).
        $klassciUser = [
            'role_display_name' => 'Enseignant',
            'avatar'            => 'avatar.png',
            'is_admin'          => true,
            'permissions'       => ['*'],
            'admin_data'        => ['etablissement' => 'Lycee X', 'is_admin' => true, 'permissions' => ['*']],
            'enseignant_data'   => ['matieres' => [1, 2]],
            'etudiant_data'     => null,
        ];

        $response = (new AuthResponsePresenter())->successfulKlassci(
            $user,
            'sanctum-token',
            $klassciUser,
            ['annee_universitaire_courante' => '2025-2026'],
            ['code' => 'demo', 'api_base_url' => 'https://demo.klassci.test'],
        );

        /** @var array{data: array{user: array<string, mixed>}} $decoded */
        $decoded = $response->getData(true);

        return $decoded['data']['user'];
    }

    public function test_raw_is_admin_permissions_admin_data_are_excluded_from_login(): void
    {
        $user = $this->loginUserPayload();

        self::assertArrayNotHasKey('is_admin', $user, 'is_admin brut ne doit pas fuir dans la réponse login');
        self::assertArrayNotHasKey('permissions', $user, 'permissions brutes ne doivent pas fuir');
        self::assertArrayNotHasKey('admin_data', $user, 'admin_data brut (peut contenir is_admin imbriqué) ne doit pas fuir');
    }

    public function test_legitimate_display_fields_are_preserved(): void
    {
        $user = $this->loginUserPayload();

        self::assertSame('Dupont', $user['name']);
        self::assertSame('enseignant', $user['role']);
        self::assertSame('avatar.png', $user['avatar']);
        self::assertSame('Enseignant', $user['role_display_name']);
        self::assertSame(['matieres' => [1, 2]], $user['enseignant_data']);
    }

    public function test_institution_name_is_still_derived_from_admin_data_in_meta(): void
    {
        $user = new User();
        $user->forceFill(['id' => 1, 'klassci_id' => 'k1', 'name' => 'X', 'email' => 'x@x.test', 'role' => 'enseignant']);

        $response = (new AuthResponsePresenter())->successfulKlassci(
            $user,
            'token',
            ['admin_data' => ['etablissement' => 'Lycee X']],
            [],
            ['code' => 'demo', 'api_base_url' => 'https://demo.klassci.test'],
        );

        /** @var array{meta: array{institution_name: string}} $decoded */
        $decoded = $response->getData(true);

        // Dérivé en interne d'admin_data.etablissement — exposé en meta, pas dans user.
        self::assertSame('Lycee X', $decoded['meta']['institution_name']);
    }
}
