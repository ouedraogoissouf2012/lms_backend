# ADR-845-01 — Les transitions d'une période de formation

**Date :** 2026-09-22  
**Statut :** accepté  
**Issue :** #845 · prolonge [ADR-711-04](2026-09-06-711-04-status-phase.md)

## Décision

Le statut d'une période change par des **transitions nommées**, jamais par un `PATCH status` libre.

| Opération | De | Vers |
|---|---|---|
| **publier** | `brouillon` | `publiee` |
| **annuler** | `brouillon`, `publiee` | `annulee` |
| **clôturer** | `publiee` | `cloturee` |
| **archiver** | `cloturee`, `annulee` | `archivee` |

Toute autre transition est **refusée**, avec le statut courant dans le message.

**Publier exige une date de début.** Une période publiée sans calendrier n'a aucune phase — elle serait visible et sans contenu temporel.

`purgee` **n'est atteignable par aucune route**. Ce n'est pas une décision humaine, c'est l'issue d'une politique de rétention.

Même garde de rôle que la création : `role:coordinateur,admin,superAdmin`. Organiser une formation est un acte de gestion.

## Pourquoi

### Cinq états sur six étaient inatteignables

`TrainingSessionCrudService:55` écrit `Brouillon`, et c'est le **seul** écrivain de `status` dans tout `app/`. Aucune route, aucune commande, aucun job ne le fait avancer. Une période naissait brouillon et le restait — l'écran livré par `frontend_lms#405` affichait donc « Brouillon » pour toutes, à jamais.

### Pourquoi des transitions nommées et pas un `PATCH status`

Un `PATCH` libre laisserait passer `brouillon → archivee`, ou le retour d'`annulee` à `publiee`. Le graphe cesserait d'exister, et chaque appelant inventerait ses propres règles — exactement ce que le dépôt a déjà payé sur l'inscription, où trois écrivains produisaient trois politiques.

Nommer l'opération rend aussi le refus lisible : « une période clôturée ne se publie pas » dit quelque chose ; « transition invalide » ne dit rien.

### Annuler est irréversible, et c'est voulu

ADR-711-04 refuse « reportée » comme état, au motif qu'il créerait « un état dont personne ne saurait sortir ». Le même raisonnement s'applique en sens inverse : si annuler pouvait être défait, l'annulation ne vaudrait rien pour ceux qu'elle informe.

Une période annulée par erreur se recrée. Elle ne se ressuscite pas.

### Publier exige une date de début

`phase` est **dérivée des cinq dates** (ADR-711-04), et toutes sont nullables. Une période publiée sans `starts_on` n'aurait donc aucune phase calculable : ni à venir, ni en cours, ni terminée.

Exiger la seule date de début — et non les cinq — est délibéré : on publie souvent avant d'avoir arrêté la fin, la date de certificat ou les bornes d'inscription. Le minimum est celui sans lequel l'objet n'a pas de sens, pas celui qui serait complet.

### `purgee` n'appartient pas à cette liste, et c'est une tension à nommer

ADR-711-04 place `purgee` dans une énumération dont le docblock dit qu'elle « ne stocke que des décisions humaines ». **C'est incohérent** : une purge est l'effet d'une durée de conservation, pas d'un clic.

Le dépôt possède déjà un cadre de rétention — `RetentionRunner`, `RetentionRegistry`, trois politiques. **Aucune ne concerne les périodes.**

Cet ADR ne tranche pas cette tension : il **refuse d'exposer** `purgee` à une route, et laisse sa production à une politique de rétention qui reste à écrire. Trancher ici reviendrait à décider d'une durée de conservation au passage, ce qui est une question réglementaire et non technique.

### Pas de job pour faire avancer le statut

ADR-711-04 l'écrit sans détour : « Pas de job "avancer le statut" ». Une période dont les dates sont passées **reste** `publiee` ; c'est sa **phase** qui dit « terminée ». Clôturer demeure une décision.

## Ce que cet ADR ne décide pas

La **durée de conservation** d'une période, ni le moment de sa purge.

L'effet de l'annulation sur les **inscriptions déjà faites** : elles ne sont pas touchées ici. Le lot devra dire explicitement qu'annuler une période ne désinscrit personne — comme régénérer un code ne désinscrit personne (#846).

Les **notifications** aux inscrits lors d'une annulation : le produit n'a aucun canal de courriel (#808).

## Conséquences

- Quatre opérations nommées sur un service unique, chacune vérifiant le statut de départ.
- Quatre routes, dans le groupe de gestion déjà existant.
- Un refus **motivé** portant le statut courant, jamais un 422 muet.
- Aucune migration : `status` existe déjà.
- Les tests couvrent d'abord les **transitions refusées** — élargir une machine d'états est l'occasion classique de trop ouvrir.

## Vérifié

`origin/lms` @ `42d88689`, le 2026-09-22.
