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
 * ## Les trois mesures
 *
 * 1. **Un fichier par checkout.** Le nom dérive du chemin racine : deux
 *    worktrees n'écrivent plus jamais le même fichier.
 *
 *    Ce qui suit était écrit ici, et c'était FAUX : « le cas concurrent
 *    disparaît par construction ». Il ne disparaît que pour des checkouts
 *    DIFFÉRENTS. Deux suites lancées dans le MÊME répertoire retombent sur le
 *    même fichier — or c'est précisément l'incident que le dépôt documente
 *    (`docs/RETROSPECTIVE_PARCOURS_ENSEIGNANT.md:354` : « Agents lançant PHPUnit
 *    en parallèle → Corrompt database.testing.sqlite »), et ces agents-là
 *    partagent le répertoire de travail.
 *
 *    Cette moitié est fermée par la mesure 2.
 *
 * 2. **Une seule suite à la fois par checkout.** Un verrou exclusif non
 *    bloquant (`flock`) sur `<empreinte>.sqlite.lock`, pris AVANT tout contrôle
 *    et toute écriture, et tenu jusqu'à la fin du processus. Une seconde suite
 *    s'arrête avec un message qui nomme la cause. Mesuré le 23/11 sur une
 *    copie : `.lms-wt-reparateur` avait mis en quarantaine, le 14/11, une base
 *    « malformed », deux mois après la mesure 1. Le système rend le verrou à
 *    la mort du processus, même tué : il n'y a pas de verrou orphelin à
 *    nettoyer, seulement un `php` encore vivant à arrêter. Les processus isolés
 *    d'une suite (`#[RunInSeparateProcess]`) héritent de son verrou par
 *    l'environnement : la suite parente les attend, ils ne la concurrencent pas.
 *
 * 3. **Un contrôle d'intégrité AVANT la suite.** Si le fichier est malformé, on
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

/** Porte, vers les processus isolés de la suite, le chemin du verrou qu'elle tient (#692). */
const VERROU_HERITE = 'LMS_TEST_VERROU_CHECKOUT';

