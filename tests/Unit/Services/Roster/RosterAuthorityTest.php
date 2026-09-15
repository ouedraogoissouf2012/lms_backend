<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Roster;

use App\Services\Roster\KlassciRosterAuthority;
use App\Services\Roster\LocalRosterAuthority;
use PHPUnit\Framework\TestCase;

/**
 * #805 — qui écrit le roster d'un établissement.
 *
 * Deux implémentations d'un même contrat, chacune muette sur le « mode » :
 * l'appelant demande un DROIT, jamais une nature d'établissement. C'est ce qui
 * permet d'ouvrir plus tard l'inscription locale à d'autres cas sans toucher
 * une seule vue ni un seul contrôleur (article 1 de l'épique #697).
 */
final class RosterAuthorityTest extends TestCase
{
    public function test_un_roster_klassci_interdit_l_inscription_locale(): void
    {
        // Les élèves viennent de la synchronisation : une écriture locale
        // créerait une seconde liste à côté de celle qui fait foi.
        self::assertFalse((new KlassciRosterAuthority)->allowsLocalEnrolment());
    }

    public function test_un_roster_local_autorise_l_inscription(): void
    {
        self::assertTrue((new LocalRosterAuthority)->allowsLocalEnrolment());
    }

    public function test_les_deux_implementations_honorent_le_meme_contrat(): void
    {
        // Substituabilité : un appelant typé sur l'interface ne peut pas
        // distinguer laquelle il tient, sinon la question du mode ressortirait
        // chez lui.
        foreach ([new KlassciRosterAuthority, new LocalRosterAuthority] as $autorite) {
            self::assertIsBool($autorite->allowsLocalEnrolment());
        }
    }
}
