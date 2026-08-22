# Requirements — #576 : limite d'upload annoncée à 30 Mo qui vaut 30 Go

> Sous-issue de #563 · Sévérité **P1 — ÉLEVÉ** (saturation disque par un seul envoi)
> Références : `PRODUCTION_STANDARDS.md` §1.5 (validation systématique), §1.2 (sécurité absolue), §1.6 (DRY / source unique).

## Contexte

Deux `FormRequest` annoncent une limite de 30 Mo et en autorisent en réalité **~30 Go** :

- `app/Http/Requests/UploadFileRequest.php:76` → `'max:31457280'`
- `app/Http/Requests/StoreChapterRequest.php:104` → `'max:31457280'`

Cause racine : pour un fichier, la règle `max` de Laravel s'exprime en **kilo-octets**, pas
en octets. `31457280` est le résultat de `30 × 1024 × 1024` (une conversion en octets)
appliquée à une règle qui attend des kilo-octets. `31457280` Ko = 30 720 Mo ≈ 30 Go.

La seule protection effective aujourd'hui provient de `upload_max_filesize` / `post_max_size`
côté PHP : une configuration serveur non versionnée, potentiellement différente entre le poste
de développement et l'hébergement mutualisé. La validation applicative ne protège rien.

La valeur `30 Mo` est par ailleurs **dupliquée** et décrite de trois façons désynchronisées :
la règle `max`, le message d'erreur (`:120`, `:148`), la docblock, et `getMaxFileSize()`
(`:138`) qui renvoie la chaîne `'30 MB'` codée en dur.

## Glossaire

| Terme | Définition |
|---|---|
| Ko | Kilo-octet = 1024 octets (unité de la règle `max` de Laravel pour un fichier) |
| Limite d'upload | Taille maximale acceptée par la validation applicative, en Ko |
| Source unique | Un seul emplacement typé d'où dérivent la règle, le message et l'affichage |

## Exigences (format EARS)

### R1 — Limite effective à 30 Mo sur l'upload de fichier générique

- **R1.1** WHEN un utilisateur authentifié envoie un fichier strictement supérieur à 30 Mo
  sur `POST /api/files/upload`, THE système SHALL rejeter la requête avec un statut **422**.
- **R1.2** WHEN un utilisateur authentifié envoie un fichier inférieur ou égal à 30 Mo
  (type autorisé), THE système SHALL ne PAS rejeter la requête pour dépassement de taille.
- **R1.3** WHEN la requête est rejetée pour dépassement de taille, THE système SHALL renvoyer
  un message d'erreur cohérent avec la limite réellement appliquée (« 30 MB »).

### R2 — Limite effective à 30 Mo sur l'upload de chapitre

- **R2.1** WHEN un enseignant/coordinateur envoie un fichier de chapitre strictement supérieur
  à 30 Mo sur `POST /api/lessons/{lessonId}/chapters`, THE système SHALL rejeter avec **422**.
- **R2.2** WHEN le fichier de chapitre est inférieur ou égal à 30 Mo (type autorisé),
  THE système SHALL ne PAS rejeter pour dépassement de taille.
- **R2.3** WHEN la requête est rejetée pour dépassement, THE système SHALL renvoyer un message
  cohérent avec la limite réellement appliquée.

### R3 — Source unique de vérité (DRY, anti-régression)

- **R3.1** THE limite d'upload SHALL être définie une seule fois, dans un emplacement typé
  (`int` en kilo-octets), et non dupliquée entre les deux `FormRequest`.
- **R3.2** THE règle `max` des deux `FormRequest` SHALL dériver de cette source unique.
- **R3.3** THE représentation lisible (« 30 MB ») exposée par `getMaxFileSize()` et les
  messages d'erreur SHALL dériver de cette même source (aucune chaîne « 30 MB » codée en dur
  en plusieurs endroits).
- **R3.4** THE source unique SHALL porter un commentaire explicite indiquant que l'unité est
  le **kilo-octet** (unité de la règle `max` de Laravel), pour empêcher la régression.

### R4 — Cohérence avec la configuration PHP du serveur

- **R4.1** WHERE le serveur applique `upload_max_filesize` / `post_max_size` inférieurs à la
  limite applicative, la validation à 30 Mo ne sera jamais atteinte. THE relation entre la
  limite applicative et ces directives PHP SHALL être documentée (dette de documentation
  tracée : la note dans le guide de déploiement est portée par #577, qui possède ce fichier ;
  voir « Hors périmètre »).

### R5 — Non-régression

- **R5.1** THE suite `php artisan test` SHALL passer à 100 %.
- **R5.2** THE analyse `PHPStan level 9` SHALL rester à 0 erreur.
- **R5.3** THE contrat public des deux endpoints (statuts, clés de réponse, autres règles de
  validation) SHALL rester inchangé hors la correction de la borne de taille.

## Critères de fermeture (issue #576)

- [ ] Test : fichier > 30 Mo (31 Mo) sur `/api/files/upload` → **422** avec message attendu.
- [ ] Test : fichier ≤ 30 Mo (29 Mo) → accepté.
- [ ] Même couverture sur l'upload de chapitre.
- [ ] Valeur unique, non dupliquée entre les deux requests.
- [ ] `php artisan test` 100 %, PHPStan level 9 vert.

## Hors périmètre (dette tracée)

- La note « limite applicative vs `upload_max_filesize`/`post_max_size` » dans
  `GUIDE_DEPLOIEMENT_PRODUCTION.md` est portée par **#577** (qui possède les fichiers de
  déploiement et édite déjà ce guide) afin d'éviter deux PR concurrentes sur le même fichier.
- `app/Services/FileConversion/FileValidator.php` applique **déjà** correctement 30 Mo en
  octets (`30 * 1024 * 1024`) sur le pipeline de conversion. Il n'est pas dans le périmètre
  de cette issue (c'est un service, pas un `FormRequest`) et n'est pas modifié.
