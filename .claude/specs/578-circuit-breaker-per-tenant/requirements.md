# Requirements — #578 · Cloisonner le circuit breaker KLASSCI par tenant

> Sous-issue de #563 · Sévérité **P1 — ÉLEVÉ** (défaut d'isolation des pannes :
> une école en panne dégrade toutes les autres).

## Contexte

`app/Services/Klassci/KlassciCircuitBreaker.php` protège les appels sortants vers
KLASSCI (`KlassciHttpClient::executeHttp()`). Son état (compteur d'échecs +
horodatage d'ouverture) est stocké dans le cache sous **deux constantes
littérales**, sans aucune dimension de cible réseau :

```php
private const FAILURES_KEY   = 'klassci:circuit:failures';
private const OPEN_UNTIL_KEY = 'klassci:circuit:open_until';
```

Or chaque institution possède sa propre instance KLASSCI
(`institutions.klassci_api_url`), résolue par `KlassciConfigResolver::baseUrl()`
selon une priorité 3-tiers (token perso / token institution / token global).

Un état de disjoncteur **global** dans une application **multi-tenant** est à la
fois un faux positif et un faux négatif dès qu'il y a plus d'un tenant actif.

## Exigences (format EARS)

### R1 — Isolation des ouvertures (faux positif éliminé)

- **1.1** WHEN N échecs consécutifs surviennent sur la cible KLASSCI de
  l'institution A (seuil `circuit_breaker_failures`, défaut 3), the system SHALL
  ouvrir le disjoncteur **uniquement** pour les appels dont la cible résolue est
  celle de A.
- **1.2** WHILE le disjoncteur de A est ouvert, WHEN un appel vise la cible d'une
  institution B distincte, the system SHALL considérer le disjoncteur de B comme
  **fermé** (B répond normalement).

### R2 — Isolation des compteurs (faux négatif éliminé)

- **2.1** WHEN un appel réussit sur la cible de B, the system SHALL réinitialiser
  **uniquement** l'état de la cible de B et SHALL NOT effacer le compteur
  d'échecs de la cible de A.

### R3 — Partage légitime entre institutions co-hébergées

- **3.1** WHERE deux institutions résolvent la **même** URL de base KLASSCI, the
  system SHALL faire partager à ces deux institutions **un seul et même** état de
  disjoncteur (une panne du serveur partagé les protège toutes les deux).

### R4 — Repli global sans cible résolue

- **4.1** IF aucune URL de base ne peut être résolue (`baseUrl()` retourne `null`
  ou une chaîne vide), the system SHALL utiliser une partition de repli unique
  identifiée par le jeton `default` (clés `klassci:circuit:default:*`).

### R5 — Dérivation par injection (DI stricte)

- **5.1** The system SHALL dériver la partition depuis `KlassciConfigResolver::baseUrl()`
  via une **abstraction injectée au constructeur**, sans accès statique ni Facade
  (§1.6 D) et sans créer de cycle de dépendances avec `KlassciHttpClient`.
- **5.2** L'abstraction injectée SHALL être **substituable par un double de test**
  sans contournement (§1.6 L) — le résolveur concret étant `final`, la dépendance
  passe par une interface fine (§1.6 I).

### R6 — Sémantique préservée par partition

- **6.1** The system SHALL conserver, **par partition**, la sémantique existante :
  seuil (`circuit_breaker_failures`), fenêtre (`circuit_breaker_window`),
  cooldown (`circuit_breaker_cooldown`) et interrupteur
  (`circuit_breaker_enabled`), sans changement de contrat observable pour un
  tenant isolé.
- **6.2** The system SHALL garder la partition **stable** pour une même cible sur
  toute la durée d'une requête (isOpen → reportFailure/reportSuccess visent la
  même partition).

## Contraintes non-fonctionnelles (PRODUCTION_STANDARDS.md)

- §1.1 — Aucun fichier > 300 lignes ; §5 — méthodes ≤ 40 lignes.
- §1.6 — SOLID + DI strict, substituable par mock sans contournement.
- §1.3 — `php artisan test` 100 % ; PHPStan **level 9** vert (0 erreur).
- §1.2 — Aucun secret loggé/exposé (l'URL de base peut contenir un hôte mais
  jamais de token ; la partition est un **hash**, pas l'URL en clair).

## Critères de fermeture (issue #578)

- [ ] Test à deux tenants : 3 échecs sur A → A reçoit 503, **B répond normalement**.
- [ ] Test : un succès sur B n'efface pas le compteur d'échecs de A.
- [ ] Test : deux institutions partageant la même URL KLASSCI partagent le disjoncteur.
- [ ] `php artisan test` 100 %, PHPStan level 9 vert.
