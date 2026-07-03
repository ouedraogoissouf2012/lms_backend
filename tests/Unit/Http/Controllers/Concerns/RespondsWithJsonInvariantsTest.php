<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controllers\Concerns;

use App\Http\Controllers\Concerns\RespondsWithJson;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Http\JsonEnvelopeProbe;
use Tests\TestCase;

/**
 * Axe #1 — Invariants du trait `RespondsWithJson` (tests de propriété).
 *
 * Vérifie que le contrat canonique (spec `.claude/specs/api-response-envelope/`
 * design §4) tient pour TOUT le domaine d'entrée JSON-sûr, pas seulement pour
 * les exemples du happy path :
 *
 * - I1 : `success` est toujours présent, en première position, et vaut le bon booléen.
 * - I2 : `message` présent ⟺ `message !== ''` (succès) / toujours présent (erreur).
 * - I3 : `data` présent ⟺ `data !== null` — y compris pour les valeurs falsy
 *        (`false`, `0`, `''`, `[]`, `'0'`), pièges classiques d'un `empty()` mal placé.
 * - I4 : `meta`/`errors` présents ⟺ tableau non vide.
 * - I5 : le statut HTTP passé est restitué tel quel (aucune réécriture).
 * - I6 : `data` fait l'aller-retour d'encodage sans altération (assertSame).
 * - I7 : aucune clé parasite hors du contrat.
 *
 * Pas de lib property-based (Eris absent de composer.json) : le corpus est
 * produit par un LCG de Lehmer embarqué, à graine FIXE — même séquence à chaque
 * exécution, sur tout moteur PHP (déterminisme exigé par PRODUCTION_STANDARDS §5 :
 * pas de flakiness). Un échec affiche l'itération fautive pour rejouer le cas.
 */
#[CoversTrait(RespondsWithJson::class)]
final class RespondsWithJsonInvariantsTest extends TestCase
{
    private const CORPUS_SIZE = 200;

    private const ENVELOPE_KEY_ORDER = ['success', 'message', 'data', 'meta'];

    private JsonEnvelopeProbe $probe;

    /** État du générateur pseudo-aléatoire déterministe (LCG de Lehmer). */
    private int $lcgState = 20260702;

    protected function setUp(): void
    {
        parent::setUp();
        $this->probe = new JsonEnvelopeProbe;
    }

    // ----- Propriétés sur corpus généré -----

    public function test_invariants_succes_sur_corpus_genere(): void
    {
        for ($i = 0; $i < self::CORPUS_SIZE; $i++) {
            $data = $this->randomJsonSafeValue(depth: 0);
            $message = $this->randomMessage();
            $status = 100 + $this->nextInt(500); // 100..599 : toute la plage valide
            $meta = $this->nextInt(3) === 0 ? ['page' => $this->nextInt(100)] : [];

            $payload = $this->probe->success($data, $message, $status, $meta)->getData(true);
            $context = sprintf('itération %d — data=%s message=%s status=%d', $i, json_encode($data), json_encode($message), $status);

            // I1 + I7 : uniquement les clés du contrat, dans l'ordre canonique.
            $expectedKeys = array_values(array_filter(self::ENVELOPE_KEY_ORDER, fn (string $key): bool => match ($key) {
                'success' => true,
                'message' => $message !== '',
                'data' => $data !== null,
                'meta' => $meta !== [],
            }));
            self::assertSame($expectedKeys, array_keys($payload), $context);
            self::assertTrue($payload['success'], $context);

            // I5 : statut restitué tel quel.
            self::assertSame($status, $this->probe->success($data, $message, $status, $meta)->status(), $context);

            // I6 : aller-retour sans altération (types et valeurs).
            if ($data !== null) {
                self::assertSame($data, $payload['data'], $context);
            }
            if ($message !== '') {
                self::assertSame($message, $payload['message'], $context);
            }
        }
    }

    public function test_invariants_erreur_sur_corpus_genere(): void
    {
        for ($i = 0; $i < self::CORPUS_SIZE; $i++) {
            $message = $this->randomMessage();
            $status = 400 + $this->nextInt(200); // 400..599 : plage d'erreur valide
            $errors = $this->nextInt(2) === 0
                ? ['field_'.$this->nextInt(10) => ['règle violée '.$this->nextInt(10)]]
                : [];

            $payload = $this->probe->error($message, $status, $errors)->getData(true);
            $context = sprintf('itération %d — message=%s status=%d', $i, json_encode($message), $status);

            // Contrat erreur : success=false et message TOUJOURS présents (même '').
            $expectedKeys = $errors === [] ? ['success', 'message'] : ['success', 'message', 'errors'];
            self::assertSame($expectedKeys, array_keys($payload), $context);
            self::assertFalse($payload['success'], $context);
            self::assertSame($message, $payload['message'], $context);

            if ($errors !== []) {
                self::assertSame($errors, $payload['errors'], $context);
            }
        }
    }

