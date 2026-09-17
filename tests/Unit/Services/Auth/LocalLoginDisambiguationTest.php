<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Auth;

use App\Models\Institution;
use App\Models\User;
use App\Services\Auth\LocalLmsAuthenticator;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * #796 — le login local ne prend plus « le premier trouvé ».
 *
 * ## Le défaut
 *
 * En monde KLASSCI, l'unicité de l'identifiant venait de l'amont. Elle n'existe
 * plus : `users.name` n'a AUCUNE contrainte d'unicité, et `users.email` n'est
 * unique que par institution. `->first()` testait donc une seule ligne choisie
 * par le hasard de l'ordre SQL, et le second homonyme ne pouvait jamais se
 * connecter — sans le moindre message qui l'explique.
 *
 * Ce n'est pas un contournement : `Hash::check` restait exigé. C'est un déni de
 * service silencieux, et il est PROVOCABLE — `ImportApplyService` crée des
 * comptes dont le `name` vaut « prenom nom », si bien qu'un import dans un
 * établissement peut fabriquer la collision qui bloque quelqu'un d'un autre.
 *
 * ## Le départageur
 *
 * Le mot de passe : la seule information que seul le bon compte possède.
 *
 * @see docs/adr/2026-09-17-796-01-desambiguisation-du-login-local.md
 */
