<?php

declare(strict_types=1);

/**
 * Garde Open/Closed du chantier « mode autonome » (V2). Contrat : issue #697.
 *
 * ## Ce qu'elle interdit
 *
 * Résoudre le mode d'établissement — ou lire l'URL/le jeton KLASSCI — ailleurs
 * qu'au point de liaison déclaré. La règle vient de `PRODUCTION_STANDARDS.md`
 * §1.6 (« Règle O — Open/Closed ») et sa raison d'être est le « zéro régression » :
 * chaque `if ($institution->mode === …)` inséré dans une classe existante modifie
 * un chemin que les écoles KLASSCI exécutent aujourd'hui en production.
 *
 * ## Pourquoi un cliquet, et pas un refus sec
 *
 * Une garde qui rougit au premier run est désactivée dans la semaine. Le dépôt a
 * déjà tranché ce point deux fois — `scripts/check-method-sizes.php` et
 * `FRONT/scripts/check-file-size.mjs` gèlent la dette existante et n'autorisent
 * que sa décroissance. On reprend ce patron.
 *
 * ## Codes de sortie — convention de `scripts/check-phpstan-baseline.php:16-24`
 *
 *   0  conforme — le dénominateur est imprimé
 *   1  violation neuve, ou dette aggravée
 *   2  la garde N'A PAS PU TRAVAILLER (liste absente/illisible, rien à inspecter)
 *
 * `exit(2)` distingue « rien à redire » de « je n'ai rien regardé ». C'est la
 * leçon de `scripts/check-file-sizes.php`, qui affiche « ✓ » sans argument en
 * n'ayant rien contrôlé — et de son jumeau `check-method-sizes.php`.
 *
 * Usage :
 *   php scripts/check-ocp.php [racine]            contrôle
 *   php scripts/check-ocp.php [racine] --update   met le cliquet à jour
 */

require_once __DIR__ . '/lib/ocp-ratchet.php';

const OCP_ALLOWLIST = '.ocp-allowlist.json';
const OCP_SCAN_DIR = 'app';

$racine = rtrim($argv[1] ?? getcwd(), '/\\');
$majSouhaitee = in_array('--update', array_slice($argv, 1), true);

/* ------------------------------------------------------------ liste d'exemption */

$cheminListe = $racine . DIRECTORY_SEPARATOR . OCP_ALLOWLIST;

if (! is_file($cheminListe)) {
    fwrite(STDERR, "OCP: liste d'exemption introuvable : {$cheminListe}\n");
    fwrite(STDERR, "La garde ne peut pas travailler sans elle.\n");
    exit(2);
}

$liste = json_decode((string) file_get_contents($cheminListe), true);

if (! is_array($liste) || ! isset($liste['entries']) || ! is_array($liste['entries'])) {
    fwrite(STDERR, 'OCP: ' . OCP_ALLOWLIST . " illisible ou sans clé « entries ».\n");
    exit(2);
}

$classe = ocpClasseEntrees(
    $liste['entries'],
    static fn (string $adr): bool => is_file($racine . DIRECTORY_SEPARATOR . $adr)
);

if ($classe['erreurs'] !== []) {
    fwrite(STDERR, "OCP: liste d'exemption invalide — la garde refuse de travailler.\n\n");

    foreach ($classe['erreurs'] as $m) {
        fwrite(STDERR, "  · {$m}\n");
    }

    fwrite(STDERR, "\nArticle 7 : une dérogation exige TROIS demandes explicites et distinctes,\n");
    fwrite(STDERR, "un ADR daté et un accord nommé. Sans quoi : chercher une solution conforme.\n");
    exit(2);
}

/* ------------------------------------------------------------------- collecte */

$base = $racine . DIRECTORY_SEPARATOR . OCP_SCAN_DIR;

if (! is_dir($base)) {
    fwrite(STDERR, "OCP: répertoire à inspecter introuvable : {$base}\n");
    exit(2);
}

$fichiers = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
);

foreach ($it as $f) {
    if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
        $fichiers[] = $f->getPathname();
    }
}

