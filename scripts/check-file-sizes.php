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
 * ## Codes de sortie (#701)
 *
 *   0  conforme — le DÉNOMINATEUR est imprimé (« N fichiers inspectés »)
 *   1  au moins un dépassement
 *   2  la garde N'A PAS PU TRAVAILLER : aucun chemin de son périmètre ne lui a
 *      été fourni
 *
 * Le code 2 distingue « rien à redire » de « je n'ai rien regardé ». Sans lui,
 * ce script affichait « ✓ … respectent la limite » quand on l'appelait sans
 * argument — en n'ayant rien contrôlé. Convention reprise de
 * `scripts/check-phpstan-baseline.php`.
 *
 * Le cas légitime « aucun fichier app/ modifié » est traité par la CI, qui ne
 * lance pas le script dans ce cas : c'est à l'appelant de savoir si l'appel se
 * justifie, pas au script de deviner.
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

/** Chemins relevant du périmètre (app/**.php), qu'ils existent encore ou non. */
$inScope = 0;
/** Chemins réellement lus. Un fichier supprimé par la PR est dans le périmètre sans être lu. */
$inspected = 0;

foreach ($files as $file) {
    $normalized = str_replace('\\', '/', $file);

    // Seul le code applicatif est contrôlé (migrations, config, specs exclus de fait).
    if (preg_match('#(^|/)app/.+\.php$#', $normalized) !== 1) {
        continue;
    }

    $inScope++;

    if (! is_file($file)) {
        continue; // fichier supprimé dans la PR : dans le périmètre, mais rien à lire
    }

    $inspected++;
    $contents = file($file);
    $lines = $contents === false ? 0 : count($contents);

    $isModel = preg_match('#(^|/)app/Models/#', $normalized) === 1;
    $limit = $isModel ? LIMIT_MODELS : LIMIT_DEFAULT;

    if ($lines > $limit) {
        $violations[] = sprintf('%s = %d lignes (max %d)', $normalized, $lines, $limit);
    }
}

// Aucun chemin du périmètre : la garde n'a pas pu travailler. Ce n'est pas un
// succès. Distinguer ce cas d'un vrai « 0 violation » est tout l'objet de #701.
if ($inScope === 0) {
    fwrite(STDERR, "
❌ Garde-fou taille : aucun fichier app/**.php dans les arguments — rien n'a été contrôlé.
");
    fwrite(STDERR, "   Arguments reçus : " . count($files) . "
");
    fwrite(STDERR, "   Un vert ici ne prouverait rien. Fournir les fichiers à contrôler.

");
    exit(2);
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

printf(
    "✓ Garde-fou taille : %d fichier(s) inspecté(s) sur %d dans le périmètre, 0 violation.
",
    $inspected,
    $inScope
);
exit(0);
