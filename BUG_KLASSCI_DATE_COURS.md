# Bug KLASSCI: date_cours → date_seance

**Date**: 2025-10-20
**Severity**: 🔴 CRITIQUE - Bloque complètement les séances
**Status**: ⏳ À corriger dans KLASSCI

---

## 🐛 Description du Bug

### Erreur SQL
```
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'date_cours' in 'WHERE'
SQL: select * from `esbtp_seance_cours`
     where `date_cours` between 2025-10-20 and 2025-10-26
```

### Fichier KLASSCI Concerné
```
/home/c2569688c/public_html/presentation/app/Http/Controllers/API/LMSDataController.php
Ligne: 468
Fonction: emploiTemps()
```

### Cause
Le code KLASSCI utilise la colonne **`date_cours`** qui n'existe pas dans la table `esbtp_seance_cours`.
La colonne correcte est **`date_seance`**.

---

## ✅ Solution: Corriger le Code KLASSCI

### Étape 1: Ouvrir le Fichier

**Sur le serveur KLASSCI**:
```bash
nano /home/c2569688c/public_html/presentation/app/Http/Controllers/API/LMSDataController.php
```

### Étape 2: Localiser la Ligne 468

Chercher la fonction `emploiTemps()` autour de la ligne 468:

```php
public function emploiTemps(Request $request)
{
    // ... code avant ...

    $query = SeanceCours::query()
        ->whereBetween('date_cours', [$dateDebut, $dateFin])  // ❌ ERREUR ICI
        ->whereHas('emploiTemps', function ($q) use ($anneeId) {
            $q->where('annee_universitaire_id', $anneeId)
              ->where('is_active', true);
        })
        ->orderBy('date_cours', 'asc')   // ❌ ERREUR ICI AUSSI
        ->orderBy('heure_debut', 'asc');

    // ... code après ...
}
```

### Étape 3: Remplacer `date_cours` par `date_seance`

**AVANT** (bugué):
```php
$query = SeanceCours::query()
    ->whereBetween('date_cours', [$dateDebut, $dateFin])
    ->whereHas('emploiTemps', function ($q) use ($anneeId) {
        $q->where('annee_universitaire_id', $anneeId)
          ->where('is_active', true);
    })
    ->orderBy('date_cours', 'asc')
    ->orderBy('heure_debut', 'asc');
```

**APRÈS** (corrigé):
```php
$query = SeanceCours::query()
    ->whereBetween('date_seance', [$dateDebut, $dateFin])  // ✅ Corrigé
    ->whereHas('emploiTemps', function ($q) use ($anneeId) {
        $q->where('annee_universitaire_id', $anneeId)
          ->where('is_active', true);
    })
    ->orderBy('date_seance', 'asc')  // ✅ Corrigé
    ->orderBy('heure_debut', 'asc');
```

### Étape 4: Sauvegarder et Tester

```bash
# Sauvegarder (Ctrl+O dans nano, puis Entrée, puis Ctrl+X)

# Tester l'endpoint
curl -X GET "https://klassci-url/api/emploi-temps?date_debut=2025-10-20&date_fin=2025-11-19" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

## 🔍 Vérification: Nom Réel de la Colonne

### SQL Direct
```sql
-- Sur le serveur MySQL de KLASSCI
USE c2569688c_base_esbtp;  -- ou votre nom de DB

SHOW COLUMNS FROM esbtp_seance_cours LIKE '%date%';
```

**Résultat attendu**:
```
Field        | Type | Null | Key | Default | Extra
-------------|------|------|-----|---------|-------
date_seance  | date | YES  |     | NULL    |
created_at   | ...  | ...  | ... | ...     | ...
updated_at   | ...  | ...  | ... | ...     | ...
```

Si la colonne s'appelle `date_seance`, c'est confirmé.

---

## 📊 Impact du Bug

### Avant Correction
```
Frontend LMS → Backend LMS → KLASSCI API /emploi-temps
                                    ↓
                               500 Error (date_cours)
                                    ↓
                            Backend LMS catch exception
                                    ↓
                            Retourne tableau vide []
                                    ↓
                            Frontend affiche "0 séances"
```

### Après Correction
```
Frontend LMS → Backend LMS → KLASSCI API /emploi-temps
                                    ↓
                            Requête SQL correcte (date_seance)
                                    ↓
                            Retourne séances [...]
                                    ↓
                            Backend enrichit avec visio
                                    ↓
                            Frontend affiche séances ✅