sort($fichiers);

if ($fichiers === []) {
    fwrite(STDERR, "OCP: aucun fichier PHP sous {$base} — la garde n'a rien pu inspecter.\n");
    exit(2);
}

/* -------------------------------------------------------------------- analyse */

$inspectes = 0;
$exemptes = 0;
$trouves = [];
$detail = [];

foreach ($fichiers as $chemin) {
    $relatif = str_replace('\\', '/', substr($chemin, strlen($racine) + 1));

    if (isset($classe['liaison'][$relatif])) {
        $exemptes++;

        continue;
    }

    $source = @file_get_contents($chemin);

    if ($source === false) {
        fwrite(STDERR, "OCP: fichier illisible : {$relatif}\n");
        exit(2);
    }

    $inspectes++;

    foreach (ocpViolations($source) as $v) {
        $trouves[$relatif] = ($trouves[$relatif] ?? 0) + 1;
        $detail[] = ['fichier' => $relatif] + $v;
    }
}

/* --------------------------------------------------------- mise à jour cliquet */

if ($majSouhaitee) {
    $conserves = array_values(array_filter(
        $liste['entries'],
        static fn ($e) => is_array($e) && ($e['type'] ?? null) !== 'dette'
    ));

    foreach ($trouves as $chemin => $n) {
        $conserves[] = [
            'path' => $chemin,
            'type' => 'dette',
            'reason' => 'dette gelée par --update — à documenter avant revue',
            'count' => $n,
        ];
    }

    $liste['entries'] = $conserves;
    file_put_contents(
        $cheminListe,
        json_encode($liste, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
    );

    echo 'OCP: cliquet mis à jour — ' . count($trouves) . " fichier(s) en dette.\n";
    exit(0);
}

/* -------------------------------------------------------------------- verdict */

$verdict = ocpVerdict($trouves, $classe['dettes']);

if ($verdict['neuves'] !== [] || $verdict['aggravees'] !== []) {
    fwrite(STDERR, "OCP: le mode ne se résout qu'au point de liaison.\n\n");

    foreach ($detail as $v) {
        if (isset($verdict['neuves'][$v['fichier']]) || isset($verdict['aggravees'][$v['fichier']])) {
            fwrite(STDERR, "  {$v['fichier']}:{$v['ligne']}  [{$v['motif']}]\n");
            fwrite(STDERR, "      {$v['extrait']}\n");
        }
    }

    if ($verdict['aggravees'] !== []) {
        fwrite(STDERR, "\nDette aggravée (le cliquet ne remonte jamais) :\n");

        foreach ($verdict['aggravees'] as $c => $delta) {
            fwrite(STDERR, "  · {$c} : {$delta}\n");
        }
    }

    fwrite(STDERR, "\n{$inspectes} fichiers inspectés, {$exemptes} au point de liaison, "
        . count($classe['dettes']) . " en dette.\n\n");
    fwrite(STDERR, "Article 1 : un nouveau comportement s'ajoute en implémentant une interface\n");
    fwrite(STDERR, "nommée par la préoccupation. Patrons déjà présents dans le dépôt :\n");
    fwrite(STDERR, "  · KlassciTargetResolver (1 verbe) + KlassciConfigResolver — bind(), AppServiceProvider.php:57\n");
    fwrite(STDERR, "  · HasKlassciEndpointShortcuts — 4 méthodes abstract, contrat de source déjà écrit\n");
    fwrite(STDERR, "  · DuplicateSurvivorPolicy → RankedSurvivorPolicy → filles\n");
    exit(1);
}

echo "OCP: {$inspectes} fichiers inspectés, {$exemptes} au point de liaison, "
    . count($classe['dettes']) . " en dette, 0 violation neuve.\n";

if ($verdict['reduites'] !== []) {
    echo "\nDette réduite — abaisser « count » (php scripts/check-ocp.php . --update) :\n";

    foreach ($verdict['reduites'] as $c => $delta) {
        echo "  · {$c} : {$delta}\n";
    }
}

exit(0);