#[CoversClass(LocalLmsAuthenticator::class)]
final class LocalLoginDisambiguationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Hacheur qui ne dit « oui » que pour le mot de passe d'UN compte précis.
     *
     * L'empreinte attendue est relue sur le modèle et non écrite en dur : le
     * modèle `User` porte le cast `'password' => 'hashed'`, si bien que la
     * valeur stockée est un bcrypt et jamais la chaîne fournie au factory.
     * Comparer à cette chaîne faisait échouer tous les cas — pour la mauvaise
     * raison.
     */
    private function hasherAcceptant(string $clair, User $compte): Hasher
    {
        $empreinte = (string) $compte->password;

        $hasher = Mockery::mock(Hasher::class);
        $hasher->shouldReceive('check')
            ->andReturnUsing(
                static fn (string $propose, string $stocke): bool => $propose === $clair && $stocke === $empreinte
            );

        return $hasher;
    }

    private function compteDans(?Institution $ecole, string $nom, string $email, string $empreinte): User
    {
        return User::factory()->create([
            'institution_id' => $ecole?->id,
            'name' => $nom,
            'email' => $email,
            'password' => $empreinte,
        ]);
    }

    private function authentificateur(Hasher $hasher, ?LoggerInterface $logger = null): LocalLmsAuthenticator
    {
        return new LocalLmsAuthenticator($hasher, $logger ?? Mockery::spy(LoggerInterface::class));
    }

    public function test_le_second_homonyme_peut_enfin_se_connecter(): void
    {
        // Le cœur de #796. Avant, seule la première ligne était testée : la
        // seconde personne se voyait refuser sans explication.
        $premier = $this->compteDans(Institution::factory()->create(), 'Awa Kone', 'awa@ecole-a.ci', 'empreinte_a');
        $second = $this->compteDans(Institution::factory()->create(), 'Awa Kone', 'awa@ecole-b.ci', 'empreinte_b');

        $resultat = $this->authentificateur($this->hasherAcceptant('mdp_b', $second))
            ->attemptLocalAuth('Awa Kone', 'mdp_b');

        self::assertNotNull($resultat, 'Le second homonyme reste bloqué.');
        self::assertSame($second->id, $resultat->id);
        self::assertNotSame($premier->id, $resultat->id);
    }

    public function test_le_premier_homonyme_continue_de_se_connecter(): void
    {
        // Non-régression : désambiguïser ne doit pas déplacer le problème.
        $premier = $this->compteDans(Institution::factory()->create(), 'Awa Kone', 'awa@ecole-a.ci', 'empreinte_a');
        $this->compteDans(Institution::factory()->create(), 'Awa Kone', 'awa@ecole-b.ci', 'empreinte_b');

        $resultat = $this->authentificateur($this->hasherAcceptant('mdp_a', $premier))
            ->attemptLocalAuth('Awa Kone', 'mdp_a');

        self::assertSame($premier->id, $resultat?->id);
    }

    public function test_l_email_prime_sur_le_nom_d_un_autre_compte(): void
    {
        // Quelqu'un dont le NOM vaut l'EMAIL d'un autre ne doit pas détourner la
        // recherche : l'email est l'identifiant le plus spécifique, il est
        // interrogé en premier. C'est aussi ce qui garde le chemin nominal à une
        // seule vérification.
        $proprietaire = $this->compteDans(Institution::factory()->create(), 'Vrai Titulaire', 'cible@ecole.ci', 'empreinte_vraie');
        $this->compteDans(Institution::factory()->create(), 'cible@ecole.ci', 'usurpateur@ailleurs.ci', 'empreinte_usurpateur');

        $resultat = $this->authentificateur($this->hasherAcceptant('mdp_vrai', $proprietaire))
            ->attemptLocalAuth('cible@ecole.ci', 'mdp_vrai');

        self::assertSame($proprietaire->id, $resultat?->id);
    }

    public function test_le_chemin_nominal_ne_coute_qu_une_verification(): void
    {
        // Preuve que la correction n'introduit PAS d'amplification quand il n'y
        // a pas de collision : un seul candidat, un seul calcul bcrypt.
        $this->compteDans(Institution::factory()->create(), 'Seul Compte', 'seul@ecole.ci', 'empreinte');

        $hasher = Mockery::mock(Hasher::class);
        $hasher->shouldReceive('check')->once()->andReturn(true);

        self::assertNotNull($this->authentificateur($hasher)->attemptLocalAuth('seul@ecole.ci', 'mdp'));
    }

    public function test_deux_candidats_qui_correspondent_tous_les_deux_sont_refuses(): void
    {
        // Authentifier « l'un des deux » revient à authentifier personne en
        // particulier : la session porterait une identité devinée, et dans un
        // système multi-tenant elle ouvrirait un établissement à quelqu'un d'un
        // autre. Exige un identifiant ET un mot de passe identiques.
        $this->compteDans(Institution::factory()->create(), 'Jumeau', 'j@ecole-a.ci', 'meme_empreinte');
        $this->compteDans(Institution::factory()->create(), 'Jumeau', 'j@ecole-b.ci', 'meme_empreinte');

        $hasher = Mockery::mock(Hasher::class);
        $hasher->shouldReceive('check')->andReturn(true);

        self::assertNull($this->authentificateur($hasher)->attemptLocalAuth('Jumeau', 'mdp'));
    }

    public function test_l_ambiguite_vraie_est_journalisee_sans_l_identifiant(): void
    {
        // Plusieurs comptes partageant identifiant ET mot de passe est un signal
        // d'exploitation autant qu'un accident : il doit se voir. Mais le
        // journal n'a pas à devenir le canal où l'identifiant d'un inconnu
        // s'écrit en clair.
        $this->compteDans(Institution::factory()->create(), 'Jumeau', 'j@ecole-a.ci', 'e');
        $this->compteDans(Institution::factory()->create(), 'Jumeau', 'j@ecole-b.ci', 'e');

        $hasher = Mockery::mock(Hasher::class);
        $hasher->shouldReceive('check')->andReturn(true);

        // Les avertissements sont CAPTURÉS puis assertés, plutôt que vérifiés
        // par une attente Mockery : PHPUnit ne compte pas `shouldHaveReceived`
        // comme une assertion, et signalait le test comme « risqué » — vert sans
        // rien prouver, exactement ce qu'on cherche à éviter.
        $avertissements = [];
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldIgnoreMissing();
        $logger->shouldReceive('warning')->andReturnUsing(
            static function (string $message, array $contexte = []) use (&$avertissements): void {
                $avertissements[] = ['message' => $message, 'contexte' => $contexte];
            }
        );

        $this->authentificateur($hasher, $logger)->attemptLocalAuth('Jumeau', 'mdp');

        self::assertCount(1, $avertissements, 'Une ambiguïté vraie doit laisser une trace.');

        $trace = json_encode($avertissements[0], JSON_UNESCAPED_UNICODE);

        self::assertIsString($trace);
        self::assertStringNotContainsString('Jumeau', $trace, "L'identifiant d'un inconnu ne doit pas atterrir dans le journal.");
    }

    public function test_les_trois_premiers_homonymes_passent_le_plafond(): void
    {
        // Le plafond ne peut pas régresser sur l'existant : aujourd'hui UN seul
        // de N comptes homonymes peut se connecter. Ici, trois le peuvent.
        $ecoles = [Institution::factory()->create(), Institution::factory()->create(), Institution::factory()->create()];
        $this->compteDans($ecoles[0], 'Trio', 'a@x.ci', 'e1');
        $this->compteDans($ecoles[1], 'Trio', 'b@x.ci', 'e2');
        $troisieme = $this->compteDans($ecoles[2], 'Trio', 'c@x.ci', 'e3');

        $resultat = $this->authentificateur($this->hasherAcceptant('mdp3', $troisieme))
            ->attemptLocalAuth('Trio', 'mdp3');

        self::assertSame($troisieme->id, $resultat?->id);
    }

    public function test_au_dela_du_plafond_aucun_calcul_n_est_lance(): void
    {
        // Mesuré : une vérification bcrypt coûte ~442 ms. Sans plafond, quelqu'un
        // qui fabrique des homonymes — un import suffit — transforme le login en
        // amplificateur CPU. `never()` prouve que le refus a lieu AVANT tout
        // calcul, et pas seulement que le résultat est null.
        foreach (range(1, 4) as $i) {
            $this->compteDans(Institution::factory()->create(), 'Foule', "u{$i}@x.ci", "e{$i}");
        }

        $hasher = Mockery::mock(Hasher::class);
        $hasher->shouldReceive('check')->never();

        self::assertNull($this->authentificateur($hasher)->attemptLocalAuth('Foule', 'mdp'));
    }
}
