<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * Garde-fou : aucun secret réel ne doit être versionné.
 *
 * ## L'incident qui motive ce test
 *
 * Le 2026-08-31, la clé `JWT_APP_SECRET` du serveur Jitsi de PRODUCTION a été
 * trouvée en clair dans `tests/Unit/Services/Visio/VisioAccessTokenIssuerTest.php`,
 * commitée depuis #668. Cette clé signe les jetons d'accès aux salles : quiconque
 * la détenait pouvait fabriquer un droit d'entrée **modérateur sur n'importe
 * quelle salle, de n'importe quel établissement**.
 *
 * Un garde-fou existait déjà ({@see CiAppKeyNotCommittedTest}) mais cherchait
 * **une chaîne précise dans un fichier précis**. Il ne pouvait pas voir celle-ci.
 *
 * ## Ce que ce test cherche, et pourquoi ainsi
 *
 * Pas « toute chaîne hexadécimale longue » : les empreintes, sommes de contrôle
 * et identifiants de commit sont légitimes et abondants, et un test qui crie au
 * loup finit désactivé — c'est la même faute qu'un contrôle de santé toujours
 * rouge.
 *
 * Le motif retenu est plus étroit et colle à la faute réelle : **un identifiant
 * dont le NOM annonce un secret, affecté à une valeur à forte entropie**. C'est
 * exactement la forme de l'incident, et elle ne décrit rien d'anodin.
 *
 * ## Comment satisfaire ce test
 *
 * Un test de signature n'a jamais besoin d'une vraie clé : n'importe quelle
 * valeur stable convient, à condition que son intitulé dise qu'elle est fictive
 * (`secret-de-test`, `fake-…`, `dummy-…`). Le code applicatif, lui, lit ses
 * secrets par `env()` / `config()` — jamais en dur.
 *
 * @see docs/ENV_VARIABLES.md
 */
final class NoHardcodedSecretsTest extends TestCase
{
    /**
     * Répertoires inspectés. `vendor/`, `storage/` et `node_modules/` sont hors
     * périmètre : ils ne sont pas notre code.
     *
     * @var list<string>
     */
    private const SCANNED = ['app', 'config', 'database', 'routes', 'tests', 'scripts', 'docs', '.github'];

    /**
     * Identifiants dont le nom annonce un secret.
     *
     * `_ID` et `_URL` sont volontairement absents : `JITSI_APP_ID` ou
     * `KLASSCI_API_URL` ne sont pas des secrets.
     */
    private const SECRET_NAME = 'secret|password|passwd|api_?key|app_?key|private_?key|auth_?token|access_?token|bearer';

    /**
     * Valeur à forte entropie : au moins 32 caractères hexadécimaux, ou une
     * base64 d'au moins 40 caractères.
     *
     * 32 hexadécimaux = 16 octets. En dessous, le risque de faux positif
     * (identifiants courts, couleurs, versions) dépasse le gain.
     */
    private const HIGH_ENTROPY = '[A-Za-z0-9+\/=_-]{32,}';

    /**
     * Marqueurs qui déclarent une valeur fictive. Leur présence DANS la valeur
     * suffit à l'exonérer : c'est le contrat proposé aux auteurs de tests.
     *
     * @var list<string>
     */
    private const FAKE_MARKERS = [
        'test', 'fake', 'dummy', 'example', 'exemple', 'sample', 'placeholder',
        'jamais-utilise', 'not-a-real', 'xxxxx', 'change-me', 'changeme',
    ];

    /**
     * Le secret réellement divulgué le 2026-08-31. Renouvelé depuis, donc sans
     * danger ici — mais sa présence dans ce test est ce qui prouve que le
     * détecteur fonctionne, et sa réapparition ailleurs doit rester impossible.
     */
    private const LEAKED_2026_08_31 = '7c6f975c477d82082dc00418276bc8b2e53db075c49eac1c98b3f06b1f2961ab';

    // ------------------------------------------------------------------ le garde

    public function test_no_secret_named_identifier_holds_a_high_entropy_literal(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $file) {
            foreach ($this->suspiciousLines($file) as $line => $excerpt) {
                $offenders[] = sprintf('%s:%d  %s', $this->relative($file), $line, $excerpt);
            }
        }

