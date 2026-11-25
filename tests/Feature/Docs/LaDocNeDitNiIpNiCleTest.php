<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * #1529 : aucun fichier de `docs/` ne dit une adresse IPv4 publique, ni une clé Brevo.
 *
 * La documentation dit COMMENT relever l'IP sortante du serveur (à autoriser chez Brevo), jamais
 * laquelle : une IP dans le dépôt désigne la machine à attaquer, et se périme au premier
 * changement de serveur. `NoHardcodedSecretsTest` garde le code, pas `docs/`.
 *
 * Restent permises les adresses non routables sur Internet : privées (RFC 1918), loopback,
 * réservées, et de documentation (RFC 5737), celles que PHP exclut avec
 * `FILTER_FLAG_GLOBAL_RANGE`.
 *
 * Les tests du détecteur construisent leurs entrées à l'exécution : aucun littéral d'IP publique
 * ni de forme de clé n'entre dans le dépôt, ni dans son historique (scanneurs de secrets compris).
 */
final class LaDocNeDitNiIpNiCleTest extends TestCase
{
    private const IPV4 = '/(?<![\d.])(?:\d{1,3}\.){3}\d{1,3}(?!\.?\d)/';

    public function test_aucun_fichier_de_docs_ne_dit_une_ip_publique_ni_une_cle(): void
    {
        $inspectes = 0;
        $trouves = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('docs'), FilesystemIterator::SKIP_DOTS)) as $fichier) {
            if (! $fichier instanceof \SplFileInfo || ! $fichier->isFile()) {
                continue;
            }
            $inspectes++;
            $relatif = substr($fichier->getPathname(), strlen(base_path()) + 1);
            foreach (self::fautes((string) file_get_contents($fichier->getPathname())) as $faute) {
                // Le chemin et la NATURE de la faute, jamais la valeur : le journal de la CI est public.
                $trouves[] = "$relatif : $faute";
            }
        }

        self::assertGreaterThan(50, $inspectes, 'Presque rien d\'inspecté : la garde ne prouverait rien.');
        self::assertSame([], $trouves, "docs/ ne dit ni IP publique ni clé (#1529) :\n  - ".implode("\n  - ", $trouves));
    }

    public function test_le_detecteur_voit_une_ip_publique(): void
    {
        $ip = implode('.', [8, 8, 8, 8]);

        self::assertSame(['une adresse IPv4 publique'], self::fautes("Autoriser $ip chez Brevo."));
        self::assertSame(['une adresse IPv4 publique'], self::fautes("en fin de phrase : $ip."));
    }

    public function test_le_detecteur_voit_une_cle_brevo(): void
    {
        foreach (['smtp', 'key'] as $sorte) {
            $cle = implode('', ['x', $sorte, 'sib', '-']).str_repeat('a', 64).'-'.str_repeat('B', 16);

            self::assertSame(['une clé Brevo'], self::fautes("MAIL_PASSWORD=$cle"), $sorte);
        }
    }

    /** @return array<string, array{list<int>}> */
    public static function adressesNonRoutables(): array
    {
        return [
            'privée 10/8' => [[10, 0, 0, 5]],
            'privée 172.16/12' => [[172, 16, 1, 1]],
            'privée 192.168/16' => [[192, 168, 1, 1]],
            'loopback' => [[127, 0, 0, 1]],
            'non spécifiée' => [[0, 0, 0, 0]],
            'lien local' => [[169, 254, 1, 1]],
            'documentation 192.0.2/24' => [[192, 0, 2, 1]],
            'documentation 198.51.100/24' => [[198, 51, 100, 7]],
            'documentation 203.0.113/24' => [[203, 0, 113, 7]],
        ];
    }

    /** @param list<int> $octets */
    #[DataProvider('adressesNonRoutables')]
    public function test_le_detecteur_laisse_les_adresses_non_routables(array $octets): void
    {
        self::assertSame([], self::fautes('ip -4 route get '.implode('.', $octets)));
    }

    public function test_le_detecteur_ne_prend_pas_un_numero_plus_long_pour_une_ip(): void
    {
        $long = implode('.', [8, 8, 8, 8, 8]);

        self::assertSame([], self::fautes("version $long"));
    }

    /** @return list<string> La nature de chaque faute du texte (jamais sa valeur). */
    private static function fautes(string $texte): array
    {
        $fautes = [];
        preg_match_all(self::IPV4, $texte, $formes);
        foreach ($formes[0] as $forme) {
            if (filter_var($forme, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE) !== false) {
                $fautes[] = 'une adresse IPv4 publique';
            }
        }
        // Le préfixe est assemblé : aucune forme de clé littérale dans ce fichier.
        if (preg_match('/'.implode('', ['x', '(?:smtp|key)', 'sib', '-']).'[A-Za-z0-9-]{8,}/', $texte) === 1) {
            $fautes[] = 'une clé Brevo';
        }

        return $fautes;
    }
}
