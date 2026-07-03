<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controllers\Concerns;

use App\Http\Controllers\Concerns\RespondsWithJson;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Http\JsonEnvelopeProbe;
use Tests\TestCase;

/**
 * Axe #1 — Cas limites d'encodage du trait `RespondsWithJson`.
 *
 * Fige le comportement d'encodage RÉEL de l'enveloppe (mesuré sur PHP 8.3 /
 * Laravel 12) sur les axes qui cassent silencieusement les clients :
 * chaînes Unicode pathologiques, fuseaux horaires exotiques, précision des
 * flottants, forme objet-vs-tableau du JSON, en-têtes HTTP.
 *
 * Ces tests sont des sentinelles de contrat : si une montée de version Laravel
 * ou un changement d'options d'encodage modifie l'une de ces sorties, c'est un
 * breaking change client qui doit être détecté ICI, pas en production.
 */
#[CoversTrait(RespondsWithJson::class)]
final class RespondsWithJsonEncodingEdgeCasesTest extends TestCase
{
    private JsonEnvelopeProbe $probe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->probe = new JsonEnvelopeProbe;
    }

    // ----- Chaînes pathologiques (UTF-8 valide mais hostile) -----

    /**
     * @return array<string, array{string}>
     */
    public static function hostileUtf8Provider(): array
    {
        return [
            'émoji multi-codepoints (ZWJ)' => ['famille 👨‍👩‍👧‍👦'],
            'texte RTL arabe' => ['مرحبا بالعالم'],
            'caractère combinant (e + U+0301)' => ["e\u{0301}cole"],
            'séparateurs de ligne JS U+2028/U+2029' => ["ligne\u{2028}et\u{2029}paragraphe"],
            'null byte et caractères de contrôle' => ["a\x00b\x01c\nd\te"],
            'BOM en tête de chaîne' => ["\u{FEFF}contenu"],
            'plan astral (U+10FFFF max Unicode)' => ["fin \u{10FFFF}"],
        ];
    }

    #[DataProvider('hostileUtf8Provider')]
    public function test_utf8_valide_hostile_fait_l_aller_retour_sans_alteration(string $hostile): void
    {
        $payload = $this->probe->success(['text' => $hostile], $hostile)->getData(true);

        self::assertSame($hostile, $payload['data']['text']);
        self::assertSame($hostile, $payload['message']);
    }

    public function test_unicode_est_echappe_en_sequences_u_dans_le_flux_brut(): void
    {
        // Laravel encode avec options=0 : tout non-ASCII sort en \uXXXX. Les
        // séparateurs U+2028/U+2029 (mortels pour un vieux `eval` JS) et le null
        // byte voyagent donc échappés — jamais en octets bruts dans le flux HTTP.
        $content = $this->probe->success(['s' => "piège\u{2028}\x00"])->getContent();

        self::assertIsString($content);
        // chr(92) = antislash : la sequence attendue est construite dynamiquement
        // pour ne jamais placer d'octet de controle litteral dans ce fichier source.
        $backslash = chr(92);
        self::assertStringContainsString($backslash.'u2028', $content);
        self::assertStringContainsString($backslash.'u0000', $content);
        self::assertStringNotContainsString(chr(0), $content);
        self::assertStringNotContainsString("\u{2028}", $content);
    }

    public function test_html_n_est_pas_echappe_la_protection_xss_repose_sur_le_content_type(): void
    {
        // Mesuré : options=0 (pas JSON_HEX_TAG) → "<script>" part tel quel, seul
        // le "/" est échappé. Sans danger TANT QUE la réponse reste servie en
        // application/json (jamais interprétée comme HTML) — d'où le test d'en-tête.
        $response = $this->probe->success(['html' => '<script>alert(1)</script>']);

        self::assertSame('{"success":true,"data":{"html":"<script>alert(1)<\/script>"}}', $response->getContent());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
    }

    // ----- Fuseaux horaires exotiques -----

    /**
     * @return array<string, array{string, string}>
     */
    public static function exoticTimezoneProvider(): array
    {
        // Instant identique exprimé dans 3 fuseaux ; l'enveloppe doit toujours
        // émettre le MÊME instant, normalisé UTC (suffixe Z), microsecondes incluses.
        return [
            'Pacific/Kiritimati (UTC+14, extrême est)' => ['Pacific/Kiritimati', '2026-07-01T22:00:00.000000Z'],
            'Pacific/Chatham (UTC+12:45, offset non entier)' => ['Pacific/Chatham', '2026-07-01T23:15:00.000000Z'],
            'Pacific/Marquesas (UTC-9:30)' => ['Pacific/Marquesas', '2026-07-02T21:30:00.000000Z'],
        ];
    }

    #[DataProvider('exoticTimezoneProvider')]
    public function test_carbon_fuseau_exotique_est_normalise_en_utc(string $timezone, string $expectedUtc): void
    {
        // 12:00 heure LOCALE du fuseau — la sérialisation Laravel de Carbon
        // convertit en UTC : le client ne reçoit JAMAIS d'offset exotique.
        $localNoon = new Carbon('2026-07-02 12:00:00', $timezone);

        $payload = $this->probe->success(['at' => $localNoon])->getData(true);

        self::assertSame($expectedUtc, $payload['data']['at']);
    }

    public function test_transition_dst_ambigue_est_resolue_sans_erreur(): void
    {
        // 02:30 le 2026-10-25 à Paris existe DEUX fois (retour à l'heure d'hiver).
        // MESURÉ : PHP résout l'ambiguïté sur la SECONDE occurrence (offset
        // post-transition +01:00 → 01:30Z). On fige cette résolution pour
        // détecter tout changement de comportement du moteur de dates.
        $ambiguous = new Carbon('2026-10-25 02:30:00', 'Europe/Paris');

        $payload = $this->probe->success(['at' => $ambiguous])->getData(true);

        self::assertSame('2026-10-25T01:30:00.000000Z', $payload['data']['at']);
    }

    // ----- Précision des flottants -----

    public function test_flottants_finis_font_l_aller_retour_exactement(): void
    {
        // serialize_precision=-1 (défaut PHP 7.1+) : l'aller-retour double→JSON→double
        // est exact, y compris pour le classique 0.1+0.2.
        $floats = ['sum' => 0.1 + 0.2, 'tiny' => PHP_FLOAT_MIN, 'huge' => PHP_FLOAT_MAX];

        $payload = $this->probe->success($floats)->getData(true);

        self::assertSame($floats, $payload['data']);
    }

    public function test_entier_au_dela_de_2_53_en_float_perd_precision_et_type(): void
    {
        // DOUBLE PIÈGE MESURÉ : (1) 2^53+1 stocké en float est DÉJÀ arrondi à 2^53
        // avant même l'encodage (limite IEEE 754) ; (2) un float à valeur entière
        // est émis sans partie décimale ("...992" et non "...992.0"), donc
        // json_decode — et tout client typé — le relit comme un ENTIER. Les
        // identifiants > 9 007 199 254 740 992 doivent voyager en int PHP natif
        // (exact en 64 bits) ou en chaîne — jamais en float.
        $payload = $this->probe->success(['id_as_float' => 9007199254740993.0])->getData(true);

        self::assertSame(9007199254740992, $payload['data']['id_as_float']);
    }

    public function test_float_a_valeur_entiere_mute_en_int_a_travers_l_enveloppe(): void
    {
        // MESURÉ : json_encode(2.0) émet "2" — le type float ne survit pas à
        // l'aller-retour quand la valeur est entière. Un client typé strict
        // (SDK généré, Kotlin/Swift) qui attend un Double peut casser.
        $payload = $this->probe->success(['price' => 2.0])->getData(true);

        self::assertSame(2, $payload['data']['price']);
    }

    public function test_grand_entier_natif_reste_exact(): void
    {
        $payload = $this->probe->success(['id' => PHP_INT_MAX])->getData(true);

        self::assertSame(PHP_INT_MAX, $payload['data']['id']);
    }

    // ----- Forme du JSON : objet vs tableau, collisions, volume -----

    public function test_meta_a_cles_numeriques_emet_un_tableau_json_et_non_un_objet(): void
    {
        // PIÈGE DOCUMENTÉ : le contrat type `meta` comme objet, mais un tableau
        // PHP à clés séquentielles ["a","b"] devient un TABLEAU JSON. Un client
        // typé (SDK généré) qui attend un objet casse. Même piège pour `errors`.
        $content = $this->probe->success(['x' => 1], 'ok', 200, ['premier', 'second'])->getContent();

        self::assertIsString($content);
        self::assertStringContainsString('"meta":["premier","second"]', $content);
    }

    public function test_errors_a_cles_numeriques_emet_un_tableau_json(): void
    {
        $content = $this->probe->error('invalide', 422, ['err1', 'err2'])->getContent();

        self::assertIsString($content);
        self::assertStringContainsString('"errors":["err1","err2"]', $content);
    }

    public function test_data_contenant_les_cles_du_contrat_reste_imbriquee_sans_collision(): void
    {
        // data:{success:false} ne doit JAMAIS écraser le success:true de l'enveloppe.
        $trap = ['success' => false, 'message' => 'piège', 'errors' => ['x']];

        $payload = $this->probe->success($trap)->getData(true);

        self::assertTrue($payload['success']);
        self::assertArrayNotHasKey('errors', $payload);
        self::assertSame($trap, $payload['data']);
    }

    public function test_payload_d_un_mega_octet_est_transmis_integralement(): void
    {
        // Pas de troncature silencieuse sur les gros exports (listes de notes, etc.).
        $big = str_repeat('x', 1024 * 1024);

        $payload = $this->probe->success(['blob' => $big])->getData(true);

        self::assertSame(1024 * 1024, strlen($payload['data']['blob']));
        self::assertSame($big, $payload['data']['blob']);
    }
}
