<?php

declare(strict_types=1);

/**
 * tests/load/setup/purge-load-test-data.php — Purge du tenant de test isolé (#372).
 *
 * ## Pourquoi ce script (D1)
 *
 * Symétrique de `setup/prepare-load-test-data.php` : script PHP autonome
 * bootstrappant Laravel exactement comme le binaire `artisan`, sans jamais
 * créer/modifier de code sous `app/` (Requirement 12.3 — une Artisan Command
 * y serait forcément enregistrée).
 *
 * ## Ce que fait ce script (Requirement 6.5)
 *
 * 1. Sélectionne le(s) tenant(s) de test via le marqueur machine
 *    `settings.load_test = true` (D3, `Institution::where('settings->load_test', true)`
 *    — fonctionne nativement sans parsing manuel grâce au cast Eloquent
 *    `'settings' => 'array'`, `app/Models/Institution.php:31`). Ce marqueur ne
 *    peut par construction jamais correspondre à un tenant réel : c'est LA
 *    protection contre toute purge accidentelle de données de production,
 *    pas un garde-fou `APP_ENV` séparé (cohérent avec `PurgeAuditLogs`,
 *    `app/Console/Commands/PurgeAuditLogs.php`, qui n'en a pas non plus).
 * 2. Option `--institution-id=<id>` : restreint la sélection à CETTE
 *    institution précise — mais UNIQUEMENT si elle porte aussi le marqueur
 *    `settings.load_test = true`. Cette option est une sécurité
 *    SUPPLÉMENTAIRE (cibler un seul tenant de test parmi plusieurs), jamais
 *    un moyen de contourner le marqueur pour purger une institution
 *    arbitraire par id.
 * 3. Affiche un résumé (institutions/utilisateurs/tokens concernés) AVANT
 *    toute action, dans les deux modes.
 * 4. Mode `--dry-run` (comportement PAR DÉFAUT si ni `--dry-run` ni `--force`
 *    n'est passé, et si les deux sont passés ensemble — fail-safe) : affiche
 *    le résumé, ne supprime rien.
 * 5. Mode `--force` (et seul `--force`, sans `--dry-run`) : purge réelle en
 *    transaction DB, dans cet ordre imposé par les contraintes FK :
 *      a. révocation des tokens Sanctum (`personal_access_tokens`, relation
 *         polymorphique `tokenable_type`/`tokenable_id`) ;
 *      b. suppression des utilisateurs de test ;
 *      c. suppression de l'institution de test.
 *    Puis suppression de `setup/fixtures/load-test-users.json` (les tokens
 *    qu'il contient viennent d'être révoqués, il n'a plus de valeur et ne
 *    doit pas traîner sur disque — Requirement 12.1).
 *
 * ## Usage
 *
 *   php tests/load/setup/purge-load-test-data.php                        # dry-run (défaut)
 *   php tests/load/setup/purge-load-test-data.php --dry-run              # dry-run explicite
 *   php tests/load/setup/purge-load-test-data.php --force                # purge réelle, TOUS les tenants marqués load_test
 *   php tests/load/setup/purge-load-test-data.php --force --institution-id=42  # purge réelle, un seul tenant
 *
 * Options CLI :
 *   --dry-run              Force le mode dry-run même si --force est aussi passé (fail-safe).
 *   --force                Purge réellement les données (aucun effet sans ce flag).
 *   --institution-id=<id>  Restreint la purge à cette institution (doit être marquée load_test).
 *
 * Préparation : voir `tests/load/setup/prepare-load-test-data.php` (Requirement 6).
 *
 * @see .claude/specs/372-k6-load-testing/design.md §"Composants et Interfaces"/4.5
 * @see .claude/specs/372-k6-load-testing/requirements.md Requirement 6.5, 12.1, 12.3
 */

// `use` déclaré AVANT le bootstrap : PHP résout les alias `use` de façon
// LEXICALE (dans l'ordre du fichier), jamais par ordre d'exécution runtime —
// même piège déjà rencontré et corrigé dans prepare-load-test-data.php.
use App\Models\Institution;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

// -----------------------------------------------------------------------------------
// Bootstrap Laravel — identique au bootstrap du binaire `artisan` (`artisan:10-14`)
// et à `prepare-load-test-data.php`. Ce fichier LIT `bootstrap/app.php` (et
// l'autoloader Composer), il ne les modifie jamais (Requirement 12.3).
// -----------------------------------------------------------------------------------
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
/** @var Kernel $kernel */
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

