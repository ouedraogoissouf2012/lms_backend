<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Prouve que la garde Open/Closed ROUGIT quand elle doit rougir.
 *
 * ## Pourquoi ce test existe
 *
 * Une garde qu'on ne teste que sur du code conforme ne prouve rien : elle
 * pourrait être verte parce qu'elle n'inspecte rien. Le dépôt a payé cette
 * leçon deux fois — `scripts/check-file-sizes.php` affiche « ✓ » sans argument
 * en n'ayant rien contrôlé, et son jumeau `check-method-sizes.php` porte le même
 * défaut. La règle qui en découle, inscrite au contrat (#697, article 6) :
 *
 *   « Pour chaque garde-fou, écrire D'ABORD le test qui prouve qu'il rougit. »
 *
 * Chaque cas ci-dessous vérifie donc un CODE DE SORTIE, pas seulement un message.
 *
 * @see scripts/check-ocp.php
 * @see .ocp-allowlist.json
 */
final class OcpGuardTest extends TestCase
{
    private string $racine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->racine = \dirname(__DIR__, 3);
    }

    #[Test]
    public function le_depot_est_conforme_aujourd_hui(): void
    {
        [$code, $sortie] = $this->lancer($this->racine);

        $this->assertSame(0, $code, "La garde OCP doit être verte sur le dépôt.\n" . $sortie);

        // Le dénominateur doit être publié : « rien à redire » et « je n'ai rien
        // regardé » ne doivent jamais produire le même message.
        $this->assertMatchesRegularExpression(
            '/\d+ fichiers inspectés/u',
            $sortie,
            'La garde doit publier son dénominateur (article 6).'
        );
    }

    #[Test]
    public function elle_rougit_sur_une_conditionnelle_de_mode_neuve(): void
    {
        $sonde = $this->racine . '/app/Services/OcpGuardProbe.php';

        file_put_contents($sonde, <<<'PHP'
            <?php
            namespace App\Services;
            final class OcpGuardProbe {
                public function resolve(object $institution): string {
                    return $institution->mode === 'standalone' ? 'local' : 'klassci';
                }
            }
            PHP);

        try {
            [$code, $sortie] = $this->lancer($this->racine);
        } finally {
            @unlink($sonde);
        }

        $this->assertSame(1, $code, "Une conditionnelle de mode neuve doit faire échouer la garde.\n" . $sortie);
        $this->assertStringContainsString('OcpGuardProbe.php', $sortie);
    }

    #[Test]
    public function elle_refuse_de_travailler_sans_liste_d_exemption(): void
    {
        $vide = $this->racineJetable();
        mkdir($vide . '/app', 0o777, true);
        file_put_contents($vide . '/app/Rien.php', "<?php\n");

        try {
            [$code] = $this->lancer($vide);
        } finally {
            $this->supprimer($vide);
        }

        // 2 et non 1 : la garde n'a pas trouvé de violation, elle n'a pas pu travailler.
        $this->assertSame(2, $code, 'Liste absente ⇒ code 2, jamais 0.');
    }

    #[Test]
    public function elle_refuse_de_travailler_quand_il_n_y_a_rien_a_inspecter(): void
    {
        $vide = $this->racineJetable();
        mkdir($vide . '/app', 0o777, true);
        file_put_contents($vide . '/.ocp-allowlist.json', '{"version":1,"entries":[]}');

        try {
            [$code] = $this->lancer($vide);
        } finally {
            $this->supprimer($vide);
        }

        $this->assertSame(2, $code, 'Zéro fichier inspecté ⇒ code 2. C’est le défaut de check-file-sizes.php.');
    }

    #[Test]
    public function une_derogation_sans_trois_demandes_distinctes_est_refusee(): void
    {
        $cas = $this->racineJetable();
        mkdir($cas . '/app', 0o777, true);
        file_put_contents($cas . '/app/Rien.php', "<?php\n");
        file_put_contents($cas . '/adr.md', "ADR de test\n");

        // Deux dates identiques : la garde doit compter UNE demande distincte.
        file_put_contents($cas . '/.ocp-allowlist.json', json_encode([
            'version' => 1,
            'entries' => [[
                'path' => 'app/Rien.php',
                'type' => 'derogation',
                'reason' => 'test',
                'demandes' => ['2026-09-04', '2026-09-04'],
                'adr' => 'adr.md',
                'accorde_par' => 'mainteneur',
            ]],
        ]));

        try {
            [$code, $sortie] = $this->lancer($cas);
        } finally {
            $this->supprimer($cas);
        }

        $this->assertSame(2, $code, 'Article 7 : moins de trois demandes distinctes ⇒ dérogation refusée.');
        $this->assertStringContainsString('sur les 3 requises', $sortie);
    }

    #[Test]
    public function une_derogation_sans_adr_sur_le_disque_est_refusee(): void
    {
        $cas = $this->racineJetable();
        mkdir($cas . '/app', 0o777, true);
        file_put_contents($cas . '/app/Rien.php', "<?php\n");

        file_put_contents($cas . '/.ocp-allowlist.json', json_encode([
            'version' => 1,
            'entries' => [[
                'path' => 'app/Rien.php',
                'type' => 'derogation',
                'reason' => 'test',
                'demandes' => ['2026-09-04', '2026-09-06', '2026-09-09'],
                'adr' => 'docs/adr/inexistant.md',
                'accorde_par' => 'mainteneur',
            ]],
        ]));

        try {
            [$code, $sortie] = $this->lancer($cas);
        } finally {
            $this->supprimer($cas);
        }

        $this->assertSame(2, $code, 'Un ADR absent du disque ne vaut pas accord.');
        $this->assertStringContainsString('ADR introuvable', $sortie);
    }

    /** @return array{0: int, 1: string} */
    private function lancer(string $racine): array
    {
        $commande = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($this->racine . '/scripts/check-ocp.php')
            . ' ' . escapeshellarg($racine)
            . ' 2>&1';

        exec($commande, $lignes, $code);

        return [$code, implode("\n", $lignes)];
    }

    private function racineJetable(): string
    {
        $chemin = sys_get_temp_dir() . '/ocp-' . bin2hex(random_bytes(6));
        mkdir($chemin, 0o777, true);

        return $chemin;
    }

    private function supprimer(string $chemin): void
    {
        if (! is_dir($chemin)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($chemin, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }

        @rmdir($chemin);
    }
}
