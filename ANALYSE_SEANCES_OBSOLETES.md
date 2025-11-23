# ANALYSE: Gestion des séances obsolètes

**Date**: 2025-11-19
**Problème rapporté**: Séances supprimées dans Klassci toujours visibles dans le LMS

---

## 🔴 PROBLÈMES IDENTIFIÉS

### 1. Séances supprimées de Klassci restent dans le LMS

**Observation**:
- Dans "Matières → Séances", toutes les séances historiques sont conservées
- Même si supprimées dans Klassci, elles restent dans le LMS
- Crée de la confusion

**Impact**:
- ❌ Étudiants voient des séances qui n'existent plus
- ❌ Mémoire utilisée inutilement
- ❌ Interface encombrée

### 2. Séances passées visibles indéfiniment

**Observation**:
- Les séances terminées restent visibles pour toujours
- Pas de nettoyage automatique
- Dashboard étudiant encombré

**Impact**:
- ❌ Confusion pour les étudiants
- ❌ Difficulté à trouver les séances actuelles
- ❌ Données inutiles stockées

---

## 💡 TES PROPOSITIONS (excellentes!)

### Proposition 1: Synchronisation avec Klassci
> "Une séance qui n'existe plus dans Klassci ne doit pas être visible dans le LMS"

**✅ JE SUIS D'ACCORD**

**Raisons**:
- Klassci est la source de vérité
- Évite les incohérences
- Nettoyage automatique

### Proposition 2: Archivage automatique après 2 semaines
> "Les séances passées doivent avoir un délai de 2 semaines pour disparaître dans le dashboard étudiant"

**✅ JE SUIS D'ACCORD**

**Raisons**:
- 2 semaines = délai raisonnable pour revoir un cours
- Libère la mémoire
- Interface plus claire

---

## 🎯 STRATÉGIE PROPOSÉE

### Option A: SOFT DELETE (Recommandée)

**Concept**: Ne pas supprimer, mais masquer

**Avantages**:
- ✅ Conservation des données historiques (stats, présences)
- ✅ Pas de perte de données
- ✅ Possibilité de restaurer si erreur
- ✅ Audit trail complet

**Implémentation**:
1. Ajouter un champ `is_active` à la table `seances`
2. Marquer `is_active = false` si:
   - Séance supprimée de Klassci
   - Séance > 2 semaines après la date
3. Filtrer par `is_active = true` dans les vues étudiants

### Option B: HARD DELETE

**Concept**: Supprimer réellement de la base

**Avantages**:
- ✅ Libère vraiment la mémoire
- ✅ Base de données plus légère

**Inconvénients**:
- ❌ Perte des données de présence
- ❌ Perte des stats
- ❌ Impossible de retrouver l'historique

---

## 📋 SOLUTION DÉTAILLÉE (Option A)

### 1. Modifier la table `seances`

```sql
ALTER TABLE seances ADD COLUMN is_active BOOLEAN DEFAULT true;
ALTER TABLE seances ADD COLUMN archived_at TIMESTAMP NULL;
ALTER TABLE seances ADD COLUMN archive_reason VARCHAR(255) NULL;
```

**Champs**:
- `is_active`: true = visible, false = archivée
- `archived_at`: Date d'archivage
- `archive_reason`: Raison (supprimé_klassci, trop_ancienne, etc.)

### 2. Job de nettoyage automatique

**Créer**: `app/Jobs/ArchiveOldSeances.php`

```php
class ArchiveOldSeances implements ShouldQueue
{
    public function handle()
    {
        $twoWeeksAgo = now()->subWeeks(2);

        // Archiver les séances > 2 semaines
        Seance::where('is_active', true)
            ->whereNotNull('programmation->date')
            ->whereRaw("DATE(json_extract(programmation, '$.date')) < ?", [$twoWeeksAgo])
            ->update([
                'is_active' => false,
                'archived_at' => now(),
                'archive_reason' => 'trop_ancienne'
            ]);
    }
}
```

### 3. Synchronisation avec Klassci

**Modifier**: `app/Jobs/SyncKlassciSeances.php`

Ajouter la vérification des séances supprimées:

```php
// Récupérer les IDs de toutes les séances Klassci actuelles
$klassciSeanceIds = [...]; // Collectées pendant le parcours

// Marquer comme inactives les séances qui n'existent plus dans Klassci
Seance::where('is_active', true)
    ->whereNotIn('klassci_seance_id', $klassciSeanceIds)
    ->update([
        'is_active' => false,
        'archived_at' => now(),
        'archive_reason' => 'supprimee_klassci'
    ]);
```

### 4. Filtrage dans les APIs

