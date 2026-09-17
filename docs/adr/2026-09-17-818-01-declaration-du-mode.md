# ADR-818-01 — Le mode se déclare à la création, et se bascule par une route dédiée

**Date :** 2026-09-17  
**Statut :** accepté  
**Issue :** #818 · épique #805

## Décision

`institutions.mode` devient déclarable depuis l'écran d'administration, en trois gestes distincts :

1. **À la création**, `POST /admin/institutions` accepte `mode`, borné à `App\Enums\InstitutionMode`. Absent, le modèle retombe sur `klassci`.
2. **Le `PUT` de mise à jour ne l'accepte PAS**, et ne doit jamais l'accepter.
3. **La bascule a sa propre route** : `PATCH /admin/institutions/{id}/mode`, corps `{"mode": "standalone"|"klassci"}`, tracée dans `audit_logs` sous `institution.mode_changed`.

Le mode est aussi **rendu en lecture** (`buildListItem`), sans quoi l'écran ne saurait ni l'afficher ni proposer la bascule.

## Pourquoi

### Le manque, mesuré

`InstitutionController` ne contenait pas une seule occurrence du mot `mode`. Le seul écrivain de la colonne dans toute l'application était `SchoolRequestDecisionService:97`, au bout du parcours de demande publique.

Conséquence : toute institution créée par le supradmin depuis l'écran naissait `klassci` et le restait à jamais. Comme la porte de l'assistant d'import est `allowsLocalEnrolment()` (`ImportPreviewRequest:48`), ces écoles recevaient un **403**, quoi que fasse l'exploitant. La fonctionnalité livrée en #718 était hors d'atteinte pour tout établissement ne venant pas d'une demande validée.

### Pourquoi la bascule n'est pas un champ du `PUT`

C'est le point que #818 signalait comme « pas mineur ». Le laisser passer dans la mise à jour générique en ferait l'effet de bord possible d'un changement de couleur ou de logo — un `PUT` partiel envoyé par un formulaire qui recopie son état complet suffirait à basculer l'autorité d'inscription d'un établissement entier.

Le dépôt tranche déjà ce type de question : `is_active` n'est pas un champ du `PUT`, il a `PATCH /{id}/toggle`. La bascule de mode suit ce précédent.

### Pourquoi exiger le mode voulu plutôt que l'inverser

`toggle` inverse un booléen, ce qui est sans ambiguïté sur deux valeurs *figées*. `InstitutionMode` en a deux aujourd'hui et peut en avoir trois demain : une inversion implicite deviendrait alors un piège silencieux. Le mode cible est donc exigé dans le corps — **le dire, c'est le vouloir**, et c'est aussi la confirmation explicite que l'issue réclamait.

### Pourquoi la bascule n'a pas besoin d'une garde « établissement déjà peuplé »

L'issue craignait qu'elle « change l'autorité d'inscription pour tous ses élèves d'un coup ». La mesure dit autre chose, et c'est ce qui permet de trancher :

- `mode` n'est lu **qu'à un seul endroit** : `RosterAuthorityFactory:60`. Le contrat OCP de #697 tient.
- La capacité qu'il produit, `allowsLocalEnrolment()`, n'a que **trois** consommateurs : l'analyse d'import, sa confirmation, et la capacité publiée à la connexion.
- `CompositeEnrollmentSource` — la source des inscriptions — déclare explicitement « aucune conditionnelle de mode » : elle lit le pivot local puis se replie sur le cache KLASSCI. **Aucun élève ne perd l'accès à ses cours en basculant.**
- Un import analysé avant la bascule ne peut pas aboutir après : `ImportConfirmController` revérifie la capacité au moment qui écrit vraiment.

La bascule est donc **réversible et non destructive**. Poser une garde « refuser si peuplé » aurait interdit le cas d'usage le plus légitime — une école qui quitte KLASSCI — pour un danger qui n'existe pas.

### Pourquoi une trace d'audit et pas seulement un log

C'est le geste qui ouvre ou ferme l'inscription locale pour un établissement entier. Le journal applicatif s'élague ; `audit_logs` est append-only (#215) et répond à « qui a décidé ça, et quand ». Même traitement que la suppression logique d'une institution (`institution.soft_deleted`).

## Conséquences

- La route rejoint le groupe `['auth:sanctum', 'role:supradmin', 'platform.supradmin']` — la double garde du CRUD cross-tenant (#511).
- Elle est **documentée dans `docs/openapi.yaml`** plutôt qu'ajoutée à `openapi-coverage-baseline.php` : cette liste est un cliquet de dette qui ne doit pas grossir.
- Le contrat OCP reste tenu : ce lot **déclare** le mode, il ne le résout nulle part. Aucune lecture nouvelle n'est introduite hors du point de liaison.

## Limite connue, non corrigée ici

La capacité `peut_inscrire_localement` est publiée **à la connexion** (`LoginOrchestrator` → `AuthResponsePresenter`). Un utilisateur déjà connecté au moment d'une bascule garde donc un drapeau périmé jusqu'à sa prochaine ouverture de session : le front peut lui montrer l'entrée d'import et le serveur la lui refuser en 403.

Ce n'est pas un trou de sécurité — le serveur reste l'autorité et tranche à chaque appel — mais c'est une aspérité d'expérience. La corriger demanderait soit de révoquer les sessions du tenant à la bascule (brutal et disproportionné pour un changement réversible), soit de rafraîchir la capacité hors connexion, ce qui est un sujet en soi. Laissé de côté délibérément, et écrit ici pour ne pas être découvert par surprise.

## Volet front

Aucune issue front n'existe pour ce champ : `useAdminInstitutions.js` ne connaît ni `mode` ni la nouvelle route. L'écran ne pourra donc pas encore déclarer le mode tant qu'elle n'est pas ouverte — le serveur est prêt, le client ne l'est pas.
