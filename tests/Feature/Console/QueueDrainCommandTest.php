<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\Scheduler\QueueDrainCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class QueueDrainCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Budget mémoire volontairement large.
     *
     * `queue:drain` lance `queue:work` dans le processus courant : le worker compte
     * la mémoire déjà consommée par PHPUnit dans son budget. Sans cette surcharge, le
     * test mesure la consommation de la suite — donc son propre rang d'exécution —
     * plutôt que le comportement de la commande, et devient intermittent.
     */
    private const TEST_MEMORY_MB = 2048;

    public function test_successful_empty_drain_records_worker_heartbeat(): void
    {
        config(['queue.default' => 'database']);
        Cache::forget(QueueDrainCommand::HEARTBEAT_KEY);

        // --max-time court : la queue est vide, inutile d'immobiliser la CI 55 s.
        $this->artisan('queue:drain', [
            '--memory'   => (string) self::TEST_MEMORY_MB,
            '--max-time' => '5',
        ])->assertSuccessful();

        self::assertIsString(Cache::get(QueueDrainCommand::HEARTBEAT_KEY));
    }

    /**
     * Le défaut doit rester sous le `memory_limit` PHP de production (256 Mo) :
     * un worker qui ne s'arrête jamais de lui-même finit tué par PHP, sans
     * enregistrer son heartbeat — la supervision croit alors la queue en panne.
     */
    public function test_default_memory_limit_stays_below_php_limit(): void
    {
        $reflection = new \ReflectionClass(QueueDrainCommand::class);
        $default = $reflection->getConstant('DEFAULT_MEMORY_MB');

        self::assertIsInt($default);
        self::assertGreaterThan(128, $default, 'Le défaut Laravel de 128 Mo est trop bas ici.');
        self::assertLessThan(256, $default, 'Doit rester sous le memory_limit PHP de production.');
    }
}
