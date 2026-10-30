<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Rules\NotificationActionUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Le lien d'action d'une notification : un chemin interne du LMS, ou une
 * adresse `https://`. Chaque refus est nommé ; la règle `url` du framework
 * acceptait plusieurs d'entre eux (`data://`, `file://`, `http://`) et refusait
 * le chemin interne.
 *
 * @see app/Rules/NotificationActionUrl.php
 */
final class NotificationActionUrlTest extends TestCase
{
    /** @return iterable<string, array{0: mixed}> */
    public static function refusees(): iterable
    {
        yield 'javascript: sans //' => ['javascript:alert(1)'];
        yield 'javascript: avec //' => ['javascript://x/%0Aalert(1)'];
        yield 'JavaScript: en casse mêlée' => ['JaVaScRiPt:alert(1)'];
        yield 'data: sans //' => ['data:text/html,<script>alert(1)</script>'];
        yield 'data://' => ['data://text/html,x'];
        yield 'file://' => ['file:///etc/passwd'];
        yield 'view-source://' => ['view-source://exemple.ci'];
        yield 'http:// en clair' => ['http://exemple.ci/x'];
        yield 'relative au protocole //hôte' => ['//exemple.ci/x'];
        yield 'antislash /\\hôte' => ['/\\exemple.ci/x'];
        yield 'antislash dans le chemin' => ['/student\\x'];
        yield '//hôte encodé %2F%2F' => ['/%2F%2Fexemple.ci'];
        yield 'antislash encodé %5C' => ['/%5Cexemple.ci'];
        yield 'segment ..' => ['/student/../admin'];
        yield 'segment .. final' => ['/student/..'];
        yield 'segment . ' => ['/./student'];
        yield 'segment .. encodé %2E%2E' => ['/student/%2E%2E/admin'];
        yield 'segment .. encodé en minuscules %2e%2e' => ['/student/%2e%2e/admin'];
        yield 'https avec identifiant' => ['https://x@exemple.ci/'];
        yield 'https avec identifiant et mot de passe' => ['https://moi:secret@exemple.ci/x'];
        yield 'blanc en tête' => [' /student'];
        yield 'espace dans le chemin' => ['/student /x'];
        yield 'tabulation' => ["/student\t/x"];
        yield 'saut de ligne final, chemin' => ["/student\n"];
        yield 'saut de ligne final, https' => ["https://exemple.ci/x\n"];
        yield 'caractère nul' => ["/student\0"];
        yield 'chemin sans / initial' => ['student/evaluations/3'];
        yield 'chaîne vide' => [''];
        yield 'pas une chaîne' => [['/student']];
        yield 'entier' => [42];
    }

    #[DataProvider('refusees')]
    public function test_refusee(mixed $valeur): void
    {
        self::assertSame(NotificationActionUrl::MESSAGE, $this->echec($valeur));
    }

    /** @return iterable<string, array{0: string}> */
    public static function acceptees(): iterable
    {
        yield 'chemin interne' => ['/student/evaluations/3'];
        yield 'racine' => ['/'];
        yield 'chemin avec requête et fragment' => ['/forum/topics/7?page=2#reponse'];
        yield 'chemin avec un point dans un nom' => ['/fichiers/cours.v2.pdf'];
        // La requête et le fragment ne sont pas résolus comme un chemin : leurs
        // valeurs encodées, et un chemin passé en paramètre, sont légitimes.
        yield 'requête avec un blanc encodé' => ['/recherche?q=a%20b'];
        yield 'requête avec un antislash encodé' => ['/recherche?f=a%5Cb'];
        yield 'requête avec un octet latin-1 encodé' => ['/recherche?q=%E9'];
        yield 'chemin de retour en paramètre' => ['/connexion?next=/../x'];
        yield 'https' => ['https://exemple.ci/x?y=1#z'];
        yield 'HTTPS en capitales' => ['HTTPS://EXEMPLE.CI/'];
    }

    #[DataProvider('acceptees')]
    public function test_acceptee(string $valeur): void
    {
        self::assertNull($this->echec($valeur));
    }

    private function echec(mixed $valeur): ?string
    {
        $echec = null;
        (new NotificationActionUrl())->validate(
            'action_url',
            $valeur,
            function (string $message) use (&$echec): void {
                $echec = $message;
            },
        );

        return $echec;
    }
}