// -----------------------------------------------------------------------------------
// Constantes de configuration
// -----------------------------------------------------------------------------------

/** Chemin de la fixture générée par `prepare-load-test-data.php`, relatif à ce fichier. */
const FIXTURE_RELATIVE_PATH = '/fixtures/load-test-users.json';

// -----------------------------------------------------------------------------------
// Parsing CLI minimal (`--key=value` / `--flag`) — dupliqué depuis
// prepare-load-test-data.php : `setup/` ne contient que ces deux scripts
// autonomes (D1), aucun répertoire `lib/` PHP partagé n'existe dans le design
// pour ce couple ; dupliquer ~20 lignes de parsing reste plus simple et plus
// sûr que d'introduire un nouveau fichier partagé hors du périmètre approuvé.
// -----------------------------------------------------------------------------------

/**
 * Parse les arguments `--key=value` (ou `--flag` sans valeur) de `$argv`.
 *
 * @param  list<string>  $argv
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
 * Résout `--institution-id` : absent par défaut (`null`, purge TOUS les
 * tenants marqués `load_test`). Refuse dur (stderr + exit 1) si une valeur
 * est fournie mais n'est pas un entier strictement positif — jamais de
 * fallback silencieux vers "toutes les institutions" en cas de faute de
 * frappe (ex. `--institution-id=abc`), ce qui purgerait bien plus que prévu.
 *
 * @param  array<string, string|true>  $options
 */
function resolveInstitutionId(array $options): ?int
{
    if (! array_key_exists('institution-id', $options)) {
        return null;
    }

    $raw = $options['institution-id'];
    $id = $raw === true ? false : filter_var($raw, FILTER_VALIDATE_INT);

    if ($id === false || $id < 1) {
        $displayed = $raw === true ? '(sans valeur)' : (string) $raw;
        fwrite(STDERR, "[purge-load-test-data] --institution-id doit être un entier strictement positif (reçu : \"{$displayed}\").\n");
        exit(1);
    }

    return $id;
}

/**
 * Détermine le mode d'exécution. Dry-run est le défaut ET le mode
 * fail-safe : si `--dry-run` ET `--force` sont passés ensemble, dry-run
 * gagne (ambiguïté résolue du côté le moins destructeur), jamais l'inverse.
 *
 * @param  array<string, string|true>  $options
 */
function isDryRun(array $options): bool
{
    $forceRequested = array_key_exists('force', $options);
    $dryRunRequested = array_key_exists('dry-run', $options);

    return ! $forceRequested || $dryRunRequested;
}

// -----------------------------------------------------------------------------------
// Sélection du/des tenant(s) de test (Requirement 6.5, D3)
// -----------------------------------------------------------------------------------

/**
 * Sélectionne les institutions de test à purger : marquées `settings.load_test
 * = true` (D3 — seule source de vérité machine, jamais un pattern de nommage
 * fragile), optionnellement restreintes à un id précis via
 * `--institution-id`. Le marqueur reste TOUJOURS appliqué même avec
 * `--institution-id` : cette option est une sécurité supplémentaire
 * (cibler un tenant parmi plusieurs), jamais un moyen de purger une
 * institution arbitraire qui ne serait pas un tenant de test.
 *
 * @return Collection<int, Institution>
 */
function selectTestInstitutions(?int $institutionId): Collection
{
    $query = Institution::where('settings->load_test', true);

    if ($institutionId !== null) {
        $query->where('id', $institutionId);
    }

    return $query->get();
}

/**
 * @return array{institution: Institution, user_ids: list<int>, token_count: int}
 */
function snapshotInstitution(Institution $institution): array
{
    /** @var list<int> $userIds */
    $userIds = $institution->users()->pluck('id')->all();

    // Révocation des tokens Sanctum AVANT suppression des utilisateurs
    // (contrainte FK) : la relation polymorphique standard Sanctum
    // (`tokenable_type`/`tokenable_id`) cible `App\Models\User` en clair —
    // `User` n'est pas présent dans le morph map applicatif
    // (`config/fileables.php`, dédié exclusivement au modèle `File`), donc
    // `tokenable_type` est stocké comme FQCN, pas comme alias court.
    $tokenCount = PersonalAccessToken::query()
        ->where('tokenable_type', User::class)
        ->whereIn('tokenable_id', $userIds)
        ->count();

    return [
        'institution' => $institution,
        'user_ids' => $userIds,
        'token_count' => $tokenCount,
    ];
}