        self::assertSame([], $offenders, sprintf(
            "Secret potentiellement versionné (%d). Un test de signature n'a jamais besoin d'une vraie clé :\n".
            "utiliser une valeur fictive dont l'intitulé le dit (« secret-de-test… »).\n".
            "Le code applicatif lit ses secrets par env()/config().\n\n%s",
            count($offenders),
            implode("\n", $offenders),
        ));
    }

    /**
     * Le secret divulgué le 2026-08-31 ne doit jamais réapparaître, sous aucune
     * forme — y compris hors d'une affectation nommée.
     */
    public function test_the_2026_08_31_leaked_secret_never_reappears(): void
    {
        $found = [];

        foreach ($this->sourceFiles() as $file) {
            $contents = (string) file_get_contents($file);

            // Ce fichier le porte délibérément, comme échantillon de détection.
            if (str_ends_with(str_replace('\\', '/', $file), 'NoHardcodedSecretsTest.php')) {
                continue;
            }

            if (str_contains($contents, self::LEAKED_2026_08_31)) {
                $found[] = $this->relative($file);
            }
        }

        self::assertSame([], $found, 'Le secret Jitsi divulgué le 2026-08-31 est réapparu : '.implode(', ', $found));
    }

    // ------------------------------------------------- le detecteur se prouve

    /**
     * Un garde-fou qui ne peut pas échouer ne surveille rien. Ce test rejoue la
     * faute réelle sur un échantillon en mémoire et vérifie qu'elle serait
     * attrapée — sans quoi le test ci-dessus pourrait être vert par accident.
     */
    public function test_the_detector_catches_the_real_incident(): void
    {
        $incident = "    private const SECRET = '".self::LEAKED_2026_08_31."';";

        self::assertTrue(
            $this->isSuspicious($incident),
            'Le détecteur ne reconnaît pas la faute qui a motivé son écriture.',
        );
    }

    /**
     * @return list<array{string}>
     */
    public static function harmlessLines(): array
    {
        return [
            'valeur fictive annoncée' => ["const SECRET = 'secret-de-test-jamais-utilise-en-production';"],
            'lecture par env()' => ["'webhook_secret' => env('VISIO_RECORDING_WEBHOOK_SECRET'),"],
            'lecture par config()' => ["\$secret = config('services.visio.webhook_secret');"],
            'empreinte en commentaire' => ['// empreinte : 4c75fda580a74c53e1759a6188000ac3d5b02380ef6f4a1e'],
            'identifiant non secret' => ["'app_id' => env('JITSI_APP_ID', 'lms-klassci'),"],
            'valeur courte' => ["const SECRET = 'abc123';"],
            'nom sans rapport' => ["const ROOM = 'lms_7c6f975c477d82082dc00418276bc8b2e53db075c';"],
        ];
    }

    /**
     * Le détecteur ne doit pas crier au loup : un garde-fou bruyant finit
     * désactivé, et ne protège alors plus rien.
     *
     * @dataProvider harmlessLines
     */
    public function test_the_detector_stays_quiet_on_harmless_lines(string $line): void
    {
        self::assertFalse($this->isSuspicious($line), "Faux positif sur : {$line}");
    }

    // --------------------------------------------------------------- mécanique

    private function isSuspicious(string $line): bool
    {
        $pattern = '/(?i)(?<name>'.self::SECRET_NAME.')[^\n]{0,40}?[=>:]\s*[\'"](?<value>'.self::HIGH_ENTROPY.')[\'"]/';

        if (preg_match($pattern, $line, $m) !== 1) {
            return false;
        }

        $value = strtolower($m['value']);

        foreach (self::FAKE_MARKERS as $marker) {
            if (str_contains($value, $marker)) {
                return false;
            }
        }

        // Un appel env()/config() n'est pas un littéral, même sur la même ligne.
        return preg_match('/(?i)(env|config)\s*\(/', $line) !== 1;
    }

    /**
     * @return array<int, string> ligne => extrait tronqué
     */
    private function suspiciousLines(string $file): array
    {
        $found = [];
        $lines = file($file, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return [];
        }

        foreach ($lines as $index => $line) {
            if ($this->isSuspicious($line)) {
                $found[$index + 1] = mb_substr(trim($line), 0, 90);
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $files = [];

        foreach (self::SCANNED as $directory) {
            $root = base_path($directory);

            if (! is_dir($root)) {
                continue;
            }

            /** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $iterator */
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $entry) {
                if (! $entry instanceof \SplFileInfo || ! $entry->isFile()) {
                    continue;
                }

                if (in_array($entry->getExtension(), ['php', 'yml', 'yaml', 'md', 'sh', 'neon'], true)) {
                    $files[] = $entry->getPathname();
                }
            }
        }

        return $files;
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $path));
    }
}
