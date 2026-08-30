<?php

declare(strict_types=1);

/**
 * Garde-fou longueur de méthodes — applique PRODUCTION_STANDARDS.md §5 (« Méthodes ≤ 40 lignes »).
 *
 * Complète `check-file-sizes.php` : un fichier peut respecter les 300 lignes tout en
 * concentrant 100 lignes dans une seule méthode. C'est cette forme-là qui fragilise le
 * plus — méthode intestable unitairement, où chaque ajout se greffe au lieu de créer
 * une abstraction.
 *
 * Ce que compte le script : les lignes de CODE du corps de la méthode, hors lignes
 * vides et hors commentaires. Documenter une méthode ne doit jamais la pénaliser.
 *
 * Analyse par `token_get_all()`, pas par expression régulière : les accolades dans
 * les chaînes, les heredocs et les closures imbriquées sont correctement ignorés.
 *
 * La CI passe la liste des fichiers MODIFIÉS par la PR — le legacy non touché n'est
 * jamais bloqué. Les méthodes déjà en dépassement sont listées dans
 * `scripts/method-length-baseline.php` : dette tracée, tolérée, mais qui ne peut plus
 * grossir ni se multiplier.
 *
 * Usage : php scripts/check-method-sizes.php <fichier1> <fichier2> ...
 *
 * @see docs/MANIFESTE_REFACTORING.md
 */

const MAX_METHOD_LINES = 40;

$baselineFile = __DIR__ . '/method-length-baseline.php';
/** @var array<string, int> $baseline  clé « chemin::méthode » => longueur tolérée */
$baseline = is_file($baselineFile) ? (require $baselineFile) : [];

/** @var array<int, string> $files */
$files = array_slice($argv, 1);

/** @var array<int, string> $violations */
$violations = [];
/** @var array<int, string> $shrunk */
$shrunk = [];

foreach ($files as $file) {
    $normalized = str_replace('\\', '/', $file);

    // Seul le code applicatif est contrôlé (migrations, config, tests exclus de fait).
    if (preg_match('#(^|/)app/.+\.php$#', $normalized) !== 1) {
        continue;
    }

    if (! is_file($file)) {
        continue; // fichier supprimé dans la PR
    }

    $source = file_get_contents($file);
    if ($source === false) {
        continue;
    }

    foreach (methodLengths($source) as $method => $length) {
        $key = $normalized . '::' . $method;
        $tolerated = $baseline[$key] ?? null;

        if ($tolerated !== null) {
            // Méthode connue : elle a le droit d'exister, pas de grossir.
            if ($length > $tolerated) {
                $violations[] = sprintf(
                    '%s = %d lignes (dette tracée à %d — elle ne doit pas grossir)',
                    $key,
                    $length,
                    $tolerated
                );
            } elseif ($length < $tolerated) {
                $shrunk[] = sprintf('%s : %d → %d lignes', $key, $tolerated, $length);
            }

            continue;
        }

        if ($length > MAX_METHOD_LINES) {
            $violations[] = sprintf('%s = %d lignes (max %d)', $key, $length, MAX_METHOD_LINES);
        }
    }
}

if ($shrunk !== []) {
    fwrite(STDOUT, "\n✅ Méthodes réduites — pense à mettre à jour la baseline :\n");
    foreach ($shrunk as $item) {
        fwrite(STDOUT, "   - {$item}\n");
    }
}

if ($violations !== []) {
    fwrite(STDERR, "\n❌ Garde-fou longueur de méthodes (§5 : ≤ " . MAX_METHOD_LINES . " lignes) :\n");
    foreach ($violations as $violation) {
        fwrite(STDERR, "   - {$violation}\n");
    }
    fwrite(STDERR, "\n→ Extrais des méthodes privées nommées, ou un collaborateur dédié.\n");
    fwrite(STDERR, "  Une méthode longue n'est pas testable unitairement : chaque ajout s'y greffe\n");
    fwrite(STDERR, "  au lieu de créer l'abstraction qui manque.\n");
    fwrite(STDERR, "  Voir PRODUCTION_STANDARDS.md §5 et docs/MANIFESTE_REFACTORING.md\n\n");
    exit(1);
}

fwrite(STDOUT, "✅ Longueur des méthodes conforme (≤ " . MAX_METHOD_LINES . " lignes).\n");
exit(0);

/**
 * Longueur de chaque méthode d'un fichier, en lignes de code effectives.
 *
 * @return array<string, int>  nom de méthode => nombre de lignes de code
 */
function methodLengths(string $source): array
{
    $tokens = token_get_all($source);
    $lines = explode("\n", $source);
    $result = [];

    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (! is_array($token) || $token[0] !== T_FUNCTION) {
            continue;
        }

        // Nom de la méthode : premier T_STRING après `function`.
        // Une closure n'en a pas — on la rattache implicitement à sa méthode porteuse.
        $name = null;
        for ($j = $i + 1; $j < $count; $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                $name = $tokens[$j][1];
                break;
            }
            if ($tokens[$j] === '(') {
                break; // closure anonyme
            }
        }

        if ($name === null) {
            continue;
        }

        // Accolade ouvrante du corps, en sautant la signature (types, valeurs par défaut).
        $depth = 0;
        $bodyStart = null;
        $bodyEnd = null;

        for ($j = $i; $j < $count; $j++) {
            $t = $tokens[$j];

            if ($t === ';' && $bodyStart === null) {
                break; // méthode abstraite ou d'interface : pas de corps
            }

            if ($t === '{') {
                if ($bodyStart === null) {
                    $bodyStart = tokenLine($tokens, $j);
                }
                $depth++;
            } elseif ($t === '}') {
                $depth--;
                if ($depth === 0 && $bodyStart !== null) {
                    $bodyEnd = tokenLine($tokens, $j);
                    break;
                }
            }
        }

        if ($bodyStart === null || $bodyEnd === null) {
            continue;
        }

        $result[$name] = countCodeLines($lines, $bodyStart, $bodyEnd);
    }

    return $result;
}

/**
 * Numéro de ligne d'un token, y compris pour les tokens simples (caractères).
 *
 * @param array<int, array{0:int,1:string,2:int}|string> $tokens
 */
function tokenLine(array $tokens, int $index): int
{
    for ($i = $index; $i >= 0; $i--) {
        if (is_array($tokens[$i])) {
            $line = $tokens[$i][2];
            // Un token multi-lignes (heredoc, commentaire) décale le compteur.
            return $line + substr_count($tokens[$i][1], "\n");
        }
    }

    return 1;
}

/**
 * Lignes de code entre deux bornes, hors lignes vides et commentaires.
 *
 * @param array<int, string> $lines
 */
function countCodeLines(array $lines, int $from, int $to): int
{
    $count = 0;

    for ($n = $from; $n <= $to; $n++) {
        $line = trim($lines[$n - 1] ?? '');

        if ($line === '' || $line === '{' || $line === '}') {
            continue;
        }

        if (str_starts_with($line, '//') || str_starts_with($line, '*') || str_starts_with($line, '/*')) {
            continue;
        }

        $count++;
    }

    return $count;
}
