<?php

declare(strict_types=1);

const KLASSCI_STUB_LOOPBACK_HOSTS = ['127.0.0.1', '::1', 'localhost'];
const KLASSCI_STUB_DEFAULT_PORT = 8089;
const KLASSCI_STUB_DEFAULT_LATENCY_MS = 80;

if (PHP_SAPI !== 'cli-server') {
    exit(klassci_stub_launch());
}

klassci_stub_guard_loopback_or_die();
klassci_stub_simulate_latency();
klassci_stub_dispatch(
    strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
    (string) ($_SERVER['REQUEST_URI'] ?? '/'),
);

function klassci_stub_launch(): int
{
    $host = '127.0.0.1';
    $port = klassci_stub_configured_port();

    fwrite(
        STDOUT,
        sprintf(
            "[klassci-stub] Listening on %s:%d, simulated latency %d ms\n",
            $host,
            $port,
            klassci_stub_configured_latency_ms(),
        ),
    );
    fwrite(STDOUT, "[klassci-stub] Press Ctrl+C to stop.\n");

    passthru(
        sprintf('%s -S %s:%d %s', escapeshellarg(PHP_BINARY), $host, $port, escapeshellarg(__FILE__)),
        $exitCode,
    );

    return $exitCode;
}

function klassci_stub_configured_port(): int
{
    $raw = getenv('KLASSCI_STUB_PORT');
    if ($raw === false || trim($raw) === '') {
        return KLASSCI_STUB_DEFAULT_PORT;
    }

    $port = filter_var($raw, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 65535],
    ]);

    return $port !== false ? $port : KLASSCI_STUB_DEFAULT_PORT;
}

function klassci_stub_configured_latency_ms(): int
{
    $raw = getenv('KLASSCI_STUB_LATENCY_MS');
    if ($raw === false || trim($raw) === '') {
        return KLASSCI_STUB_DEFAULT_LATENCY_MS;
    }

    $latency = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

    return $latency !== false ? $latency : KLASSCI_STUB_DEFAULT_LATENCY_MS;
}

function klassci_stub_guard_loopback_or_die(): void
{
    $serverAddr = (string) ($_SERVER['SERVER_ADDR'] ?? '');
    $serverName = (string) ($_SERVER['SERVER_NAME'] ?? '');
    $remoteAddr = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    $serverIsLoopback = in_array($serverAddr, KLASSCI_STUB_LOOPBACK_HOSTS, true)
        || in_array($serverName, KLASSCI_STUB_LOOPBACK_HOSTS, true);
    $clientIsLoopback = in_array($remoteAddr, KLASSCI_STUB_LOOPBACK_HOSTS, true);

    if ($serverIsLoopback && $clientIsLoopback) {
        return;
    }

    klassci_stub_respond(403, [
        'success' => false,
        'message' => 'Stub KLASSCI accessible only from loopback.',
    ]);
    exit;
}

function klassci_stub_simulate_latency(): void
{
    usleep(klassci_stub_configured_latency_ms() * 1000);
}

function klassci_stub_dispatch(string $method, string $uri): void
{
    $path = rtrim((string) (parse_url($uri, PHP_URL_PATH) ?? '/'), '/') ?: '/';
    $routes = klassci_stub_routes();

    if (! array_key_exists($path, $routes)) {
        klassci_stub_respond(404, [
            'success' => false,
            'message' => "Endpoint stub KLASSCI introuvable: {$path}",
        ]);
        return;
    }

    if ($method !== 'GET') {
        klassci_stub_respond(405, [
            'success' => false,
            'message' => 'Le stub KLASSCI ne sert que des endpoints GET.',
        ]);
        return;
    }

    klassci_stub_respond(200, $routes[$path]());
}

/**
 * @return array<string, callable(): array<string, mixed>>
 */
