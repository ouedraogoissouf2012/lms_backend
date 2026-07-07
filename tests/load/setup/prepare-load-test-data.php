<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
/** @var Kernel $kernel */
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

const DEFAULT_USER_COUNT = 20;
const DEFAULT_KLASSCI_STUB_URL = 'http://127.0.0.1:8089';
const LOOPBACK_HOSTS = ['127.0.0.1', 'localhost', '::1'];
const LOGIN_POOL_SIZE = 5;
const FIXTURE_RELATIVE_PATH = '/fixtures/load-test-users.json';

/**
 * @param list<string> $argv
 * @return array<string, string|true>
 */
function parseCliOptions(array $argv): array
{
    $options = [];

    foreach (array_slice($argv, 1) as $arg) {
        if (! str_starts_with($arg, '--')) {
            continue;
        }

        $pair = substr($arg, 2);
        $eqPos = strpos($pair, '=');

        if ($eqPos === false) {
            $options[$pair] = true;
            continue;
        }

        $options[substr($pair, 0, $eqPos)] = substr($pair, $eqPos + 1);
    }

    return $options;
}

/**
 * @param array<string, string|true> $options
 */
function resolveUserCount(array $options): int
{
    $raw = $options['users'] ?? getenv('LOAD_TEST_USER_COUNT');

    if ($raw === false || $raw === true || trim((string) $raw) === '') {
        return DEFAULT_USER_COUNT;
    }

    $count = filter_var($raw, FILTER_VALIDATE_INT);
    if ($count === false || $count < 1) {
        fwrite(STDERR, "[prepare-load-test-data] --users doit etre un entier strictement positif (recu: {$raw}).\n");
        exit(1);
    }

    return $count;
}

/**
 * @param array<string, string|true> $options
 */
function resolveKlassciStubUrl(array $options): string
{
    $raw = $options['klassci-stub-url'] ?? getenv('LOAD_TEST_KLASSCI_STUB_URL');

    if ($raw === false || $raw === true || trim((string) $raw) === '') {
        return DEFAULT_KLASSCI_STUB_URL;
    }

    return (string) $raw;
}

function guardNotProduction(): void
{
    if (! app()->environment('production')) {
        return;
    }

    fwrite(
        STDERR,
        "[prepare-load-test-data] REFUS: APP_ENV=production detecte. Aucune donnee de test ne doit etre creee en production.\n",
    );
    exit(1);
}

function guardKlassciStubIsLoopback(string $klassciStubUrl): void
{
    $host = parse_url($klassciStubUrl, PHP_URL_HOST);
    if (is_string($host) && str_starts_with($host, '[') && str_ends_with($host, ']')) {
        $host = substr($host, 1, -1);
    }

    if (is_string($host) && in_array(strtolower($host), LOOPBACK_HOSTS, true)) {
        return;
    }

    fwrite(
        STDERR,
        "[prepare-load-test-data] REFUS: --klassci-stub-url doit pointer vers un host loopback (".
        implode(', ', LOOPBACK_HOSTS)."). Aucune ecriture effectuee.\n",
    );
    exit(1);
}

function createTestInstitution(string $klassciStubUrl): Institution
{
    return Institution::factory()->create([
        'slug' => 'loadtest-'.Str::random(8),
        'name' => 'Load Test Tenant (#372)',
        'klassci_api_url' => $klassciStubUrl,
        'klassci_api_token_encrypted' => 'loadtest-institution-token-'.Str::random(32),
        'is_active' => true,
        'settings' => [
            'load_test' => true,
            'created_at' => now()->toIso8601String(),
        ],
    ]);
}

/**
 * @return list<array{user: User, token: string}>
 */
function createTestUsers(Institution $institution, int $count, string $klassciStubUrl, string $plainPassword): array
{
    $created = [];

    for ($i = 0; $i < $count; $i++) {
        $user = User::factory()->for($institution)->create([
            'role' => Role::Etudiant->value,
            'last_klassci_sync' => now(),
            'klassci_tenant_url' => $klassciStubUrl,
            'klassci_token' => 'loadtest-stub-token-'.Str::random(16),
            'password' => Hash::make($plainPassword),
        ]);

        $created[] = [
            'user' => $user,
            'token' => $user->createToken('lms-backend-token', ['lms:access'])->plainTextToken,
        ];
    }

    return $created;
}

/**
 * @param list<array{user: User, token: string}> $createdUsers
 * @return array<string, mixed>
 */
function buildFixturePayload(
    Institution $institution,
    string $klassciStubUrl,
    array $createdUsers,
    string $plainPassword,
): array {
    $loginPool = array_map(
        static fn (array $entry): array => [
            'username' => $entry['user']->email,
            'password' => $plainPassword,
        ],
        array_slice($createdUsers, 0, LOGIN_POOL_SIZE),
    );

    $users = array_map(
        static fn (array $entry, int $index): array => [
            'index' => $index,
            'user_id' => $entry['user']->id,
            'role' => $entry['user']->role,
            'token' => $entry['token'],
            'token_type' => 'Bearer',
        ],
        $createdUsers,
        array_keys($createdUsers),
    );

    return [
        'generated_at' => now()->toIso8601String(),
        'klassci_stub_url' => $klassciStubUrl,
        'institution' => [
            'id' => $institution->id,
            'slug' => $institution->slug,
            'settings_marker' => 'load_test',
        ],
        'login_pool' => array_values($loginPool),
        'users' => $users,
    ];
}

/**
 * @param array<string, mixed> $payload
 */
function writeFixtureFile(string $absolutePath, array $payload): void
{
    $directory = dirname($absolutePath);
    if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
        fwrite(STDERR, "[prepare-load-test-data] Impossible de creer le repertoire {$directory}.\n");
        exit(1);
    }

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($absolutePath, $json."\n") === false) {
        fwrite(STDERR, "[prepare-load-test-data] Impossible d'ecrire la fixture {$absolutePath}.\n");
        exit(1);
    }
}

$options = parseCliOptions($argv);
$userCount = resolveUserCount($options);
$klassciStubUrl = resolveKlassciStubUrl($options);

guardNotProduction();
guardKlassciStubIsLoopback($klassciStubUrl);

$plainPassword = 'LoadTest-'.Str::random(24);

[$institution, $createdUsers] = DB::transaction(function () use ($userCount, $klassciStubUrl, $plainPassword): array {
    $institution = createTestInstitution($klassciStubUrl);

    return [$institution, createTestUsers($institution, $userCount, $klassciStubUrl, $plainPassword)];
});

$fixturePath = __DIR__.FIXTURE_RELATIVE_PATH;
writeFixtureFile($fixturePath, buildFixturePayload($institution, $klassciStubUrl, $createdUsers, $plainPassword));

$loginPoolCount = min($userCount, LOGIN_POOL_SIZE);
echo "[prepare-load-test-data] Institution: {$institution->slug} (id={$institution->id}).\n";
echo "[prepare-load-test-data] {$userCount} users crees, {$loginPoolCount} comptes login connus.\n";
echo "[prepare-load-test-data] Stub Klassci: {$klassciStubUrl}\n";
echo "[prepare-load-test-data] Fixture: {$fixturePath}\n";
echo "[prepare-load-test-data] Purge: php tests/load/setup/purge-load-test-data.php --force\n";
