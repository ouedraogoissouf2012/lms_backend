<?php

declare(strict_types=1);

use App\Rules\KlassciApiUrl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * #685 — bascule en `https` les `institutions.klassci_api_url` restées en clair.
 *
 * ## Ce qu'une seule lettre manquante a coûté
 *
 * `institutions#1` portait `http://presentation.klassci.com/api/lms`, port 80.
 * KLASSCI a cessé d'y répondre ; le 443 fonctionnait. Mesuré depuis le conteneur
 * de production le 2026-09-03 :
 *
 *     http://presentation.klassci.com/api/lms   → code 000, timeout à 10 s
 *     https://presentation.klassci.com/api/lms  → 404 en 1,74 s
 *
 * `GET /api/lms/seances/my-teaching` répondait 500, la liste des séances de
 * l'enseignant restait vide, et **aucun bouton visio ne s'affichait** : la
 * fonctionnalité paraissait absente alors qu'elle était déployée. Même cause
 * pour l'enrichissement des évaluations.
 *
 * ## Pourquoi une migration plutôt qu'un UPDATE manuel
 *
 * L'issue proposait un `UPDATE` à passer à la main. Un ordre SQL joué une fois
 * sur une base ne laisse aucune trace, ne se rejoue pas sur les autres
 * environnements, et ne protège pas les lignes déjà écrites ailleurs. La
 * migration, elle, part avec le déploiement et vaut pour tous les tenants.
 *
 * Elle est **idempotente** : une URL déjà en `https` n'est pas touchée.
 *
 * ## La boucle locale est épargnée
 *
 * `http://localhost`, `http://127.0.0.x` et `http://[::1]` restent tels quels :
 * le trafic ne quitte pas la machine, et les réécrire casserait un KLASSCI servi
 * en local. Même frontière que {@see KlassciApiUrl}, qui empêche
 * désormais une URL en clair d'entrer par la validation.
 *
 * ## Pas de `down()` destructeur
 *
 * Remettre `http://` re-casserait délibérément la liaison amont. L'inverse de
 * cette migration n'est pas « restaurer un état », c'est « réintroduire la
 * panne ». Elle ne s'annule donc pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        $lignes = DB::table('institutions')
            ->whereNotNull('klassci_api_url')
            ->where('klassci_api_url', 'like', 'http://%')
            ->get(['id', 'klassci_api_url']);

        foreach ($lignes as $ligne) {
            $url = (string) $ligne->klassci_api_url;

            if ($this->viseLaBoucleLocale($url)) {
                continue;
            }

            DB::table('institutions')
                ->where('id', $ligne->id)
                ->update(['klassci_api_url' => 'https://'.substr($url, strlen('http://'))]);
        }
    }

    public function down(): void
    {
        // Volontairement vide : voir le docblock.
    }

    private function viseLaBoucleLocale(string $url): bool
    {
        $hote = parse_url($url, PHP_URL_HOST);

        if (! is_string($hote) || $hote === '') {
            return false;
        }

        $hote = strtolower(trim($hote, '[]'));

        return $hote === 'localhost'
            || $hote === '::1'
            || str_starts_with($hote, '127.');
    }
};
