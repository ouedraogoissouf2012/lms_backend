<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Import\Fields;

use App\Enums\Role;
use App\Services\Import\Fields\RoleField;
use PHPUnit\Framework\TestCase;

/**
 * #718 — la colonne `role`, et le plafond qui l'encadre.
 *
 * Le fichier vient d'un tableur : il dit ce qu'il veut. Ce qui décide, c'est le
 * rôle de CELUI QUI IMPORTE. Sans plafond, la colonne serait une élévation de
 * privilège en libre-service — un enseignant fabriquerait des administrateurs
 * en tapant un mot dans Excel.
 */
final class RoleFieldTest extends TestCase
{
    private RoleField $field;

    protected function setUp(): void
    {
        parent::setUp();
        $this->field = new RoleField;
    }

    public function test_une_colonne_absente_donne_le_moindre_privilege(): void
    {
        // C'est le comportement historique, et c'est le bon défaut : un fichier
        // sans colonne `role` décrit des apprenants.
        $issue = $this->field->resolve('', Role::Admin);

        self::assertFalse($issue->isRejected());
        self::assertSame(Role::Etudiant->value, $issue->value);
    }

    public function test_un_role_sous_le_plafond_est_retenu(): void
    {
        $issue = $this->field->resolve('enseignant', Role::Coordinateur);

        self::assertSame(Role::Enseignant->value, $issue->value);
    }

    public function test_un_role_egal_au_plafond_est_retenu(): void
    {
        // Le plafond est un maximum, pas une frontière stricte : un enseignant
        // qui inscrit son binôme reste dans son propre périmètre.
        $issue = $this->field->resolve('enseignant', Role::Enseignant);

        self::assertSame(Role::Enseignant->value, $issue->value);
    }

    public function test_les_alias_connus_de_l_enum_sont_acceptes(): void
    {
        // `Role::tryFromString` porte déjà la table FR/EN (#121) : la dupliquer
        // ici la ferait diverger au premier alias ajouté.
        self::assertSame(Role::Enseignant->value, $this->field->resolve('teacher', Role::Admin)->value);
        self::assertSame(Role::Etudiant->value, $this->field->resolve('student', Role::Admin)->value);
    }

    public function test_la_casse_et_les_blancs_du_tableur_ne_font_pas_echouer(): void
    {
        self::assertSame(Role::Enseignant->value, $this->field->resolve('  ENSEIGNANT ', Role::Admin)->value);
    }

    public function test_superadmin_garde_sa_casse_propre(): void
    {
        // `superAdmin` est camelCase dans l'enum. Une minusculisation aveugle le
        // rendrait introuvable et le ferait refuser comme rôle inconnu.
        self::assertSame(Role::SuperAdmin->value, $this->field->resolve('superAdmin', Role::SuperAdmin)->value);
    }

    public function test_un_role_au_dessus_du_plafond_est_refuse(): void
    {
        $issue = $this->field->resolve('coordinateur', Role::Enseignant);

        self::assertTrue($issue->isRejected());
        self::assertSame('role_interdit', $issue->code);
    }

    public function test_le_role_plateforme_est_refuse_meme_a_qui_le_porte(): void
    {
        // Même politique qu'à la création via KLASSCI (#510) : `supradmin` est
        // cross-tenant et ne naît que du seeder, jamais d'une donnée d'entrée.
        $issue = $this->field->resolve('supradmin', Role::Supradmin);

        self::assertTrue($issue->isRejected());
        self::assertSame('role_plateforme', $issue->code);
    }

    public function test_un_mot_inconnu_est_refuse_et_non_rabattu_en_silence(): void
    {
        // Rabattre « responsable » sur `etudiant` rendrait « N acceptées » sur un
        // fichier que personne n'a compris. Le refus explicite est le service.
        $issue = $this->field->resolve('responsable', Role::Admin);

        self::assertTrue($issue->isRejected());
        self::assertSame('role_inconnu', $issue->code);
    }

    public function test_le_refus_porte_un_message_lisible_par_qui_corrige_son_fichier(): void
    {
        $issue = $this->field->resolve('coordinateur', Role::Enseignant);

        self::assertNotSame('', (string) $issue->message);
        self::assertStringContainsStringIgnoringCase('coordinateur', (string) $issue->message);
    }
}
