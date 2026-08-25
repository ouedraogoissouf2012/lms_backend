# Tasks — #591 Partitionnement du cache KLASSCI par porteur

Ordre imposé par le TDD strict : le test rouge d'abord, l'implémentation ensuite.

- [x] **1. Preuve du défaut (RED)**
  - [x] 1.1 Écrire `tests/Feature/Proxy/ProxyAcademicPerUserIsolationTest.php` :
        transport HTTP feinté routant la réponse d'après `Authorization: Bearer`,
        cache distribué réel, frontière de requête fidèle (`forgetScopedInstances`
        + `Route::flushController`). _Requirements: R1, R2, R3, R4, R5, R8_
  - [x] 1.2 Rouge constaté sur R1/R2 : Bob recevait la charge utile d'Alice
        (`11` au lieu de `22`, `33` au lieu de `44`). _Requirements: R8_

- [x] **2. Conception : deux itérations, la 1ʳᵉ réfutée par le test**
  - [x] 2.1 Itération A — dériver le porteur d'un `KlassciCredentialResolver`
        injecté : restée ROUGE. Instrumentation → Laravel mémoïse le contrôleur
        sur l'objet `Route` (seul Octane appelle `Route::flushController()`),
        donc le résolveur injecté survit à la requête. Écartée, cf. design §3/§5.
  - [x] 2.2 Itération B (retenue) — le porteur vient de l'objet `Request`, source
        per-requête par construction. _Requirements: R7_

- [x] **3. Isolation garantie par le typage**
  - [x] 3.1 `getEvaluations(string $userToken, array $filters = [])` et
        `getEmploiTemps(string $userToken, array $filters = [])` : le porteur
        devient obligatoire, l'appel non isolé devient inécrivable.
        _Requirements: R1, R2, R6_
  - [x] 3.2 Les deux passent par `requestWithUserToken()` → `generateUserTokenKey`.
  - [x] 3.3 `abstract public function requestWithUserToken(...)` ajouté au contrat
        du trait ; classification des endpoints documentée dans son docblock.
        _Requirements: R6_
  - [x] 3.4 Les 8 raccourcis catalogue restent sur `get()`. _Requirements: R5_

- [x] **4. Fail-secure côté contrôleur**
  - [x] 4.1 `personalKlassciToken(Request)` lit `$request->user()->klassci_token`.
        _Requirements: R7_
  - [x] 4.2 Absence de jeton personnel → 401, même message que
        `ProxyDashboardController` ; aucun repli institution/système.
        _Requirements: R3, R4_

- [x] **5. Validation (GREEN)**
  - [x] 5.1 `tests/Feature/Proxy` + garde structurel : **10/10 verts**.
  - [x] 5.2 Suite impactée verte : 407 passed / 1 skipped (segfault Windows
        pré-existant, couvert en CI) sur Auth + Security + Middleware +
        Unit/Services.
  - [x] 5.3 PHPStan : 0 erreur, baseline 336/443 inchangée.

- [x] **6. Audits & corrections**
  - [x] 6.1 `spec-security` (FAIL, 1 HIGH) et `spec-architect` (PASS, 2 MEDIUM)
        lancés sur le diff. Verdicts et suites : design §9.
  - [x] 6.2 Régression corrigée : garde jeton rentré dans le `try`
        (`klassci_token` = accesseur sur colonne castée `encrypted`).
  - [x] 6.3 Verdict d'audit erroné corrigé : `matieres`/`matieres/{id}` ne sont
        plus certifiés « clé globale correcte » ; avertissements portés sur les
        méthodes elles-mêmes. _Requirements: R9_
  - [x] 6.4 Classification rendue **exécutable**
        (`KlassciEndpointClassificationGuardTest`), capacité à échouer vérifiée
        par dégradation volontaire. _Requirements: R6_
  - [x] 6.5 5ᵉ cas de test : 2 institutions distinctes (§1.3).

- [ ] **7. Livraison**
  - [ ] 7.1 Accord user sur le commit et la PR.
  - [ ] 7.2 PR vers `lms` : diff, changement de comportement 401 assumé, audit
        §6, dettes signalées.
  - [ ] 7.3 Commenter #591 avec le verdict d'audit ; ouvrir les issues de suivi
        (matieres · classes/etudiants · trait jeton perso · Octane).
