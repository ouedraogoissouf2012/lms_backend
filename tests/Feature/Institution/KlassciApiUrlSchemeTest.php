<?php

declare(strict_types=1);

namespace Tests\Feature\Institution;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Unit\Rules\KlassciApiUrlTest;

/**
 * #685 — une `klassci_api_url` en clair ne doit plus pouvoir entrer.
 *
 * ## La panne
 *
 * `institutions#1` portait `http://presentation.klassci.com/api/lms`, port 80.
 * KLASSCI a cessé d'y répondre ; le 443 fonctionnait. Mesuré en production le
 * 2026-09-03 : `http` → code 000 après 10 s, `https` → 404 en 1,74 s.
 *
 * `GET /api/lms/seances/my-teaching` répondait 500, la liste des séances de
 * l'enseignant restait vide, et **aucun bouton visio ne s'affichait** : la
 * fonctionnalité paraissait absente alors qu'elle était déployée.
 *
 * La validation laissait passer — `'klassci_api_url' => 'nullable|url|max:500'`
 * accepte `http://` sans réserve. Ce fichier ferme la porte d'entrée ; la
 * migration `2026_09_11_210000` nettoie les lignes déjà écrites.
 *
 * Le test unitaire {@see KlassciApiUrlTest} couvre la règle
 * elle-même. Ici on vérifie ce qu'aucun test de règle ne peut prouver : qu'elle
 * est bien **branchée** sur les deux verbes, création et mise à jour. Une règle
 * parfaite mais non câblée était exactement la situation d'avant.
 *
 * @see app/Rules/KlassciApiUrl.php
 * @see app/Http/Controllers/API/InstitutionController.php
 */
final class KlassciApiUrlSchemeTest extends TestCase
{
    use RefreshDatabase;

    private function actAsPlatformSupradmin(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'institution_id' => null,
            'role' => 'supradmin',
            'email' => 'sup'.uniqid().'@scheme685.test',
        ]));
    }

    public function test_la_creation_refuse_une_url_en_clair(): void
    {
        $this->actAsPlatformSupradmin();

        $reponse = $this->postJson('/api/admin/institutions', [
            'slug' => 'tenant-en-clair',
            'name' => 'Tenant en clair',
            // Le cas EXACT de la production le 2026-09-03.
            'klassci_api_url' => 'http://presentation.klassci.com/api/lms',
        ]);

        $reponse->assertStatus(422)->assertJsonValidationErrors('klassci_api_url');
        $this->assertDatabaseMissing('institutions', ['slug' => 'tenant-en-clair']);
    }

    public function test_la_creation_accepte_https(): void
    {
        $this->actAsPlatformSupradmin();

        $this->postJson('/api/admin/institutions', [
            'slug' => 'tenant-chiffre',
            'name' => 'Tenant chiffré',
            'klassci_api_url' => 'https://presentation.klassci.com/api/lms',
        ])->assertStatus(201);

        $this->assertDatabaseHas('institutions', [
            'slug' => 'tenant-chiffre',
            'klassci_api_url' => 'https://presentation.klassci.com/api/lms',
        ]);
    }

    public function test_la_mise_a_jour_refuse_de_retrograder_vers_http(): void
    {
        $this->actAsPlatformSupradmin();
        $institution = Institution::factory()->create([
            'klassci_api_url' => 'https://presentation.klassci.com/api/lms',
        ]);

        $this->putJson("/api/admin/institutions/{$institution->id}", [
            'klassci_api_url' => 'http://presentation.klassci.com/api/lms',
        ])->assertStatus(422)->assertJsonValidationErrors('klassci_api_url');

        // L'URL qui marchait n'a pas bougé : un refus ne doit rien abîmer.
        $this->assertDatabaseHas('institutions', [
            'id' => $institution->id,
            'klassci_api_url' => 'https://presentation.klassci.com/api/lms',
        ]);
    }

    public function test_un_klassci_servi_en_local_reste_possible(): void
    {
        // Sans cette tolérance, la règle serait contournée dès le premier
        // développeur qui monte un KLASSCI sur sa machine.
        $this->actAsPlatformSupradmin();

        $this->postJson('/api/admin/institutions', [
            'slug' => 'tenant-local',
            'name' => 'Tenant local',
            'klassci_api_url' => 'http://127.0.0.1:8080/api/lms',
        ])->assertStatus(201);
    }

    public function test_la_regle_ne_se_declenche_pas_sur_une_valeur_absente(): void
    {
        // `nullable` court-circuite les règles suivantes : la porte ne doit pas
        // EXIGER une URL, seulement contraindre son schéma quand il y en a une.
        //
        // On l'observe sur la mise à jour, et non sur la création : créer une
        // institution sans `klassci_api_url` rend aujourd'hui un **500** — la
        // colonne est `NOT NULL` en base alors que le contrôleur la valide
        // `nullable`. C'est un défaut RÉEL mais DISTINCT de #685 — ouvert en #767,
        // découvert en écrivant ce fichier. Le corriger ici mélangerait deux
        // préoccupations ; #767 porte aussi le retrait de ce contournement.
        $this->actAsPlatformSupradmin();
        $institution = Institution::factory()->create([
            'klassci_api_url' => 'https://presentation.klassci.com/api/lms',
        ]);

        $this->putJson("/api/admin/institutions/{$institution->id}", [
            'name' => 'Nom seul, sans toucher a l URL',
        ])->assertStatus(200);
    }
}
