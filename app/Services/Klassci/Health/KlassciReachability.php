<?php

declare(strict_types=1);

namespace App\Services\Klassci\Health;

/**
 * « Cette cible KLASSCI répond-elle, et en combien de temps se connecte-t-on ? »
 *
 * Interface d'un seul membre (§1.6 I), nommée par la préoccupation et non par
 * l'implémentation. Elle existe pour rendre la sonde substituable : le seul test
 * du dépôt qui traverse réellement le transport se saute sous Windows
 * (`Http::fake` + `ConnectionException` y termine le processus), donc une
 * mesure appelant le réseau en dur ne serait vérifiable qu'en CI.
 *
 * @see InMemoryKlassciReachability  double sans réseau (§1.6 L)
 * @see HttpKlassciReachability      mesure réelle
 */
interface KlassciReachability
{
    public function probe(string $baseUrl): ReachabilityMeasure;
}
