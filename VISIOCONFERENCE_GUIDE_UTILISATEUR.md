# Guide Utilisateur - Visioconférence LMS

**Version:** 1.0
**Date:** 2025-10-22
**Technologie:** Jitsi Meet

---

## Vue d'ensemble

Le système de visioconférence permet aux enseignants de donner des cours en ligne et aux étudiants d'y participer via Jitsi Meet.

---

## Pour les Enseignants

### 1. Accéder à vos séances

**Navigation:** Menu principal → **"Mes Séances"**

Ou depuis une matière:
- Menu → **"Matières"** → Cliquer sur une matière → Voir les séances

### 2. Programmer une visioconférence

Sur la liste de vos séances:

1. Trouver la séance souhaitée
2. Cliquer sur **"Programmer en visio"**
3. Confirmer
4. ✅ La séance affiche maintenant le badge **"PROGRAMMÉE"**

### 3. Démarrer la visioconférence

**Quand démarrer ?**
- À tout moment (pas de restriction de fenêtre temporelle)
- Avant, pendant ou après l'heure prévue
- Pour cours de rattrapage ou remplacement

**Comment démarrer ?**

1. Cliquer sur **"Démarrer la visio"** (bouton bleu)
2. ✅ Une fenêtre Jitsi s'ouvre automatiquement
3. ✅ Le badge passe à **"EN DIRECT"** (vert)
4. ✅ Un message confirme : *"Visioconférence démarrée ! Les étudiants peuvent maintenant rejoindre."*

**Note:** Vous êtes automatiquement modérateur dans Jitsi.

### 4. Pendant le cours

**Bouton "Participants (X)":**
- Affiche le nombre d'étudiants **inscrits dans la classe**
- Exemple: "Participants (25)" = 25 étudiants attendus

**Contrôles Jitsi:**
- Partager écran
- Couper/activer micro
- Couper/activer caméra
- Chat
- Expulser un participant (modérateur)

### 5. Terminer la visioconférence

**Option 1: Automatique**
- Fermez simplement la fenêtre Jitsi
- Le statut reste "EN DIRECT"

**Option 2: Manuelle**
- Cliquer sur **"Terminer la visio"**
- ✅ Badge passe à **"TERMINÉE"**
- Les étudiants ne peuvent plus rejoindre

---

## Pour les Étudiants

### 1. Voir les cours disponibles

**Navigation:** Dashboard étudiant → **"Matières"** → Sélectionner une matière

Vous verrez la liste des séances programmées.

### 2. Rejoindre une visioconférence

**Conditions:**
- L'enseignant doit avoir **démarré** la visio
- Badge **"EN DIRECT"** visible (vert)

**Comment rejoindre ?**

1. Cliquer sur **"Rejoindre la visio"** (bouton violet)
2. ✅ Une fenêtre Jitsi s'ouvre automatiquement
3. Votre nom s'affiche automatiquement

**Si le bouton est grisé:**
- L'enseignant n'a pas encore démarré
- Message: *"La visio sera disponible lorsque l'enseignant la démarrera"*

### 3. Pendant le cours

**Contrôles disponibles:**
- Activer/couper micro
- Activer/couper caméra
- Chat avec participants
- Lever la main (emoji)

### 4. Quitter le cours

- Fermez simplement la fenêtre Jitsi
- Ou cliquez sur le bouton "Raccrocher" dans Jitsi

---

## Badges de statut

| Badge | Couleur | Signification |
|-------|---------|---------------|
| **PROGRAMMÉE** | Bleu | Visio configurée mais pas encore démarrée |
| **EN DIRECT** | Vert | Cours en cours, étudiants peuvent rejoindre |
| **TERMINÉE** | Gris | Cours terminé |

---

## FAQ - Questions fréquentes

### Enseignants

**Q: Puis-je démarrer avant l'heure prévue ?**
R: Oui, vous pouvez démarrer à tout moment.

**Q: Combien d'étudiants peuvent rejoindre ?**
R: Illimité (selon les capacités de Jitsi)

**Q: Que voit le badge "Participants (X)" ?**
R: Le nombre d'étudiants **inscrits** dans la classe (pas le nombre connecté en temps réel).

**Q: Puis-je programmer plusieurs visios pour la même séance ?**
R: Non, une seule visio par séance.

**Q: Comment annuler une visio programmée ?**
R: Cliquer sur "Désactiver visio" (pour coordinateurs uniquement).

### Étudiants

**Q: Pourquoi le bouton est grisé ?**
R: L'enseignant n'a pas encore démarré la visio.

**Q: Puis-je rejoindre en retard ?**
R: Oui, tant que la visio est "EN DIRECT".

**Q: Dois-je avoir un compte Jitsi ?**
R: Non, tout est automatique.

**Q: Mon nom s'affiche-t-il ?**
R: Oui, votre nom LMS s'affiche automatiquement dans Jitsi.

**Q: Puis-je utiliser mon téléphone ?**
R: Oui, Jitsi fonctionne sur mobile (navigateur ou app).

---

## Dépannage

### Le bouton "Démarrer" reste grisé (Enseignant)

**Solutions:**
1. Rafraîchir la page (CTRL + SHIFT + R)
2. Vérifier que vous êtes bien enseignant de cette matière
3. Vérifier que la visio est bien programmée (badge "PROGRAMMÉE")

### Le bouton "Rejoindre" reste grisé (Étudiant)

**Solutions:**
1. Attendre que l'enseignant démarre (badge doit être "EN DIRECT")
2. Rafraîchir la page
3. Vérifier que vous êtes inscrit dans la classe

### Jitsi ne s'ouvre pas

**Solutions:**
1. Désactiver le bloqueur de pop-ups
2. Autoriser les pop-ups pour localhost:5173
3. Essayer un autre navigateur (Chrome recommandé)

### Pas de son/vidéo dans Jitsi

**Solutions:**
1. Autoriser micro/caméra dans le navigateur
2. Vérifier paramètres audio/vidéo Jitsi (icônes en bas)
3. Tester micro/caméra avant de rejoindre

### Erreur 500 dans la console

**Solutions:**
1. Vérifier connexion internet
2. Vérifier token KLASSCI (se reconnecter)
3. Contacter support technique

---

## Support technique

**En cas de problème:**
1. Consulter cette documentation
2. Vérifier la FAQ ci-dessus
3. Contacter l'administrateur LMS

---

## Limites actuelles

**Note:** Le système actuel ne fait PAS:
- ❌ Vérification que l'étudiant est inscrit dans la classe (tout le monde avec le lien peut rejoindre)
- ❌ Tracking des présences automatique
- ❌ Enregistrement de la durée de participation
- ❌ Synchronisation automatique avec KLASSCI
- ❌ Statistiques en temps réel

Ces fonctionnalités sont prévues pour une version future.

---

## Changelog

**Version 1.0 (2025-10-22):**
- ✅ Enseignant peut démarrer visio à tout moment
- ✅ Étudiant peut rejoindre quand visio active
- ✅ Badge "Participants (X)" affiche effectif classe
- ✅ Support multi-rôles (enseignant, étudiant, coordinateur)
- ✅ Intégration Jitsi Meet
- ✅ Gestion des statuts (programmee, active, terminee)
