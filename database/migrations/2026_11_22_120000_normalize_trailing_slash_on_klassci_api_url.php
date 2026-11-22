<?php

declare(strict_types=1);

use App\Models\Institution;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * #1331 — retire le « / » final des `institutions.klassci_api_url` déjà écrites.
 *
 * ## Le défaut
 *
 * La connexion stocke dans `users.klassci_tenant_url` l'URL de l'école rognée de son
 * « / » final. Le résolveur la compare, avec `===`, à l'URL brute de l'école : pour une
 * école saisie avec un slash final, la comparaison échouait toujours, et chaque appel
 * KLASSCI de ses comptes rendait 503. Le client HTTP, lui, ajoutait `/endpoint` à l'URL
 * brute : `…/api/lms//seances`.
 *
 * Le mutateur {@see Institution::setKlassciApiUrlAttribute()} rend désormais toute
 * nouvelle écriture canonique. Cette migration aligne les lignes écrites avant lui.
 *
 * Elle passe par `DB::table` et non par le modèle : elle ne dépend pas du code qui
 * évoluera après elle. Elle est **idempotente** : une URL sans slash final n'est pas
 * touchée. En production le 22/11 : aucune ligne concernée.
 *
 * ## Pas de `down()`
 *
 * Remettre un slash final recasserait délibérément la liaison des comptes de l'école.
 * L'inverse de cette migration n'est pas « restaurer un état », c'est « réintroduire la
 * panne ». Elle ne s'annule donc pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        $lignes = DB::table('institutions')
            ->where('klassci_api_url', 'like', '%/')
            ->get(['id', 'klassci_api_url']);

        foreach ($lignes as $ligne) {
            $canonique = rtrim((string) $ligne->klassci_api_url, '/');

            DB::table('institutions')
                ->where('id', $ligne->id)
                ->update(['klassci_api_url' => $canonique === '' ? null : $canonique]);
        }
    }

    public function down(): void
    {
        // Volontairement vide : voir le docblock.
    }
};
