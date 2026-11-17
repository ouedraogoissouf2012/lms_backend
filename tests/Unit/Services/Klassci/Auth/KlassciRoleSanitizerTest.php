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
 * désignation EXACTE du rôle plateforme vers l'admin d'INSTITUTION (`superAdmin`).
 * #949 : un rôle connu de la table est écrit sous sa valeur canonique ; un rôle
 * hors de la table, variante de casse comprise, n'est rien (`null`).
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
     * #510 : le rôle PLATEFORME n'est jamais initialisé depuis KLASSCI ; sa valeur
     * exacte est ramenée à l'admin d'institution.
     */
    public function test_neutralise_le_role_plateforme_vers_admin_institution(): void
    {
        self::assertSame('superAdmin', $this->sanitizer->forNewUser('supradmin'));
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
            'alias anglais, canonique'     => ['teacher', 'enseignant'],
            'null → etudiant par défaut'   => [null, 'etudiant'],
        ];
    }

    #[DataProvider('preservedRoleProvider')]
    public function test_ecrit_la_valeur_canonique_d_un_role_connu(?string $incoming, string $expected): void
    {
        self::assertSame($expected, $this->sanitizer->forNewUser($incoming));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function inconnuProvider(): array
    {
        return [
            'variante capitale du plateforme' => ['Supradmin'],
            'variante majuscules'             => ['SUPRADMIN'],
            "entouré d'espaces"             => ['  supradmin  '],
            'variante de casse'               => ['Enseignant'],
            'rôle hors du LMS'                => ['directeur'],
            'ancien alias retiré'             => ['administrator'],
        ];
    }

    /**
     * #949 : un rôle hors de la table (`Role::tryFromString`) n'est rien : ni promu,
     * ni écrit. `null`, et l'appelant refuse la connexion.
     */
    #[DataProvider('inconnuProvider')]
    public function test_un_role_hors_de_la_table_n_est_rien(string $incoming): void
    {
        self::assertNull($this->sanitizer->forNewUser($incoming));
    }

    public function test_ne_retourne_jamais_le_role_plateforme(): void
    {
        foreach (['supradmin', 'Supradmin', 'SUPRADMIN', ' supradmin '] as $incoming) {
            self::assertNotSame('supradmin', $this->sanitizer->forNewUser($incoming));
        }
    }
}
