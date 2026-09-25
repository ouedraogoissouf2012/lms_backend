# ADR-885-01 — La porte par code, authentifiée, et qui décide d'une adhésion

**Date :** 2026-09-25  
**Statut :** accepté  
**Issue :** #885 · prolonge [ADR-803-03](2026-09-15-803-03-trois-portes-un-service.md) et [ADR-711-02](2026-09-06-711-02-adhesion-datee.md)

## Décision

**1. La troisième porte a deux variantes, pas une quatrième porte.**

| Variante | Qui | Établissement lu sur | Écrit |
|---|---|---|---|
| `POST /inscriptions` | anonyme — crée le compte | l'en-tête `X-Institution` | compte + adhésion |
| `POST /me/inscriptions` | apprenant connecté | **son compte**, jamais un en-tête | adhésion |

Toutes deux résolvent le code par `ClasseOuverteParCode` et écrivent par `StudentEnrolmentService::rejoindre()`.

**2. Une adhésion se décide selon QUI agit — et à un seul endroit.**

| Méthode | Acteur | Ligne absente | Ligne `actif` | Ligne non active |
|---|---|---|---|---|
| `inscrire()` | l'établissement (import) | créée | mise à jour | **mise à jour** |
| `rejoindre()` | l'apprenant (code) | créée `actif`, datée | rien — 200 | **refusée — 409, intacte** |

**Un code fait entrer, il ne rouvre pas.** Seul l'établissement rouvre une adhésion close.

**3. Un code n'ouvre rien là où la liste des apprenants vient d'ailleurs.** `ClasseOuverteParCode` consulte `RosterAuthority::allowsLocalEnrolment()` pour l'établissement du code, et refuse par la **même** 404 qu'un code inconnu.

**4. La forme de la ligne appartient au service.** Les deux méthodes posent `institution_id` ; `rejoindre()` pose aussi `date_inscription`, obligatoire à l'écriture locale selon ADR-711-02.

## Pourquoi

### La porte anonyme renvoyait vers une porte qui n'existait pas

Elle refuse tout compte existant — à raison : une adresse n'y prouve rien, et poser un mot de passe dessus serait une prise de contrôle (#812). Son message disait « Connectez-vous pour rejoindre cette classe ». Il n'y avait rien derrière. L'apprenant d'une seconde formation, celui créé par import puis activé, celui qui revient : tout le monde, dès la deuxième fois, était bloqué.

### Pourquoi une variante et non la porte 1

ADR-803-03 prévoit une saisie unitaire par l'établissement. Elle ne sert pas ce cas : un formateur qui diffuse son code par WhatsApp n'a ni la liste ni le temps d'inscrire chacun à la main. La porte 1 reste à écrire, pour ce qu'elle sert — l'inscription nominative décidée par l'école.

### Pourquoi le refus d'une adhésion close

`inscrire()` repose sur `syncWithoutDetaching`, qui **met à jour** une ligne existante. Brancher la porte authentifiée dessus aurait permis à un apprenant `suspendu` de se réactiver en ressaisissant le code de sa classe — un code qui circule par WhatsApp. La porte anonyme n'y était pas exposée : elle refuse tout compte existant.

C'est la règle de Moodle : `enrol_self::can_self_enrol()` refuse dès qu'une inscription existe, **quel que soit son statut** (`enrol/self/lib.php:284`).

Deux règles dans un seul service ne sont pas les « politiques divergentes » qu'ADR-803-03 interdit : elles ne répondent pas différemment à la même question, elles répondent à deux acteurs différents — et sont écrites côte à côte.

### Pourquoi le droit d'inscrire est revérifié à la lecture du code

Émettre un code exige le catalogue local. Mais basculer un établissement vers KLASSCI (`InstitutionCrudService::changeMode`) ne retire pas les codes déjà dictés : ils auraient continué d'écrire une liste qui appartient à KLASSCI — deux sources pour une même réalité, la cause racine de #673. Le droit est demandé **pour l'établissement du code**, par `RosterAuthorityFactory::forInstitution()`, jamais au tenant ambiant : le contrôleur peut être construit avant que `ResolveInstitution` ait posé le tenant.

## Débit

| Seau | Clé | Borne | Contre |
|---|---|---|---|
| `rejoindre-classe` | compte | 10/min, 30/jour | un compte qui insiste |
| plafond d'échecs | établissement | 200 échecs/jour | des comptes accumulés, chacun avec ses essais |

**Jamais par IP** : le jour de la rentrée, une classe entière rejoint depuis le wifi de l'école.

**Prix assumé du plafond d'établissement** : quelques comptes malveillants peuvent fermer cette porte à leur **propre** école pour la journée. C'est l'arbitrage déjà fait pour la borne globale de la porte anonyme. L'atteinte est journalisée. Avec K codes ouverts, le risque quotidien de tomber sur l'un d'eux est au plus 200·K / 31⁶ — environ 1,1·10⁻⁵ pour 50 classes.

## Comment on le vérifie

`RejoindreParCodeTest`, `InscriptionParCodeTest`, `ImportEnrolmentColumnsTest`. Quatorze falsifications, chacune sur le code réel : treize font rougir leur test. La quatorzième — retirer `role:etudiant` de la route — reste verte parce que le service refuse lui aussi tout rôle autre qu'apprenant ; l'inverse, retirer le contrôle du service, laisse passer le `superAdmin` d'établissement, que `EnsureRole` exempte.

La course sur l'unique `(classe_id, user_id)` est provoquée de façon déterministe par `DB::beforeExecuting`.

## Ce que cette décision ne règle pas

- **La porte 1** (saisie unitaire par l'établissement) n'existe toujours pas. → #894
- **`classe_etudiant.statut`** a désormais son énumération, mais 16 occurrences en code emploient encore la chaîne `'actif'` (mesure du 2026-09-25, commentaires exclus) — dont plusieurs lisent des charges KLASSCI et non le pivot. L'inventaire est dans #896.
- **Une Période close** ne retire pas le code de ses classes. → #897
- **L'import réécrit la date d'inscription** d'une ligne existante quand le fichier n'en porte pas. → #895