(static function (): void {
    // La jambe MySQL de la CI pose `DB_CONNECTION=mysql` et `DB_DATABASE=lms_testing` :
    // y écrire un chemin de FICHIER n'a aucun sens et casse la connexion.
    //
    // À cet instant, Laravel n'a pas encore chargé `.env` — `getenv()` ne rend
    // donc que ce que le shell ou la CI ont réellement posé, jamais la valeur du
    // fichier. C'est précisément le discriminant qu'il faut : absent en local,
    // explicite en CI. Premier jet sans cette garde : jambe MySQL rouge.
    $connexion = getenv('DB_CONNECTION');

    if (is_string($connexion) && $connexion !== '' && $connexion !== 'sqlite') {
        return;
    }

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

    $raison = null;
    $verdict = is_file($chemin) ? verdictIntegrite($chemin, $raison) : 'saine';

    // Ne rien détruire sur un doute est le bon choix. Ne RIEN DIRE ne l'est pas :
    // on retomberait sur « N erreurs, cause non nommée » — le défaut même que
    // #692 corrige. Un verrou, une permission refusée, un disque plein : la
    // suite va échouer, et l'exploitant doit savoir que ce n'est pas son code.
    if ($verdict === 'indeterminable') {
        fwrite(STDERR, PHP_EOL
            .'  ⚠ Base de test NON VÉRIFIABLE — laissée en place, rien n\'a été touché.'.PHP_EOL
            .'    fichier : '.$chemin.PHP_EOL
            .'    SQLite dit : '.($raison ?? 'raison inconnue').PHP_EOL
            .'    Verrou d\'une autre suite ? Permission ? Disque plein ?'.PHP_EOL
            .'    Si les tests échouent ensuite, la cause est ICI, pas dans votre code.'.PHP_EOL
            .PHP_EOL);
    }

    if ($verdict === 'corrompue') {
        $ecarte = $chemin.'.corrompue-'.date('Ymd-His');

        // Le retour de `rename()` EST testé, et ce n'est pas de la prudence
        // décorative. Premier jet : il ne l'était pas. Sous Windows, un échec
        // laissait `is_file()` vrai, sautait le `touch()`, et envoyait la suite
        // sur la base TOUJOURS corrompue — précédée d'une bannière affirmant le
        // contraire. Les 94 erreurs revenaient, avec en prime un message qui
        // jurait que le problème était traité. Pire que pas de garde.
        if (! @rename($chemin, $ecarte)) {
            fwrite(STDERR, PHP_EOL
                .'  ⚠ Base de test corrompue, et IMPOSSIBLE à écarter.'.PHP_EOL
                .'    fichier : '.$chemin.PHP_EOL
                .'    Un autre processus la tient probablement ouverte.'.PHP_EOL
                .'    Fermez les suites en cours, puis relancez.'.PHP_EOL
                .PHP_EOL);

            // On s'arrête. Continuer reviendrait à migrer sur un fichier abîmé.
            exit(1);
        }

        fwrite(STDERR, PHP_EOL
            .'  ⚠ Base de test corrompue — elle a été écartée, pas réparée.'.PHP_EOL
            .'    écartée : '.basename($ecarte).PHP_EOL
            .'    Une base neuve est créée ; RefreshDatabase va remigrer.'.PHP_EOL
            .'    Cause habituelle : deux exécutions concurrentes sur le même fichier (#692).'.PHP_EOL
            .PHP_EOL);
    }

    // AVANT le contrôle d'intégrité : celui-ci peut renommer la base. Sans verrou,
    // une seconde suite déclarait corrompue la base que la première était en train
    // d'écrire, et la déplaçait sous ses pieds — mesuré le 23/11.
    $cheminDuVerrou = $chemin.'.lock';

    // Un test `#[RunInSeparateProcess]` relance CE bootstrap dans un processus
    // enfant, que la suite parente attend : ce n'est pas une seconde suite. Premier
    // jet sans ce cas : les 3 tests de `SessionSecureCookieDefaultTest` refusés sur
    // les deux jambes sqlite. L'enfant reconnaît l'héritage au chemin EXACT de son
    // verrou ; une suite d'un autre checkout, ou lancée d'ailleurs, ne l'a pas.
    $verrou = getenv(VERROU_HERITE) === $cheminDuVerrou ? 'herite' : verrouDuCheckout($cheminDuVerrou);

    if ($verrou === 'tenu') {
        // PHPUnit passe aux enfants soit l'environnement du processus, soit `$_SERVER` :
        // les deux portent l'héritage.
        putenv(VERROU_HERITE.'='.$cheminDuVerrou);
        $_ENV[VERROU_HERITE] = $cheminDuVerrou;
        $_SERVER[VERROU_HERITE] = $cheminDuVerrou;
    }

    if ($verrou === 'pris') {
        fwrite(STDERR, PHP_EOL
            .'  ⚠ Une autre suite tourne dans ce checkout — arrêt AVANT toute écriture (#692).'.PHP_EOL
            .'    verrou : '.$cheminDuVerrou.PHP_EOL
            .'    Deux suites sur la même base SQLite la corrompent. Attendez la fin de'.PHP_EOL
            .'    l\'autre, ou lancez celle-ci dans un autre worktree.'.PHP_EOL
            .'    Le verrou se rend seul quand son processus meurt, même tué. S\'il reste'.PHP_EOL
            .'    pris, un `php` est encore vivant : l\'arrêter, et ne pas effacer le .lock.'.PHP_EOL
            .PHP_EOL);

        exit(1);
    }

    if ($verrou === 'impossible') {
        fwrite(STDERR, PHP_EOL
            .'  ⚠ Verrou de checkout IMPOSSIBLE à poser — la suite continue sans lui.'.PHP_EOL
            .'    fichier : '.$cheminDuVerrou.PHP_EOL
            .'    Une seconde suite dans ce checkout ne serait pas arrêtée (#692).'.PHP_EOL
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
 * Prend le verrou exclusif de ce checkout, sans attendre (#692).
 *
 * Rend `'tenu'`, `'pris'` (une autre suite le tient), ou `'impossible'`.
 *
 * ## Pourquoi la poignée est `static`
 *
 * `flock` vaut tant que la poignée est ouverte. Une variable locale serait
 * libérée au retour de la fonction, et le verrou avec elle : la garde
 * protégerait les quelques microsecondes de l'amorçage, pas la suite. La
 * variable statique vit jusqu'à la fin du processus, et le système rend le
 * verrou à sa mort, même tué : rien à nettoyer derrière.
 *
 * ## Pourquoi `impossible` laisse passer
 *
 * Un fichier qu'on ne peut pas ouvrir dans un répertoire qu'on vient de créer
 * est un poste en panne, pas une suite concurrente. Bloquer la suite là-dessus
 * ferait désactiver la garde ; on retombe donc sur le comportement d'avant ce
 * verrou, en le DISANT.
 *
 * Mesuré sur ce poste (Windows, PHP 8.3 ZTS) avec deux processus `php` réels :
 * le second est refusé (`wouldblock`), et le verrou est rendu après un
 * `taskkill /F` du premier.
 */
function verrouDuCheckout(string $chemin): string
{
    /** @var resource|null $poignee */
    static $poignee = null;

    if ($poignee !== null) {
        return 'tenu';
    }

    $fichier = @fopen($chemin, 'c');

    if ($fichier === false) {
        return 'impossible';
    }

    // Seul « bloquerait » veut dire « une autre suite le tient ». Un refus pour une
    // autre raison (système de fichiers sans verrous) n'est pas une concurrence.
    $bloquerait = 0;

    if (! flock($fichier, LOCK_EX | LOCK_NB, $bloquerait)) {
        fclose($fichier);

        return $bloquerait === 1 ? 'pris' : 'impossible';
    }

    $poignee = $fichier;

    return 'tenu';
}

/**
 * `PRAGMA integrity_check` sur le fichier, sans passer par Laravel — le
 * framework n'est pas encore démarré à cet instant.
 *
 * Rend `'saine'`, `'corrompue'`, ou `'indeterminable'`.
 *
 * ## Pourquoi TROIS verdicts et non un booléen
 *
 * Le premier jet rendait `false` dès que quelque chose clochait. Or « la base
 * est verrouillée par un pair » et « la base est abîmée » n'ont RIEN à voir :
 * une base parfaitement saine, tenue en transaction par une autre suite, faisait
 * expirer le délai d'attente, tombait dans le `catch`, et se faisait mettre en
 * quarantaine. Sous Linux le renommage réussit — la base de l'exécution en cours
 * était déplacée sous ses pieds.
 *
 * Détruire sur un doute est le contraire de ce qu'on veut d'une garde qui touche
 * à des fichiers. Dans le doute : on ne touche à rien.
 *
 * ## Un fichier vide est SAIN
 *
 * C'est l'état d'une base pas encore migrée, pas celui d'une base abîmée. Les
 * confondre ferait écarter un fichier neuf à chaque première exécution, et la
 * garde deviendrait un bruit qu'on désactive.
 *
 * ## `ERRMODE_SILENT`, et ce n'est pas un détail
 *
 * En mode exception, l'exception levée par le PRAGMA retient la poignée du
 * fichier sous Windows quand Xdebug est en `develop` — le réglage de ce poste.
 * Le `rename()` suivant échouait alors sans qu'on le sache. Le mode silencieux
 * rend exactement les mêmes verdicts et relâche la poignée.
 */
function verdictIntegrite(string $chemin, ?string &$raison = null): string
{
    if (filesize($chemin) === 0) {
        return 'saine';
    }

    try {
        $pdo = new PDO('sqlite:'.$chemin, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
            // Deux secondes : on veut savoir si c'est verrouillé, pas attendre
            // qu'un pair finisse. Le défaut de SQLite est de 60 s.
            PDO::ATTR_TIMEOUT => 2,
        ]);

        $requete = $pdo->query('PRAGMA integrity_check');

        if ($requete === false) {
            $raison = (string) ($pdo->errorInfo()[2] ?? '');

            return verdictDepuisCode($raison);
        }

        return $requete->fetchColumn() === 'ok' ? 'saine' : 'corrompue';
    } catch (Throwable $e) {
        $raison = $e->getMessage();

        return verdictDepuisCode($raison);
    }
}

/**
 * Traduit un message SQLite en verdict.
 *
 * Seules les formes qui désignent un FICHIER ABÎMÉ autorisent la quarantaine.
 * Tout le reste — verrou, permission, disque plein — est indéterminable : on
 * laisse le fichier tranquille et la suite dira elle-même ce qui ne va pas.
 */
function verdictDepuisCode(string $message): string
{
    $message = strtolower($message);

    // `unsupported file format` a été AJOUTÉ après coup, et son absence était une
    // régression : le code remplacé attrapait tout `Throwable` et écartait donc
    // ce cas. Mesuré : octet 47 modifié → SQLite rend « unsupported file
    // format » → mon premier jet rendait `indeterminable` → le fichier restait
    // en place → la suite repartait dessus et rendait des erreurs en série,
    // SANS une ligne de cet amorçage. Le symptôme même que #692 supprime.
    //
    // Une liste de mots reste un filet à trous : elle dépend de la version de
    // SQLite. C'est une DETTE assumée ici — le verdict par défaut est
    // `indeterminable`, qui ne détruit rien et qui PARLE. Un mot manquant coûte
    // donc un message imprécis, jamais un fichier perdu.
    $formesDeCorruption = [
        'malformed',
        'not a database',
        'file is encrypted',
        'disk image',
        'unsupported file format',
        'database corrupt',
    ];

    foreach ($formesDeCorruption as $signe) {
        if (str_contains($message, $signe)) {
            return 'corrompue';
        }
    }

    return 'indeterminable';
}