```

---

## 🛠️ Workaround Temporaire (Si Impossible de Corriger KLASSCI Immédiatement)

### Option: Requête Directe à la Base

Si vous ne pouvez pas modifier le code KLASSCI tout de suite, nous pouvons interroger directement la table:

**Fichier**: `app/Http/Controllers/API/LMSDataController.php` (LMS, pas KLASSCI)
**Méthode**: `upcomingSeances()`

```php
// Au lieu d'utiliser l'endpoint KLASSCI bugué
try {
    $seancesResponse = $this->klassciService->requestWithUserToken(...);
    $seances = collect($seancesResponse['data'] ?? []);
} catch (\Exception $klassciError) {
    // Workaround: requête directe DB
    Log::warning('KLASSCI endpoint failed, using direct DB query');

    $seances = DB::table('esbtp_seance_cours as sc')
        ->join('esbtp_emploi_temps as et', 'sc.emploi_temps_id', '=', 'et.id')
        ->join('esbtp_matieres as m', 'et.matiere_id', '=', 'm.id')
        ->join('esbtp_classes as c', 'et.classe_id', '=', 'c.id')
        ->leftJoin('esbtp_enseignants as ens', 'et.enseignant_id', '=', 'ens.id')
        ->whereBetween('sc.date_seance', [$dateDebut, $dateFin])  // ✅ date_seance
        ->where('et.annee_universitaire_id', 1)
        ->where('et.is_active', 1)
        ->whereNull('sc.deleted_at')
        ->when($teacherId, fn($q) => $q->where('et.enseignant_id', $teacherId))
        ->when($classeId, fn($q) => $q->where('et.classe_id', $classeId))
        ->select([
            'sc.id',
            'sc.date_seance',
            'sc.heure_debut',
            'sc.heure_fin',
            'sc.salle',
            'm.id as matiere_id',
            'm.libelle as matiere_libelle',
            'c.id as classe_id',
            'c.libelle as classe_libelle',
            'ens.id as enseignant_id',
            'ens.nom as enseignant_nom',
            'ens.prenom as enseignant_prenom'
        ])
        ->orderBy('sc.date_seance', 'asc')
        ->orderBy('sc.heure_debut', 'asc')
        ->get()
        ->map(function ($seance) {
            return [
                'id' => $seance->id,
                'date_seance' => $seance->date_seance,
                'heure_debut' => $seance->heure_debut,
                'heure_fin' => $seance->heure_fin,
                'salle' => $seance->salle,
                'matiere' => [
                    'id' => $seance->matiere_id,
                    'libelle' => $seance->matiere_libelle
                ],
                'classe' => [
                    'id' => $seance->classe_id,
                    'libelle' => $seance->classe_libelle
                ],
                'enseignant' => [
                    'id' => $seance->enseignant_id,
                    'nom' => $seance->enseignant_nom,
                    'prenom' => $seance->enseignant_prenom
                ]
            ];
        });
}
```

**Avantages**:
- ✅ Contourne le bug KLASSCI
- ✅ Récupère les séances immédiatement
- ✅ Même format de données

**Inconvénients**:
- ⚠️ Nécessite accès direct à la DB KLASSCI
- ⚠️ Bypass l'API KLASSCI (perte de logs/sécurité API)
- ⚠️ À remplacer dès que KLASSCI est corrigé

---

## ✅ Checklist Correction

### Sur KLASSCI
- [ ] Ouvrir `/home/c2569688c/public_html/presentation/app/Http/Controllers/API/LMSDataController.php`
- [ ] Localiser ligne 468 (fonction `emploiTemps()`)
- [ ] Remplacer tous les `date_cours` par `date_seance`
- [ ] Sauvegarder
- [ ] Tester endpoint `/api/emploi-temps`

### Sur LMS
- [ ] Rafraîchir frontend `/coordinateur/seances`
- [ ] Vérifier console: `📊 X séances chargées` (X > 0)
- [ ] Tester "Activer visio" sur une séance

---

## 📝 Questions à Se Poser

### 1. Pourquoi ce bug existe?
Probablement une migration/refactoring KLASSCI où la colonne `date_cours` a été renommée en `date_seance`, mais le code n'a pas été mis à jour partout.

### 2. Y a-t-il d'autres endroits avec ce bug?
Oui, potentiellement. Chercher dans tout le code KLASSCI:
```bash
cd /home/c2569688c/public_html/presentation
grep -r "date_cours" app/ | grep -v ".swp"
```

### 3. Comment éviter ça à l'avenir?
- ✅ Tests automatisés sur les endpoints
- ✅ Migration avec renommage explicite
- ✅ Search & replace global lors de refactoring

---

## 🎯 Prochaines Étapes

**PRIORITÉ 1** 🔴:
Corriger le bug dans KLASSCI (5 minutes)

**PRIORITÉ 2** ⚠️:
Si correction impossible immédiatement, implémenter workaround requête directe DB

**PRIORITÉ 3** ✅:
Tester que les séances s'affichent correctement dans LMS

---

**Note Importante**: Le LMS fonctionne correctement. C'est uniquement l'API KLASSCI qui est buguée. Une fois corrigé, tout fonctionnera immédiatement.
