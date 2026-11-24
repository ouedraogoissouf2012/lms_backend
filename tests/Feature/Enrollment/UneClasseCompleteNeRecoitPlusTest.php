<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use App\Services\Enrollment\RejoindreParCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\PreparesClasseWithCode;
use Tests\TestCase;

/**
 * #1115 — une classe complète ne reçoit plus personne par son code, par aucune des deux portes.
 *
 * Depuis #915, les plafonds ne comptent que les ÉCHECS : un code VALIDE qui a fuité (il circule
 * par WhatsApp) laissait entrer autant de comptes qu'on voulait. La borne existait dans le modèle
 * (`Classe::isComplete()`), personne ne la lisait.
 *
 * `effectif` est une CAPACITÉ (KLASSCI l'écrit depuis `places_totales`). 0 veut dire « non
 * renseignée », donc sans limite : c'est la valeur par défaut d'une classe locale. Seules les
 * adhésions ACTIVES occupent une place.
 *
 * Le refus est un 409 `class_full` : le code est valide, ce n'est pas un échec compté. La porte
 * anonyme le pose AVANT de savoir si le compte existe : un compte existant et un compte neuf y
 * reçoivent la même réponse (#914).
 *
 * Volet KLASSCI : la porte n'y existe pas (404, témoin). Jeton Bearer réel.
 */
final class UneClasseCompleteNeRecoitPlusTest extends TestCase
{
    use ActsAsTenantUser;
    use PreparesClasseWithCode;
    use RefreshDatabase;

    private const PORTE_ANONYME = '/api/inscriptions';

    private const PORTE_AUTHENTIFIEE = '/api/me/inscriptions';

    // ── Ce qui refuse ──

    public function test_la_porte_authentifiee_refuse_une_classe_complete(): void
    {
        [$ecole, $code, $classe] = $this->classe(capacite: 2, actifs: 2);

        $this->rejoindre($this->apprenant($ecole), $code)
            ->assertStatus(409)->assertJsonPath('reason', 'class_full');

        self::assertSame(2, $this->lignes($classe), 'Une adhésion de trop a été écrite.');
    }

    public function test_la_porte_anonyme_refuse_une_classe_complete_sans_creer_de_compte(): void
    {
        [$ecole, $code, $classe] = $this->classe(capacite: 1, actifs: 1);

        $this->inscrire($ecole, $code, 'neuf@test.ci')
            ->assertStatus(409)->assertJsonPath('reason', 'class_full');

        self::assertSame(1, $this->lignes($classe));
        self::assertFalse(User::query()->withoutGlobalScopes()->where('email', 'neuf@test.ci')->exists(), 'Un compte est né sans place.');
    }

    /** #914 : sur une classe complète, la porte anonyme ne dit pas si l'adresse a un compte. */
    public function test_un_compte_existant_et_un_compte_neuf_recoivent_la_meme_reponse(): void
    {
        [$ecole, $code] = $this->classe(capacite: 1, actifs: 1);
        $this->apprenant($ecole, 'connu@test.ci');

        $existant = $this->inscrire($ecole, $code, 'connu@test.ci')->assertStatus(409);
        $neuf = $this->inscrire($ecole, $code, 'inconnu@test.ci')->assertStatus(409);

        self::assertSame($neuf->json(), $existant->json());
    }

    /** La capacité se lit à chaque adhésion : la dernière place se prend, la suivante est refusée. */
    public function test_la_derniere_place_se_prend_et_la_suivante_est_refusee(): void
    {
        [$ecole, $code, $classe] = $this->classe(capacite: 2, actifs: 1);

        $this->rejoindre($this->apprenant($ecole, 'a@test.ci'), $code)->assertStatus(201);
        $this->rejoindre($this->apprenant($ecole, 'b@test.ci'), $code)->assertStatus(409);

        self::assertSame(2, $this->lignes($classe));
    }

