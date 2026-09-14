# ADR-718-01 — La cartographie des colonnes s'applique côté serveur

**Date :** 2026-09-13  
**Statut :** accepté  
**Issue :** #718 (backend) · #334 (frontend)

## Décision

`POST /api/lms/imports/preview` accepte deux champs supplémentaires, tous deux facultatifs :

- `mapping` — tableau `champ canonique => en-tête du fichier` (`mapping[nom]=Nom`, `mapping[prenom]=Prénom`…) ;
- `delimiter` — le séparateur avec lequel le client a présenté les colonnes à l'utilisateur, désigné par un nom : `semicolon`, `comma` ou `tab`.

Le client envoie le fichier **tel quel**, octet pour octet. Il ne le réécrit plus. En l'absence de `mapping`, le comportement reste celui d'aujourd'hui : le champ canonique est cherché sous son propre nom.

## Pourquoi

Le client réécrivait le CSV pour renommer les colonnes avant l'envoi, avec un `split`/`join` sans analyse ni échappement. Mesuré sur le code de `feat/334-import-wizard` :

- `"Ouedraogo; fils";Ali` repartait en `nom = "Ouedraogo`, `prenom = fils"`, et `Ali` disparaissait — la ligne, dont les deux champs requis étaient non vides, était ensuite classée `ok` par cette même analyse à blanc ;
- une colonne vide au milieu (`nom;;prenom`) décalait toutes les colonnes suivantes d'un cran ;
- un export Windows-1252 était décodé en UTF-8 par `File.text()` puis ré-encodé : `Ouédraogo` partait en `4f 75 ef bf bd 64 …`, soit le caractère de remplacement U+FFFD. Le fichier reçu étant alors de l'UTF-8 valide, `mb_check_encoding` réussissait et la branche `iconv('Windows-1252', …)` de `ImportPreviewService` ne s'exécutait jamais.

Le serveur possède déjà tout ce qu'il faut : League\Csv analyse les guillemets, `Info::getDelimiterStats` détecte le séparateur, et la conversion Windows-1252 est écrite. Réparer le réécriture côté client aurait dupliqué ces trois compétences dans un second parseur à maintenir. Déplacer la cartographie ici **supprime** le parseur client au lieu de le corriger.

`delimiter` est transmis parce que l'utilisateur a cartographié des colonnes qu'il a vues découpées d'une certaine façon : laisser les deux côtés détecter le séparateur indépendamment rendrait la cartographie ambiguë dès qu'ils divergent. Ce n'est pas une valeur de confiance — au pire elle fait rejeter des lignes, jamais écrire de données — et elle est validée contre une liste fermée.

Elle voyage sous forme de NOM et non de caractère : `TrimStrings` élague les blancs de toute valeur d'entrée, si bien qu'une tabulation arrivait vide et faisait échouer la validation. Mesuré avant correction : 302 au lieu de 200, donc tout fichier tabulé refusé.

## Conséquences

`ImportPreviewRequest` valide les deux champs. Un objet `ColumnMap` résout « champ canonique → valeur de l'enregistrement » ; sans cartographie il se réduit à l'identité, ce qui évite une condition dans `classify()`.

Le serveur reçoit désormais des fichiers qu'il ne produisait pas lui-même, ce qui rend deux cas atteignables et donc à couvrir : les en-têtes dupliqués, que `Reader::computeHeader()` rejette par une `SyntaxError` aujourd'hui non capturée (donc une 500), et la normalisation des en-têtes accentués, qui doit être la même des deux côtés de la cartographie.

Le déploiement est ordonné : le serveur d'abord, qui reste compatible avec l'ancien client puisque les deux champs sont facultatifs ; le client ensuite.
