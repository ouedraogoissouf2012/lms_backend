<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\EvaluationSubmission;
use App\Models\User;
use PHPUnit\Framework\TestCase;

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
 * (`Query/Builder.php:936-937`). Le jour où #798 rendra la colonne nullable
 * pour accueillir les élèves sans identité KLASSCI, la forme non gardée
 * attribuerait à chacun d'eux TOUTES les copies sans propriétaire KLASSCI.
 * La garde est écrite avant ce jour, et ce test la tient d'ici là.
 */
final class EvaluationSubmissionOwnedByTest extends TestCase
{
    public function test_un_eleve_sans_identite_klassci_n_interroge_pas_par_whereNull(): void
    {
        $sansKlassci = new User;
        $sansKlassci->klassci_id = null;

        $sql = EvaluationSubmission::query()->ownedBy($sansKlassci)->toSql();

        self::assertStringNotContainsString(
            'klassci_etudiant_id" is null',
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
        $avecKlassci->klassci_id = 4242;

        $sql = EvaluationSubmission::query()->ownedBy($avecKlassci)->toSql();

        self::assertStringContainsString('klassci_etudiant_id', $sql);
        self::assertStringNotContainsString('1 = 0', $sql);
    }
}
