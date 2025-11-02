# À TESTER DEMAIN - Visioconférence

**Date:** 2025-10-23 (demain)
**Prérequis:** Avoir des séances programmées dans KLASSCI pour aujourd'hui

---

## Tests à effectuer

### ✅ Test 1: Workflow Enseignant

**En tant qu'enseignant (BEDE ABEL TEST):**

1. Se connecter au LMS
2. Aller sur **"Mes Séances"** (menu principal)
3. Trouver une séance programmée pour aujourd'hui

**Vérifier:**
- [ ] Badge "Participants (X)" affiche le bon nombre (ex: 2 pour B2 COM)
- [ ] Bouton "Programmer en visio" visible
- [ ] Clic → Badge passe à "PROGRAMMÉE"
- [ ] Bouton "Démarrer la visio" **bleu et cliquable** (pas grisé)
- [ ] Clic → Jitsi s'ouvre automatiquement
- [ ] Badge passe à "EN DIRECT" (vert)
- [ ] Votre nom s'affiche dans Jitsi

**Console navigateur:**
- [ ] Aucune erreur 500
- [ ] Log: `🎥 Démarrage visio par enseignant...`
- [ ] Log: `✅ API Response: /lms/seances/X/start-visio 200`

---

### ✅ Test 2: Workflow Étudiant

**En tant qu'étudiant (MARCEL OUEDRAOGO):**

1. Se connecter au LMS
2. Dashboard → **"Matières"** → Sélectionner une matière
3. Voir la liste des séances

**Vérifier AVANT que l'enseignant démarre:**
- [ ] Badge "Participants (X)" affiche le bon nombre
- [ ] Bouton "Rejoindre la visio" **GRISÉ**
- [ ] Message: "La visio sera disponible lorsque l'enseignant la démarrera"

**Vérifier APRÈS que l'enseignant démarre:**
- [ ] Badge passe à "EN DIRECT" (vert avec animation)
- [ ] Bouton "Rejoindre la visio" devient **VIOLET et cliquable**
- [ ] Clic → Jitsi s'ouvre
- [ ] Nom "MARCEL OUEDRAOGO" s'affiche dans Jitsi
- [ ] Vous voyez l'enseignant dans la salle

**Console navigateur:**
- [ ] Aucune erreur 500
- [ ] Log: `👨‍🎓 Étudiant rejoint la visio...`
- [ ] Log: `✅ Étudiant a rejoint la visio`

---

### ✅ Test 3: Page Détails Séance (Étudiant)

**En tant qu'étudiant:**

1. Depuis la liste des séances → Cliquer sur une séance
2. Accès à `/seances/19` (ou autre ID)

**Vérifier:**
- [ ] Page charge **SANS erreur 500**
- [ ] Toutes les infos affichées:
  - Date et horaire
  - Matière
  - Enseignant
  - Salle
  - Durée
- [ ] Badge visio correct
- [ ] Bouton "Rejoindre" fonctionne

**Console navigateur:**
- [ ] Log: `✅ API Response: /lms/seances/X/details 200`
- [ ] **PAS** d'erreur 500
- [ ] **PAS** d'erreur KLASSCI emploi-temps

---

### ✅ Test 4: Terminer la Visio (Enseignant)

**En tant qu'enseignant:**

1. Pendant que la visio est EN DIRECT
2. Cliquer sur **"Terminer la visio"**

**Vérifier:**
- [ ] Confirmation demandée
- [ ] Badge passe à "TERMINÉE" (gris)
- [ ] Bouton "Rejoindre" redevient grisé pour les étudiants
- [ ] Les étudiants déjà connectés peuvent continuer (Jitsi reste ouvert)

---

## Checklist Complète

### Backend
- [ ] Aucune erreur 500 sur `/start-visio`
- [ ] Aucune erreur 500 sur `/seances/{id}/details`
- [ ] Aucune erreur 500 sur `/matieres/{id}`
- [ ] Badge participants affiche le bon nombre
- [ ] `visio_active` passe à `true` quand démarrage
- [ ] `visio_status` passe à `active` quand démarrage

### Frontend
- [ ] Bouton enseignant toujours actif (pas de fenêtre temporelle)
- [ ] Bouton étudiant activé seulement si visio active
- [ ] Jitsi s'ouvre correctement (pop-up non bloqué)
- [ ] Nom utilisateur affiché dans Jitsi
- [ ] Pas d'appel à `validateParticipant` (supprimé)
- [ ] Pas d'erreur console 500

---

## Problèmes Potentiels

### Si bouton enseignant grisé
**Solution:**
1. Vérifier que `visio_enabled = true` en BDD
2. Rafraîchir la page (CTRL+SHIFT+R)
3. Vérifier rôle utilisateur = enseignant

### Si bouton étudiant reste grisé (alors que visio active)
**Solution:**
1. Vérifier `visio_active = true` en BDD (pas juste `visio_status`)
2. Rafraîchir la page
3. Vérifier console: doit avoir `visio_active: true` dans la réponse API

### Si erreur 500 sur /seances/{id}/details pour étudiant
**Cause:** Cas étudiant manquant dans seanceDetails()
**Fix appliqué:** Ligne 1203-1226 de LMSDataController.php

### Si Jitsi ne s'ouvre pas
**Solution:**
1. Désactiver bloqueur pop-ups
2. Autoriser pop-ups pour localhost:5173
3. Tester autre navigateur (Chrome recommandé)

---

## Commandes Utiles

### Vérifier état BDD
```bash
php artisan tinker --execute="
\$visio = App\Models\Seance::where('klassci_seance_id', 19)->first();
echo 'Status: ' . \$visio->visio_status . PHP_EOL;
echo 'Active: ' . (\$visio->visio_active ? 'OUI' : 'NON') . PHP_EOL;
"
```

### Réinitialiser une séance
```bash
php reset_seance_for_test.php
```

### Tester workflow complet
```bash
php test_workflow_complet.php
```

---

## Après les Tests

### Si tout fonctionne ✅
1. Marquer toutes les tâches comme terminées
2. Passer à la prochaine fonctionnalité LMS
3. Optionnel: Implémenter tracking présences (Option B du plan)

### Si problèmes ❌
1. Noter les erreurs console exactes
2. Vérifier état BDD avec commande ci-dessus
3. Consulter `RAPPORT_AUDIT_VISIO_COMPLET.md`
4. Me contacter avec logs complets

---

## Prochaines Améliorations (Optionnel)

Ces fonctionnalités sont en attente:

### Option A: Sécurité minimale
- Vérifier que l'étudiant est inscrit dans la classe
- Bloquer accès si non autorisé

### Option B: Tracking simple (RECOMMANDÉ)
- Enregistrer qui a rejoint (avec nom)
- Enregistrer heure de connexion
- Afficher liste réelle des participants

### Option C: Tracking complet
- Durée de participation précise
- Webhook Jitsi
- Synchronisation avec KLASSCI /presences
- Statistiques temps réel

**À décider après les tests.**

---

## Documentation Utilisateur

Pour les utilisateurs finaux, consulter:
- **VISIOCONFERENCE_GUIDE_UTILISATEUR.md**

Pour les développeurs:
- **RAPPORT_AUDIT_VISIO_COMPLET.md**
- **CORRECTION_BOUTON_VISIO_GRISE.md**
- **AJOUT_EFFECTIF_PARTICIPANTS.md**
