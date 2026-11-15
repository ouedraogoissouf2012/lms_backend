<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Enums\InstitutionMode;
use App\Jobs\DispatchLessonPublishedNotifications;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Matiere;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserClass;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * (b), D614 : quand une leçon est publiée, les élèves inscrits dans SA classe sont avertis.
 *
 * Avant : le job interrogeait KLASSCI (`/matieres/{id}` avec l'id LOCAL de la matière, puis
 * `classe_ids` et `etudiant_ids`, deux clés absentes de la forme réelle). Personne n'était
 * averti, et en école autonome, KLASSCI n'existe pas.
 *
 * Après : les destinataires sont les élèves de la classe de la leçon, par la règle
 * d'inscription de l'école (`EnrollmentSource::localStudentIdsOf`) : le pivot actif en
 * école autonome ; le pivot actif puis le miroir `user_classes` en école KLASSCI. Aucun
 * appel KLASSCI. C'est le sens inverse de la règle qui ouvre la leçon : un élève qui peut la
 * lire est averti.
 *
 * Vraie route de publication, vrai jeton ; le job est ensuite exécuté sans tenant, comme un
 * worker.
 */
final class ElevesAvertisDeLaPublicationTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private const KLASSCI_CLASSE = 7001;

    private Institution $ecole;

    private Classe $classe;

    private User $auteur;

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    public function test_ecole_autonome_les_eleves_actifs_de_la_classe_sont_avertis(): void
    {
        $this->ecoleEtClasse(InstitutionMode::Standalone);
        $actif1 = $this->eleveInscrit('actif');
        $actif2 = $this->eleveInscrit('actif');
        $this->eleveInscrit('inactif');
        $ailleurs = $this->membre($this->ecole, 'etudiant');
        $this->inscrire($ailleurs, $this->classe($this->ecole, '5e B'), 'actif');

        $this->publier($this->lecon());

        self::assertSame([$actif1->id, $actif2->id], $this->avertis());
    }

    public function test_ecole_klassci_le_pivot_et_le_miroir_sont_avertis_sans_appel_klassci(): void
    {
        $this->ecoleEtClasse(InstitutionMode::Klassci);
        $parLePivot = $this->eleveInscrit('actif');
        $parLeMiroir = $this->membre($this->ecole, 'etudiant');
        UserClass::query()->withoutGlobalScopes()->create([
            'user_id' => $parLeMiroir->id,
            'institution_id' => $this->ecole->id,
            'klassci_classe_id' => self::KLASSCI_CLASSE,
            'classe_nom' => '6e A',
        ]);

        $this->publier($this->lecon());

        self::assertSame([$parLePivot->id, $parLeMiroir->id], $this->avertis());
    }

    /** @return iterable<string, array{0: InstitutionMode}> */
    public static function volets(): iterable
    {
        yield 'école autonome' => [InstitutionMode::Standalone];
        yield 'école KLASSCI' => [InstitutionMode::Klassci];
    }

    /** Un compte d'une autre école lié par erreur à la classe, et un enseignant inscrit, ne sont pas avertis. */
    #[DataProvider('volets')]
    public function test_ni_un_compte_d_une_autre_ecole_ni_un_enseignant_ne_sont_avertis(InstitutionMode $mode): void
    {
        $this->ecoleEtClasse($mode);
        $eleve = $this->eleveInscrit('actif');
        $etranger = $this->membre(Institution::factory()->create(), 'etudiant');
        $this->inscrire($etranger, $this->classe, 'actif');
        $enseignant = $this->membre($this->ecole, 'enseignant');
        $this->inscrire($enseignant, $this->classe, 'actif');

        $this->publier($this->lecon());

        self::assertSame([$eleve->id], $this->avertis());
    }

    /** La classe décide, pas la matière : une leçon sans matière avertit sa classe. */
    #[DataProvider('volets')]
    public function test_une_lecon_sans_matiere_avertit_sa_classe(InstitutionMode $mode): void
    {
        $this->ecoleEtClasse($mode);
        $eleve = $this->eleveInscrit('actif');

        $this->publier($this->lecon(['matiere_id' => null]));

        self::assertSame([$eleve->id], $this->avertis());
    }

    /** Une leçon ancienne sans classe n'avertit personne, sans erreur. */
    #[DataProvider('volets')]
    public function test_une_lecon_sans_classe_n_avertit_personne(InstitutionMode $mode): void
    {
        $this->ecoleEtClasse($mode);
        $this->eleveInscrit('actif');

        $this->asTenant($this->auteur)->postJson('/api/lessons/'.$this->lecon(['classe_id' => null])->id.'/publish')->assertOk();
        foreach (Queue::pushed(DispatchLessonPublishedNotifications::class) as $job) {
            $this->executer($job);
        }

        self::assertSame([], $this->avertis());
    }

    private function ecoleEtClasse(InstitutionMode $mode): void
    {
        Queue::fake();
        // Aucune sortie réseau : la règle ne relit jamais KLASSCI, dans aucune école.
        Http::preventStrayRequests();
        $this->disableKlassciMiddleware();
        $this->ecole = $this->ecole($mode);
        $this->classe = $this->classe($this->ecole, '6e A');
        $this->classe->forceFill(['klassci_id' => self::KLASSCI_CLASSE])->save();
        $this->auteur = $this->membre($this->ecole, 'enseignant');
    }

    private function eleveInscrit(string $statut): User
    {
        $eleve = $this->membre($this->ecole, 'etudiant');
        $this->inscrire($eleve, $this->classe, $statut);

        return $eleve;
    }

    /** @param  array<string, mixed>  $attributs */
    private function lecon(array $attributs = []): Lesson
    {
        return Lesson::factory()->create(array_merge([
            'institution_id' => $this->ecole->id,
            'enseignant_id' => $this->auteur->id,
            'classe_id' => $this->classe->id,
            'matiere_id' => Matiere::factory()->create(['institution_id' => $this->ecole->id])->id,
            'title' => 'Les fractions',
            'status' => 'draft',
            'published_at' => null,
        ], $attributs));
    }

    /** La vraie route, puis le job poussé, exécuté comme par un worker. */
    private function publier(Lesson $lecon): void
    {
        $this->asTenant($this->auteur)->postJson('/api/lessons/'.$lecon->id.'/publish')->assertOk();

        $jobs = Queue::pushed(DispatchLessonPublishedNotifications::class);
        self::assertCount(1, $jobs, 'la publication pousse un seul job');
        $this->executer($jobs->first());
    }

    private function executer(DispatchLessonPublishedNotifications $job): void
    {
        // Un worker : aucun tenant hérité de la requête.
        $this->app->forgetScopedInstances();
        app(TenantManager::class)->reset();
        $this->app->call([$job, 'handle']);
    }

    /** @return list<int> les comptes avertis de la publication, triés */
    private function avertis(): array
    {
        $ids = Notification::withoutGlobalScope('institution')
            ->where('type', Notification::TYPE_LESSON_PUBLISHED)
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        return $ids;
    }
}
