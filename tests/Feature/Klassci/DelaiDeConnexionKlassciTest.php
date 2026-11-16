<?php

declare(strict_types=1);

namespace Tests\Feature\Klassci;

use App\Services\AdminAnalytics\KlassciBackpressureMetricsService;
use App\Services\Klassci\KlassciBatchFetcher;
use App\Services\Klassci\KlassciHttpClient;
use App\Services\Klassci\KlassciTransportRetry;
use ReflectionProperty;
use Tests\TestCase;

/**
 * #744 — un SYN avalé ne coûte plus 2 s par tentative.
 *
 * L'hébergeur de KLASSCI avale les SYN après une rafale de connexions. Un SYN
 * avalé consomme le délai de connexion ENTIER, à chaque tentative : avec 3
 * tentatives sur une lecture (#746), une lecture vouée au 503 attendait environ
 * 3 × 2 s. Une connexion saine depuis la production prend 0,017 s (mesure du
 * 2026-09-06) : 1 s en garde une marge d'environ 50 fois, et ramène l'échec à
 * environ 3 × 1 s. Une lecture qui aboutit n'est pas touchée.
 *
 * La valeur a UNE source, `KlassciTransportRetry::CONNECT_TIMEOUT_SECONDS`. La
 * configuration et les trois lecteurs s'y réfèrent : avant, quatre « 2 » écrits
 * en dur devaient rester alignés.
 */
final class DelaiDeConnexionKlassciTest extends TestCase
{
    public function test_le_delai_de_connexion_par_defaut_est_d_une_seconde(): void
    {
        self::assertSame(1, KlassciTransportRetry::CONNECT_TIMEOUT_SECONDS);
        self::assertSame(1, (int) config('services.klassci.connect_timeout'));
    }

    /**
     * Sans la clé de configuration, chaque lecteur se replie sur la même source,
     * et non sur un « 2 » qui lui serait propre.
     */
    public function test_les_lecteurs_se_replient_sur_la_meme_source(): void
    {
        $klassci = config('services.klassci');
        self::assertIsArray($klassci);
        unset($klassci['connect_timeout']);
        config()->set('services.klassci', $klassci);

        self::assertSame(1, $this->delai(app(KlassciHttpClient::class)));
        self::assertSame(1, $this->delai(app(KlassciBatchFetcher::class)));
        self::assertSame(1, app(KlassciBackpressureMetricsService::class)->snapshot()['connect_timeout_seconds']);
    }

    private function delai(object $lecteur): int
    {
        $valeur = (new ReflectionProperty($lecteur, 'connectTimeout'))->getValue($lecteur);
        self::assertIsInt($valeur);

        return $valeur;
    }
}
