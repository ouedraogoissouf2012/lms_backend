# Stratégie de test des SDKs clients (#222)

Les SDKs clients (TypeScript, Python, Go, Swift, Java, JS) sont générés depuis
la spec OpenAPI par `scripts/generate-sdks.sh` (openapi-generator). Cette page
définit comment on garantit leur qualité — sans théâtre de test.

## Étape 1 — Smoke test de génération (actif en CI)

**Invariant garanti aujourd'hui** : la spec `docs/openapi.yaml` doit toujours
**générer un SDK sans erreur**. C'est le vrai prérequis : une spec cassée, un
`$ref` non résolu ou un schéma invalide font échouer la génération.

Le job CI **SDK Generation (smoke test)** (`.github/workflows/security.yml`)
génère un SDK `typescript-fetch` à chaque PR et échoue si aucun artefact n'est
produit. Couplé à :

- **Docs Sync** (#213) — tout endpoint documenté existe réellement en route ;
- **Validator OpenAPI** (#220) — la spec respecte les standards internes ;

…cela donne la chaîne : *le code et la doc concordent → la spec est valide →
un SDK se génère*. Une régression à n'importe quel maillon bloque la PR.

## Étape 2 — Tests contractuels (à activer à la première publication SDK)

Tester un SDK **contre l'API réelle** dans une app de démo n'a de valeur que
lorsqu'un SDK est **publié et consommé**. Ce n'est pas encore le cas, et
l'OpenAPI est partiel (≈ 26/152 routes documentées). Monter aujourd'hui des
apps de démo par langage serait coûteux et de faible valeur.

Plan à exécuter **quand un SDK sera publié** :

1. **Couverture spec** : porter l'OpenAPI à 100 % des endpoints du SDK ciblé.
2. **App de démo minimale** par langage publié dans `examples/<lang>/`, qui
   exerce les flux clés (login → action → lecture).
3. **API éphémère en CI** : démarrer l'app Laravel (sqlite + seed) et lancer la
   démo SDK contre elle ; asserts sur les réponses.
4. **Tests contractuels** : vérifier que les types générés correspondent aux
   réponses réelles (ex. via Pact ou des assertions de schéma).
5. **Versioning** : un SDK épingle `/api/v1` (cf. `docs/API_VERSIONING.md`) ;
   un nouveau SDK majeur accompagne un `/api/v2`.

## Dette tracée

L'étape 2 est **volontairement reportée** jusqu'à la première publication d'un
SDK consommé — décision documentée ici plutôt que masquée par des tests de
façade. Rouvrir une issue dédiée à ce moment-là.
