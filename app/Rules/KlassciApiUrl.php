<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\Klassci\Health\KlassciReachability;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Exige une URL de base KLASSCI en `https`, sauf sur une adresse de bouclage.
 *
 * ## La panne corrigée (#685)
 *
 * `institutions#1` portait `klassci_api_url = http://presentation.klassci.com/api/lms`
 * — en clair, port 80. KLASSCI a cessé de répondre sur ce port ; le 443
 * fonctionnait. Mesuré depuis le conteneur de production :
 *
 *   http://presentation.klassci.com/api/lms   → code 000, timeout à 10 s
 *   https://presentation.klassci.com/api/lms  → 404 en 1,74 s
 *
 * Conséquence en production : `GET /api/lms/seances/my-teaching` répondait 500,
 * la liste des séances de l'enseignant restait vide, et **aucun bouton visio ne
 * s'affichait** — la fonctionnalité paraissait absente alors qu'elle était
 * déployée. Le même schéma cassait l'enrichissement des évaluations.
 *
 * La validation ne pouvait rien y faire : `'klassci_api_url' => 'nullable|url'`
 * accepte `http://` sans réserve. Une seule lettre manquante, et tout un tenant
 * perdait sa liaison amont — silencieusement, jusqu'au premier utilisateur.
 *
 * ## Pourquoi une règle, et pas `url:https`
 *
 * `url:https` refuserait aussi `http://localhost:8080/api`, forme légitime d'un
 * KLASSCI servi en local pendant le développement. Refuser le chiffrement sur la
 * boucle locale n'apporte aucune sécurité — le trafic ne quitte pas la machine —
 * mais rendrait le produit intestable hors TLS.
 *
 * On applique donc la règle des contextes sécurisés du web : `https` exigé
 * partout, `http` toléré sur `localhost`, `127.0.0.0/8` et `[::1]` seulement.
 *
 * ## Ce qu'elle ne fait PAS
 *
 * Elle ne contacte rien. Une URL bien formée en `https` peut être injoignable,
 * et c'est une autre préoccupation — celle de
 * {@see KlassciReachability}. Mélanger les deux
 * ferait dépendre l'enregistrement d'une institution de la disponibilité d'un
 * tiers, et un KLASSCI momentanément coupé bloquerait la création d'un tenant.
 */
final class KlassciApiUrl implements ValidationRule
{
    /**
     * Hôtes où `http` reste acceptable : le trafic ne quitte pas la machine.
     *
     * @var list<string>
     */
    private const HOTES_DE_BOUCLAGE = ['localhost', '::1'];

    /**
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('Le champ :attribute doit être une URL.');

            return;
        }

        $schema = parse_url($value, PHP_URL_SCHEME);
        $hote = parse_url($value, PHP_URL_HOST);

        if (! is_string($schema) || ! is_string($hote) || $hote === '') {
            $fail('Le champ :attribute doit être une URL absolue, avec un schéma et un hôte.');

            return;
        }

        $schema = strtolower($schema);

        if ($schema === 'https') {
            return;
        }

        if ($schema === 'http' && $this->estUneBoucleLocale($hote)) {
            return;
        }

        $fail(
            'Le champ :attribute doit utiliser https. KLASSCI ne répond plus en clair '
            .'sur le port 80 : une URL en http coupe la liaison du tenant sans message '
            .'d\'erreur pour l\'utilisateur (#685).'
        );
    }

    /**
     * `127.0.0.0/8` en entier, pas seulement `127.0.0.1` : la plage est
     * réservée au bouclage et certains environnements y placent des services.
     */
    private function estUneBoucleLocale(string $hote): bool
    {
        $hote = strtolower(trim($hote, '[]'));

        if (in_array($hote, self::HOTES_DE_BOUCLAGE, true)) {
            return true;
        }

        return filter_var($hote, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && str_starts_with($hote, '127.');
    }
}
