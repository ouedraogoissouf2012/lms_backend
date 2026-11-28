<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * #1306 : aucun code applicatif n'appelle le broker de réinitialisation Laravel.
 *
 * Le broker est retiré de `config/auth.php`, mais Laravel complète ce fichier par ses propres
 * défauts : `auth.passwords.users` désigne ENCORE la table `password_reset_tokens`, que la
 * migration de #1306 supprime. Ce n'est sans risque que parce qu'aucun appel au broker ne
 * subsiste. Ce verrou l'interdit : un futur `Password::sendResetLink()` tomberait sur une table
 * absente, en 500. Le lien de réinitialisation est le jeton d'activation (#808, ADR-803-02).
 *
 * Les commentaires ne comptent pas.
 */
final class AucunBrokerDeReinitialisationTest extends TestCase
{
    /** Ce qui atteint le broker : la façade, l'appel, et l'espace de noms de Laravel. */
    private const MOTIFS = [
        '/\bPassword::/',
        '/->broker\(/',
        '/Illuminate\\\\Auth\\\\Passwords\\\\/',
        '/Illuminate\\\\Support\\\\Facades\\\\Password\b/',
    ];

    public function test_aucun_appel_au_broker_dans_app(): void
    {
        $inspectes = 0;
        $trouves = [];

        $fichiers = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));
        foreach ($fichiers as $fichier) {
            if (! $fichier instanceof \SplFileInfo || $fichier->getExtension() !== 'php') {
                continue;
            }
            $inspectes++;
            $code = $this->sansCommentaires((string) file_get_contents($fichier->getPathname()));
            foreach (self::MOTIFS as $motif) {
                if (preg_match($motif, $code) === 1) {
                    $trouves[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $fichier->getPathname()).'  '.$motif;
                }
            }
        }

        self::assertGreaterThan(100, $inspectes, 'Le verrou n\'a presque rien inspecté : il ne prouverait rien.');
        self::assertSame([], $trouves, 'Le broker de réinitialisation Laravel est appelé : sa table n\'existe plus (#1306).');
    }

    private function sansCommentaires(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $jeton) {
            if (is_array($jeton) && in_array($jeton[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($jeton) ? $jeton[1] : $jeton;
        }

        return $code;
    }
}