// -----------------------------------------------------------------------------------
// Affichage du résumé (avant toute action, dans les deux modes)
// -----------------------------------------------------------------------------------

/**
 * @param  list<array{institution: Institution, user_ids: list<int>, token_count: int}>  $snapshots
 * @return array{institutions: int, users: int, tokens: int}
 */
function printSummary(array $snapshots, string $verbPrefix): array
{
    $totals = ['institutions' => 0, 'users' => 0, 'tokens' => 0];

    foreach ($snapshots as $snapshot) {
        $institution = $snapshot['institution'];
        $userCount = count($snapshot['user_ids']);
        $tokenCount = $snapshot['token_count'];

        echo "[purge-load-test-data] {$verbPrefix} institution id={$institution->id} slug={$institution->slug} : ".
            "{$userCount} utilisateur(s), {$tokenCount} token(s) Sanctum.\n";

        $totals['institutions']++;
        $totals['users'] += $userCount;
        $totals['tokens'] += $tokenCount;
    }

    echo "[purge-load-test-data] Total : {$totals['institutions']} institution(s), ".
        "{$totals['users']} utilisateur(s), {$totals['tokens']} token(s) Sanctum.\n";

    return $totals;
}

// -----------------------------------------------------------------------------------
// Point d'entrée
// -----------------------------------------------------------------------------------

$options = parseCliOptions($argv);
$institutionId = resolveInstitutionId($options);
$dryRun = isDryRun($options);

$institutions = selectTestInstitutions($institutionId);

if ($institutions->isEmpty()) {
    if ($institutionId !== null) {
        // Une institution-id explicite qui ne matche rien est très probablement
        // une erreur de l'appelant (id erroné, ou id d'une institution RÉELLE
        // sans marqueur load_test) — signal fort plutôt qu'un no-op silencieux
        // qui pourrait laisser croire à tort qu'une purge a eu lieu.
        fwrite(
            STDERR,
            "[purge-load-test-data] REFUS : aucune institution de test (settings.load_test=true) ".
            "trouvée avec id={$institutionId}. Soit cet id n'existe pas, soit il désigne une institution ".
            "qui n'est PAS un tenant de test — ce script ne purge jamais une institution sans ce marqueur. ".
            "Aucune action effectuée.\n"
        );
        exit(1);
    }

    echo "[purge-load-test-data] Aucune institution de test (settings.load_test=true) trouvée. Rien à purger.\n";
    exit(0);
}

$snapshots = $institutions->map(static fn (Institution $institution): array => snapshotInstitution($institution))->all();

if ($dryRun) {
    echo "[purge-load-test-data] Mode DRY-RUN (défaut — relancez avec --force pour purger réellement) :\n";
    printSummary($snapshots, 'Serait purgée :');
    echo "[purge-load-test-data] Aucune suppression effectuée.\n";
    exit(0);
}

echo "[purge-load-test-data] Mode FORCE — purge réelle :\n";
$totals = printSummary($snapshots, 'Purge de');

DB::transaction(function () use ($snapshots): void {
    foreach ($snapshots as $snapshot) {
        $userIds = $snapshot['user_ids'];

        // Ordre imposé par les contraintes FK : tokens → utilisateurs → institution.
        PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $userIds)
            ->delete();

        User::whereIn('id', $userIds)->delete();

        $snapshot['institution']->delete();
    }
});

$fixturePath = __DIR__.FIXTURE_RELATIVE_PATH;

if (is_file($fixturePath)) {
    // Les tokens qu'elle contenait viennent d'être révoqués ci-dessus : cette
    // fixture n'a plus de valeur et ne doit pas traîner sur disque (Requirement 12.1).
    unlink($fixturePath);
    echo "[purge-load-test-data] Fixture supprimée : {$fixturePath}\n";
}

echo "[purge-load-test-data] Purge terminée : {$totals['institutions']} institution(s), ".
    "{$totals['users']} utilisateur(s) et {$totals['tokens']} token(s) Sanctum supprimés.\n";
