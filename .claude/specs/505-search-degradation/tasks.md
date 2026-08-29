# Tasks — #505

- [x] 1. Contrat
  - [x] 1.1 `SearchAggregate` (VO : résultats + sources en échec) _(R1)_
  - [x] 1.2 Mise à jour délibérée du test de caractérisation des clés racine _(R3)_
- [x] 2. Extraction des sources KLASSCI
  - [x] 2.1 `KlassciSearchSources` — `classes` + `matieres`, pannes NON avalées _(R1, §1.1)_
  - [x] 2.2 Tests unitaires (filtrage, garde `isStaff`, propagation de la panne) _(R1, R4)_
- [x] 3. Dégradation et cache
  - [x] 3.1 Tests : 4 combinaisons de panne + durée de mémorisation selon l'état de santé _(R1, R2)_
  - [x] 3.2 `GlobalSearchService` : agrégation avec drapeau, TTL fonction de l'état de santé _(R1, R2)_
  - [x] 3.3 `SearchController` : relais de `sources_failed` _(R3)_
- [x] 4. Validation
  - [x] 4.1 Suite recherche + contrat verte
  - [x] 4.2 PHPStan 0 erreur, garde taille fichiers ≤ 300

- [x] 5. Corrections issues de la revue pré-merge
  - [x] 5.1 Déballage de l'enveloppe KLASSCI `{success, data}` — sans quoi le
        drapeau de dégradation serait allumé en PERMANENCE sur un service sain _(R1)_
  - [x] 5.2 TTL court + drapeau mémorisé, au lieu de « aucun cache » _(R2)_
  - [x] 5.3 Clé de cache versionnée et forme validée à la relecture _(R3)_
  - [x] 5.4 Journalisation de la classe et de la trace d'exception _(R1)_
  - [x] 5.5 Construction explicite de l'agrégat (plus de dépendance à l'ordre
        d'évaluation des arguments) _(R1)_
  - [x] 5.6 Tests unitaires de `KlassciSearchSources` (tâche 2.2, non livrée au
        premier passage) _(R1, R4)_
