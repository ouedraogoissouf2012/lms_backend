<?php

declare(strict_types=1);

namespace Tests\Feature\Klassci;

use App\Models\Institution;
use App\Models\Seance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le harnais qui rend la campagne #805 vérifiable : provoquer une VRAIE
 * collision entre les deux espaces d'identifiants, et prouver qui gagne.
 *
 * ## Pourquoi il n'existait pas, et pourquoi il manquait
 *
 * Treize correctifs vont remplacer des résolutions écrites à la main par
 * `Seance::localIdFor()`. Chacun affirmera supprimer une indétermination — mais
 * aucun test du dépôt ne savait reproduire cette indétermination. Les douze
 * auraient donc été des déclarations d'intention, vertes par construction.
 *
 * Le seul test voisin, `KlassciIdentifierMintingTest` (#682), garantit
 * l'inverse : que les fixtures n'entrent JAMAIS en collision avec les
 * identifiants écrits à la main. C'est précisément pour cela qu'une collision
 * doit être forcée ici, ligne à ligne.
 *
 * ## Deux pièges de mesure, tous deux payés dans ce dépôt
 *
 * 1. **Aucun identifiant n'est écrit en dur.** Un test qui suppose `id = 1` est
 *    vert sous SQLite et rouge sous MySQL dès qu'il n'est plus le premier de la
 *    suite. Les identifiants sont donc CRÉÉS puis RELUS.
 * 2. **Le verdict appartient à la jambe MySQL.** Une requête portant sur une
 *    colonne inexistante est silencieusement vraie sous SQLite. Un vert local ne
 *    prouve rien ici ; c'est la matrice de la CI qui tranche.
 *
 * Éprouvé à travers `Seance`, la seule entité duale dont la colonne miroir ne
 * s'appelle pas `klassci_id` — donc celle qui exerce réellement la surcharge
 * posée par #864.
 */
final class SeanceIdentifierCollisionTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::factory()->create();
    }

    /**
     * Le cœur du harnais : deux lignes, un seul nombre, deux significations.
     *
     * La séance LOCALE a l'identifiant N dans l'espace local. La séance MIROIR
     * porte ce même N dans `klassci_seance_id`. Demander N doit rendre la
     * locale — invariant 1 du trait — et cela ne peut pas dépendre du moteur.
     */
    public function test_sur_collision_l_espace_local_gagne(): void
    {
        [$locale, $miroir] = $this->collision();

        self::assertSame(
            $locale->id,
            Seance::localIdFor($locale->id, $this->institution->id),
            'la ligne locale doit gagner sur son propre identifiant',
        );
        self::assertNotSame($miroir->id, Seance::localIdFor($locale->id, $this->institution->id));
    }

    /**
     * La question symétrique : quand l'espace est CONNU, on ne devine plus.
     *
     * `localIdForKlassciId()` existe pour les payloads KLASSCI, dont l'espace
     * n'a aucune ambiguïté à arbitrer. Sur la même collision, elle doit rendre
     * le MIROIR — l'inverse de la méthode précédente, sur la même entrée.
     */
    public function test_quand_l_espace_est_connu_le_miroir_gagne(): void
    {
        [$locale, $miroir] = $this->collision();

        self::assertSame(
            $miroir->id,
            Seance::localIdForKlassciId($locale->id, $this->institution->id),
            'un identifiant venu d un payload KLASSCI désigne le miroir, jamais la ligne locale',
        );
    }

    /**
     * Le défaut que ce test fige — et la correction d'une erreur que j'allais
     * graver dans les douze correctifs suivants.
     *
     * `ResolveInstitution` laisse un compte sans institution — supradmin — passer
     * NON SCOPÉ, et son commentaire le dit : « intentionally cross-tenant,
     * proceeds unscoped ». Le trait, lui, pose un `where institution_id`
     * inconditionnel.
     *
     * J'en avais conclu qu'il fallait fournir le tenant RÉSOLU plutôt que
     * `$user->institution_id`. **La mesure dit l'inverse** : le tenant n'est
     * peuplé QUE depuis l'`institution_id` du jeton (`ResolveInstitution:134`),
     * et le repli par en-tête `X-Institution` n'est atteint que SANS jeton
     * (`:63-69`). Sur toute requête authentifiée les deux valeurs sont donc
     * égales, nulles comprises. Changer de source n'aurait rien corrigé — et
     * aurait fait passer douze correctifs pour une correction de sécurité.
     *
     * Ce qui reste vrai, et que ce test garde : sans tenant, on ne résout RIEN.
     * Pas « tout », pas « au hasard » — rien. C'est le `null` absorbant
     * d'ADR-792-01 appliqué aux identifiants, et c'est le bon défaut.
     */
    public function test_un_tenant_nul_ne_resout_aucune_seance_d_institution(): void
    {
        $seance = $this->seance(klassciSeanceId: null);

        self::assertNull(
            Seance::localIdFor($seance->id, null),
            'résoudre avec un tenant nul ne doit PAS rendre une séance appartenant à une institution',
        );
    }

    /**
     * Le bornage tenant, sur la collision elle-même : deux institutions peuvent
     * légitimement porter le même `klassci_seance_id`.
     */
    public function test_la_collision_ne_franchit_pas_la_frontiere_de_l_institution(): void
    {
        [$locale] = $this->collision();
        $autre = Institution::factory()->create();

        self::assertNull(Seance::localIdFor($locale->id, $autre->id));
    }

    /**
     * Une séance purement locale — aucun identifiant KLASSCI — doit se résoudre
     * par sa clé primaire. C'est le cas du formateur autonome, et la raison
     * d'être de toute la campagne.
     */
    public function test_une_seance_sans_identifiant_klassci_se_resout(): void
    {
        $seance = $this->seance(klassciSeanceId: null);

        self::assertSame($seance->id, Seance::localIdFor($seance->id, $this->institution->id));
        self::assertNull(Seance::localIdForKlassciId($seance->id, $this->institution->id));
    }

    /**
     * Deux séances de la MÊME institution dont l'une porte, en `klassci_seance_id`,
     * l'identifiant local de l'autre.
     *
     * L'ordre de création compte : la locale naît d'abord, son identifiant est
     * RELU, puis le miroir l'emprunte. Écrire un nombre en dur ici rendrait le
     * test vert sous SQLite et faux sous MySQL.
     *
     * @return array{0: Seance, 1: Seance}
     */
    private function collision(): array
    {
        $locale = $this->seance(klassciSeanceId: null);
        $miroir = $this->seance(klassciSeanceId: $locale->id);

        self::assertNotSame(
            $locale->id,
            $miroir->id,
            'les deux lignes doivent être distinctes, sans quoi il n y a pas de collision à arbitrer',
        );

        return [$locale, $miroir];
    }

    private function seance(?int $klassciSeanceId): Seance
    {
        return Seance::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_seance_id' => $klassciSeanceId,
        ]);
    }
}
