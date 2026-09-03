<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Visio;

use App\Models\ESBTPAttendance;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * `joined_at` est l'heure d'ARRIVÉE, pas l'heure du dernier clic (#683).
 *
 * ## Le défaut corrigé
 *
 * `join()` écrivait `joined_at => now()` inconditionnellement via
 * `updateOrCreate`, dont la clé est `(seance_id, user_id, institution_id)`. Un
 * second appel sur la même séance réinitialisait donc l'heure d'arrivée, et
 * **la durée de présence mesurée s'en trouvait raccourcie** — silencieusement,
 * sans erreur ni journal, dans les rapports remis aux établissements.
 *
 * Les déclencheurs sont ordinaires : double-clic, retour arrière puis nouveau
 * clic, second onglet.
 *
 * ## Pourquoi #673 rend le correctif indispensable
 *
 * La salle est désormais embarquée : un rechargement de page la détruit, donc
 * l'application reprend la participation en rappelant `join`. Sans ce
 * correctif, **chaque F5 raccourcirait la présence**.
 *
 * ## La règle
 *
 * Participation encore `connected` → c'est la MÊME session, `joined_at` est
 * préservé. Participation `disconnected` → c'est une NOUVELLE session, il est
 * réinitialisé. `last_seen_at` est rafraîchi dans les deux cas.
 */
final class JoinVisioPreservesJoinedAtTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableKlassciMiddleware();
        Config::set('services.klassci.url', 'https://klassci.test');
        Config::set('services.visio.jitsi.app_secret', 'secret-de-test-jamais-utilise-en-production');

        $this->institution = Institution::create([
            'slug' => 'school-joined-at',
            'name' => 'School Joined At',
            'klassci_api_url' => 'https://klassci.test',
            'klassci_api_token_encrypted' => 'token',
            'is_active' => true,
            'settings' => ['timezone' => 'UTC'],
        ]);
        app(TenantManager::class)->set($this->institution);
    }

    /**
     * LE test du défaut. Une heure s'écoule entre les deux appels : si
     * `joined_at` bougeait, la présence perdrait cette heure.
     */
    public function test_a_second_join_on_an_open_participation_preserves_the_arrival_time(): void
    {
        $seance = $this->activeSeance();
        $teacher = $this->teacher();

        $this->asUser($teacher)->postJson("/api/lms/seances/{$seance->id}/join")->assertStatus(200);
        $arrivee = $this->attendance($seance, $teacher)->joined_at;
        self::assertNotNull($arrivee);

        $this->travel(1)->hours();
        $this->asUser($teacher)->postJson("/api/lms/seances/{$seance->id}/join")->assertStatus(200);

        $apres = $this->attendance($seance, $teacher);
        self::assertSame(
            $arrivee->toDateTimeString(),
            $apres->joined_at?->toDateTimeString(),
            'joined_at doit rester l\'heure d\'arrivee : la deplacer raccourcit la presence mesuree',
        );
    }

    /** `last_seen_at` reste le signal d'activité, et lui DOIT avancer. */
    public function test_a_second_join_still_refreshes_the_activity_signal(): void
    {
        $seance = $this->activeSeance();
        $teacher = $this->teacher();

        $this->asUser($teacher)->postJson("/api/lms/seances/{$seance->id}/join")->assertStatus(200);
        $avant = $this->attendance($seance, $teacher)->last_seen_at;

        $this->travel(1)->hours();
        $this->asUser($teacher)->postJson("/api/lms/seances/{$seance->id}/join")->assertStatus(200);

        $apres = $this->attendance($seance, $teacher)->last_seen_at;
        self::assertNotNull($avant);
        self::assertNotNull($apres);
        self::assertTrue($apres->greaterThan($avant), 'last_seen_at doit avancer');
    }

    /**
     * Après une sortie, revenir est une NOUVELLE session : l'heure d'arrivée
     * doit repartir de maintenant, sinon la présence serait au contraire
     * surévaluée.
     */
    public function test_rejoining_after_leaving_resets_the_arrival_time(): void
    {
        $seance = $this->activeSeance();
        $teacher = $this->teacher();

        $this->asUser($teacher)->postJson("/api/lms/seances/{$seance->id}/join")->assertStatus(200);
        $premiere = $this->attendance($seance, $teacher)->joined_at;

        $this->attendance($seance, $teacher)->update(['status' => 'disconnected']);

        $this->travel(1)->hours();
        $this->asUser($teacher)->postJson("/api/lms/seances/{$seance->id}/join")->assertStatus(200);

        $apres = $this->attendance($seance, $teacher);
        self::assertNotNull($premiere);
        self::assertTrue(
            $apres->joined_at?->greaterThan($premiere) ?? false,
            'une nouvelle session doit repartir d\'une nouvelle heure d\'arrivee',
        );
    }

    // ───────────────────────── Fixtures ─────────────────────────

    /**
     * `klassci_enseignant_id` est ce qui fait la propriete de la seance :
     * {@see \App\Services\Visio\VisioActorAuthorization::teacherOwns}. Sans
     * lui, `join` repond 403 `teacher_not_owner` et le test ne mesurerait rien.
     */
    private const OWNER_ID = 4242;

    private function activeSeance(): Seance
    {
        return Seance::factory()->visioActive()->create([
            'institution_id' => $this->institution->id,
            'klassci_enseignant_id' => self::OWNER_ID,
        ]);
    }

    private function teacher(): User
    {
        return User::factory()->create([
            'institution_id' => $this->institution->id,
            'email' => 'prof@example.test',
            'role' => 'enseignant',
            'klassci_enseignant_id' => self::OWNER_ID,
        ]);
    }

    private function attendance(Seance $seance, User $user): ESBTPAttendance
    {
        $attendance = ESBTPAttendance::query()
            ->where('seance_id', $seance->id)
            ->where('user_id', $user->id)
            ->first();

        self::assertInstanceOf(ESBTPAttendance::class, $attendance);

        return $attendance;
    }

    /** Jeton porteur RÉEL : sans bearer, aucun tenant n'est résolu. */
    private function asUser(User $user): self
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $user->createToken('joined-at-test')->plainTextToken,
        ]);
    }
}
