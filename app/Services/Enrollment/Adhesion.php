<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

/**
 * Issue d'un « rejoindre » réussi (#885).
 *
 * Deux issues et non un booléen, parce que la porte les rend différemment :
 * 201 quand une adhésion naît, 200 quand l'apprenant était déjà là. Le second
 * cas n'est pas une faute — un double clic, un lien rouvert depuis WhatsApp —
 * et le traiter en erreur ferait croire à l'apprenant qu'il n'est pas inscrit.
 *
 * Le refus, lui, n'est pas une issue : c'est une exception, comme tous les
 * refus des portes HTTP de ce module.
 */
enum Adhesion
{
    case Nouvelle;
    case DejaActive;
}
