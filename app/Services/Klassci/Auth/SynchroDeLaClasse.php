<?php

declare(strict_types=1);

namespace App\Services\Klassci\Auth;

/**
 * Ce qu'a fait la synchronisation de la classe KLASSCI d'un élève au login
 * ({@see StudentClassSynchronizer}, #969). Seule `Ecrite` touche à
 * `user_classes` ; les autres laissent l'élève avec sa ligne précédente.
 */
enum SynchroDeLaClasse: string
{
    /** La classe reçue est écrite, et elle seule reste. */
    case Ecrite = 'ecrite';

    /** KLASSCI dit explicitement que l'élève n'a pas de classe (`data.classe` à `null`). */
    case SansClasse = 'sans_classe';

    /** `data` ou `classe` absent ou mal formé, ou un identifiant non numérique. */
    case ReponseInattendue = 'reponse_inattendue';

    /** Une exception : l'appel à KLASSCI, ou l'écriture. */
    case Echec = 'echec';
}
