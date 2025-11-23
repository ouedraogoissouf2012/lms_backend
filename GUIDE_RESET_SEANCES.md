# 🔧 GUIDE: Réinitialisation des Séances Actives

## LE PROBLÈME

Les séances dans la base de données ont `visio_status = 'active'` alors qu'elles devraient être à `'programmee'`.

Cela empêche le workflow correct:
- ❌ **Actuellement**: Étudiant voit "EN DIRECT" et peut rejoindre même si l'enseignant n'a pas démarré
- ✅ **Attendu**: Étudiant doit attendre que l'enseignant clique "Démarrer maintenant"

---

## SOLUTION

Vous devez exécuter une requête SQL pour réinitialiser toutes les séances actives à `'programmee'`.

### Option 1: Via phpMyAdmin (RECOMMANDÉ)

1. Ouvrez phpMyAdmin dans votre navigateur
2. Sélectionnez la base de données `esbtp_lms`
3. Cliquez sur l'onglet "SQL"
4. Copiez et exécutez cette requête:

```sql
UPDATE esbtp_seances
SET visio_status = 'programmee',
    visio_active = 0,
    visio_started_at = NULL
WHERE visio_status = 'active';
```

5. Vérifiez le résultat en exécutant:

```sql
SELECT id, klassci_seance_id, matiere_nom, visio_status, visio_enabled
FROM esbtp_seances
WHERE visio_enabled = 1
ORDER BY id DESC;
```

Vous devriez voir toutes les séances avec `visio_status = 'programmee'`.

---

### Option 2: Via MySQL Command Line

Si vous avez accès à la ligne de commande MySQL:

```bash
mysql -u root -p
```

Entrez le mot de passe, puis:

```sql
USE esbtp_lms;

UPDATE esbtp_seances
SET visio_status = 'programmee',
    visio_active = 0,
    visio_started_at = NULL
WHERE visio_status = 'active';

-- Vérifier
SELECT id, klassci_seance_id, matiere_nom, visio_status
FROM esbtp_seances
WHERE visio_enabled = 1;
```

---

### Option 3: Via Script PHP (nécessite MySQL démarré)

Si votre serveur MySQL est démarré:

```bash
php reset_seances_mysql_direct.php
```

**Note**: Ce script nécessite que MySQL soit accessible sur `localhost:3306` avec:
- Username: `root`
- Password: `ESBTP2024`
- Database: `esbtp_lms`

---

## VÉRIFICATION

Après avoir exécuté la requête, vérifiez:

1. **Backend corrigé** ✅
   - `activateVisio()` → status = 'programmee' (pas 'active')
   - Séances dans la DB remises à 'programmee'

2. **Frontend déjà correct** ✅
   - TeacherSeances.vue a le bouton "Démarrer maintenant"
   - SeanceDetails.vue bloque si status !== 'active'

---

## TEST COMPLET

1. **Rechargez le navigateur** (Ctrl+Shift+R pour vider le cache)

2. **Coordinateur active la visio**
   - Status devient `'programmee'`
   - Enseignant voit le bouton "Démarrer maintenant"
   - Étudiant voit "En attente de l'enseignant"

3. **Enseignant clique "Démarrer maintenant"**
   - Status devient `'active'`
   - Jitsi s'ouvre
   - Étudiant voit maintenant "COURS EN DIRECT" et peut rejoindre

4. **Étudiant rejoint**
   - Peut rejoindre la visio
   - Heure d'entrée enregistrée

5. **Tous quittent Jitsi**
   - Heures de sortie enregistrées automatiquement
   - Liste de présence complète avec entrée/sortie

---

## SI ÇA NE MARCHE PAS

### Problème: MySQL ne démarre pas

Si `php reset_seances_mysql_direct.php` échoue avec "connexion refusée":

1. Vérifiez que MySQL/MariaDB est démarré
2. Vérifiez le port (3306 par défaut)
3. Utilisez phpMyAdmin ou HeidiSQL à la place

### Problème: Étudiant voit encore "EN DIRECT"

1. Assurez-vous que la base de données est mise à jour (vérifiez avec SQL)
2. Videz le cache du navigateur (Ctrl+Shift+R)
3. Vérifiez que le frontend a bien le code corrigé dans SeanceDetails.vue

### Problème: Sortie visio pas enregistrée

Vérifiez que vous avez appliqué les fixes dans:
- ✅ SeanceDetails.vue (pour étudiants)
- ✅ TeacherSeances.vue (pour enseignants/coordinateurs)

---

## RÉSUMÉ DES FIXES APPLIQUÉS

### Backend ✅
- `LMSDataController.php` → `activateVisio()` remet status = 'programmee'
- `getVisioParticipants()` → Retourne les infos enseignant

### Frontend ✅
- `SeanceDetails.vue` → Tracking fermeture fenêtre + appel `leaveVisio()`
- `TeacherSeances.vue` → Même chose pour enseignants
- `ParticipantsModal.vue` → Affiche nom enseignant

### Database ⏳
- **À FAIRE**: Exécuter requête UPDATE (voir Option 1, 2 ou 3 ci-dessus)

---

## CONTACT

Si vous avez besoin d'aide pour exécuter la requête SQL, faites-moi signe!