function klassci_stub_routes(): array
{
    return [
        '/auth/me' => 'klassci_stub_payload_auth_me',
        '/structure' => 'klassci_stub_payload_structure',
        '/classes' => 'klassci_stub_payload_classes',
        '/filieres' => 'klassci_stub_payload_filieres',
        '/niveaux-etudes' => 'klassci_stub_payload_niveaux_etudes',
        '/matieres' => 'klassci_stub_payload_matieres',
        '/enseignants' => 'klassci_stub_payload_enseignants',
        '/emploi-temps' => 'klassci_stub_payload_emploi_temps',
        '/evaluations' => 'klassci_stub_payload_evaluations',
    ];
}

/**
 * @param array<string, mixed> $payload
 */
function klassci_stub_respond(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function klassci_stub_payload_auth_me(): array
{
    return [
        'success' => true,
        'data' => [
            'user' => [
                'id' => 9001,
                'nom' => 'Utilisateur Stub KLASSCI',
                'name' => 'Utilisateur Stub KLASSCI',
                'email' => 'stub.klassci@loadtest.local',
                'role' => 'etudiant',
            ],
        ],
    ];
}

function klassci_stub_payload_structure(): array
{
    return [
        'success' => true,
        'data' => [
            'filieres' => klassci_stub_canned_filieres(),
            'niveaux_etudes' => klassci_stub_canned_niveaux_etudes(),
        ],
    ];
}

function klassci_stub_payload_classes(): array
{
    $classes = klassci_stub_canned_classes();

    return [
        'success' => true,
        'data' => $classes,
        'meta' => ['total' => count($classes)],
    ];
}

function klassci_stub_payload_filieres(): array
{
    return ['success' => true, 'data' => klassci_stub_canned_filieres()];
}

function klassci_stub_payload_niveaux_etudes(): array
{
    return ['success' => true, 'data' => klassci_stub_canned_niveaux_etudes()];
}

function klassci_stub_payload_matieres(): array
{
    return [
        'success' => true,
        'data' => [
            ['id' => 1, 'nom' => 'Matiere Stub A', 'code' => 'MSA', 'filiere_id' => 1],
            ['id' => 2, 'nom' => 'Matiere Stub B', 'code' => 'MSB', 'filiere_id' => 2],
        ],
    ];
}

function klassci_stub_payload_enseignants(): array
{
    return [
        'success' => true,
        'data' => [
            ['id' => 101, 'nom' => 'Enseignant', 'prenom' => 'Stub Un', 'email' => 'enseignant1@loadtest.local'],
            ['id' => 102, 'nom' => 'Enseignant', 'prenom' => 'Stub Deux', 'email' => 'enseignant2@loadtest.local'],
        ],
    ];
}

function klassci_stub_payload_emploi_temps(): array
{
    return [
        'success' => true,
        'data' => [
            [
                'id' => 1,
                'classe_id' => 1,
                'matiere_id' => 1,
                'enseignant_id' => 101,
                'date' => '2026-07-06',
                'heure_debut' => '08:00',
                'heure_fin' => '10:00',
            ],
        ],
    ];
}

function klassci_stub_payload_evaluations(): array
{
    return [
        'success' => true,
        'data' => [
            ['id' => 1, 'matiere_id' => 1, 'classe_id' => 1, 'statut' => 'planifie', 'titre' => 'Evaluation Stub 1'],
        ],
    ];
}

function klassci_stub_canned_filieres(): array
{
    return [
        ['id' => 1, 'nom' => 'Filiere Stub A', 'code' => 'FSA'],
        ['id' => 2, 'nom' => 'Filiere Stub B', 'code' => 'FSB'],
    ];
}

function klassci_stub_canned_niveaux_etudes(): array
{
    return [
        ['id' => 1, 'nom' => 'Niveau Stub 1', 'ordre' => 1],
        ['id' => 2, 'nom' => 'Niveau Stub 2', 'ordre' => 2],
    ];
}

function klassci_stub_canned_classes(): array
{
    return [
        ['id' => 1, 'nom' => 'Classe Stub A', 'filiere_id' => 1, 'niveau_id' => 1, 'annee_id' => 2026],
        ['id' => 2, 'nom' => 'Classe Stub B', 'filiere_id' => 2, 'niveau_id' => 2, 'annee_id' => 2026],
    ];
}
