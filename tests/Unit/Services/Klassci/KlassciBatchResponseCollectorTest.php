<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Klassci;

use App\Services\Klassci\KlassciBatchResponseCollector;
use App\Services\Klassci\KlassciRequestMemo;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\AbstractLogger;
use Stringable;
use Tests\TestCase;

/**
 * Le tri des réponses d'un pool KLASSCI (#744, dernier point).
 *
 * ## Les deux échecs que le code confondait
 *
 * `KlassciBatchFetcher` traitait identiquement — log `error` puis abandon de
 * l'identifiant — deux situations qui n'ont rien à voir :
 *
 * - une **panne de transport** (SYN avalé par le filtrage de l'hébergeur) :
 *   transitoire, et un second essai aboutit en 17 ms ;
 * - une **réponse HTTP d'erreur** (403, 404) : une réponse, pas une panne.
 *   La rejouer ne changerait rien.
 *
 * Conséquence mesurable : un SYN avalé pendant le pool `classes/{id}` faisait
 * afficher un effectif de classe à **0**, sans que rien ne le signale.
 *
 * ## Pourquoi ce tri est testable sans réseau
 *
 * Vérifié dans Laravel : `PendingRequest::promise()` intercepte le rejet et
 * **retourne** l'exception comme valeur (`->otherwise(... return $exception)`).
 * Le tableau du pool contient donc un `ConnectionException` là où une `Response`
 * était attendue — un objet que ce test fabrique directement, sans le moindre
 * appel HTTP. C'est ce qui rend la règle falsifiable ici, alors que le seul test
 * traversant le transport se saute sous Windows.
 */
#[CoversClass(KlassciBatchResponseCollector::class)]
final class KlassciBatchResponseCollectorTest extends TestCase
{
    private BatchLogSpy $journal;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::store('array')->flush();
        $this->journal = new BatchLogSpy;
    }

    /**
     * LE défaut : un échec de transport doit être signalé comme rejouable, pas
     * abandonné en silence.
     */
    public function test_a_transport_failure_is_reported_as_retryable(): void
    {
        $aRejouer = $this->collect(
            batch: [101 => 'classes/101'],
            responses: [101 => new ConnectionException('cURL error 28')],
            resolved: $resolved,
        );

        self::assertSame([101], $aRejouer, 'Un SYN avalé est transitoire : il doit pouvoir être rejoué.');
        self::assertSame([], $resolved);
    }

    /**
     * LA distinction. Une réponse HTTP d'erreur est une RÉPONSE : KLASSCI a
     * parlé. La rejouer ne changerait rien et doublerait la charge sur un hôte
     * qui filtre déjà sur le volume.
     */
    public function test_an_http_error_is_never_retried(): void
    {
        $aRejouer = $this->collect(
            batch: [101 => 'classes/101'],
            responses: [101 => $this->httpResponse(404)],
            resolved: $resolved,
        );

        self::assertSame([], $aRejouer, 'Un 404 est une réponse, pas une panne de transport.');
        self::assertSame([], $resolved);
    }

    /**
     * Le cas nominal reste intact : une réponse OK alimente le résultat.
     */
    public function test_a_successful_response_is_collected(): void
    {
        $aRejouer = $this->collect(
            batch: [101 => 'classes/101'],
            responses: [101 => $this->httpResponse(200, ['data' => ['places_occupees' => 30]])],
            resolved: $resolved,
        );

        self::assertSame([], $aRejouer);
        self::assertSame(['data' => ['places_occupees' => 30]], $resolved[101]);
    }

    /**
     * Un identifiant absent du tableau du pool n'a pas obtenu de réponse : c'est
     * un échec de transport, donc rejouable. L'omettre serait le perdre.
     */
    public function test_a_missing_entry_counts_as_a_transport_failure(): void
    {
        $aRejouer = $this->collect(
            batch: [101 => 'classes/101'],
            responses: [],
            resolved: $resolved,
        );

        self::assertSame([101], $aRejouer);
    }

    /**
     * Un lot mixte trie correctement : seuls les échecs de transport repartent.
     */
    public function test_a_mixed_batch_is_sorted_correctly(): void
    {
        $aRejouer = $this->collect(
            batch: [101 => 'classes/101', 102 => 'classes/102', 103 => 'classes/103'],
            responses: [
                101 => $this->httpResponse(200, ['data' => 'ok']),
                102 => new ConnectionException('cURL error 28'),
                103 => $this->httpResponse(403),
            ],
            resolved: $resolved,
        );

        self::assertSame([102], $aRejouer);
        self::assertSame([101], array_keys($resolved));
    }

    /**
     * Niveaux de journalisation, hérités de l'issue #256 : un 4xx est une
     * réponse attendue (`warning`), un 5xx une panne KLASSCI (`error`). Les
     * logger tous en `error` est précisément ce qui avait produit 239 Mo de
     * journaux.
     */
    public function test_a_4xx_is_a_warning_and_a_5xx_an_error(): void
    {
        $this->collect([101 => 'classes/101'], [101 => $this->httpResponse(404)], $r1);
        $this->collect([102 => 'classes/102'], [102 => $this->httpResponse(500)], $r2);

        self::assertSame('warning', $this->journal->entries[0]['level']);
        self::assertSame('error', $this->journal->entries[1]['level']);
    }

    // ───────────────────── Fixtures ─────────────────────

    /**
     * @param  array<int, string>  $batch  identifiant => endpoint
     * @param  array<int, mixed>  $responses
     * @param  array<int, array<string, mixed>>|null  $resolved
     * @return list<int>
     */
    private function collect(array $batch, array $responses, ?array &$resolved): array
    {
        $resolved = [];

        $metas = [];
        $parCle = [];
        foreach ($batch as $id => $endpoint) {
            $metas[$id] = [
                'endpoint' => $endpoint,
                'memoKey' => "memo:{$id}",
                'cacheKey' => "cache:{$id}",
            ];
        }
        foreach ($responses as $id => $reponse) {
            $parCle[(string) $id] = $reponse;
        }

        $collector = new KlassciBatchResponseCollector(
            Cache::store('array'),
            new KlassciRequestMemo,
            $this->journal,
        );

        return $collector->collect($metas, $parCle, 600, $resolved);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function httpResponse(int $status, array $payload = []): Response
    {
        return new Response(new Psr7Response($status, [], (string) json_encode($payload)));
    }
}

/**
 * Journal d'essai : conserve chaque entrée pour pouvoir l'interroger.
 */
final class BatchLogSpy extends AbstractLogger
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
}
