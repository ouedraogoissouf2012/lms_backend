<?php

declare(strict_types=1);

namespace Tests\Support\SondeIdentite;

/**
 * #691 — SONDE DE MESURE, jamais fusionnée : les requêtes de test servies sous une
 * AUTRE identité que celle qu'elles envoient.
 *
 * Le garde Sanctum mémoïse l'utilisateur du premier appel d'un test : une requête
 * suivante, anonyme ou d'un autre compte, est servie comme le premier. La sonde ne
 * fait AUCUNE requête en base (elle fausserait les tests qui comptent les requêtes) :
 * elle compare l'en-tête `Authorization` envoyé à celui qui a fixé l'utilisateur
 * mémorisé.
 */
final class ReleveIdentite
{
    /** @var array<string, list<string>> par test, les requêtes servies sous une autre identité */
    private static array $ecarts = [];

    private static int $requetes = 0;

    private static int $servisMemorises = 0;

    private static bool $inscrit = false;

    public static function requete(): void
    {
        self::inscrire();
        self::$requetes++;
    }

    public static function servieMemorisee(): void
    {
        self::$servisMemorises++;
    }

    public static function ecart(string $test, string $methode, string $uri, string $envoye, int $memorise, int $statut): void
    {
        self::$ecarts[$test][] = sprintf('%s %s | envoye=%s | servi_comme=user#%d | statut=%d', $methode, $uri, $envoye, $memorise, $statut);
    }

    private static function inscrire(): void
    {
        if (self::$inscrit) {
            return;
        }
        self::$inscrit = true;

        register_shutdown_function(static function (): void {
            $lignes = [
                '', '=== SONDE IDENTITE #691 ===',
                sprintf('Requetes : %d ; servies avec un utilisateur deja memorise : %d ; tests avec au moins un ecart d identite : %d', self::$requetes, self::$servisMemorises, count(self::$ecarts)),
            ];
            foreach (self::$ecarts as $test => $ecarts) {
                $lignes[] = $test;
                foreach ($ecarts as $ecart) {
                    $lignes[] = '    '.$ecart;
                }
            }
            fwrite(STDERR, implode("\n", $lignes)."\n=== FIN SONDE ===\n");
        });
    }
}
