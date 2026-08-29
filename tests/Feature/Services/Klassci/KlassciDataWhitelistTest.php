<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Klassci;

use App\Services\Klassci\Data\KlassciDataWhitelist;
use Tests\TestCase;

/**
 * Issue #477 — Contrat du collaborateur KlassciDataWhitelist (défense en profondeur).
 *
 * `filter()` restreint un payload KLASSCI (potentiellement compromis) à une
 * liste blanche stricte de clés d'affichage, tout en préservant les clés LMS
 * internes `_lms_*` du blob existant. Fonction pure, testée en isolation (pas de DB).
 */
final class KlassciDataWhitelistTest extends TestCase
{
    private KlassciDataWhitelist $whitelist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->whitelist = new KlassciDataWhitelist;
    }

    public function test_drops_non_whitelisted_keys(): void
    {
        $result = $this->whitelist->filter([
            'is_admin' => true,
            'permissions' => ['*'],
            'is_superuser' => true,
            'scopes' => ['a', 'b'],
            'foo' => 'bar',
            'nom' => 'Dupont',
            'id' => 1,
        ]);

        self::assertArrayNotHasKey('is_admin', $result);
        self::assertArrayNotHasKey('permissions', $result);
        self::assertArrayNotHasKey('is_superuser', $result);
        self::assertArrayNotHasKey('scopes', $result);
        self::assertArrayNotHasKey('foo', $result);
        self::assertSame('Dupont', $result['nom']);
        self::assertSame(1, $result['id']);
    }

    public function test_keeps_display_keys(): void
    {
        $result = $this->whitelist->filter([
            'id' => 1,
            'nom' => 'a',
            'name' => 'b',
            'prenom' => 'c',
            'photo' => 'https://cdn/avatar.png',
            'role' => 'enseignant',
            'enseignant_id' => 42,
        ]);

        self::assertEqualsCanonicalizing(
            ['id', 'nom', 'name', 'prenom', 'photo', 'role', 'enseignant_id'],
            array_keys($result),
        );
    }

    public function test_preserves_existing_lms_keys(): void
    {
        $result = $this->whitelist->filter(
            ['nom' => 'X'],
            ['_lms_tenant_url' => 'https://school.klassci.test'],
        );

        self::assertSame('https://school.klassci.test', $result['_lms_tenant_url']);
        self::assertSame('X', $result['nom']);
    }

    public function test_rejects_lms_keys_from_payload(): void
    {
        // Un KLASSCI compromis tente d'injecter/écraser un _lms_* : la valeur
        // existante (LMS interne) DOIT gagner, jamais celle du payload.
        $result = $this->whitelist->filter(
            ['_lms_tenant_url' => 'https://evil.attacker.test', 'nom' => 'X'],
            ['_lms_tenant_url' => 'https://real.klassci.test'],
        );

        self::assertSame('https://real.klassci.test', $result['_lms_tenant_url']);
    }

    public function test_empty_and_malformed_payload_never_throws(): void
    {
        self::assertSame([], $this->whitelist->filter([]));

        // Valeur non-scalaire sous une clé whitelistée : conservée telle quelle
        // (la whitelist filtre les CLÉS, pas les valeurs) — aucune exception.
        $result = $this->whitelist->filter(['nom' => ['array', 'imbriqué']]);
        self::assertSame(['array', 'imbriqué'], $result['nom']);

        // Payload vide + _lms_* existants → seules les _lms_* survivent.
        $withLms = $this->whitelist->filter([], ['_lms_tenant_url' => 'https://x']);
        self::assertSame(['_lms_tenant_url' => 'https://x'], $withLms);
    }
}
