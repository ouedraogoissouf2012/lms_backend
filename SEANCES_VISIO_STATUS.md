# État Actuel: Système Séances & Visioconférence

**Date**: 2025-10-20
**Status**: ✅ Implémentation terminée - En attente de test

---

## ✅ Corrections Appliquées

### 1. Architecture Clarifiée
- **KLASSCI**: Source de vérité pour les séances (date, heure, classe, enseignant, matière)
- **LMS**: Gère uniquement les données de visioconférence
- **Table `seances`**: Stocke uniquement `klassci_seance_id` + colonnes visio

### 2. Backend (`LMSDataController.php`)

#### Endpoint: `GET /api/lms/seances/upcoming`
**Ligne 500-593**

**Workflow**:
1. Récupère séances de KLASSCI via `GET /emploi-temps`
2. Gère l'erreur KLASSCI gracieusement (bug SQL connu ligne 525-533)
3. Enrichit chaque séance avec données visio locales (ligne 536-565)
4. Retourne format: `{ success: true, data: [...], meta: {...} }`

**Structure Response**:
```json
{
  "success": true,
  "data": [
    {
      "id": 123,                    // ← ID KLASSCI
      "date_seance": "2025-10-21",
      "heure_debut": "08:00",
      "heure_fin": "10:00",
      "matiere": {...},
      "classe": {...},
      "enseignant": {...},
      "visio_enabled": true,        // ← Ajouté par enrichissement
      "visio_type": "jitsi",        // ← Ajouté par enrichissement
      "visio_room_id": "lms_seance_123_1729431234"
    }
  ],
  "meta": {
    "total_seances": 15,
    "date_debut": "2025-10-20",
    "date_fin": "2025-11-19"
  }
}
```

#### Endpoint: `POST /api/lms/seances/{seanceId}/toggle-visio`
**Ligne 1165-1223**

**Workflow**:
1. Vérifie séance existe dans KLASSCI via `GET /seances/{id}`
2. Crée/Met à jour entrée locale avec `updateOrCreate(['klassci_seance_id' => $id])`
3. Génère `visio_room_id` unique si activation
4. Retourne infos visio créées

### 3. Frontend (`SeanceManagement.vue`)

#### Fix Appliqué: Ligne 293
```javascript
// Backend retourne data.data (tableau direct), pas data.data.seances
this.seances = Array.isArray(data.data) ? data.data : (data.data.seances || [])
```

**Raison**: L'intercepteur axios extrait déjà `response.data`, donc le service reçoit directement l'objet `{ success, data, meta }`.

#### Services LMS (`lms.js`)
**Ligne 59-79**: Ajout de `getClasses()` et `getEnseignants()` pour les filtres

---

## 🔍 Points de Vérification

### Pour Tester l'Affichage Frontend

1. **Ouvrir la console navigateur** (F12)
2. **Aller sur** `/coordinateur/seances`
3. **Vérifier les logs console**:
   ```
   📅 Chargement séances à venir...
   ✅ Séances reçues: { success: true, data: [...], meta: {...} }
   📊 X séances chargées
   🔍 Première séance: { id: ..., date_seance: ..., ... }
   ```

### Cas 1: Aucune Séance Affichée

**Diagnostic**:
```javascript
// Dans la console
console.log(data)           // Voir la structure complète
console.log(data.data)      // Doit être un array
console.log(data.success)   // Doit être true
```

**Causes possibles**:
- ❌ KLASSCI n'a pas de séances pour la période (30 prochains jours par défaut)
- ❌ Bug KLASSCI emploi-temps (SQL error sur `date_cours`)
- ❌ Filtres trop restrictifs (enseignant/classe sélectionnés sans séances)

**Solutions**:
```bash
# Backend: Vérifier les logs Laravel
tail -f storage/logs/laravel.log

# Chercher:
# - "Récupération séances à venir"
# - "KLASSCI emploi-temps endpoint failed"
# - Erreurs SQL
```

### Cas 2: Erreur 401 Unauthorized

**Cause**: Token KLASSCI expiré/invalide

**Solution**:
1. Se déconnecter du LMS
2. Se reconnecter (génère nouveau token)
3. Réessayer

### Cas 3: Erreur 500 Server Error

**Vérifier**:
```bash
# Logs backend
tail -f storage/logs/laravel.log

# Migration table seances
php artisan migrate:status | grep seances
# Doit afficher: Ran
```

---

## 🧪 Test Complet du Workflow

### Étape 1: Vérifier Séances KLASSCI Existent

