<?php

declare(strict_types=1);

/**
 * Amorçage de la suite : une base de test PAR CHECKOUT, et jamais corrompue en
 * silence (#692).
 *
 * ## Le défaut
 *
 * `phpunit.xml` pointait un fichier UNIQUE, `database/database.testing.sqlite`.
 * Or le travail se fait en plusieurs fenêtres et worktrees en parallèle : deux
 * exécutions concurrentes écrivaient le même fichier SQLite. C'est le scénario
 * classique de corruption, et il s'est produit au moins deux fois — une
 * sauvegarde `…corrupt-backup-20260712-restore` traînait déjà dans `database/`.
 *
 * ## Ce que ça coûtait
 *
 * Une exécution a rendu **94 erreurs** réparties sur des tests totalement
 * indépendants (`PdfConverterTest`, `StorageHttpGuardTest`,
 * `UpdateChapterRequestTest`…) :
 *
 *     SQLSTATE[HY000]: General error: 11 database disk image is malformed
 *
 * Le message ne désigne pas la cause. Devant 94 erreurs sur des fichiers sans
 * lien, la première hypothèse naturelle est « j'ai cassé quelque chose » — et on
 * part chercher une régression qui n'existe pas.
 *
 * ## Les deux mesures
 *
 * 1. **Un fichier par checkout.** Le nom dérive du chemin racine : deux
 *    worktrees n'écrivent plus jamais le même fichier. Le cas concurrent
 *    disparaît par construction, il n'est pas seulement rendu moins probable.
 *
 * 2. **Un contrôle d'intégrité AVANT la suite.** Si le fichier est malformé, on
 *    le met de côté et on repart d'un fichier neuf, avec un message explicite.
 *    `RefreshDatabase` remigre. Mieux vaut une ligne qui nomme la cause que
 *    quatre-vingt-quatorze qui la masquent.
 *
 * ## Ce qui n'est jamais touché
 *
 * `database/database.sqlite` — la base de DÉVELOPPEMENT. Ce fichier n'est ni lu
 * ni écrit ici, et la base de test vit désormais dans un sous-répertoire dédié,
 * ce qui rend une confusion de chemin impossible.
 */
require __DIR__.'/../vendor/autoload.php';

(static function (): void {
    $racine = dirname(__DIR__);

    // L'empreinte du chemin racine : stable pour un checkout donné, différente
    // pour chaque worktree. Douze caractères suffisent — on distingue des
    // répertoires sur une machine, on ne résiste pas à un adversaire.
    $empreinte = substr(sha1($racine), 0, 12);
    $repertoire = $racine.'/database/testing';
    $chemin = $repertoire.'/'.$empreinte.'.sqlite';

    if (! is_dir($repertoire)) {
        mkdir($repertoire, 0o775, true);
    }

    if (is_file($chemin) && ! estIntegre($chemin)) {
        $ecarte = $chemin.'.corrompue-'.date('Ymd-His');
        rename($chemin, $ecarte);

        fwrite(STDERR, PHP_EOL
            .'  ⚠ Base de test corrompue — elle a été écartée, pas réparée.'.PHP_EOL
            .'    écartée : '.basename($ecarte).PHP_EOL
            .'    Une base neuve est créée ; RefreshDatabase va remigrer.'.PHP_EOL
            .'    Cause habituelle : deux exécutions concurrentes sur le même fichier (#692).'.PHP_EOL
            .PHP_EOL);
    }

    if (! is_file($chemin)) {
        touch($chemin);
    }

    // Posé AVANT le démarrage de Laravel. Dotenv ne remplace pas une variable
    // déjà définie, donc ceci fait autorité sur le `.env` comme sur phpunit.xml.
    putenv('DB_DATABASE='.$chemin);
    $_ENV['DB_DATABASE'] = $chemin;
    $_SERVER['DB_DATABASE'] = $chemin;
})();

/**
 * `PRAGMA integrity_check` sur le fichier, sans passer par Laravel — le
 * framework n'est pas encore démarré à cet instant.
 *
 * Un fichier vide est INTÈGRE : c'est l'état d'une base qui n'a pas encore été
 * migrée, pas celui d'une base abîmée. Le confondre ferait écarter un fichier
 * neuf à chaque première exécution.
 */
function estIntegre(string $chemin): bool
{
    if (filesize($chemin) === 0) {
        return true;
    }

    try {
        $pdo = new PDO('sqlite:'.$chemin, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $verdict = $pdo->query('PRAGMA integrity_check')?->fetchColumn();

        return $verdict === 'ok';
    } catch (Throwable) {
        // Illisible : c'est précisément ce qu'on cherche à détecter.
        return false;
    }
}
