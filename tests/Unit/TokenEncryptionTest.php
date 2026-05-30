<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TokenEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_klassci_token_is_encrypted_in_database(): void
    {
        $plainToken = 'test-token-plaintext-12345';

        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'klassci_id' => 123,
            'role' => 'student',
            'klassci_token_encrypted' => $plainToken,
            'institution_id' => 1,
        ]);

        $rawData = \DB::table('users')->find($user->id);
        $this->assertNotEquals($plainToken, $rawData->klassci_token_encrypted);
        $this->assertStringNotContainsString($plainToken, $rawData->klassci_token_encrypted);

        $this->assertEquals($plainToken, $user->klassci_token);
    }

    public function test_user_klassci_token_accessor_decrypts_correctly(): void
    {
        $plainToken = 'my-secret-token-xyz';

        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'klassci_id' => 123,
            'role' => 'student',
            'klassci_token_encrypted' => $plainToken,
            'institution_id' => 1,
        ]);

        $retrievedUser = User::find($user->id);
        $this->assertEquals($plainToken, $retrievedUser->klassci_token);
    }

    public function test_institution_klassci_api_token_is_encrypted(): void
    {
        $plainToken = 'institution-api-token-secret';

        $institution = Institution::create([
            'slug' => 'test-institution',
            'name' => 'Test Institution',
            'klassci_api_url' => 'https://klassci.test.com',
            'klassci_api_token_encrypted' => $plainToken,
        ]);

        $rawData = \DB::table('institutions')->find($institution->id);
        $this->assertNotEquals($plainToken, $rawData->klassci_api_token_encrypted);

        $this->assertEquals($plainToken, $institution->klassci_api_token);
    }

    /**
     * Vérifie que l'ancienne colonne plaintext `klassci_token` n'existe plus.
     *
     * Utilise `Schema::hasColumn` (Laravel abstraction) pour rester
     * cross-DB compatible (sqlite local, mysql prod) — l'original utilisait
     * `information_schema.columns` qui est PostgreSQL/MySQL specifique
     * et fait crasher sqlite (TEST-01 fix).
     */
    public function test_plaintext_token_column_does_not_exist(): void
    {
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('users', 'klassci_token'),
            'The plaintext klassci_token column should not exist'
        );
    }

    public function test_plaintext_api_token_column_does_not_exist(): void
    {
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('institutions', 'klassci_api_token'),
            'The plaintext klassci_api_token column should not exist'
        );
    }
}
