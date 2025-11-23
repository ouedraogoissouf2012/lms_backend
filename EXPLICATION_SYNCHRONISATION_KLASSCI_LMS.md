# 📚 EXPLICATION: Mécanisme de synchronisation Klassci ↔ LMS

**Date**: 2025-11-19
**Audience**: Utilisateur final / Chef de projet

---

## 🤔 VOTRE QUESTION

> "si je comprend bien klassci n'a retourner aucune seance mais le lms affiche des seance avec la date du jour? ma question comment se fait la synchronisation entre klassci et le lms sur ce point?"

---

## ✅ RÉPONSE SIMPLE

**Avant la correction**:
- Klassci retournait 0 séances (car vous les aviez supprimées)
- LMS avait 8 séances "fantômes" dans sa base de données locale
- Ces séances fantômes s'affichaient avec la date d'aujourd'hui (dates inventées)

**Maintenant (après correction)**:
- Klassci retourne 0 séances (normal, elles sont supprimées)
- LMS détecte automatiquement que ces séances n'existent plus dans Klassci
- LMS archive ces séances (is_active = false)
- Frontend affiche 0 séance ✅

---

## 📊 DEUX TYPES DE DONNÉES

### Type A: Données de PROGRAMMATION (source: Klassci)

**Stockées UNIQUEMENT dans Klassci**:
- 📅 Date de la séance
- ⏰ Heure début / fin
- 🏫 Salle
- 👨‍🏫 Enseignant
- 📖 Matière
- 🎓 Classe

**LMS ne stocke PAS ces données** → Il les récupère en temps réel depuis l'API Klassci

### Type B: Données VISIO (source: LMS local)

**Stockées dans la base de données LMS**:
- 🎥 URL Jitsi
- ✅ Visio activée (oui/non)
- 👥 Liste des participants connectés
- ⏱️ Durée de connexion
- 📊 Statut (en cours, terminée)

**Klassci ne gère PAS ces données** → Elles sont propres au LMS

---

## 🔄 WORKFLOW DE SYNCHRONISATION

### 1. L'enseignant programme une séance DANS KLASSCI

```
┌─────────────────────────────────────┐
│  KLASSCI (Interface enseignant)     │
│                                     │
│  Enseignant crée:                   │
│  • Séance #60                       │
│  • Date: 2025-11-25                 │
│  • Heure: 10h00 - 12h00             │
│  • Matière: Marketing digital       │
│  • Classe: BTS SIO 1                │
└─────────────────────────────────────┘
             ↓ Sauvegardé dans Klassci
             ↓
┌─────────────────────────────────────┐
│  BASE DE DONNÉES KLASSCI            │
│                                     │
│  Séance #60 existe avec toutes      │
│  les informations de programmation  │
└─────────────────────────────────────┘
```

**À ce stade**: La séance existe SEULEMENT dans Klassci, pas encore dans le LMS.

---

### 2. Un étudiant consulte ses cours DANS LE LMS

```
┌─────────────────────────────────────┐
│  FRONTEND LMS (Vue étudiant)        │
│                                     │
│  Étudiant clique sur "Mes cours"    │
└─────────────────────────────────────┘
             ↓ Requête HTTP
             ↓
┌─────────────────────────────────────┐
│  BACKEND LMS                        │
│                                     │
│  Appelle: GET /lms/seances/my-classes│
└─────────────────────────────────────┘
             ↓ Le backend appelle Klassci
             ↓
┌─────────────────────────────────────┐
│  API KLASSCI                        │
│                                     │
│  GET /me/dashboard                  │
│  → Retourne toutes les séances      │
│     programmées pour cet étudiant   │
└─────────────────────────────────────┘
             ↓ Données récupérées
             ↓
┌─────────────────────────────────────┐
│  BACKEND LMS                        │
│                                     │
│  Pour chaque séance Klassci:        │
│  1. Vérifier si elle existe en      │
│     local (pour infos visio)        │
│  2. Enrichir avec données visio     │
│  3. Retourner au frontend           │
└─────────────────────────────────────┘
             ↓ JSON
             ↓
┌─────────────────────────────────────┐
│  FRONTEND LMS                       │
│                                     │
│  Affiche:                           │
│  • Séance #60                       │
│  • Marketing digital                │
│  • 25 nov 2025, 10h00-12h00         │
│  • [Bouton "Rejoindre"] (si visio)  │
└─────────────────────────────────────┘
```

