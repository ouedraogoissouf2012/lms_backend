<?php

declare(strict_types=1);

/**
 * Garde-fou taille de fichiers (issue #276 — applique PRODUCTION_STANDARDS.md §1.1 / §5).
 *
 * Échoue (exit 1) si un fichier source dépasse sa limite de lignes :
 *   - `app/Models/**` ............ 150 lignes (§5 : modèles = relations/casts/scopes)
 *   - `app/**` (autre) ........... 300 lignes (§1.1)
 *
 * Seuls les fichiers `app/**.php` sont contrôlés. La CI lui passe la liste des
 * fichiers MODIFIÉS par la PR (git diff base...HEAD), donc le legacy non touché
 * n'est jamais bloqué — seul le code qu'on ajoute/modifie doit respecter la règle.
 *
 * Usage : php scripts/check-file-sizes.php <fichier1> <fichier2> ...
 *
 * @see docs/MANIFESTE_REFACTORING.md
 */

const LIMIT_DEFAULT = 300;
const LIMIT_MODELS = 150;

/** @var array<int, string> $files */
$files = array_slice($argv, 1);

/** @var array<int, string> $violations */
$violations = [];

foreach ($files as $file) {
    $normalized = str_replace('\\', '/', $file);

    // Seul le code applicatif est contrôlé (migrations, config, specs exclus de fait).
    if (preg_match('#(^|/)app/.+\.php$#', $normalized) !== 1) {
        continue;
    }

    if (! is_file($file)) {
        continue; // fichier supprimé dans la PR : rien à contrôler
    }

    $contents = file($file);
    $lines = $contents === false ? 0 : count($contents);

    $isModel = preg_match('#(^|/)app/Models/#', $normalized) === 1;
    $limit = $isModel ? LIMIT_MODELS : LIMIT_DEFAULT;

    if ($lines > $limit) {
        $violations[] = sprintf('%s = %d lignes (max %d)', $normalized, $lines, $limit);
    }
}

if ($violations !== []) {
    fwrite(STDERR, "\n❌ Garde-fou taille (§1.1 ≤300 / §5 modèles ≤150) — fichiers en dépassement :\n");
    foreach ($violations as $violation) {
        fwrite(STDERR, "   - {$violation}\n");
    }
    fwrite(STDERR, "\n→ Découpe en collaborateurs DIP (services/presenters/concerns) plutôt que de grossir le fichier.\n");
    fwrite(STDERR, "  Voir docs/MANIFESTE_REFACTORING.md et PRODUCTION_STANDARDS.md §1.1 / §5.\n\n");
    exit(1);
}

echo "✓ Garde-fou taille : tous les fichiers contrôlés respectent la limite.\n";
exit(0);
