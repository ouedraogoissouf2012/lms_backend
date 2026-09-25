# ADR-905-01 — La collection des classes locales, et leur code

**Date :** 2026-09-25  
**Statut :** accepté  
**Issue :** #905 · prolonge [ADR-848-01](2026-09-18-848-01-catalogue-pedagogique-local.md), [ADR-803-03](2026-09-15-803-03-trois-portes-un-service.md) et [ADR-760-01](2026-09-19-760-01-deux-portes-deux-espaces.md)

## Décision

`GET /classes` est la **collection** de `POST /classes` : même chemin, même groupe, mêmes rôles (`coordinateur`, `admin`, `superAdmin`), même droit (`CatalogueAuthority`).

Elle rend les classes **locales** — sans `klassci_id` — de l'établissement de l'appelant, triées par libellé, paginées (25 par défaut, 100 au plus). Chacune porte l'état de son code d'inscription :

| `etat` | `valeur` |
|---|---|
| `actif` | le code |
| `retire` | `null` |
| `absent` | `null` |

## Pourquoi

**Rien ne listait les classes locales.** La liste d'administration vient de KLASSCI ; `GET /lms/classes/local/{id}` traduit vers KLASSCI (ADR-760-01) et répond donc 409 à une classe qui n'y existe pas — précisément celles qui portent un code.

**Le code ne se relisait pas.** Seul `POST …/code-inscription` le rendait. Relire revenait à régénérer, donc à **invalider le code déjà dicté**. Le rendre avec la classe règle la relecture sans aucun appel de plus.

**Un code retiré ne sort plus.** Sa valeur reste en base pour garder l'unicité d'un code tout juste dicté, mais l'afficher inviterait à dicter un code qui n'ouvre rien.

**Pas d'`effectif`.** Seul le synchroniseur KLASSCI tient cette colonne ; pour une classe locale elle vaut 0, toujours.

**L'état est une énumération, `App\Enums\EtatCodeInscription`**, dérivée des deux colonnes et jamais stockée — même forme que `TrainingSessionPhase`. Elle porte aussi la règle « la valeur ne sort que si le code est actif » (`valeurVisible`), en un seul endroit.

**L'établissement est contrôlé avant le droit.** Sans établissement, la capacité rend faux, et un compte de plateforme recevrait « cet établissement vient de KLASSCI », faux pour qui n'en a aucun. Il reçoit 409, et aucune classe d'aucune école ne sort.

**`per_page` passe par une FormRequest** (`ListerClassesLocalesRequest`), qui garde le contrat « ramené, jamais refusé » de la liste des Périodes sans son défaut : un bornage en ligne par `Request::integer()` fait de `?per_page=abc` une page d'**une** classe, en silence.

## Écarté

- **`GET /classes/{classe}/code-inscription` séparé** : deux appels pour une information que la liste porte déjà.
- **Élargir `/lms/classes/local/{id}`** : cette porte parle KLASSCI par construction.

## Ce qui ferait changer d'avis

Qu'un apprenant doive lire le code de sa propre classe pour le transmettre : la garde de rôle serait alors à revoir. Rien ne le prévoit aujourd'hui.