**Point clé**: Le LMS ne stocke PAS les dates/horaires. Il les récupère EN DIRECT depuis Klassci à chaque fois.

---

### 3. L'enseignant active la visio DANS LE LMS

```
┌─────────────────────────────────────┐
│  FRONTEND LMS (Vue enseignant)      │
│                                     │
│  Enseignant clique:                 │
│  "Activer la visio" sur séance #60  │
└─────────────────────────────────────┘
             ↓ Requête HTTP
             ↓
┌─────────────────────────────────────┐
│  BACKEND LMS                        │
│                                     │
│  POST /lms/seances/60/activate-visio│
└─────────────────────────────────────┘
             ↓
┌─────────────────────────────────────┐
│  BASE DE DONNÉES LMS LOCALE         │
│                                     │
│  Crée (si n'existe pas):            │
│                                     │
│  Seance {                           │
│    id: 12                           │
│    klassci_seance_id: 60            │
│    visio_enabled: true              │
│    visio_url: https://meet.jit...   │
│    matiere_nom: "Marketing digital" │
│    enseignant_nom: "BEDE ABEL"      │
│    is_active: true                  │
│  }                                  │
│                                     │
│  ⚠️  PAS de date_heure_debut        │
│  ⚠️  PAS de date_heure_fin          │
└─────────────────────────────────────┘
```

**C'est ici que la séance est créée dans la BDD LMS locale** pour la première fois !

**Mais attention**: Le LMS ne stocke QUE les infos visio, pas les dates de programmation.

---

### 4. L'enseignant supprime la séance DANS KLASSCI

```
┌─────────────────────────────────────┐
│  KLASSCI (Interface enseignant)     │
│                                     │
│  Enseignant supprime la séance #60  │
└─────────────────────────────────────┘
             ↓ DELETE dans Klassci
             ↓
┌─────────────────────────────────────┐
│  BASE DE DONNÉES KLASSCI            │
│                                     │
│  Séance #60 n'existe plus           │
│  GET /seances/60 → 404 NOT FOUND    │
└─────────────────────────────────────┘
             ↓ MAIS...
             ↓
┌─────────────────────────────────────┐
│  BASE DE DONNÉES LMS LOCALE         │
│                                     │
│  Seance {                           │
│    id: 12                           │
│    klassci_seance_id: 60            │
│    is_active: true ← ENCORE ACTIVE! │
│  }                                  │
│                                     │
│  ⚠️  La séance existe toujours      │
│      dans le LMS local!             │
└─────────────────────────────────────┘
```

**Problème**: La séance fantôme reste dans le LMS local jusqu'au prochain nettoyage.

---

### 5. Le job de nettoyage automatique s'exécute

```
┌─────────────────────────────────────┐
│  SCHEDULER LARAVEL                  │
│                                     │
│  Toutes les 30 minutes:             │
│  → Exécute CleanObsoleteSeances     │
└─────────────────────────────────────┘
             ↓
┌─────────────────────────────────────┐
│  JOB: CleanObsoleteSeances          │
│                                     │
│  Pour chaque séance locale active:  │
│  1. Vérifier si existe dans Klassci │
│  2. Si 404 → Archiver               │
└─────────────────────────────────────┘
             ↓ Vérification
             ↓
┌─────────────────────────────────────┐
│  API KLASSCI                        │
│                                     │
│  GET /seances/60                    │
│  → 404 NOT FOUND                    │
└─────────────────────────────────────┘
             ↓ 404 détecté
             ↓
┌─────────────────────────────────────┐
│  BASE DE DONNÉES LMS LOCALE         │
│                                     │
│  UPDATE Seance #12                  │
│  SET is_active = false              │
│                                     │
│  ✅ Séance archivée                 │
└─────────────────────────────────────┘
             ↓
┌─────────────────────────────────────┐
│  LOGS                               │
│                                     │
│  🗑️ Séance archivée                 │
│     seance_id: 12                   │
│     klassci_seance_id: 60           │
│     raison: N'existe plus dans      │
│            Klassci                  │
└─────────────────────────────────────┘
```

