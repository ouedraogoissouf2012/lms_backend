<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use App\Models\UserClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * #869 — « Mes cours » n'émet et n'accepte qu'un seul espace d'identifiants.
 *
 * ## L'aller-retour qui se trompait de colonne
 *
 * `lessons.enseignant_id` est **local par contrat** : sa migration le dit
 * (`comment('users.id (LOCAL)')`) et son unique écrivain y met `$author->id`.
 * Quatre sites le traitaient pourtant comme possiblement KLASSCI — la
 * préchargement par `orWhereIn`, un second dictionnaire indexé miroir, le
 * champ de filtre émis en `klassci_id`, et le filtre entrant qui comparait les
 * deux valeurs à la colonne locale.
 *
 * Les quatre formaient **un seul aller-retour** : l'API émettait un identifiant
 * KLASSCI, le frontend le renvoyait tel quel, et l'API le rattrapait par une
 * tolérance qui rendait, sur collision, les leçons de quelqu'un d'autre.
 *
 * ## La fixture EST le test
 *
 * Neuf utilisateurs sur 218 sont en collision en production (mesure du
 * 19/09/2026), dont un étudiant dont l'`id` local est le `klassci_id` d'un
 * enseignant. Sans reproduire cet écart, aucune assertion ne peut distinguer
 * les deux espaces : un test où `id == klassci_id` passe dans les deux sens.
 *
 * Les identifiants sont donc **créés puis relus**, jamais écrits en dur — un id
 * fixé à la main est vert sous SQLite et faux sous MySQL dès le second test.
 *
 * @see app/Services/Lesson/MyCoursesPresenter.php
 * @see app/Services/Lesson/LessonListService.php
 */
final class MyCoursesEnseignantEspaceTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private Classe $classe;

    private User $etudiant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        $this->classe = Classe::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => 9001,
        ]);
        $this->etudiant = $this->etudiantInscrit();
    }

    /**
     * Le cœur : l'identifiant proposé au frontend est celui que la colonne
     * stocke, et c'est lui — et lui seul — qui filtre.
     */
    public function test_le_filtre_enseignant_est_emis_et_accepte_dans_l_espace_local(): void
    {
        [$prof, $lesson] = $this->profEnCollisionAvecSaLecon();

        // `filters` vit a la RACINE de la reponse, hors enveloppe `data`
        // (LessonCrudController:66-71). Le chemin est fige ici plutot que
        // devine : un garde qui tatonne sur la forme ne garde pas la forme.
        $filtres = $this->appel()->json('filters.enseignants');

        self::assertSame(
            [$prof->id],
            collect($filtres)->pluck('id')->all(),
            'le filtre doit proposer l identifiant LOCAL de l auteur',
        );
        self::assertNotSame($prof->klassci_id, $filtres[0]['id']);

        self::assertSame(
            [$lesson->id],
            $this->idsFiltresPar($prof->id),
            'filtrer par l identifiant propose doit rendre la lecon',
        );
    }

    /**
     * La face qui prouve que la tolérance a bien disparu.
     *
     * `$prof->klassci_id` est l'`id` LOCAL d'un autre compte. L'accepter
     * rendrait les leçons de cet autre compte — c'est exactement ce que faisait
     * la branche `orWhere`.
     */
    public function test_l_identifiant_klassci_ne_ramene_plus_les_lecons_de_son_porteur(): void
    {
        [$prof] = $this->profEnCollisionAvecSaLecon();

        self::assertSame(
            [],
            $this->idsFiltresPar((int) $prof->klassci_id),
            'un identifiant KLASSCI ne doit plus atteindre une colonne locale',
        );
    }

    /**
     * La précharge ne filtrait que `role = 'enseignant'`. Or la route de
     * création autorise le coordinateur, et son identifiant atterrit dans la
     * même colonne — c'est cette exclusion qui faisait tomber la résolution
     * dans le repli miroir, et affichait un autre nom.
     */
    public function test_une_lecon_ecrite_par_un_coordinateur_porte_son_nom(): void
    {
        $coordinateur = $this->auteur(role: 'coordinateur', nom: 'Awa Coordinatrice');
        $this->leconDe($coordinateur);

        self::assertSame(['Awa Coordinatrice'], $this->nomsDAuteurs());
    }

    /**
     * Le nom de l'auteur survit à la suppression de son compte (#566) : une
     * leçon sans auteur affiché serait une perte d'information, pas une
     * protection.
     */
    public function test_le_nom_de_l_auteur_survit_a_la_suppression_du_compte(): void
    {
        $prof = $this->auteur(role: 'enseignant', nom: 'Bintou Parti');
        $this->leconDe($prof);
        $prof->delete();

        self::assertSame(['Bintou Parti'], $this->nomsDAuteurs());
    }

    /**
     * Un enseignant dont l'identifiant KLASSCI est l'identifiant LOCAL d'un
     * autre compte — la collision mesurée en production.
     *
     * Le leurre naît d'ABORD, son identifiant est relu, puis l'enseignant
     * l'emprunte comme identifiant miroir.
     *
     * @return array{0: User, 1: Lesson}
     */
    private function profEnCollisionAvecSaLecon(): array
    {
        $leurre = $this->auteur(role: 'etudiant', nom: 'Compte leurre');
        $prof = $this->auteur(role: 'enseignant', nom: 'Moussa Prof', klassciId: $leurre->id);

        self::assertNotSame($prof->id, $prof->klassci_id, 'sans ecart, le test passerait dans les deux sens');

        return [$prof, $this->leconDe($prof)];
    }

    private function auteur(string $role, string $nom, ?int $klassciId = null): User
    {
        return User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => $role,
            'name' => $nom,
            'klassci_id' => $klassciId,
        ]);
    }

    private function leconDe(User $auteur): Lesson
    {
        return Lesson::factory()->create([
            'institution_id' => $this->institution->id,
            'classe_id' => $this->classe->id,
            'enseignant_id' => $auteur->id,
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
    }

    private function etudiantInscrit(): User
    {
        $etudiant = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
        ]);

        UserClass::create([
            'user_id' => $etudiant->id,
            'klassci_classe_id' => (int) $this->classe->klassci_id,
            'classe_nom' => $this->classe->libelle,
            'institution_id' => $this->institution->id,
            'synced_at' => now(),
        ]);
        $this->classe->etudiants()->attach($etudiant->id, ['statut' => 'actif']);

        return $etudiant;
    }

    private function appel(array $parametres = []): TestResponse
    {
        Sanctum::actingAs($this->etudiant);

        return $this->getJson('/api/lessons/my-courses'.($parametres === [] ? '' : '?'.http_build_query($parametres)))
            ->assertStatus(200);
    }

    /** @return array<int, int> */
    private function idsFiltresPar(int $enseignantId): array
    {
        return collect($this->appel(['enseignant_id' => $enseignantId])->json('data'))
            ->pluck('id')
            ->all();
    }

    /** @return array<int, string> */
    private function nomsDAuteurs(): array
    {
        return collect($this->appel()->json('data'))
            ->pluck('enseignant.name')
            ->filter()
            ->values()
            ->all();
    }
}