    // ----- Pièges falsy : la présence de `data` dépend de `!== null`, pas de truthiness -----

    /**
     * @return array<string, array{mixed, mixed}>
     */
    public static function falsyButNotNullDataProvider(): array
    {
        // [valeur envoyée, valeur relue après aller-retour JSON]. Seul 0.0 diffère :
        // json_encode(0.0) émet "0", relu en int (mutation de type mesurée, voir
        // RespondsWithJsonEncodingEdgeCasesTest sur les floats à valeur entière).
        return [
            'false' => [false, false],
            'zéro entier' => [0, 0],
            'zéro flottant (mute en int)' => [0.0, 0],
            'chaîne vide' => ['', ''],
            'chaîne "0"' => ['0', '0'],
            'tableau vide' => [[], []],
        ];
    }

    #[DataProvider('falsyButNotNullDataProvider')]
    public function test_data_falsy_non_null_reste_presente(mixed $falsy, mixed $expectedDecoded): void
    {
        // Régression guettée : remplacer `!== null` par `empty()` ou un cast bool
        // ferait disparaître ces payloads légitimes (ex. `data: false` d'un toggle).
        $payload = $this->probe->success($falsy)->getData(true);

        self::assertArrayHasKey('data', $payload);
        self::assertSame($expectedDecoded, $payload['data']);
    }

    public function test_message_chaine_zero_est_conserve(): void
    {
        // "0" est falsy en PHP mais N'EST PAS une chaîne vide : la clé doit rester.
        $payload = $this->probe->success(null, '0')->getData(true);

        self::assertSame(['success' => true, 'message' => '0'], $payload);
    }

    public function test_message_espaces_seuls_est_conserve(): void
    {
        // Seule la chaîne strictement vide est omise — pas de trim() implicite.
        $payload = $this->probe->success(null, '   ')->getData(true);

        self::assertSame(['success' => true, 'message' => '   '], $payload);
    }

    public function test_erreur_avec_message_vide_emet_quand_meme_la_cle(): void
    {
        // Asymétrie assumée du contrat : côté erreur, `message` n'est jamais omis.
        // Un caller qui passe '' produit {"success":false,"message":""} — piège
        // documenté, à intercepter en revue de code côté caller.
        $payload = $this->probe->error('')->getData(true);

        self::assertSame(['success' => false, 'message' => ''], $payload);
    }

    // ----- Générateur déterministe (LCG de Lehmer, graine fixe) -----

    /**
     * Entier pseudo-aléatoire déterministe dans [0, $bound).
     */
    private function nextInt(int $bound): int
    {
        // Paramètres MINSTD (Park-Miller) : période 2^31-2, portable, sans mt_rand
        // dont la séquence pourrait varier entre moteurs/versions PHP.
        $this->lcgState = ($this->lcgState * 48271) % 2147483647;

        return $this->lcgState % $bound;
    }

    /**
     * Valeur JSON-sûre : scalaires (dont falsy), chaînes Unicode, tableaux imbriqués.
     */
    private function randomJsonSafeValue(int $depth): mixed
    {
        // Au-delà de 3 niveaux, on force un scalaire pour borner la taille du cas.
        $choice = $depth >= 3 ? $this->nextInt(8) : $this->nextInt(10);

        return match ($choice) {
            0 => null,
            1 => $this->nextInt(2) === 0,
            2 => $this->nextInt(1_000_000) - 500_000,
            3 => ($this->nextInt(1_000_000) - 500_000) / 1024,
            4 => 'ascii_'.$this->nextInt(1000),
            5 => ['🔥 émoji', 'مرحبا (RTL)', "combiné e\u{0301}", "séparateur\u{2028}JS"][$this->nextInt(4)],
            6 => '',
            7 => '0',
            8 => array_map(fn (): mixed => $this->randomJsonSafeValue($depth + 1), range(1, 1 + $this->nextInt(3))),
            9 => ['clef_'.$this->nextInt(100) => $this->randomJsonSafeValue($depth + 1)],
        };
    }

    private function randomMessage(): string
    {
        return ['', '0', '   ', 'Opération réussie', 'Süppression effectuée 🗑️'][$this->nextInt(5)];
    }
}