**Délai maximum**: 30 minutes entre suppression dans Klassci et archivage dans LMS.

---

### 6. L'étudiant consulte ses cours après le nettoyage

```
┌─────────────────────────────────────┐
│  FRONTEND LMS (Vue étudiant)        │
│                                     │
│  Étudiant clique sur "Mes cours"    │
└─────────────────────────────────────┘
             ↓
┌─────────────────────────────────────┐
│  BACKEND LMS                        │
│                                     │
│  GET /lms/seances/my-classes        │
│                                     │
│  Filtre: is_active = true           │
└─────────────────────────────────────┘
             ↓ Séance #12 is_active=false
             ↓ donc pas retournée
             ↓
┌─────────────────────────────────────┐
│  FRONTEND LMS                       │
│                                     │
│  ✅ Séance #60 n'apparaît plus      │
│  ✅ Liste propre et à jour          │
└─────────────────────────────────────┘
```

---

## 🎯 RÉSUMÉ DU MÉCANISME

### Données de PROGRAMMATION (dates, horaires, salle):
```
Klassci (source unique)
    → API en temps réel
        → LMS Backend
            → Frontend
```

**Le LMS ne stocke JAMAIS ces données localement.**

### Données VISIO (URL, participants, statuts):
```
LMS (source unique)
    → Base de données locale
        → API LMS
            → Frontend
```

**Klassci ne gère JAMAIS ces données.**

### Synchronisation SUPPRESSION:
```
Suppression dans Klassci
    → 404 NOT FOUND
        → Job CleanObsoleteSeances (30 min max)
            → Archivage LMS local (is_active = false)
                → Frontend ne montre plus
```

---

## 🔍 POURQUOI CE SYSTÈME?

### Avantages:
1. **Source unique de vérité**: Les dates/horaires sont TOUJOURS à jour depuis Klassci
2. **Pas de désynchronisation**: Le LMS lit en temps réel
3. **Légèreté**: Le LMS ne duplique pas les données de programmation
4. **Extensibilité**: Le LMS ajoute des fonctionnalités (visio) sans modifier Klassci

### Inconvénients (résolus):
1. ~~Séances fantômes après suppression~~ → ✅ Résolu par CleanObsoleteSeances
2. ~~Dates inventées quand API vide~~ → ✅ Résolu en supprimant les dates fake

---

## 📖 ANALOGIE

Imaginez **Klassci = Planning papier** et **LMS = Post-it numériques**

### Planning papier (Klassci):
- Contient TOUTES les dates, horaires, salles (source officielle)
- Si l'enseignant efface une séance au Tipp-Ex, elle n'existe plus

### Post-it numériques (LMS):
- Chaque séance qui a une visio a un post-it
- Le post-it contient seulement: "Visio activée, URL Jitsi"
- Le post-it NE contient PAS la date (on regarde le planning papier pour ça)

### Problème avant:
- L'enseignant efface la séance du planning papier
- Le post-it reste collé → séance "fantôme"
- Et pire: on inventait une fausse date sur le post-it!

### Solution maintenant:
- Toutes les 30 minutes, on vérifie tous les post-it
- Si le planning papier dit "404 cette séance n'existe plus"
- → On range le post-it dans les archives
- → Plus de séance fantôme visible

---

## ✅ CONCLUSION

**Votre compréhension était correcte**:
- Klassci retournait 0 séances (car supprimées)
- LMS affichait 8 séances avec dates du jour (fantômes avec dates inventées)

**Ce que j'ai corrigé**:
1. ✅ Supprimé la génération de fausses dates
2. ✅ Créé un job de nettoyage automatique toutes les 30 minutes
3. ✅ Archivé les 8 séances fantômes existantes
4. ✅ Maintenant: Klassci 0 séances = LMS 0 séances visibles

**Le LMS charge maintenant moins de données et fonctionne plus facilement** comme vous le souhaitiez ! 🎉

---

**Document créé le**: 2025-11-19
**Auteur**: Claude Code
**Version**: 1.0
