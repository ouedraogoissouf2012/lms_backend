<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controllers\Concerns;

use App\Exceptions\UnserializablePayloadException;
use App\Http\Controllers\Concerns\RespondsWithJson;
use Illuminate\Http\JsonResponse;
use PHPUnit\Framework\Attributes\CoversTrait;
use Tests\TestCase;

/**
 * #693 — le RELAIS d'une enveloppe déjà construite par un service.
 *
 * ## Le motif recopié
 *
 * Les services rendent `array{status:int, payload:array}`. Mesuré sur `lms` le
 * 2026-09-14 : **47 occurrences dans 14 fichiers** de
 * `app/Http/Controllers/`, toutes de forme STRICTEMENT identique —
 *
 *     return response()->json($result['payload'], $result['status']);
 *
 * Aucune variante. L'issue mettait en garde contre « trois formes distinctes en
 * circulation » : elles n'existent plus sur ce chemin-là.
 *
 * ## Ce que le relais apporte, et ce qu'il n'apporte pas
 *
 * Il ne fait PAS gagner de lignes : une ligne pour une ligne. `ChapterController`
 * est à 170 pour une limite de 200 — la justification « le motif fait déborder
 * le fichier » ne tient plus.
 *
 * Ce qu'il apporte est ailleurs, et c'est mesurable : **le chemin de relais
 * échappait à `JsonPayloadGuard`**. Le dépôt a décidé en #360 qu'une `Closure`
 * dans un payload méritait une garde — `json_encode` l'encode silencieusement en
 * `{}`, produisant un 200 au payload vide sans aucun signal d'échec — puis a
 * laissé la moitié de ses réponses passer à côté :
 *
 *     json_encode(['data' => ['calcul' => fn () => 42]])
 *     → {"data":{"calcul":{}}}      200, donnée disparue, aucune erreur
 *
 * ## Ce que le relais ne doit SURTOUT pas faire
 *
 * Reformer. Les services placent déjà `success` / `data` / `message` dans
 * `payload`. Le faire transiter par `successResponse()` changerait la forme du
 * JSON et casserait le front. Le relais passe la charge telle quelle.
 *
 * @see app/Http/Controllers/Concerns/RespondsWithJson.php
 * @see app/Support/Http/JsonPayloadGuard.php
 */
#[CoversTrait(RespondsWithJson::class)]
final class RespondsWithJsonRelayTest extends TestCase
{
    /**
     * Sujet de test : expose le relais protégé du trait en public.
     */
    private function subject(): object
    {
        return new class
        {
            use RespondsWithJson;

            /**
             * @param  array{status: int, payload: array<string, mixed>}  $result
             */
            public function relay(array $result): JsonResponse
            {
                return $this->relayResponse($result);
            }
        };
    }

    public function test_il_relaie_la_charge_telle_quelle(): void
    {
        // La forme EXACTE qu'un service produit aujourd'hui.
        $reponse = $this->subject()->relay([
            'status' => 200,
            'payload' => ['success' => true, 'data' => ['id' => 7], 'message' => 'Chapitres récupérés'],
        ]);

        $this->assertSame(200, $reponse->status());
        $this->assertSame(
            ['success' => true, 'data' => ['id' => 7], 'message' => 'Chapitres récupérés'],
            $reponse->getData(true),
            'Le relais ne doit rien reformer : le front lit cette forme telle quelle.'
        );
    }

    public function test_il_ne_reordonne_ni_n_ajoute_aucune_cle(): void
    {
        // Un service qui n'émet ni `data` ni `message` doit rester tel quel.
        // Passer par successResponse() y ajouterait des clés absentes.
        $reponse = $this->subject()->relay([
            'status' => 204,
            'payload' => ['success' => true],
        ]);

        $this->assertSame(['success' => true], $reponse->getData(true));
    }

    public function test_il_relaie_le_statut_du_service_et_pas_un_defaut(): void
    {
        foreach ([201, 403, 404, 422, 500] as $statut) {
            $reponse = $this->subject()->relay([
                'status' => $statut,
                'payload' => ['success' => false, 'message' => 'x'],
            ]);

            $this->assertSame($statut, $reponse->status(), "Statut {$statut} non relayé.");
        }
    }

    public function test_une_closure_dans_la_charge_est_refusee(): void
    {
        // LE gain de ce refactor. Sans la garde : 200, `{"calcul":{}}`, et
        // personne ne sait que la donnée a disparu.
        $this->expectException(UnserializablePayloadException::class);

        $this->subject()->relay([
            'status' => 200,
            'payload' => ['success' => true, 'data' => ['calcul' => fn () => 42]],
        ]);
    }

    public function test_une_closure_profondement_enfouie_est_refusee_aussi(): void
    {
        $this->expectException(UnserializablePayloadException::class);

        $this->subject()->relay([
            'status' => 200,
            'payload' => ['data' => ['lignes' => [['meta' => ['calcul' => fn () => 1]]]]],
        ]);
    }

    public function test_un_objet_traverse_sans_etre_inspecte(): void
    {
        // La garde ne descend QUE dans les tableaux — c'est ce qui rend son coût
        // négligeable sur le cas courant, où `payload.data` est une Collection
        // Eloquent. Mesuré : 18 µs par appel, contre 4,98 ms sur 200 lignes en
        // tableau brut. Aucun service n'émet cette seconde forme aujourd'hui.
        $objet = new \stdClass;
        $objet->id = 3;

        $reponse = $this->subject()->relay([
            'status' => 200,
            'payload' => ['success' => true, 'data' => $objet],
        ]);

        $this->assertSame(200, $reponse->status());
        $this->assertSame(['success' => true, 'data' => ['id' => 3]], $reponse->getData(true));
    }
}