    /** Le code est valide : le refus ne consomme pas le plafond d'échecs de l'école (#915). */
    public function test_le_refus_n_est_pas_compte_comme_un_echec(): void
    {
        [$ecole, $code] = $this->classe(capacite: 1, actifs: 1);

        // La porte anonyme d'abord : la seconde requête porterait sinon le jeton de la première.
        $this->inscrire($ecole, $code, 'neuf@test.ci')->assertStatus(409);
        $this->rejoindre($this->apprenant($ecole), $code)->assertStatus(409);

        self::assertSame(0, (int) RateLimiter::attempts(RejoindreParCodeService::cleDesEchecs($ecole->id)));
    }

    // ── Ce qui reste ouvert (témoins) ──

    /** Un membre déjà actif ne prend pas de nouvelle place : il reste admis (le double clic). */
    public function test_un_membre_deja_actif_reste_admis_dans_une_classe_complete(): void
    {
        [$ecole, $code, $classe] = $this->classe(capacite: 1, actifs: 0);
        $membre = $this->apprenant($ecole);
        $this->adhesion($classe, $membre, 'actif');

        $this->rejoindre($membre, $code)->assertStatus(200);

        self::assertSame(1, $this->lignes($classe));
    }

    /** Capacité 0 : non renseignée, la valeur d'une classe locale. Aucune limite. */
    public function test_une_capacite_non_renseignee_ne_limite_rien(): void
    {
        [$ecole, $code, $classe] = $this->classe(capacite: 0, actifs: 3);

        $this->inscrire($ecole, $code, 'neuf@test.ci')->assertStatus(202);
        $this->rejoindre($this->apprenant($ecole), $code)->assertStatus(201);

        self::assertSame(5, $this->lignes($classe));
    }

    /** Une adhésion suspendue ou abandonnée n'occupe pas de place. */
    public function test_les_adhesions_inactives_ne_prennent_pas_de_place(): void
    {
        [$ecole, $code, $classe] = $this->classe(capacite: 1, actifs: 0);
        $this->adhesion($classe, $this->apprenant($ecole, 'suspendu@test.ci'), 'suspendu');
        $this->adhesion($classe, $this->apprenant($ecole, 'parti@test.ci'), 'abandonne');

        $this->rejoindre($this->apprenant($ecole), $code)->assertStatus(201);
    }

    /** Volet KLASSCI : la porte n'existe pas, même sur une classe complète. Le refus reste le 404. */
    public function test_dans_une_ecole_klassci_la_porte_reste_fermee(): void
    {
        [$ecole, $code] = $this->classe(capacite: 1, actifs: 1);
        $ecole->update(['mode' => InstitutionMode::Klassci]);

        $this->rejoindre($this->apprenant($ecole), $code)->assertNotFound();
    }

    // ── Harnais ──

    /** @return array{0: Institution, 1: string, 2: Classe} */
    private function classe(int $capacite, int $actifs): array
    {
        [$ecole, $code, $classe] = $this->classeAvecCode();
        $classe->forceFill(['effectif' => $capacite])->save();

        for ($i = 0; $i < $actifs; $i++) {
            $this->adhesion($classe, $this->apprenant($ecole, "inscrit{$i}@test.ci"), 'actif');
        }

        return [$ecole, $code, $classe];
    }

    private function rejoindre(User $apprenant, string $code): TestResponse
    {
        return $this->asTenant($apprenant)->postJson(self::PORTE_AUTHENTIFIEE, ['code' => $code]);
    }

    private function inscrire(Institution $ecole, string $code, string $email): TestResponse
    {
        return $this->postJson(self::PORTE_ANONYME, [
            'code' => $code,
            'nom' => 'Awa Kabore',
            'email' => $email,
            'password' => 'mot-de-passe-choisi',
            'password_confirmation' => 'mot-de-passe-choisi',
        ], ['X-Institution' => (string) $ecole->slug]);
    }

    private function adhesion(Classe $classe, User $apprenant, string $statut): void
    {
        DB::table('classe_etudiant')->insert([
            'classe_id' => $classe->id,
            'user_id' => $apprenant->id,
            'institution_id' => $classe->institution_id,
            'statut' => $statut,
            'date_inscription' => '2026-01-10',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function lignes(Classe $classe): int
    {
        return DB::table('classe_etudiant')->where('classe_id', $classe->id)->where('statut', 'actif')->count();
    }
}
