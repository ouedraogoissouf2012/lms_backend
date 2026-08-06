<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Klassci\Auth;

use App\Services\Klassci\Auth\KlassciRoleSanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #510 — le rôle PLATEFORME (`supradmin`, cross-tenant) ne doit jamais
 * pouvoir être initialisé depuis un payload KLASSCI. Le sanitizer ramène toute
 * désignation du rôle plateforme (y compris variantes de casse) vers l'admin
 * d'INSTITUTION (`superAdmin`), et préserve tout autre rôle tel quel.
 *
 * Classe pure, aucune dépendance → test unitaire sans base ni HTTP.
 *
 * @see app/Services/Klassci/Auth/KlassciRoleSanitizer.php
 */
#[CoversClass(KlassciRoleSanitizer::class)]
final class KlassciRoleSanitizerTest extends TestCase
{
    private KlassciRoleSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new KlassciRoleSanitizer();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function platformRoleProvider(): array
    {
        return [
            'token plateforme minuscule'   => ['supradmin', 'superAdmin'],
            'variante capitale'            => ['Supradmin', 'superAdmin'],
            'variante majuscules'          => ['SUPRADMIN', 'superAdmin'],
            'entouré d\'espaces'           => ['  supradmin  ', 'superAdmin'],
        ];
    }

    #[DataProvider('platformRoleProvider')]
    public function test_neutralise_le_role_plateforme_vers_admin_institution(string $incoming, string $expected): void
    {
        self::assertSame($expected, $this->sanitizer->forNewUser($incoming));
    }

    /**
     * @return array<string, array{?string, string}>
     */
    public static function preservedRoleProvider(): array
    {
        return [
            'admin institution superAdmin' => ['superAdmin', 'superAdmin'],
            'admin'                        => ['admin', 'admin'],
            'coordinateur'                 => ['coordinateur', 'coordinateur'],
            'enseignant'                   => ['enseignant', 'enseignant'],
            'etudiant'                     => ['etudiant', 'etudiant'],
            'null → etudiant par défaut'   => [null, 'etudiant'],
        ];
    }

    #[DataProvider('preservedRoleProvider')]
    public function test_preserve_les_roles_legitimes(?string $incoming, string $expected): void
    {
        self::assertSame($expected, $this->sanitizer->forNewUser($incoming));
    }

    public function test_ne_retourne_jamais_le_role_plateforme(): void
    {
        foreach (['supradmin', 'Supradmin', 'SUPRADMIN', ' supradmin '] as $incoming) {
            self::assertNotSame('supradmin', $this->sanitizer->forNewUser($incoming));
        }
    }
}
