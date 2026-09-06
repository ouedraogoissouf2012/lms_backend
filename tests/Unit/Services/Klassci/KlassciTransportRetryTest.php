<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Klassci;

use App\Services\Klassci\KlassciTransportRetry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La politique de réessai du transport KLASSCI — et surtout ce qu'elle REFUSE
 * de réessayer.
 *
 * ## Pourquoi une politique séparée du client
 *
 * Deux raisons, dont une décisive.
 *
 * 1. **Elle est falsifiable ici.** Le seul test qui traverse réellement le
 *    transport (`KlassciHttpClientTest::test_transport_failure…`) se saute sous
 *    Windows : `Http::fake` + `ConnectionException` y termine le processus. Une
 *    règle de sécurité enfouie dans la boucle du client ne serait donc vérifiable
 *    qu'en CI — c'est-à-dire jamais pendant qu'on l'écrit.
 * 2. La décision « rejouer ou non » est une règle métier de sûreté, pas de la
 *    plomberie HTTP.
 *
 * ## LA règle de sûreté, et le piège qui l'impose
 *
 * On pourrait croire qu'une `ConnectionException` prouve que la requête n'est
 * jamais partie, et qu'on peut donc tout rejouer sans risque. **C'est faux.**
 *
 * Vérifié dans le code de Guzzle : `CurlFactory::createRejection()` classe
 * `CURLE_OPERATION_TIMEOUTED` (code 28) parmi les `$connectionErrors`, et le
 * traduit donc en `ConnectException` — que Laravel enveloppe à son tour en
 * `ConnectionException`. Or le code 28 couvre AUSSI un délai de **lecture** :
 * la requête est alors bien arrivée, et KLASSCI l'a peut-être traitée.
 *
 * Rejouer un `POST evaluations/{id}/notes` dans ce cas enregistrerait les notes
 * **deux fois**. Seul `GET` est rejoué : c'est la seule méthode dont on garantit
 * qu'elle est sans effet de bord dans nos appels.
 */
#[CoversClass(KlassciTransportRetry::class)]
final class KlassciTransportRetryTest extends TestCase
{
    /**
     * LE défaut corrigé : un SYN avalé ne doit plus condamner l'appel. Mesuré en
     * production le 2026-09-06 — un essai échouait à 11,2 s, les deux suivants
     * aboutissaient en 17 ms.
     */
    public function test_a_get_is_retried(): void
    {
        self::assertGreaterThan(
            1,
            $this->policy()->attemptsFor('GET'),
            'Sans réessai, un unique SYN avalé suffit à faire échouer une lecture.',
        );
    }

    /**
     * LA garantie de sûreté. Un délai de LECTURE remonte aussi en
     * `ConnectionException` (cURL 28) : la requête peut avoir été traitée.
     * Rejouer une écriture dupliquerait des notes ou des présences.
     *
     * @param  string  $method  méthode HTTP porteuse d'effet de bord
     */
    #[DataProvider('methodesAvecEffetDeBord')]
    public function test_a_write_is_never_replayed(string $method): void
    {
        self::assertSame(
            1,
            $this->policy()->attemptsFor($method),
            "Rejouer un {$method} dupliquerait un effet de bord déjà appliqué chez KLASSCI.",
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function methodesAvecEffetDeBord(): array
    {
        return [
            'POST (notes, présences)' => ['POST'],
            'PUT' => ['PUT'],
            'DELETE' => ['DELETE'],
        ];
    }

    /**
     * La casse ne doit pas ouvrir une brèche : un `post` en minuscules reste une
     * écriture.
     */
    public function test_the_method_is_compared_case_insensitively(): void
    {
        self::assertSame(1, $this->policy()->attemptsFor('post'));
        self::assertSame($this->policy()->attemptsFor('GET'), $this->policy()->attemptsFor('get'));
    }

    /**
     * Une méthode inconnue est traitée comme une écriture : en cas de doute, on
     * ne rejoue pas.
     */
    public function test_an_unknown_method_is_treated_as_a_write(): void
    {
        self::assertSame(1, $this->policy()->attemptsFor('PATCH'));
    }

    /**
     * Le nombre de tentatives est borné : réessayer indéfiniment reviendrait à
     * marteler l'hôte qui nous filtre déjà, et à aggraver la cause.
     */
    public function test_the_attempt_count_stays_small(): void
    {
        self::assertLessThanOrEqual(
            3,
            $this->policy()->attemptsFor('GET'),
            'Marteler l\'hôte qui filtre sur le volume aggraverait la cause du problème.',
        );
    }

    /**
     * La temporisation existe et croît. Rejouer dans la milliseconde retomberait
     * dans la même fenêtre de filtrage.
     */
    public function test_the_backoff_grows_between_attempts(): void
    {
        $policy = $this->policy();

        $premier = $policy->backoffMicroseconds(1);
        $second = $policy->backoffMicroseconds(2);

        self::assertGreaterThan(0, $premier, 'Rejouer instantanément retomberait dans la même salve.');
        self::assertGreaterThan($premier, $second);
    }

    /**
     * Mais elle reste courte : un humain attend cette réponse. Le budget total
     * des pauses doit rester très en deçà du délai de connexion lui-même.
     */
    public function test_the_backoff_stays_imperceptible(): void
    {
        $policy = $this->policy();

        $total = 0;
        for ($i = 1; $i < $policy->attemptsFor('GET'); $i++) {
            $total += $policy->backoffMicroseconds($i);
        }

        self::assertLessThan(
            1_000_000,
            $total,
            'Le cumul des pauses doit rester sous la seconde : un utilisateur attend.',
        );
    }

    private function policy(): KlassciTransportRetry
    {
        return new KlassciTransportRetry;
    }
}
