<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * #949 — `users.role` se lit d'UNE façon : `Role::tryFromString`, correspondance
 * exacte sur la table de `Role::aliases()`. Une seconde normalisation (casse,
 * espaces) est exactement ce qui faisait dire à la garde de route `Enseignant`
 * est enseignant, quand les services disaient « rien ».
 *
 * Rouge si `app/` abaisse la casse d'une expression de rôle, ou si la garde de
 * route et le filtre des rôles KLASSCI abaissent quoi que ce soit.
 */
final class UneSeuleLectureDuRoleTest extends TestCase
{
    /** Une casse abaissée, appliquée à une expression où figure `role`. */
    private const NORMALISATION = '/(?:mb_)?strto(?:lower|upper)\s*\([^;]*\brole|strcasecmp\s*\([^;]*\brole/i';

    /** Les deux lectures du rôle qui portaient leur propre normalisation. */
    private const SANS_AUCUNE_CASSE = [
        'app/Http/Middleware/EnsureRole.php',
        'app/Services/Klassci/Auth/KlassciRoleSanitizer.php',
    ];

    public function test_aucune_casse_n_est_abaissee_sur_un_role(): void
    {
        $inspectes = 0;
        $ecarts = [];

        foreach ($this->fichiersPhp() as $relatif => $contenu) {
            $inspectes++;
            foreach (preg_split('/\R/', $contenu) ?: [] as $numero => $ligne) {
                if (preg_match(self::NORMALISATION, $ligne) === 1) {
                    $ecarts[] = $relatif.':'.($numero + 1).'  '.trim($ligne);
                }
            }
        }

        self::assertGreaterThan(100, $inspectes, 'Le balayage de app/ ne trouve presque rien : chemin faux ?');
        self::assertSame([], $ecarts, "Une seconde normalisation du rôle (#949) :\n".implode("\n", $ecarts));
    }

    public function test_la_garde_et_le_filtre_klassci_n_abaissent_aucune_casse(): void
    {
        foreach (self::SANS_AUCUNE_CASSE as $relatif) {
            $contenu = (string) file_get_contents(base_path($relatif));

            self::assertDoesNotMatchRegularExpression('/(?:mb_)?strto(?:lower|upper)\s*\(|strcasecmp\s*\(/', $contenu, $relatif);
        }
    }

    /** @return iterable<string, string> chemin relatif => contenu */
    private function fichiersPhp(): iterable
    {
        $racine = base_path('app');
        $fichiers = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, RecursiveDirectoryIterator::SKIP_DOTS));

        /** @var SplFileInfo $fichier */
        foreach ($fichiers as $fichier) {
            if ($fichier->getExtension() !== 'php') {
                continue;
            }

            $relatif = 'app/'.str_replace('\\', '/', substr($fichier->getPathname(), strlen($racine) + 1));

            yield $relatif => (string) file_get_contents($fichier->getPathname());
        }
    }
}