**Option A - Via Frontend LMS**:
1. Se connecter comme coordinateur
2. Aller sur `/coordinateur/seances`
3. Observer nombre de séances affichées

**Option B - Via KLASSCI Direct**:
```bash
# Requête manuelle KLASSCI
curl -X GET "https://klassci-url/api/emploi-temps?date_debut=2025-10-20&date_fin=2025-11-19" \
  -H "Authorization: Bearer YOUR_KLASSCI_TOKEN"
```

### Étape 2: Programmer Visio

1. Cliquer sur "Activer visio" pour une séance
2. Vérifier message succès
3. Vérifier la carte séance affiche maintenant section violette avec:
   - Type visio sélectionnable
   - Room ID généré
   - Message "Les étudiants pourront rejoindre 15 minutes avant"

### Étape 3: Vérifier Base de Données

```sql
-- Table seances doit contenir nouvelle entrée
SELECT
  id,
  klassci_seance_id,
  visio_enabled,
  visio_type,
  visio_room_id,
  created_at
FROM seances
ORDER BY created_at DESC
LIMIT 5;
```

**Attendu**:
```
id | klassci_seance_id | visio_enabled | visio_type | visio_room_id
---+-------------------+---------------+------------+----------------------------
1  | 123               | 1             | jitsi      | lms_seance_123_1729431234
```

---

## 🐛 Problèmes Connus

### Bug KLASSCI: emploi-temps SQL Error

**Symptôme**: Backend log montre:
```
KLASSCI emploi-temps endpoint failed (known bug)
SQLSTATE[42S22]: Column not found: 'date_cours'
```

**Impact**: Frontend affiche "0 séances" au lieu d'erreur 500

**Statut**:
- ✅ Backend gère gracieusement (ligne 525-533)
- ⏳ Nécessite correction côté KLASSCI (remplacer `date_cours` par `date_seance`)

**Workaround**: Créer séances via KLASSCI et tester avec endpoint direct `/seances/{id}` qui fonctionne.

---

## 📊 Structure Finale Table `seances`

```sql
CREATE TABLE seances (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,

  -- Références KLASSCI uniquement
  klassci_seance_id BIGINT UNIQUE,          -- Clé étrangère logique
  klassci_matiere_id BIGINT,                -- Copie pour faciliter joins
  klassci_classe_id BIGINT,                 -- Copie pour faciliter joins
  klassci_enseignant_id BIGINT,             -- Copie pour faciliter joins

  -- Visioconférence (géré par LMS)
  visio_enabled BOOLEAN DEFAULT FALSE,
  visio_type ENUM('jitsi','zoom','teams','bbb') NULL,
  visio_room_id VARCHAR(255) NULL,
  visio_active BOOLEAN DEFAULT FALSE,
  visio_started_at TIMESTAMP NULL,
  visio_ended_at TIMESTAMP NULL,

  -- Audit
  created_by BIGINT NULL,
  updated_by BIGINT NULL,
  created_at TIMESTAMP,
  updated_at TIMESTAMP,
  deleted_at TIMESTAMP NULL,

  INDEX idx_klassci_seance (klassci_seance_id),
  INDEX idx_klassci_classe (klassci_classe_id),
  INDEX idx_klassci_enseignant (klassci_enseignant_id),
  INDEX idx_visio (visio_enabled, visio_active)
);
```

**Important**: Aucune colonne `date_seance`, `heure_debut`, `heure_fin`, `titre`, `description` car ces données restent dans KLASSCI!

---

## 📝 Prochaines Étapes

1. **Test utilisateur**: Rafraîchir frontend et vérifier affichage séances
2. **Si aucune séance affichée**: Vérifier logs backend pour identifier cause (KLASSCI bug vs. pas de données)
3. **Créer séances test**: Dans KLASSCI pour période actuelle si nécessaire
4. **Test complet workflow**: Programmer visio → Démarrer visio → Rejoindre → Sync présences

---

## 📚 Documentation Complète

- [WORKFLOW_SEANCES_VISIO.md](./WORKFLOW_SEANCES_VISIO.md) - Architecture et workflow détaillé
- Migration: `database/migrations/2025_10_20_153241_create_seances_table.php`
- Model: `app/Models/Seance.php`
- Controller: `app/Http/Controllers/API/LMSDataController.php` (lignes 488-593, 1165-1223)
- Frontend: `src/views/coordinateur/SeanceManagement.vue`
- Service: `src/services/lms.js`

---

**Status Final**: ✅ Toutes les corrections appliquées. Le système est prêt pour tests.
