<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * Garde structurel : changer d'identité au milieu d'un test sans purger le garde
 * d'authentification produit un test vert qui ne prouve rien (#691).
 *
 * ## Le défaut verrouillé ici
 *
 * Le conteneur survit d'une requête à l'autre au sein d'un même test, et le
 * garde Sanctum **mémoïse** l'utilisateur résolu au premier appel : les en-têtes
 * des requêtes suivantes ne sont même plus lus. Mesuré pendant #689, sur une
 * route réellement protégée :
 *
 *     même requête anonyme, seule dans son test ........... 401 (correct)
 *     la même, après une requête authentifiée ............. 200
 *
 * `flushHeaders()` ne suffit pas : il purge les en-têtes, pas le garde. Il faut
 * `$this->app['auth']->forgetGuards()`, ce que {@see ActsAsTenantUser::asTenant()}
 * fait déjà.
 *
 * Le motif touche précisément les tests de REFUS — ceux qui gardent la sécurité.
 * « Je supprime en tant qu'enseignant, puis je vérifie qu'un étudiant ne peut
 * pas » a pu tester l'enseignant deux fois.
 *
 * ## Ce que l'audit a trouvé — et ce qu'il a failli faire réécrire pour rien
 *
 * Premier passage, motif incluant `actingAs` : **onze** méthodes signalées, dont
 * `test_student_cannot_create_or_publish_lessons`, qui alterne trois fois
 * d'identité. De quoi croire le problème répandu.
 *
 * Lecture faite de la source de Sanctum, aucune ne l'était : `actingAs()` écrase
 * l'utilisateur mémoïsé. Motif resserré aux identités posées par en-tête, il
 * restait **une** méthode — et ses deux appels emploient le même jeton, donc la
 * mémoïsation y est inoffensive. Elle a migré vers `asTenant()` pour lever
 * l'ambiguïté, pas pour corriger un défaut.
 *
 * Le dépôt est donc sain sur ce point, parce que #709 a introduit
 * `ActsAsTenantUser` et que sept fichiers l'emploient déjà.
 *
 * Cette garde ne corrige rien — elle **empêche le retour**. C'est sa seule
 * raison d'être, et elle suffit : le défaut est invisible par construction, ne
 * fait rougir aucun test, et n'apparaît qu'en relisant ligne à ligne.
 *
 * La leçon vaut d'être écrite : un signal non vérifié aurait fait réécrire onze
 * tests corrects. L'issue demandait de « prouver le faux négatif avant de
 * corriger » — c'est exactement ce qui a évité le dégât.
 *
 * ## Ce qu'il vérifie
 *
 * Aucune méthode de test ne doit poser DEUX identités différentes et lancer DEUX
 * requêtes HTTP sans que son fichier n'emploie `ActsAsTenantUser` ou
 * `forgetGuards()`.
 *
 * Le critère est volontairement syntaxique : il ne suit pas le flot, donc il ne
 * peut pas prouver qu'une identité change réellement. Il signale la FORME du
 * risque, et laisse un humain trancher — c'est ce qu'on veut d'une garde qui
 * doit rester silencieuse pour être respectée.
 *
 * @see tests/Concerns/ActsAsTenantUser.php
 * @see tests/Feature/Chapter/ChapterTrashAndRestoreTest.php (référence #689)
 */
final class NoIdentitySwitchWithoutForgetGuardsTest extends TestCase
{
    /** Un vrai appel HTTP de test — pas un `->delete()` d'Eloquent. */
    private const APPEL_HTTP = '/->(?:getJson|postJson|putJson|patchJson|deleteJson)\(|\$this->(?:get|post|put|patch|delete)\(/';

    /**
     * Poser une identité PAR EN-TÊTE — et seulement par en-tête.
     *
     * `Sanctum::actingAs()` est délibérément exclu. Lecture faite de sa source
     * (`vendor/laravel/sanctum/src/Sanctum.php:88`), il appelle
     * `app('auth')->guard($guard)->setUser($user)` : il ÉCRASE l'utilisateur
     * mémoïsé au lieu de passer par la résolution. Il est donc immunisé contre
     * ce défaut-ci.
     *
     * Onze méthodes étaient signalées quand `actingAs` figurait dans ce motif —
     * dont `test_student_cannot_create_or_publish_lessons`, qui alterne trois
     * fois d'identité. Toutes auraient été réécrites pour rien.
     *
     * (`actingAs` porte un AUTRE défaut, celui de #709 : sans jeton porteur,
     * aucun tenant n'est résolu et le scope multi-tenant s'efface. C'est la
     * raison d'être de `ActsAsTenantUser`, et ce n'est pas ce que garde ce
     * fichier.)
     */
    private const POSE_IDENTITE = '/withToken\(|Authorization.{0,20}Bearer/';

    /**
     * Toute déclaration de fonction ferme le corps précédent. Sans cela, une
     * méthode privée qui suit un test serait absorbée dans son corps — et
     * produirait de faux signalements. C'est arrivé en écrivant cette garde.
     */
    private const DECLARATION = '/^\s*(?:public|private|protected|abstract|final|static)[\w\s]*function\s+(\w+)/';

    public function test_aucun_test_ne_change_d_identite_sans_purger_le_garde(): void
    {
        $suspects = [];

        foreach ($this->fichiersDeTest() as $chemin) {
            $source = (string) file_get_contents($chemin);

            // Le fichier emploie déjà le patron correct : rien à dire.
            if (str_contains($source, 'ActsAsTenantUser') || str_contains($source, 'forgetGuards')) {
                continue;
            }

            foreach ($this->methodesDeTest($source) as $nom => $corps) {
                if (preg_match_all(self::APPEL_HTTP, $corps) < 2) {
                    continue;
                }
                if (preg_match_all(self::POSE_IDENTITE, $corps) < 2) {
                    continue;
                }

                $suspects[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $chemin)."::{$nom}";
            }
        }

        $this->assertSame([], $suspects, $this->explication($suspects));
    }

    /**
     * @return list<string>
     */
    private function fichiersDeTest(): array
    {
        $chemins = [];
        $iterateur = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('tests'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterateur as $fichier) {
            if ($fichier instanceof \SplFileInfo && $fichier->getExtension() === 'php') {
                $chemins[] = $fichier->getPathname();
            }
        }

        sort($chemins);

        return $chemins;
    }

    /**
     * Découpe le fichier en corps de méthodes de test, bornés par la déclaration
     * suivante quelle qu'elle soit.
     *
     * @return array<string, string>
     */
    private function methodesDeTest(string $source): array
    {
        $lignes = explode("\n", $source);
        $bornes = [];

        foreach ($lignes as $i => $ligne) {
            if (preg_match(self::DECLARATION, $ligne, $m) === 1) {
                $bornes[] = [$i, $m[1]];
            }
        }

        $corps = [];
        $total = count($bornes);

        foreach ($bornes as $k => [$debut, $nom]) {
            if (! str_starts_with($nom, 'test')) {
                continue;
            }

            $fin = $k + 1 < $total ? $bornes[$k + 1][0] : count($lignes);
            $corps[$nom] = implode("\n", array_slice($lignes, $debut, $fin - $debut));
        }

        return $corps;
    }

    /**
     * @param  list<string>  $suspects
     */
    private function explication(array $suspects): string
    {
        if ($suspects === []) {
            return '';
        }

        return "Ces méthodes posent DEUX identités et lancent DEUX requêtes, sans purger le garde :\n  - "
            .implode("\n  - ", $suspects)
            ."\n\nLe garde Sanctum mémoïse l'utilisateur du PREMIER appel : les en-têtes des "
            ."suivants ne sont plus lus, et un test de refus peut passer au vert sans rien prouver.\n"
            .'Employez le trait Tests\\Concerns\\ActsAsTenantUser et sa méthode asTenant(), qui '
            ."appelle forgetGuards() avant de poser le jeton.\n"
            .'Si la forme est un faux positif — deux poses de la MÊME identité, par exemple — '
            ."dites-le en employant quand même le trait : il ne coûte rien et retire l'ambiguïté.";
    }
}