**Pour les ÉTUDIANTS** (masquer les anciennes):
```php
// app/Http/Controllers/API/LMSDataController.php

public function mySeances(Request $request)
{
    // Pour les étudiants: seulement les séances actives
    $query = Seance::where('is_active', true);

    // ...
}
```

**Pour les ENSEIGNANTS/COORDINATEURS** (tout voir):
```php
public function myTeachingSeances(Request $request)
{
    // Les enseignants voient tout (même archivées)
    $query = Seance::query();

    // Optionnel: flag pour indiquer si archivée
    // ...
}
```

### 5. Scheduler

**Configurer**: `routes/console.php`

```php
// Archiver les vieilles séances tous les jours à 2h du matin
Schedule::job(new ArchiveOldSeances)
    ->dailyAt('02:00')
    ->name('archive-old-seances')
    ->withoutOverlapping();
```

---

## 🔧 RÈGLES DE GESTION

### Quand archiver une séance?

| Condition | Action | Raison |
|-----------|--------|--------|
| Supprimée de Klassci | `is_active = false` | `supprimee_klassci` |
| Date > 2 semaines | `is_active = false` | `trop_ancienne` |
| Manuelle (admin) | `is_active = false` | `archivage_manuel` |

### Qui voit quoi?

| Rôle | Séances actives | Séances archivées |
|------|-----------------|-------------------|
| Étudiant | ✅ Oui | ❌ Non |
| Enseignant | ✅ Oui | ✅ Oui (avec badge) |
| Coordinateur | ✅ Oui | ✅ Oui (avec badge) |
| Admin | ✅ Oui | ✅ Oui (avec badge) |

### Données conservées

Même archivée, une séance conserve:
- ✅ Historique des présences
- ✅ Statistiques de participation
- ✅ Enregistrements vidéo
- ✅ Logs de connexion

---

## 📊 IMPACT SUR LA MÉMOIRE

### Avant (problème actuel)

```
1000 séances × 5 KB = 5 MB
Toutes en mémoire active
```

### Après (solution)

```
50 séances actives × 5 KB = 250 KB
950 séances archivées (indexées, pas chargées)
```

**Gain**: ~95% de réduction de la charge mémoire

---

## ⚠️ POINTS D'ATTENTION

### 1. Notifications déjà envoyées

Les notifications envoyées pour des séances archivées restent visibles.
- ✅ C'est normal: l'historique de notification est conservé
- L'étudiant peut voir qu'une notification a été envoyée, mais la séance n'est plus accessible

### 2. Présences enregistrées

Les présences pour séances archivées sont conservées.
- ✅ Important pour les statistiques
- ✅ Important pour les preuves d'assiduité

### 3. Restauration

Si une séance archivée par erreur:
```php
$seance->update([
    'is_active' => true,
    'archived_at' => null,
    'archive_reason' => null
]);
```

---

## 🧪 TESTS À EFFECTUER

1. **Créer une séance vieille de 3 semaines**
   - Vérifier qu'elle est archivée automatiquement
   - Vérifier qu'elle n'apparaît pas pour les étudiants
   - Vérifier qu'elle apparaît pour les enseignants

2. **Supprimer une séance de Klassci**
   - Attendre le job de sync (5 min)
   - Vérifier qu'elle est marquée `is_active = false`
   - Vérifier qu'elle disparaît du dashboard étudiant

3. **Vérifier les stats**
   - Les présences anciennes sont toujours comptées
   - Les stats d'enseignant incluent toutes les séances

---

## 📝 RÉSUMÉ DE L'IMPLÉMENTATION

### Étapes:

1. ✅ Migration: Ajouter `is_active`, `archived_at`, `archive_reason`
2. ✅ Job: `ArchiveOldSeances` (séances > 2 semaines)
3. ✅ Job: Modifier `SyncKlassciSeances` (détecter suppressions)
4. ✅ API: Filtrer par `is_active` pour étudiants
5. ✅ Scheduler: Exécuter archivage quotidien
6. ✅ UI: Badge "Archivée" pour enseignants

### Délais:

- Migration + Jobs: 1h
- Tests: 30 min
- **Total: ~1h30**

---

## 🎯 RECOMMANDATION FINALE

**JE RECOMMANDE: Option A (Soft Delete)**

**Raisons**:
1. ✅ Pas de perte de données
2. ✅ Conformité RGPD (conservation de l'historique)
3. ✅ Possibilité de restaurer
4. ✅ Stats complètes pour enseignants
5. ✅ Interface allégée pour étudiants

**Délai de 2 semaines**: ✅ PARFAIT
- Assez long pour revoir un cours
- Assez court pour garder l'interface propre

---

**Veux-tu que j'implémente cette solution?** 🎯
