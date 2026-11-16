# ADR-919-01 — `error_code` retiré du contrat

**Date :** 2026-11-16  
**Statut :** accepté  
**Issue :** #919 · prolonge ADR-906-01 (le motif d'un refus, dans le corps)

## Décision

Le contrat décrit le corps d'erreur que l'API émet **réellement** :

```json
{ "success": false, "message": "…" }
```

avec, en option, `errors` (le détail par champ d'un 422) et `reason` (le motif d'un statut à plusieurs sens, ADR-906-01). **`error_code` n'en fait plus partie.** `reason` est la seule clé machine.

- `docs/openapi.yaml`, schéma `ErrorResponse` : `required: [success, message]`, et les propriétés `errors` et `reason` décrites ;
- `scripts/openapi-validator.py` (`check_error_responses`) refuse qu'`ErrorResponse` exige une propriété que l'API n'émet pas ;
- les guides (`docs/README.md`, `API_MAINTENANCE_GUIDE.md`, `ADDING_NEW_ENDPOINTS.md`, `API_VALIDATION.md`) décrivent le corps réel et renvoient à `reason`.

## Pourquoi retirer, et non émettre

Mesuré sur `lms` (05e00810), le 16/11 :

- **Rien ne l'émet.** `git grep error_code -- app bootstrap config routes` rend 0. Les corps d'erreur sortent de `RespondsWithJson::errorResponse()` et des rendus de `bootstrap/app.php` (401, 422, 429, 503 KLASSCI, `\Throwable`) : `success`, `message`, `errors?`, `reason?`.
- **Le contrat l'exigeait sur 72 réponses**, 25 opérations. Chacune décrivait un corps inexistant, et un client généré attendait un champ obligatoire jamais reçu.
- **Personne ne le lit.** Le front (`frontend_lms`, `origin/dev:src`) ne lit ni `error_code` ni `errorCode`. Le SDK TypeScript n'est généré qu'en test de fumée (`security.yml`, job `sdk-generation`), et n'est pas publié.
- **ADR-906-01 l'avait déjà écarté** : « exigé par le schéma `ErrorResponse` mais envoyé par aucun endpoint ; le précédent réellement lu par le front est `reason` ».

L'émettre aurait ajouté un **troisième** code machine (à côté de `reason` et du `code` de l'import), à attribuer à 72 réponses sans lecteur. Le contrat aurait gardé deux clés pour un seul rôle, ce qu'ADR-906-01 a refusé.

## Ce que cela change pour un client

Un client généré depuis la spec perd une propriété obligatoire qu'il n'a jamais reçue. Aucun client connu ne s'en servait.

## Ce qui reste

- **`MessageOnly`** (pilote #958) a été créé pour décrire un 401 « sans `error_code` ». Il reste en place : il est exact, et les deux schémas acceptent désormais ce corps. Les fusionner n'est pas nécessaire.
- **Les autres codes machine** que l'API émet hors de `reason`, à savoir `code` (`ImportPreviewController`) et des `reason` en français (`InstitutionConnectionTester`), restent hors périmètre, comme dans ADR-906-01.
- **La preuve** : `tests/Feature/Docs/ErrorResponseCorpsReelTest.php` valide le corps réel de trois statuts typés `ErrorResponse` (401, 422, 404) avec le validateur de contrat du dépôt. Une garde refuse que la spec décrive de nouveau `error_code`.
