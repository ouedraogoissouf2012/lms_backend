<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Rules\KlassciApiUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Test unitaire pur (sans DB ni réseau) de la règle {@see KlassciApiUrl}.
 *
 * ## La panne figée ici (#685)
 *
 * `institutions#1` portait `http://presentation.klassci.com/api/lms`, port 80.
 * KLASSCI a cessé d'y répondre — le 443 fonctionnait. Mesuré en production le
 * 2026-09-03 : `http` → code 000 après 10 s, `https` → 404 en 1,74 s.
 *
 * Résultat : `my-teaching` en 500, liste de séances vide, **aucun bouton visio**.
 * La validation laissait passer, `'nullable|url'` acceptant `http://` sans
 * réserve. Une lettre manquante coupait tout un tenant, en silence.
 *
 * On teste les deux versants avec le même soin : ce que la règle refuse, et ce
 * qu'elle doit laisser passer. Une règle qui crie sur `http://localhost` serait
 * contournée dans la semaine.
 *
 * @see app/Rules/KlassciApiUrl.php
 */
final class KlassciApiUrlTest extends TestCase
{
    /**
     * @return list<array{string}>
     */
    public static function urlsRefusees(): array
    {
        return [
            // Le cas EXACT de la production le 2026-09-03.
            ['http://presentation.klassci.com/api/lms'],
            ['http://esbtp-abidjan.klassci.com/api/lms'],
            // Un hôte public ne devient pas local parce qu'il contient « local ».
            ['http://localhost.attaquant.example/api'],
            // 128.x n'est pas 127.x — la frontière du bouclage est stricte.
            ['http://128.0.0.1/api'],
            // Schémas qui ne sont pas du transport web.
            ['ftp://presentation.klassci.com/api'],
            ['file:///etc/passwd'],
            // Formes non absolues : ni schéma ni hôte exploitables.
            ['presentation.klassci.com/api/lms'],
            ['/api/lms'],
            ['https://'],
            [''],
        ];
    }

    /**
     * @return list<array{string}>
     */
    public static function urlsAcceptees(): array
    {
        return [
            ['https://presentation.klassci.com/api/lms'],
            ['https://esbtp-yakro.klassci.com/api/lms'],
            // Majuscules : un schéma reste un schéma.
            ['HTTPS://presentation.klassci.com/api/lms'],
            // Boucle locale — le trafic ne quitte pas la machine.
            ['http://localhost:8080/api'],
            ['http://127.0.0.1:8000/api/lms'],
            ['http://127.0.0.53/api'],
            ['http://[::1]:8080/api'],
        ];
    }

    #[DataProvider('urlsRefusees')]
    public function test_elle_refuse(string $url): void
    {
        $this->assertSame([$url], $this->echecs($url));
    }

    #[DataProvider('urlsAcceptees')]
    public function test_elle_accepte(string $url): void
    {
        $this->assertSame([], $this->echecs($url));
    }

    public function test_le_message_dit_quoi_faire_et_pourquoi(): void
    {
        $messages = $this->messages('http://presentation.klassci.com/api/lms');

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('https', $messages[0]);
        // Un message qui n'explique pas la conséquence se lit comme un caprice.
        $this->assertStringContainsString('685', $messages[0]);
    }

    public function test_une_valeur_non_chaine_est_refusee_sans_lever(): void
    {
        // La règle est branchée après `string`, mais elle ne doit pas exploser si
        // un jour elle ne l'est plus : on échoue proprement, jamais de TypeError.
        foreach ([null, 42, [], true] as $valeur) {
            $this->assertCount(1, $this->messages($valeur));
        }
    }

    /**
     * Rend la liste des URL ayant échoué — pratique pour une assertion lisible.
     *
     * @return list<string>
     */
    private function echecs(string $url): array
    {
        return $this->messages($url) === [] ? [] : [$url];
    }

    /**
     * @return list<string>
     */
    private function messages(mixed $valeur): array
    {
        $messages = [];

        (new KlassciApiUrl)->validate(
            'klassci_api_url',
            $valeur,
            function (string $message) use (&$messages): void {
                $messages[] = $message;
            }
        );

        return $messages;
    }
}
