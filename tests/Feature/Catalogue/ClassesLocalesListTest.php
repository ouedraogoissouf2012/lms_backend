<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #905 — le front peut enfin LIRE les classes locales et leur code.
 *
 * ## Le manque
 *
 * Seule une classe locale porte un code d'inscription, et aucune route ne les
 * listait : la liste admin venait de KLASSCI, la fiche aussi, et la porte locale
 * de #760 répond 409 pour une classe sans identifiant KLASSCI. Et le code ne se
 * relisait pas : après un rechargement, le coordinateur ne pouvait que le
 * régénérer — ce qui invalidait celui déjà dicté.
 *
 * ## Ce que ce fichier garde AVANT tout
 *
 * **Qu'un code ne sort que vers l'établissement qui le possède**, et qu'un code
 * retiré ne sort plus du tout : l'afficher inviterait à dicter un code mort.
 *
 * Jeton Bearer RÉEL, par `asTenant()` : sans jeton, `ResolveInstitution` ne pose
 * aucun tenant, et le test d'isolation serait vert sans rien prouver. Le trait
 * purge aussi le garde avant chaque pose — le garde mémoïse l'utilisateur du
 * premier appel, et une seconde identité ne serait sinon jamais lue.
 *
 * @see docs/adr/2026-09-25-905-01-la-collection-des-classes-locales.md
 */
