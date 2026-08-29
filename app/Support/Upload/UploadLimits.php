<?php

namespace App\Support\Upload;

/**
 * Source unique de vérité pour la limite de taille d'un fichier uploadé (#576).
 *
 * Avant #576, la limite était dupliquée en dur dans deux FormRequest sous la
 * forme `max:31457280`. `31457280` = 30 * 1024 * 1024 (30 Mo exprimé en OCTETS)
 * alors que la règle `max` de Laravel pour un fichier s'exprime en KILO-OCTETS
 * (doc « Size Rule » : « file size in kilobytes for uploaded files »).
 * La limite effective valait donc ~30 Go au lieu de 30 Mo.
 *
 * Centraliser la valeur ici (un `int` en kilo-octets) garantit que la règle, le
 * message d'erreur et l'affichage lisible dérivent d'un seul endroit.
 */
final class UploadLimits
{
    /**
     * Taille maximale d'un fichier uploadé, en KILO-OCTETS.
     *
     * Écrite `30 * 1024` pour rendre visibles le « 30 Mo » et la conversion vers
     * l'unité de la règle Laravel, et empêcher la régression #576.
     *
     * Doit rester ≤ `upload_max_filesize` / `post_max_size` côté PHP, sinon PHP
     * coupe l'envoi avant la validation applicative (relation documentée dans le
     * guide de déploiement — cf. #577).
     *
     * Constante non typée volontairement : le projet déclare `php: ^8.2`
     * (composer.json) et les constantes de classe typées sont réservées à
     * PHP 8.3+. `30 * 1024` reste inféré `int`.
     *
     * @var int
     */
    public const MAX_KILOBYTES = 30 * 1024; // 30 Mo = 30 720 Ko

    /**
     * Fragment de règle de validation Laravel prêt à l'emploi (« max:30720 »).
     */
    public static function maxRule(): string
    {
        return 'max:' . self::MAX_KILOBYTES;
    }

    /**
     * Représentation lisible pour l'utilisateur (« 30 MB »), dérivée de la
     * constante afin de ne jamais désynchroniser affichage et règle appliquée.
     */
    public static function humanReadable(): string
    {
        return intdiv(self::MAX_KILOBYTES, 1024) . ' MB';
    }
}
