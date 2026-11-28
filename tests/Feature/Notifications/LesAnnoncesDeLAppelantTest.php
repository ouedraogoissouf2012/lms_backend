<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\InstitutionMode;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1650 (côté lecture), pour front#690 : `GET /notifications?type=annonce` rend les annonces de l'APPELANT, et elles
 * seules. C'est le fil « Mes annonces ». Le filtrer à l'écran sur une page paginée perdrait les annonces des pages
 * suivantes. Sous vrai jeton, dans les deux types d'école.
 *
 * - `type` est une liste blanche des types connus ; un type inconnu rend 422.
 * - La liste est mise en cache par appelant et par page. La clé porte aussi `type` et `per_page` : avant, deux
 *   tailles de page partageaient la même entrée, et un filtre aurait servi la liste non filtrée.
 */
final class LesAnnoncesDeLAppelantTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const CHEMIN = '/notifications';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    /** @return array<string, array{InstitutionMode}> */
    public static function volets(): array
    {
        return ['école KLASSCI' => [InstitutionMode::Klassci], 'école autonome' => [InstitutionMode::Standalone]];
    }

    #[DataProvider('volets')]
    public function test_le_filtre_rend_les_seules_annonces_de_l_appelant(InstitutionMode $mode): void
    {
        $ecole = $this->ecole($mode);
        $eleve = $this->membre($ecole, 'etudiant');
        $camarade = $this->membre($ecole, 'etudiant');
        $this->notifier($eleve, 'annonce', 'Sortie au musée');
        $this->notifier($eleve, 'annonce', 'Réunion des parents');
        $this->notifier($eleve, 'visio_scheduled', 'Visio demain');
        $this->notifier($camarade, 'annonce', 'Annonce du camarade');

        $reponse = $this->asTenant($eleve)->getJson('/api/notifications?type=annonce');

        $reponse->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
        $lues = (array) $reponse->json('data');
        self::assertSame(['annonce', 'annonce'], array_column($lues, 'type'));
        self::assertEqualsCanonicalizing(['Sortie au musée', 'Réunion des parents'], array_column($lues, 'title'));
    }

    public function test_sans_filtre_rien_ne_change(): void
    {
        $eleve = $this->membre($this->ecole(), 'etudiant');
        $this->notifier($eleve, 'annonce', 'Sortie au musée');
        $this->notifier($eleve, 'visio_scheduled', 'Visio demain');

        $this->asTenant($eleve)->getJson('/api/notifications')->assertOk()->assertJsonPath('meta.total', 2);
    }

    /** La clé de cache porte le filtre : la liste filtrée ne sert pas la liste entière, ni l'inverse. */
    public function test_le_cache_distingue_la_liste_filtree_de_la_liste_entiere(): void
    {
        $eleve = $this->membre($this->ecole(), 'etudiant');
        $this->notifier($eleve, 'annonce', 'Sortie au musée');
        $this->notifier($eleve, 'visio_scheduled', 'Visio demain');

        $this->asTenant($eleve)->getJson('/api/notifications')->assertJsonPath('meta.total', 2);
        $this->asTenant($eleve)->getJson('/api/notifications?type=annonce')->assertJsonPath('meta.total', 1);
        $this->asTenant($eleve)->getJson('/api/notifications')->assertJsonPath('meta.total', 2);
    }

    /** La clé de cache porte aussi la taille de page (défaut existant, corrigé avec le filtre). */
    public function test_le_cache_distingue_les_tailles_de_page(): void
    {
        $eleve = $this->membre($this->ecole(), 'etudiant');
        $this->notifier($eleve, 'annonce', 'Une');
        $this->notifier($eleve, 'annonce', 'Deux');

        $this->asTenant($eleve)->getJson('/api/notifications?per_page=1')->assertJsonCount(1, 'data');
        $this->asTenant($eleve)->getJson('/api/notifications?per_page=10')->assertJsonCount(2, 'data');
    }

    public function test_422_un_type_inconnu(): void
    {
        $eleve = $this->membre($this->ecole(), 'etudiant');

        $reponse = $this->asTenant($eleve)->getJson('/api/notifications?type=annonces');

        $reponse->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
    }

    private function notifier(User $destinataire, string $type, string $titre): void
    {
        Notification::query()->withoutGlobalScopes()->create([
            'user_id' => $destinataire->getKey(),
            'institution_id' => $destinataire->institution_id,
            'type' => $type,
            'title' => $titre,
            'message' => 'Texte.',
            'data' => [],
        ]);
    }
}
