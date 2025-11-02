# SPECIFICATIONS COMPLETES - MODULE SEANCES

## Vue d'ensemble

Le module SEANCES permet de gerer le cycle de vie complet d'un cours : de la planification (via KLASSCI) jusqu'aux statistiques finales, en passant par la realisation (visio, presences) et la cloture.

---

## 1. FONCTIONNALITES PAR ROLE

### 1.1 Pour les ENSEIGNANTS

#### Section "Mes Seances a venir"
- [ ] Afficher la liste des prochains cours planifies
- [ ] Filtrer par date (aujourd'hui, cette semaine, ce mois)
- [ ] Filtrer par matiere
- [ ] Filtrer par classe
- [ ] Afficher pour chaque seance :
  - [ ] Date et heure
  - [ ] Duree prevue
  - [ ] Classe
  - [ ] Matiere
  - [ ] Salle
  - [ ] Nombre d'etudiants inscrits
- [ ] Bouton "Demarrer le cours" (visible uniquement a l'heure H)
- [ ] Bouton "Lancer Visio" (disponible 10min avant l'heure)
- [ ] Indicateur de statut (A venir, En cours, Terminee, Annulee)

#### Section "Seance en cours"
- [ ] Page dediee quand l'enseignant demarre un cours
- [ ] Timer de duree du cours
- [ ] Bouton "Lancer Visio" (integration Jitsi existant)
- [ ] Liste des etudiants de la classe
- [ ] Checkbox pour marquer Present/Absent pour chaque etudiant
- [ ] Bouton "Tout marquer present"
- [ ] Zone de notes/commentaires
- [ ] Bouton "Upload document/ressource"
- [ ] Bouton "Terminer le cours"

#### Section "Historique de mes cours"
- [ ] Liste des seances deja donnees
- [ ] Filtrer par periode (semaine, mois, annee)
- [ ] Filtrer par matiere
- [ ] Filtrer par statut (Realise, Annule, Reporte)
- [ ] Afficher pour chaque seance passee :
  - [ ] Date et heure
  - [ ] Duree reelle vs duree prevue
  - [ ] Classe et matiere
  - [ ] Nombre d'etudiants presents vs inscrits
  - [ ] Taux de presence
  - [ ] Statut final
- [ ] Clic sur une seance = voir details complets
- [ ] Export PDF du compte-rendu

### 1.2 Pour les ETUDIANTS

#### Section "Mon Emploi du Temps"
- [ ] Vue calendrier des seances de la semaine
- [ ] Vue liste des seances a venir
- [ ] Afficher pour chaque seance :
  - [ ] Date et heure
  - [ ] Matiere
  - [ ] Enseignant
  - [ ] Salle
  - [ ] Duree
- [ ] Bouton "Rejoindre le cours" (visible quand seance en cours)
- [ ] Indicateur "Cours en direct" (pastille rouge)
- [ ] Notification quand un cours commence

#### Section "Mes Cours Passes"
- [ ] Liste des seances auxquelles l'etudiant a assiste
- [ ] Filtrer par matiere
- [ ] Filtrer par periode
- [ ] Afficher pour chaque seance :
  - [ ] Date
  - [ ] Matiere et enseignant
  - [ ] Sa presence (Present/Absent)
  - [ ] Ressources/documents partages
- [ ] Statistiques personnelles :
  - [ ] Taux de presence global
  - [ ] Taux par matiere
  - [ ] Nombre total de cours suivis

### 1.3 Pour les COORDINATEURS

#### Dashboard de suivi global
- [ ] Vue calendrier de TOUTES les seances
- [ ] Filtrer par enseignant
- [ ] Filtrer par classe
- [ ] Filtrer par matiere
- [ ] Statistiques globales :
  - [ ] Nombre total de seances prevues
  - [ ] Nombre de seances realisees
  - [ ] Taux de realisation global
  - [ ] Taux de presence moyen

#### Suivi par enseignant
- [ ] Liste des enseignants avec leurs stats
- [ ] Nombre de seances effectuees vs prevues
- [ ] Taux de realisation par enseignant
- [ ] Identifier les enseignants en retard
- [ ] Alertes pour les cours non donnes

#### Suivi par matiere
- [ ] Taux de realisation par matiere
- [ ] Nombre d'heures effectuees vs prevues
- [ ] Taux de presence moyen par matiere

---

## 2. FLUX DE VIE D'UNE SEANCE

### Etape 1 : PLANIFICATION (depuis KLASSCI)
- [ ] Le systeme recupere les seances depuis KLASSCI
- [ ] Chaque seance a le statut "A venir"
- [ ] Les seances sont visibles dans l'emploi du temps

### Etape 2 : AVANT LE COURS
- [ ] 10 minutes avant : bouton "Lancer Visio" devient actif
- [ ] Notification aux etudiants : "Votre cours commence bientot"
- [ ] Notification a l'enseignant : "Votre cours commence dans 10min"

### Etape 3 : DEMARRAGE
- [ ] L'enseignant clique "Demarrer le cours"
- [ ] Statut passe a "En cours"
- [ ] Timer demarre
- [ ] Les etudiants voient "Cours en direct"
- [ ] Bouton "Rejoindre" devient actif pour les etudiants

### Etape 4 : PENDANT LE COURS
- [ ] Enseignant peut lancer la visio Jitsi
- [ ] Enseignant marque les presences
- [ ] Enseignant peut ajouter des notes
- [ ] Enseignant peut uploader des ressources
- [ ] Etudiants peuvent rejoindre la visio

### Etape 5 : CLOTURE
- [ ] Enseignant clique "Terminer le cours"
- [ ] Systeme enregistre :
  - [ ] Duree reelle du cours
  - [ ] Liste des presents/absents
  - [ ] Ressources partagees
  - [ ] Notes/commentaires
- [ ] Statut passe a "Terminee"

### Etape 6 : POST-COURS
- [ ] Mise a jour automatique des statistiques :
  - [ ] Heures effectuees de l'enseignant
  - [ ] Taux de presence des etudiants
  - [ ] Progression de la matiere
- [ ] Synchronisation du statut vers KLASSCI
- [ ] Ressources disponibles pour les etudiants

---

## 3. ENDPOINTS BACKEND NECESSAIRES

### 3.1 Recuperation des seances

#### GET /api/lms/seances
- [ ] Parametres :
  - `enseignant_id` (optionnel)
  - `etudiant_id` (optionnel)
  - `classe_id` (optionnel)
  - `matiere_id` (optionnel)
  - `date_debut` (optionnel)
  - `date_fin` (optionnel)
  - `statut` (optionnel : a_venir, en_cours, terminee, annulee)
- [ ] Retourne la liste des seances filtrees
- [ ] Source : KLASSCI externe

#### GET /api/lms/seances/:id
- [ ] Retourne les details complets d'une seance
- [ ] Inclut : classe, matiere, enseignant, etudiants, ressources

#### GET /api/lms/seances/enseignant/upcoming
- [ ] Seances a venir pour l'enseignant connecte
- [ ] Triees par date

#### GET /api/lms/seances/etudiant/upcoming
- [ ] Emploi du temps de l'etudiant connecte
- [ ] Seances de la semaine en cours

### 3.2 Gestion du cycle de vie

#### POST /api/lms/seances/:id/demarrer
- [ ] Demarre une seance
- [ ] Change le statut a "en_cours"
- [ ] Enregistre l'heure de debut reelle
- [ ] Retourne les infos pour la page "Seance en cours"

#### POST /api/lms/seances/:id/terminer
- [ ] Termine une seance
- [ ] Parametres :
  - `duree_reelle` (en minutes)
  - `commentaire` (optionnel)
- [ ] Change le statut a "terminee"
- [ ] Calcule les statistiques
- [ ] Synchronise vers KLASSCI

#### POST /api/lms/seances/:id/annuler
- [ ] Annule une seance
- [ ] Parametres :
  - `raison` (obligatoire)
- [ ] Change le statut a "annulee"
- [ ] Notifie les etudiants

#### POST /api/lms/seances/:id/reporter
- [ ] Reporte une seance
- [ ] Parametres :
  - `nouvelle_date`
  - `nouvelle_heure`
  - `raison`
- [ ] Cree une nouvelle seance a la nouvelle date
- [ ] Annule l'ancienne

### 3.3 Presences

#### POST /api/lms/seances/:id/presences
- [ ] Enregistre les presences d'une seance
- [ ] Parametres :
  - `presences` : tableau d'objets `{etudiant_id, present: true/false}`
- [ ] Met a jour les taux de presence

#### GET /api/lms/seances/:id/presences
- [ ] Retourne la liste des presences pour une seance

### 3.4 Ressources

#### POST /api/lms/seances/:id/ressources
- [ ] Upload un document/fichier pour une seance
- [ ] Parametres :
  - `fichier` (upload)
  - `titre`
  - `description` (optionnel)

#### GET /api/lms/seances/:id/ressources
- [ ] Liste des ressources partagees pour une seance

### 3.5 Visioconference

#### POST /api/lms/seances/:id/visio/creer
- [ ] Cree une salle Jitsi pour la seance
- [ ] Retourne l'URL de la salle
- [ ] Configure les permissions (enseignant = moderateur)

#### GET /api/lms/seances/:id/visio/rejoindre
- [ ] Retourne l'URL pour rejoindre la visio existante
- [ ] Verifie que l'utilisateur a le droit de rejoindre

### 3.6 Statistiques

#### GET /api/lms/statistiques/enseignant/:id/seances
- [ ] Stats des seances d'un enseignant
- [ ] Retourne :
  - Nombre total de seances
  - Nombre effectuees
  - Taux de realisation
  - Heures effectuees vs prevues

#### GET /api/lms/statistiques/etudiant/:id/presences
- [ ] Stats de presence d'un etudiant
- [ ] Retourne :
  - Taux de presence global
  - Taux par matiere
  - Liste des absences

#### GET /api/lms/statistiques/global/seances
- [ ] Stats globales pour coordinateurs
- [ ] Retourne :
  - Total seances prevues
  - Total realisees
  - Taux global
  - Stats par matiere
  - Stats par enseignant

---

## 4. PAGES FRONTEND NECESSAIRES

### 4.1 Pour Enseignants

#### /enseignant/seances
- [ ] Page principale des seances enseignant
- [ ] Onglets :
  - [ ] "A venir" (seances planifiees)
  - [ ] "En cours" (cours actuels)
  - [ ] "Historique" (cours passes)
- [ ] Filtres par matiere, classe, date

#### /enseignant/seances/:id/cours
- [ ] Page "Seance en cours"
- [ ] Affichage pendant qu'un cours est actif
- [ ] Gestion presences
- [ ] Lancement visio
- [ ] Upload ressources
- [ ] Timer

#### /enseignant/seances/:id/details
- [ ] Details d'une seance passee
- [ ] Compte-rendu
- [ ] Liste des presents/absents
- [ ] Ressources partagees

### 4.2 Pour Etudiants

#### /etudiant/emploi-temps
- [ ] Vue calendrier de la semaine
- [ ] Liste des cours a venir
- [ ] Bouton "Rejoindre" pour cours en direct

#### /etudiant/cours-passes
- [ ] Historique des cours suivis
- [ ] Taux de presence personnel
- [ ] Acces aux ressources

### 4.3 Pour Coordinateurs

#### /admin/seances/calendrier
- [ ] Vue calendrier global de toutes les seances
- [ ] Filtres par enseignant, classe, matiere
- [ ] Codes couleur par statut

#### /admin/seances/statistiques
- [ ] Dashboard de suivi global
- [ ] Graphiques et indicateurs
- [ ] Alertes pour retards

---

## 5. COMPOSANTS REUTILISABLES

### 5.1 SeanceCard.vue
- [ ] Carte d'affichage d'une seance
- [ ] Props : seance (objet)
- [ ] Variants : compact, detailed
- [ ] Actions : demarrer, rejoindre, voir details

### 5.2 SeancesList.vue
- [ ] Liste de seances avec filtres
- [ ] Props : seances (array), filters (object)
- [ ] Emit : filter-change, seance-click

### 5.3 PresencesList.vue
- [ ] Liste des etudiants avec checkboxes
- [ ] Props : etudiants (array)
- [ ] Emit : presence-change

### 5.4 SeanceTimer.vue
- [ ] Chronometre de duree de cours
- [ ] Props : start_time
- [ ] Affiche duree ecoulee en temps reel

### 5.5 SeanceStatusBadge.vue
- [ ] Badge de statut coloré
- [ ] Props : status (string)
- [ ] Couleurs : vert (en cours), bleu (a venir), gris (terminee), rouge (annulee)

### 5.6 EmploiTempsCalendar.vue
- [ ] Vue calendrier hebdomadaire
- [ ] Props : seances (array)
- [ ] Emit : seance-click, date-change

---

## 6. MODELE DE DONNEES

### 6.1 Ce qui vient de KLASSCI (lecture seule)
- Seances planifiees (emploi du temps)
- Classes
- Etudiants
- Matieres
- Enseignants

### 6.2 Ce qu'on stocke localement dans LMS

#### Table : lms_seances_status
- [ ] Creer migration
- [ ] Champs :
  - `id`
  - `seance_klassci_id` (reference externe)
  - `statut` (enum: a_venir, en_cours, terminee, annulee)
  - `heure_debut_reelle`
  - `heure_fin_reelle`
  - `duree_reelle_minutes`
  - `commentaire`
  - `visio_url`
  - `created_at`
  - `updated_at`

#### Table : lms_seances_presences
- [ ] Creer migration
- [ ] Champs :
  - `id`
  - `seance_id` (reference lms_seances_status)
  - `etudiant_id`
  - `present` (boolean)
  - `commentaire`
  - `created_at`
  - `updated_at`

#### Table : lms_seances_ressources
- [ ] Creer migration
- [ ] Champs :
  - `id`
  - `seance_id`
  - `titre`
  - `description`
  - `fichier_path`
  - `fichier_type`
  - `uploaded_by` (user_id)
  - `created_at`
  - `updated_at`

---

## 7. INTEGRATION AVEC SYSTEME VISIO EXISTANT

### 7.1 Liens a etablir
- [ ] Modifier le systeme visio actuel pour accepter un `seance_id`
- [ ] Associer chaque salle Jitsi a une seance precise
- [ ] Generer l'URL de salle basee sur : `seance-{id}-{date}`
- [ ] Configurer les permissions selon le role (enseignant/etudiant)

### 7.2 Workflow visio
- [ ] Enseignant clique "Lancer Visio" depuis la seance
- [ ] Backend cree/recupere la salle Jitsi pour cette seance
- [ ] Enseignant est redirige vers la visio (moderateur)
- [ ] Etudiants voient "Cours en direct" + bouton "Rejoindre"
- [ ] Etudiants rejoignent la meme salle (participants)

---

## 8. SYNCHRONISATION AVEC KLASSCI

### 8.1 Direction KLASSCI → LMS (lecture)
- [ ] Recuperer les seances planifiees
- [ ] Recuperer les modifications (annulations, reports)
- [ ] Mise a jour automatique toutes les X heures

### 8.2 Direction LMS → KLASSCI (ecriture)
- [ ] Envoyer le statut "terminee" quand cours fini
- [ ] Envoyer les presences
- [ ] Envoyer la duree reelle

### 8.3 Endpoints KLASSCI a identifier
- [ ] Verifier quels endpoints KLASSCI existent pour les seances
- [ ] Documenter la structure exacte des donnees retournees
- [ ] Tester les appels API

---

## 9. NOTIFICATIONS

### 9.1 Pour Enseignants
- [ ] "Votre cours commence dans 10 minutes" (notification push)
- [ ] "N'oubliez pas de terminer le cours X" (si oubli)

### 9.2 Pour Etudiants
- [ ] "Votre cours de [Matiere] commence dans 10 minutes"
- [ ] "Le cours de [Matiere] est en direct, rejoignez maintenant"
- [ ] "Nouveau document partage pour le cours de [Matiere]"

### 9.3 Pour Coordinateurs
- [ ] "X cours n'ont pas ete donnes cette semaine"
- [ ] "Le taux de presence en [Matiere] est faible (X%)"

---

## 10. ORDRE D'IMPLEMENTATION RECOMMANDE

### Phase 1 : Base fonctionnelle (MVP)
1. [ ] Backend : Endpoint GET /api/lms/seances (recuperation KLASSCI)
2. [ ] Backend : Analyser structure donnees KLASSCI seances
3. [ ] Frontend : Page SeancesEnseignant.vue (liste a venir)
4. [ ] Frontend : Page SeancesEtudiant.vue (emploi du temps)
5. [ ] Frontend : Composant SeanceCard.vue
6. [ ] Integration : Lier bouton "Lancer Visio" avec systeme existant

### Phase 2 : Cycle de vie complet
7. [ ] Backend : Endpoints demarrer/terminer seance
8. [ ] Backend : Migration table lms_seances_status
9. [ ] Frontend : Page "Seance en cours"
10. [ ] Frontend : Composant SeanceTimer.vue

### Phase 3 : Presences
11. [ ] Backend : Migration table lms_seances_presences
12. [ ] Backend : Endpoints presences
13. [ ] Frontend : Composant PresencesList.vue
14. [ ] Integration dans page "Seance en cours"

### Phase 4 : Historique et stats
15. [ ] Backend : Endpoints statistiques
16. [ ] Frontend : Section historique enseignant
17. [ ] Frontend : Section cours passes etudiant
18. [ ] Frontend : Dashboard coordinateur

### Phase 5 : Ressources
19. [ ] Backend : Migration table lms_seances_ressources
20. [ ] Backend : Endpoints upload/download ressources
21. [ ] Frontend : Upload dans page "Seance en cours"
22. [ ] Frontend : Acces ressources pour etudiants

### Phase 6 : Ameliorations
23. [ ] Notifications en temps reel
24. [ ] Vue calendrier
25. [ ] Export PDF compte-rendus
26. [ ] Synchronisation bidirectionnelle KLASSCI

---

## 11. CRITERES DE SUCCES

### Pour savoir que c'est termine et fonctionnel :

- [ ] Un enseignant peut voir ses cours a venir
- [ ] Un enseignant peut demarrer un cours a l'heure H
- [ ] Un enseignant peut lancer la visio depuis son cours
- [ ] Un etudiant peut voir son emploi du temps
- [ ] Un etudiant peut rejoindre un cours en direct
- [ ] Les presences sont enregistrees correctement
- [ ] Un enseignant peut terminer un cours
- [ ] Les statistiques se mettent a jour automatiquement
- [ ] Le coordinateur voit les stats globales
- [ ] Toutes les donnees sont synchronisees avec KLASSCI

---

## 12. NOTES IMPORTANTES

- **Source de verite** : KLASSCI pour la planification, LMS pour l'execution
- **Performance** : Cache les seances de la semaine pour eviter appels API constants
- **Securite** : Verifier que l'utilisateur a le droit d'acceder a la seance
- **UX** : Boutons contextuels (ne montrer "Demarrer" que quand c'est l'heure)
- **Coherence** : Utiliser les memes emoticones partout (pas d'emojis !)

---

**Document cree le** : 2025-10-26
**Derniere mise a jour** : 2025-10-26
**Version** : 1.0
