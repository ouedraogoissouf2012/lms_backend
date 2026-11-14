<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\EvaluationSubmission;
use App\Models\User;
use Tests\TestCase;

/**
 * La portée de propriété ne doit JAMAIS interroger avec un identifiant nul.
 *
 * ## Pourquoi l'assertion porte sur le SQL
 *
 * Parce qu'aucune assertion de comportement ne peut discriminer ici :
 * `klassci_etudiant_id` est `NOT NULL`, donc un `whereNull` sur cette colonne
 * ne ramène rien — exactement comme la garde. Les deux formes rendent le même
 * résultat aujourd'hui, et une mutation de la garde resterait verte.
 *
 * Le seul témoin honnête est donc la requête émise, comme l'ADR-760-01 assertait
 * l'URL envoyée à KLASSCI plutôt que le code HTTP rendu : la porte fautive
 * répondait 200 elle aussi.
 *
 * ## Ce que la garde empêche réellement
 *
 * Laravel traduit `where($colonne, null)` en `whereNull($colonne)`
 * (`Query/Builder.php:936-937`). Ce jour est arrivé avec D72 (E2a) :
 * `klassci_etudiant_id` est nullable, et `student_id` l'était déjà. La forme
 * non gardée attribuerait à un compte sans id, ou sans identité KLASSCI,
 * TOUTES les copies sans auteur de son établissement.
 *
 * ## Le SQL est lu sans les guillemets du moteur
 *
 * SQLite cite un identifiant entre guillemets doubles, MySQL entre accents
 * graves. Une assertion écrite avec l'une des deux formes rougissait sur
 * l'autre jambe de la CI, ou pire, pour une assertion NÉGATIVE, y passait
 * sans rien prouver. {@see self::sql()} retire les deux.
 */
final class EvaluationSubmissionOwnedByTest extends TestCase
{
    public function test_un_compte_sans_id_ni_identite_klassci_n_interroge_pas_par_whereNull(): void
    {
        // L'établissement est posé : c'est la garde de l'id nul, et non celle
        // de l'établissement, qui doit produire le `1 = 0`.
        $sansKlassci = new User;
        $sansKlassci->klassci_id = null;
        $sansKlassci->institution_id = 7;

        $sql = self::sql($sansKlassci);

        self::assertStringNotContainsString('student_id is null', $sql, 'Un id nul ne doit jamais devenir un whereNull.');
        self::assertStringNotContainsString(
            'klassci_etudiant_id is null',
            $sql,
            'La portee interroge avec un identifiant nul : Laravel en fait un whereNull, '
            .'et les copies sans proprietaire KLASSCI deviendraient celles de cet eleve.',
        );
        self::assertStringContainsString('1 = 0', $sql, 'La portee doit ne rien ramener, explicitement.');
    }

    public function test_un_eleve_klassci_est_filtre_sur_sa_colonne(): void
    {
        // Contraste : sans ce cas, rien n'etablirait que la portee filtre
        // vraiment — une portee qui ne ramene jamais rien passerait le premier
        // test sans remplir son office.
        $avecKlassci = new User;
        // Un compte réel a toujours un id : sans lui, la garde de l'id nul
        // (D72, E2a) fermerait la portée avant le filtre KLASSCI.
        $avecKlassci->id = 12;
        $avecKlassci->klassci_id = 4242;
        $avecKlassci->institution_id = 7;

        $sql = self::sql($avecKlassci);

        self::assertStringContainsString('klassci_etudiant_id', $sql);
        self::assertStringContainsString('institution_id', $sql, 'L identifiant KLASSCI n est unique qu au sein d un etablissement.');
        self::assertStringNotContainsString('1 = 0', $sql);
    }

    public function test_un_eleve_du_lms_est_filtre_sur_son_auteur_sans_branche_klassci(): void
    {
        // D72, E2a : sans identité KLASSCI, la copie appartient par
        // `student_id`, et la branche KLASSCI ne s'écrit pas (sinon un
        // whereNull sur `klassci_etudiant_id`).
        $eleve = new User;
        $eleve->id = 12;
        $eleve->klassci_id = null;
        $eleve->institution_id = 7;

        $sql = self::sql($eleve);

        self::assertStringContainsString('student_id = ?', $sql);
        self::assertStringNotContainsString('klassci_etudiant_id', $sql);
        self::assertStringNotContainsString('1 = 0', $sql);
    }

    public function test_un_eleve_sans_etablissement_n_interroge_pas_par_whereNull(): void
    {
        // Sans établissement, l'identifiant KLASSCI seul désignerait les copies
        // de toutes les écoles ; et `where('institution_id', null)` deviendrait
        // un whereNull, qui ramènerait les copies orphelines.
        $sansEtablissement = new User;
        $sansEtablissement->klassci_id = 4242;
        $sansEtablissement->institution_id = null;

        $sql = self::sql($sansEtablissement);

        self::assertStringNotContainsString('institution_id is null', $sql);
        self::assertStringContainsString('1 = 0', $sql, 'La portee doit ne rien ramener, explicitement.');
    }

    /** La requête de la portée, sans les guillemets d'identifiant de SQLite ni de MySQL. */
    private static function sql(User $compte): string
    {
        return str_replace(['"', '`'], '', EvaluationSubmission::query()->ownedBy($compte)->toSql());
    }
}
