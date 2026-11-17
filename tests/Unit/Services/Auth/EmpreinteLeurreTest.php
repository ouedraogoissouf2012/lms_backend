<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Auth;

use App\Services\Auth\EmpreinteLeurre;
use App\Support\Logging\CaughtExceptionLog;
use App\Support\Logging\ExceptionDescription;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Hashing\BcryptHasher;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * #687 — l'empreinte leurre : une vérification contre elle donne à un échec de
 * login le coût d'une vraie vérification, que le compte existe ou non.
 *
 * Ce que ces tests figent :
 * - elle est GARDÉE d'une requête à l'autre. Recalculée à chaque appel, elle
 *   coûterait `make` + `check`, soit deux bcrypt contre un pour un mot de passe
 *   faux : l'oracle s'inverserait au lieu de se fermer. Sous Apache/PHP, l'état
 *   d'un singleton meurt avec la requête ; seul le cache la garde ;
 * - elle suit le coût CONFIGURÉ (`needsRehash`) : un leurre plus rapide qu'une
 *   vraie empreinte serait de nouveau reconnaissable ;
 * - une panne du cache ne casse jamais le login.
 *
 * @see docs/adr/2026-11-16-687-01-empreinte-leurre-du-login.md
 */
#[CoversClass(EmpreinteLeurre::class)]
final class EmpreinteLeurreTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** Un vrai bcrypt, au coût donné, qui compte ses `make`. */
    private function hacheur(int $cout): BcryptHasher
    {
        return new class(['rounds' => $cout]) extends BcryptHasher
        {
            public int $empreintesCalculees = 0;

            /** @param  array<string, mixed>  $options */
            public function make(#[\SensitiveParameter] $value, array $options = []): string
            {
                $this->empreintesCalculees++;

                return parent::make($value, $options);
            }
        };
    }

    private function leurre(Hasher $hacheur, CacheRepository $cache, ?LoggerInterface $logger = null): EmpreinteLeurre
    {
        return new EmpreinteLeurre(
            $hacheur,
            $cache,
            new CaughtExceptionLog($logger ?? Mockery::spy(LoggerInterface::class), new ExceptionDescription),
        );
    }

    public function test_une_seule_empreinte_d_une_requete_a_l_autre(): void
    {
        $hacheur = $this->hacheur(4);
        $cache = new Repository(new ArrayStore);

        // Deux instances : deux requêtes, qui ne partagent que le cache.
        $premiere = $this->leurre($hacheur, $cache)->empreinte();
        $seconde = $this->leurre($hacheur, $cache)->empreinte();

        self::assertSame($premiere, $seconde);
        self::assertSame(1, $hacheur->empreintesCalculees, 'Le leurre doit être calculé une fois, puis relu.');
    }

    public function test_l_empreinte_est_une_vraie_empreinte_au_cout_configure(): void
    {
        $empreinte = $this->leurre($this->hacheur(5), new Repository(new ArrayStore))->empreinte();

        $info = password_get_info($empreinte);
        self::assertSame('bcrypt', $info['algoName']);
        self::assertSame(5, $info['options']['cost'] ?? null);
    }

    public function test_elle_est_refaite_quand_le_cout_configure_change(): void
    {
        $cache = new Repository(new ArrayStore);
        $ancienne = $this->leurre($this->hacheur(4), $cache)->empreinte();

        $hacheur = $this->hacheur(5);
        $nouvelle = $this->leurre($hacheur, $cache)->empreinte();

        self::assertNotSame($ancienne, $nouvelle);
        self::assertSame(5, password_get_info($nouvelle)['options']['cost'] ?? null);
        self::assertSame(1, $hacheur->empreintesCalculees);

        // Et la nouvelle est gardée à son tour.
        self::assertSame($nouvelle, $this->leurre($hacheur, $cache)->empreinte());
        self::assertSame(1, $hacheur->empreintesCalculees);
    }

    public function test_un_cache_en_panne_ne_casse_pas_le_login(): void
    {
        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('get')->andThrow(new RuntimeException('cache injoignable'));
        $cache->shouldReceive('forever')->andThrow(new RuntimeException('cache injoignable'));

        $avertissements = [];
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldIgnoreMissing();
        $logger->shouldReceive('warning')->andReturnUsing(
            static function (string $message) use (&$avertissements): void {
                $avertissements[] = $message;
            }
        );

        $hacheur = $this->hacheur(4);
        $empreinte = $this->leurre($hacheur, $cache, $logger)->empreinte();

        // Une empreinte utilisable, calculée une seule fois malgré les deux pannes.
        self::assertSame('bcrypt', password_get_info($empreinte)['algoName']);
        self::assertFalse($hacheur->check('un-mot-de-passe', $empreinte));
        self::assertSame(1, $hacheur->empreintesCalculees);
        self::assertCount(2, $avertissements, 'La lecture et l\'écriture en panne doivent chacune se voir.');
    }
}