final class ClassesLocalesListTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const URL = '/api/classes';

    // ───────────────────────── le parcours nominal

    public function test_le_coordinateur_lit_ses_classes_et_l_etat_de_leur_code(): void
    {
        // Créées dans le DÉSORDRE : dans l'ordre alphabétique, l'ordre des
        // identifiants coïnciderait avec celui des libellés, et le tri ne
        // serait jamais éprouvé.
        $ecole = $this->ecole();
        $this->classeLocale($ecole, 'C — sans code');
        $this->classeLocale($ecole, 'A — code actif', 'BUR7K2');
        $this->classeLocale($ecole, 'B — code retiré', 'CXR4M9', retire: true);

        $reponse = $this->asTenant($this->membre($ecole, 'coordinateur'))
            ->getJson(self::URL)
            ->assertStatus(200);

        $reponse->assertJsonPath('data.0.libelle', 'A — code actif')
            ->assertJsonPath('data.0.code_inscription', ['etat' => 'actif', 'valeur' => 'BUR7K2'])
            ->assertJsonPath('data.1.code_inscription', ['etat' => 'retire', 'valeur' => null])
            ->assertJsonPath('data.2.code_inscription', ['etat' => 'absent', 'valeur' => null]);
    }

    public function test_relire_le_code_ne_le_regenere_pas(): void
    {
        // LE manque de #905 : relire, sans invalider le code déjà dicté.
        $ecole = $this->ecole();
        $classe = $this->classeLocale($ecole, 'Bureautique', 'BUR7K2');
        $gestionnaire = $this->membre($ecole, 'coordinateur');

        $this->asTenant($gestionnaire)->getJson(self::URL)->assertJsonPath('data.0.code_inscription.valeur', 'BUR7K2');
        $this->asTenant($gestionnaire)->getJson(self::URL)->assertJsonPath('data.0.code_inscription.valeur', 'BUR7K2');

        self::assertSame('BUR7K2', $classe->fresh()?->code_inscription);
    }

    public function test_la_charge_ne_porte_pas_d_effectif(): void
    {
        // `classes.effectif` n'est tenu que par le synchroniseur KLASSCI : pour
        // une classe locale il vaut 0, toujours. L'exposer, c'est afficher un
        // zéro faux (règle front #376).
        $ecole = $this->ecole();
        $this->classeLocale($ecole, 'Bureautique');

        $ligne = $this->asTenant($this->membre($ecole, 'coordinateur'))
            ->getJson(self::URL)
            ->json('data.0');

        self::assertIsArray($ligne);
        self::assertArrayNotHasKey('effectif', $ligne);
        self::assertSame(['id', 'libelle', 'code', 'training_session_id', 'code_inscription'], array_keys($ligne));
    }

    // ───────────────────────── LA garde de ce fichier

    public function test_les_classes_d_une_AUTRE_ecole_ne_sortent_pas(): void
    {
        $sienne = $this->ecole();
        $autre = $this->ecole();
        $this->classeLocale($sienne, 'La mienne', 'BUR7K2');
        $this->classeLocale($autre, 'La voisine', 'VZN3P8');

        $reponse = $this->asTenant($this->membre($sienne, 'coordinateur'))
            ->getJson(self::URL)
            ->assertStatus(200);

        self::assertSame(['La mienne'], array_column((array) $reponse->json('data'), 'libelle'));
        self::assertStringNotContainsString('VZN3P8', (string) $reponse->getContent(), 'Le code d\'une autre école a fuité.');
    }

    public function test_une_classe_miroir_KLASSCI_n_est_pas_une_classe_locale(): void
    {
        $ecole = $this->ecole();
        $this->classeLocale($ecole, 'Locale');
        Classe::factory()->create(['institution_id' => $ecole->getKey(), 'klassci_id' => 4242, 'libelle' => 'Miroir']);

        $reponse = $this->asTenant($this->membre($ecole, 'coordinateur'))->getJson(self::URL);

        self::assertSame(['Locale'], array_column((array) $reponse->json('data'), 'libelle'));
    }

    // ───────────────────────── les refus

    public function test_un_etablissement_KLASSCI_recoit_le_meme_refus_qu_a_l_emission(): void
    {
        // « Le même refus » se VÉRIFIE : on compare au message que l'émission
        // du code oppose réellement, pas à une copie écrite dans le test. Si
        // l'un des deux textes change seul, ce test rougit.
        $gestionnaire = $this->membre($this->ecole(InstitutionMode::Klassci), 'coordinateur');

        $lecture = $this->asTenant($gestionnaire)->getJson(self::URL)->assertStatus(403);
        $emission = $this->asTenant($gestionnaire)->postJson('/api/classes/1/code-inscription')->assertStatus(403);

        self::assertSame($emission->json('message'), $lecture->json('message'));
    }

    public function test_un_compte_de_plateforme_sans_ecole_ne_lit_aucun_code(): void
    {
        // Sans établissement, le scope multi-tenant ne filtre plus rien : ce
        // test garde la seule propriété qui empêche de lire les codes de TOUTES
        // les écoles d'un coup.
        $this->classeLocale($this->ecole(), 'Ecole A', 'BUR7K2');
        $this->classeLocale($this->ecole(), 'Ecole B', 'VZN3P8');
        $plateforme = User::factory()->create(['institution_id' => null, 'role' => 'supradmin']);

        $reponse = $this->asTenant($plateforme)
            ->getJson(self::URL)
            ->assertStatus(409);

        self::assertStringNotContainsString('BUR7K2', (string) $reponse->getContent());
        self::assertStringNotContainsString('VZN3P8', (string) $reponse->getContent());
    }

    #[DataProvider('gestionnaires')]
    public function test_chaque_role_de_gestion_lit_ses_codes(string $role): void
    {
        // `superAdmin` passe par une branche à part d'`EnsureRole` : chaque rôle
        // admis est éprouvé, pas seulement le coordinateur.
        $ecole = $this->ecole();
        $this->classeLocale($ecole, 'Bureautique', 'BUR7K2');

        $this->asTenant($this->membre($ecole, $role))
            ->getJson(self::URL)
            ->assertStatus(200)
            ->assertJsonPath('data.0.code_inscription.valeur', 'BUR7K2');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function gestionnaires(): array
    {
        return ['coordinateur' => ['coordinateur'], 'admin' => ['admin'], 'superAdmin' => ['superAdmin']];
    }

    #[DataProvider('publicsSansDroit')]
    public function test_ni_apprenant_ni_formateur_ne_lisent_les_codes(string $role): void
    {
        // Un code est un secret de six caractères : un apprenant qui lirait
        // ceux des autres classes y entrerait sans y avoir été invité.
        $ecole = $this->ecole();
        $this->classeLocale($ecole, 'Bureautique', 'BUR7K2');

        $reponse = $this->asTenant($this->membre($ecole, $role))
            ->getJson(self::URL)
            ->assertStatus(403);

        self::assertStringNotContainsString('BUR7K2', (string) $reponse->getContent());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function publicsSansDroit(): array
    {
        return ['apprenant' => ['etudiant'], 'formateur' => ['enseignant']];
    }

    public function test_sans_jeton_la_route_refuse(): void
    {
        $this->getJson(self::URL)->assertStatus(401);
    }

    // ───────────────────────── la pagination

    public function test_la_page_est_bornee_et_decrite(): void
    {
        $ecole = $this->ecole();
        for ($i = 1; $i <= 3; $i++) {
            $this->classeLocale($ecole, "Classe {$i}");
        }
        $gestionnaire = $this->membre($ecole, 'coordinateur');

        $this->asTenant($gestionnaire)->getJson(self::URL.'?per_page=2')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);

        // Au-delà de 100, la borne s'applique au lieu de tout rendre d'un coup.
        $this->asTenant($gestionnaire)->getJson(self::URL.'?per_page=5000')
            ->assertJsonPath('meta.per_page', 100);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function tailliesDePageDouteuses(): array
    {
        return [
            // Le défaut qu'un bornage en ligne produirait : `intval('abc')` vaut
            // 0, borné à 1 — une classe par page, en silence.
            'non numerique' => ['abc', 25],
            'vide' => ['', 25],
            'zero' => ['0', 1],
            'negatif' => ['-5', 1],
        ];
    }

    #[DataProvider('tailliesDePageDouteuses')]
    public function test_une_taille_de_page_douteuse_est_ramenee_jamais_refusee(string $saisie, int $attendue): void
    {
        $ecole = $this->ecole();
        $this->classeLocale($ecole, 'Bureautique');

        $this->asTenant($this->membre($ecole, 'coordinateur'))
            ->getJson(self::URL.'?per_page='.$saisie)
            ->assertStatus(200)
            ->assertJsonPath('meta.per_page', $attendue);
    }

    // ───────────────────────── harnais

    private function ecole(InstitutionMode $mode = InstitutionMode::Standalone): Institution
    {
        return Institution::factory()->create(['mode' => $mode]);
    }

    private function membre(Institution $ecole, string $role): User
    {
        return User::factory()->create(['institution_id' => $ecole->getKey(), 'role' => $role]);
    }

    private function classeLocale(Institution $ecole, string $libelle, ?string $code = null, bool $retire = false): Classe
    {
        return Classe::factory()->create([
            'institution_id' => $ecole->getKey(),
            'klassci_id' => null,
            'libelle' => $libelle,
            'code_inscription' => $code,
            'code_inscription_revoque_le' => $retire ? now() : null,
        ]);
    }
}
