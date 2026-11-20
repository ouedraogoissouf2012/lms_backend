<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Mail;

use App\Services\Mail\CanalDeCourriel;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * #808 — le canal de courriel n'est « disponible » que si un courriel peut réellement partir
 * ET porter un lien utilisable. Sinon, « mot de passe oublié » n'affirme aucun envoi.
 *
 * Une condition par cas : chacune, seule, rend le canal indisponible.
 */
final class CanalDeCourrielTest extends TestCase
{
    public function test_brevo_configure_rend_le_canal_disponible(): void
    {
        self::assertTrue((new CanalDeCourriel(new Repository($this->brevo())))->estDisponible());
    }

    /** @return iterable<string, array{0: string, 1: mixed}> */
    public static function conditions(): iterable
    {
        yield 'mailer log (le défaut de la prod)' => ['mail.default', 'log'];
        yield 'mailer array (les tests)' => ['mail.default', 'array'];
        yield 'expéditeur par défaut de Laravel' => ['mail.from.address', 'hello@example.com'];
        yield 'expéditeur vide' => ['mail.from.address', ''];
        yield 'hôte SMTP vide' => ['mail.mailers.smtp.host', ''];
        yield 'identifiant SMTP vide' => ['mail.mailers.smtp.username', null];
        yield 'URL du front absente (le lien serait mort)' => ['activation.url_front', null];
    }

    #[DataProvider('conditions')]
    public function test_une_condition_manquante_rend_le_canal_indisponible(string $cle, mixed $valeur): void
    {
        $config = new Repository($this->brevo());
        $config->set($cle, $valeur);

        self::assertFalse((new CanalDeCourriel($config))->estDisponible());
    }

    /** @return array<string, mixed> */
    private function brevo(): array
    {
        return [
            'mail' => [
                'default' => 'smtp',
                'mailers' => ['smtp' => ['host' => 'smtp-relay.brevo.com', 'username' => 'compte@brevo.test']],
                'from' => ['address' => 'ne-pas-repondre@lms.test'],
            ],
            'activation' => ['url_front' => 'https://lms.test'],
        ];
    }
}
