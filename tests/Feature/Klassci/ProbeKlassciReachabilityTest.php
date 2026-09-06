<?php

declare(strict_types=1);

namespace Tests\Feature\Klassci;

use App\Models\Institution;
use App\Services\Klassci\Health\HttpKlassciReachability;
use App\Services\Klassci\Health\InMemoryKlassciReachability;
use App\Services\Klassci\Health\KlassciReachability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;
use Tests\TestCase;

/**
 * La sonde de joignabilité KLASSCI (#744).
 *
 * ## Ce qu'elle existe pour produire
 *
 * L'hébergeur de KLASSCI avale les SYN par salves. Pour obtenir une mise en
 * liste blanche, il réclame des **horodatages précis** — et nous n'en avions
 * aucun : aucune mesure de fond n'était prise, et le disjoncteur ne trace que
 * les incidents déjà graves.
 *
 * Cette commande relève, à intervalle régulier, le temps de CONNEXION vers
 * chaque URL KLASSCI active. C'est la mesure décisive : un `connect` qui expire
 * signifie un SYN sans réponse — une règle de pare-feu — là où un code HTTP,
 * même 404, prouve que le serveur répond.
 *
 * ## Pourquoi la mesure passe par une interface
 *
 * Pour que ce fichier soit exécutable **ici**. Le seul test du dépôt qui
 * traverse réellement le transport se saute sous Windows (`Http::fake` +
 * `ConnectionException` y termine le processus). Une sonde qui appellerait le
 * réseau en dur ne serait vérifiable qu'en CI. Le fake en mémoire (§1.6 L)
 * rend la logique de la commande falsifiable sans réseau.
 */
final class ProbeKlassciReachabilityTest extends TestCase
{
    use RefreshDatabase;

    private ProbeLogSpy $journal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->journal = new ProbeLogSpy;
        $this->app->instance(LoggerInterface::class, $this->journal);
    }

    /**
     * Le cas nominal : une cible joignable produit une mesure exploitable.
     */
    public function test_a_reachable_target_is_measured_and_logged(): void
    {
        $this->institution('esbtp-abidjan', 'https://klassci.test/api/lms');
        $this->withReachability((new InMemoryKlassciReachability)->reachable('https://klassci.test/api/lms', connectMs: 17, status: 200));

        $this->artisan('klassci:probe')->assertExitCode(0);

        $mesures = $this->journal->matching('KLASSCI reachability');
        self::assertCount(1, $mesures);
        self::assertSame('info', $mesures[0]['level']);
        self::assertSame(17, $mesures[0]['context']['connect_ms']);
        self::assertSame('esbtp-abidjan', $mesures[0]['context']['institution']);
    }

    /**
     * LE cas qui justifie la sonde : une cible injoignable doit produire une
     * trace de niveau `warning`, datée, exploitable dans un dossier.
     */
    public function test_an_unreachable_target_is_logged_as_a_warning(): void
    {
        $this->institution('esbtp-abidjan', 'https://klassci.test/api/lms');
        $this->withReachability((new InMemoryKlassciReachability)->unreachable('https://klassci.test/api/lms', 'cURL error 28'));

        $this->artisan('klassci:probe')->assertExitCode(0);

        $mesures = $this->journal->matching('KLASSCI reachability');
        self::assertCount(1, $mesures);
        self::assertSame('warning', $mesures[0]['level']);
        self::assertFalse($mesures[0]['context']['reachable']);
    }

    /**
     * Une cible injoignable ne doit PAS faire échouer la commande.
     *
     * La sonde est un instrument de mesure, pas une garde : un code de sortie
     * non nul ferait remonter le planificateur en erreur à chaque salve de
     * filtrage, et l'on finirait par ignorer le signal — exactement ce qu'on
     * cherche à éviter.
     */
    public function test_the_probe_never_fails_the_scheduler(): void
    {
        $this->institution('esbtp-abidjan', 'https://klassci.test/api/lms');
        $this->withReachability((new InMemoryKlassciReachability)->unreachable('https://klassci.test/api/lms', 'injoignable'));

        $this->artisan('klassci:probe')->assertExitCode(0);
    }

    /**
     * Chaque institution active est mesurée séparément : le filtrage peut ne
     * frapper qu'un seul hôte.
     */
    public function test_every_active_institution_is_probed(): void
    {
        $this->institution('a', 'https://a.test/api/lms');
        $this->institution('b', 'https://b.test/api/lms');

        $this->withReachability(
            (new InMemoryKlassciReachability)
                ->reachable('https://a.test/api/lms', connectMs: 12, status: 200)
                ->unreachable('https://b.test/api/lms', 'cURL error 28')
        );

        $this->artisan('klassci:probe')->assertExitCode(0);

        $parInstitution = [];
        foreach ($this->journal->matching('KLASSCI reachability') as $m) {
            $parInstitution[$m['context']['institution']] = $m['context']['reachable'];
        }

        self::assertSame(['a' => true, 'b' => false], $parInstitution);
    }

    /**
     * Une institution désactivée est hors périmètre : la sonder produirait du
     * bruit sur une cible qu'aucun utilisateur n'atteint.
     */
    public function test_an_inactive_institution_is_skipped(): void
    {
        $this->institution('dormante', 'https://dormante.test/api/lms', active: false);
        $this->withReachability(new InMemoryKlassciReachability);

        $this->artisan('klassci:probe')->assertExitCode(0);

        self::assertSame([], $this->journal->matching('KLASSCI reachability'));
    }

    /**
     * Le câblage de production, que rien ne vérifiait.
     *
     * Tous les tests ci-dessus INJECTENT le double : ils passeraient même si
     * l'interface n'était liée à aucune implémentation — et `klassci:probe`
     * planterait alors en production sur une interface non instanciable. Ce test
     * est le seul à exercer la résolution réelle du conteneur.
     */
    public function test_the_container_resolves_the_real_http_probe(): void
    {
        self::assertInstanceOf(
            HttpKlassciReachability::class,
            $this->app->make(KlassciReachability::class),
            'Sans liaison, la commande planterait au démarrage en production.',
        );
    }

    // ───────────────────── Fixtures ─────────────────────

    private function institution(string $slug, string $url, bool $active = true): Institution
    {
        return Institution::factory()->create([
            'slug' => $slug,
            'klassci_api_url' => $url,
            'is_active' => $active,
        ]);
    }

    private function withReachability(KlassciReachability $sonde): void
    {
        $this->app->instance(KlassciReachability::class, $sonde);
    }
}

/**
 * Journal d'essai : conserve chaque entrée pour pouvoir l'interroger.
 */
final class ProbeLogSpy extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $entries = [];

    /**
     * @param  mixed  $level
     * @param  string|Stringable  $message
     * @param  array<string, mixed>  $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->entries[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function matching(string $fragment): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (array $e): bool => str_contains($e['message'], $fragment),
        ));
    }
}
