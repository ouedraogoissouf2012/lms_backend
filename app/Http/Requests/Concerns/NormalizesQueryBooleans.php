<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

/**
 * Accepte un booléen tel qu'une chaîne de requête peut le porter.
 *
 * ## Le défaut que ce concern supprime
 *
 * Une URL ne transporte QUE du texte : `?unread_only=false` arrive comme la
 * chaîne `'false'`. Or la règle `boolean` de Laravel n'accepte que
 * `[true, false, 0, 1, '0', '1']`
 * ({@see \Illuminate\Validation\Concerns\ValidatesAttributes::validateBoolean()}) —
 * `'false'` et `'true'` en sont EXCLUS.
 *
 * Mesuré le 2026-09-09 dans le navigateur : à chaque chargement du tableau de
 * bord enseignant, `GET /api/notifications?...&unread_only=false` rendait un
 * **422**, et la liste des notifications restait vide sans que rien ne le
 * signale.
 *
 * Exiger `?flag=0` d'un client HTTP est un contrat que le transport ne peut pas
 * honorer naturellement : `axios`, `fetch`, `curl` et les SDK générés
 * sérialisent tous un booléen en `'true'` / `'false'`.
 *
 * ## Pourquoi un concern, et pas une copie par requête
 *
 * Deux `FormRequest` portaient déjà la même règle sur une route GET
 * (`ListNotificationsRequest`, `ListEvaluationsRequest`), et la prochaine
 * l'aurait recopiée. Le dépôt trace déjà cette famille de duplication (#738 :
 * `positiveInt` recopié dans six fichiers, sans test). Une seule définition,
 * un seul jeu de tests.
 *
 * ## Ce que ce concern refuse de faire
 *
 * Il ne convertit QUE les formes reconnues. `$request->boolean()` rend `false`
 * pour tout ce qu'il ne comprend pas : une faute de frappe (`?flag=flase`)
 * deviendrait silencieusement `false` au lieu d'un 422. Ici, l'inconnu retombe
 * sur la règle `boolean` et se fait refuser — le contrat de validation est
 * préservé, pas contourné.
 *
 * ## Usage
 *
 * ```php
 * protected function prepareForValidation(): void
 * {
 *     $this->normalizeQueryBooleans(['unread_only']);
 * }
 * ```
 *
 * Les champs sont NOMMÉS, jamais devinés : normaliser tout ce qui ressemble à
 * un booléen convertirait aussi des champs texte dont `'true'` est une valeur
 * légitime.
 *
 * Vérifié par tests/Unit/Http/Requests/NormalizesQueryBooleansTest.php.
 */
trait NormalizesQueryBooleans
{
    /**
     * Formes textuelles reconnues. La casse et les espaces de bord sont
     * ignorés ; tout le reste est laissé intact pour que la validation tranche.
     *
     * @var array<string, bool>
     */
    private const FORMES_RECONNUES = [
        'true' => true,
        'false' => false,
        'on' => true,
        'off' => false,
        'yes' => true,
        'no' => false,
    ];

    /**
     * @param  list<string>  $champs  Les clés à normaliser, nommées explicitement.
     */
    protected function normalizeQueryBooleans(array $champs): void
    {
        foreach ($champs as $champ) {
            if (! $this->has($champ)) {
                continue;
            }

            $brut = $this->input($champ);

            if (! is_string($brut)) {
                continue;
            }

            $cle = strtolower(trim($brut));

            if (array_key_exists($cle, self::FORMES_RECONNUES)) {
                $this->merge([$champ => self::FORMES_RECONNUES[$cle]]);
            }
        }
    }
}
