<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Klassci;

use App\Services\Klassci\Concerns\HasKlassciEndpointShortcuts;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Issue #591 — garde **structurel** de la classification des endpoints KLASSCI.
 *
 * Le correctif #591 repose sur une règle : un endpoint dont KLASSCI fait varier
 * la réponse selon le porteur doit passer par `requestWithUserToken()` (clé de
 * cache dérivée du hash du porteur), jamais par `get()` (clé tenant-globale).
 *
 * Cette règle vivait dans un docblock. Un docblock ne s'exécute pas : le
 * prochain raccourci ajouté au trait pourrait appeler `$this->get(...)` et
 * rouvrir la fuite #568/#591 sans que PHP, PHPStan ni la CI ne bronchent.
 *
 * Ce test rend la règle **exécutable**, dans la lignée des gardes structurels
 * déjà en place dans le dépôt (`FileSizeGuardTest`,
 * `InstitutionRoutesPlatformGuardTest`).
 *
 * Il vérifie deux choses par réflexion sur chaque raccourci déclaré lié à
 * l'identité :
 *   1. la signature **exige** le porteur en premier paramètre (`string
 *      $userToken`, non optionnel) — l'appel non isolé est inécrivable ;
 *   2. le corps délègue bien à `requestWithUserToken()` et **pas** à `get()`.
 *
 * Ajouter un endpoint lié à l'identité au trait sans l'ajouter ici est possible
 * — aucun test ne peut deviner la sémantique d'un endpoint distant. En revanche,
 * dégrader un endpoint **déjà** classé (retirer le paramètre, revenir à `get()`)
 * casse cette garde immédiatement.
 */
#[CoversClass(HasKlassciEndpointShortcuts::class)]
final class KlassciEndpointClassificationGuardTest extends TestCase
{
    /**
     * Raccourcis dont la réponse KLASSCI dépend du porteur.
     *
     * @var list<string>
     */
    private const IDENTITY_SCOPED_SHORTCUTS = [
        'getEvaluations',
        'getEmploiTemps',
        'getMatieres',
        'getMatiereDetails',
        'getClasseEtudiants',
        'saveNotes',
        'savePresences',
        'updateCoursStatut',
    ];

    public function test_identity_scoped_shortcuts_require_the_bearer_token_first(): void
    {
        foreach (self::IDENTITY_SCOPED_SHORTCUTS as $shortcut) {
            $parameters = $this->shortcut($shortcut)->getParameters();

            self::assertNotSame([], $parameters, "{$shortcut}() doit exiger un porteur.");

            $first = $parameters[0];

            self::assertSame(
                'userToken',
                $first->getName(),
                "{$shortcut}() doit prendre le porteur en PREMIER paramètre (#591).",
            );
            self::assertSame(
                'string',
                (string) $first->getType(),
                "Le porteur de {$shortcut}() doit être un `string` non-nullable : "
                . 'un porteur optionnel rouvrirait le repli sur la clé tenant-globale.',
            );
            self::assertFalse(
                $first->isOptional(),
                "Le porteur de {$shortcut}() ne doit pas être optionnel — c'est ce qui "
                . "rend l'appel non isolé inécrivable (#591).",
            );
        }
    }

    public function test_identity_scoped_shortcuts_delegate_to_the_per_bearer_variant(): void
    {
        foreach (self::IDENTITY_SCOPED_SHORTCUTS as $shortcut) {
            $body = $this->shortcutBody($shortcut);

            self::assertStringContainsString(
                '$this->requestWithUserToken(',
                $body,
                "{$shortcut}() doit déléguer à requestWithUserToken() — sa clé de cache "
                . 'dérive du hash du porteur (generateUserTokenKey).',
            );
            self::assertStringNotContainsString(
                '$this->get(',
                $body,
                "{$shortcut}() ne doit PAS appeler get() : sa clé de cache serait "
                . "tenant-globale et la réponse du 1er appelant fuiterait à tout le "
                . 'tenant (#568, #591).',
            );
        }
    }

    private function shortcut(string $name): ReflectionMethod
    {
        self::assertTrue(
            method_exists(HasKlassciEndpointShortcuts::class, $name),
            "Le raccourci {$name}() a disparu du trait : mettre à jour cette garde "
            . 'en même temps que la classification.',
        );

        return new ReflectionMethod(HasKlassciEndpointShortcuts::class, $name);
    }

    /** Source du corps de la méthode, docblock exclu. */
    private function shortcutBody(string $name): string
    {
        $method = $this->shortcut($name);
        $file = (string) $method->getFileName();
        $lines = (array) file($file);

        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
    }
}
