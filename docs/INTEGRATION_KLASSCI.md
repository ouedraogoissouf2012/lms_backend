# Documentation d'Intégration LMS ↔ KLASSCI

## Table des Matières
1. [Vue d'Ensemble](#vue-densemble)
2. [Architecture KLASSCI](#architecture-klassci)
3. [API KLASSCI Disponibles](#api-klassci-disponibles)
4. [Plan d'Implémentation](#plan-dimplémentation)
5. [Gestion des Rôles](#gestion-des-rôles)
6. [Sécurité](#sécurité)
7. [Checklist](#checklist)

---

## Vue d'Ensemble

### Concept
**KLASSCI** est l'application CRM pédagogique centrale qui gère :
- Les utilisateurs (enseignants, étudiants, administrateurs)
- Les données académiques (classes, matières, évaluations)
- L'authentification et les permissions

**LMS Backend** est une application satellite qui :
- Utilise l'authentification KLASSCI (pas de duplication de comptes)
- Récupère les données depuis KLASSCI via API REST
- Ajoute des fonctionnalités e-learning (cours, quiz, forum)

### Schéma de Communication
```
┌──────────────────┐         API REST          ┌──────────────────┐
│                  │◄────────────────────────►  │                  │
│   LMS Laravel    │  Laravel Sanctum Token     │  KLASSCI (CRM)   │
│  (Votre App)     │                            │  (Base centrale) │
│                  │                            │                  │
└──────────────────┘                            └──────────────────┘
```

---

## Architecture KLASSCI

### 1. Système d'Authentification
- **Guard** : `web` (session-based)
- **Provider** : Eloquent avec modèle `App\Models\User`
- **Gestion des rôles** : Spatie Laravel Permission
- **API Tokens** : Laravel Sanctum

### 2. Rôles Disponibles dans KLASSCI

| Rôle | Description | Permissions |
|------|-------------|-------------|
| `superAdmin` | Administrateur système | Accès total |
| `coordinateur` | Coordinateur pédagogique | Supervision classes, enseignants |
| `secretaire` | Secrétaire académique | Gestion étudiants, notes, présences |
| `enseignant` / `teacher` | Enseignant | Émargement, notes, propres classes |
| `etudiant` | Étudiant | Vue propres données uniquement |
| `parent` | Parent d'élève | Vue données enfant |
| `serviceTechnique` | Support technique | Configuration système |

### 3. Équivalences de Rôles
- `superAdmin` = `coordinateur` (via RoleHelper)
- `enseignant` = `teacher` (noms alternatifs)

### 4. Flux d'Authentification KLASSCI

```
1. Utilisateur visite /login
   ↓
2. Entre username/email + password
   ↓
3. LoginController::login()
   - Valide credentials
   - Auth::attempt() vérifie en base
   ↓
4. Si succès → redirect('/dashboard')
   ↓
5. DashboardController@index
   - Récupère Auth::user()
   - Vérifie les rôles via hasRole()
   ↓
6. Redirection selon le rôle :
   - superAdmin → dashboard.superadmin
   - enseignant → teacher.dashboard
   - etudiant → dashboard.etudiant
```

---

## API KLASSCI Disponibles

### Base URL
```
http://localhost:8001/api/lms
# ou en production
https://klassci.votredomaine.com/api/lms
```

### Endpoints d'Authentification

#### POST `/auth/login`
**Connexion utilisateur**

**Request:**
```json
{
  "username": "email@example.com",  // ou nom d'utilisateur
  "password": "motdepasse"
}
```

**Response (200 OK):**
```json
{
  "success": true,
  "message": "Connexion réussie",
  "data": {
    "token": "2|xyz123abc456...",
    "token_type": "Bearer",
    "user": {
      "id": 123,
      "nom": "Doe",
      "prenom": "John",
      "email": "john.doe@example.com",
      "role": "enseignant",
      "role_display_name": "Enseignant",
      "admin_data": { ... },           // Si admin/coordinateur
      "enseignant_data": { ... },      // Si enseignant
      "etudiant_data": { ... }         // Si étudiant
    }
  },
  "meta": {
    "annee_universitaire_courante": {
      "id": 1,
      "libelle": "2024-2025"
    }
  }
}
```

**Response (401 Unauthorized):**
```json
{
  "success": false,
  "message": "Identifiants incorrects ou compte désactivé"
}
```

---

#### GET `/auth/me`
**Récupérer le profil de l'utilisateur connecté**

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 123,
    "nom": "Doe",
    "prenom": "John",
    "email": "john.doe@example.com",
    "role": "enseignant",
    "classes": [...],
    "matieres": [...]
  }
}
```

---

#### POST `/auth/logout`
**Déconnexion**

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "success": true,
  "message": "Déconnexion réussie"
}
```

---

#### GET `/auth/check`
**Vérifier la validité d'un token**

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "success": true,
  "authenticated": true
}
```

---

### Endpoints de Données (Lecture)

#### GET `/matieres`
**Liste des matières**

**Query Parameters:**
- `annee_id` (optionnel) : Filtrer par année universitaire
- `filiere_id` (optionnel) : Filtrer par filière

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "code": "MATH101",
      "libelle": "Mathématiques Générales",
      "coefficient": 3,
      "filiere": {...},
      "niveau_etude": {...}
    }
  ]
}
```

---

#### GET `/classes`
**Liste des classes**

**Query Parameters:**
- `annee_id` (optionnel)
- `filiere_id` (optionnel)
- `niveau_id` (optionnel)

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "code": "L1-INFO-A",
      "libelle": "Licence 1 Informatique - Groupe A",
      "filiere": {...},
      "niveau_etude": {...},
      "etudiants_count": 45
    }
  ]
}
```

---

#### GET `/classes/{id}/etudiants`
**Étudiants d'une classe**

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 100,
      "matricule": "ETU2024001",
      "nom": "Dupont",
      "prenom": "Marie",
      "email": "marie.dupont@example.com",
      "inscription": {
        "status": "active",
        "date_inscription": "2024-09-01"
      }
    }
  ]
}
```

---

#### GET `/emploi-temps`
**Emploi du temps**

**Query Parameters:**
- `classe_id` (optionnel)
- `enseignant_id` (optionnel)
- `date_debut` (optionnel)
- `date_fin` (optionnel)

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "jour": "Lundi",
      "heure_debut": "08:00",
      "heure_fin": "10:00",
      "matiere": {...},
      "classe": {...},
      "enseignant": {...},
      "salle": "A201"
    }
  ]
}
```

---

#### GET `/evaluations`
**Liste des évaluations**

**Query Parameters:**
- `matiere_id` (optionnel)
- `classe_id` (optionnel)
- `type` (optionnel) : DS, CC, TP, Examen

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "libelle": "Devoir Surveillé 1",
      "type": "DS",
      "date": "2024-10-15",
      "coefficient": 2,
      "matiere": {...},
      "classe": {...},
      "notes": [...]
    }
  ]
}
```

---

#### GET `/structure`
**Structure organisationnelle (filières & niveaux)**

**Response:**
```json
{
  "success": true,
  "data": {
    "filieres": [
      {
        "id": 1,
        "code": "INFO",
        "libelle": "Informatique",
        "niveaux": [...]
      }
    ],
    "niveaux_etudes": [
      {
        "id": 1,
        "code": "L1",
        "libelle": "Licence 1"
      }
    ]
  }
}
```

---

### Endpoints d'Écriture (LMS → KLASSCI)

#### POST `/evaluations/{id}/notes`
**Sauvegarder les notes d'une évaluation**

**Headers:**
```
Authorization: Bearer {token}
```

**Request:**
```json
{
  "notes": [
    {
      "etudiant_id": 100,
      "note": 15.5,
      "commentaire": "Bon travail"
    },
    {
      "etudiant_id": 101,
      "note": 12.0
    }
  ]
}
```

**Response:**
```json
{
  "success": true,
  "message": "Notes enregistrées avec succès",
  "data": {
    "saved_count": 2
  }
}
```

---

#### POST `/cours/{id}/presences`
**Enregistrer les présences d'un cours**

**Request:**
```json
{
  "presences": [
    {
      "etudiant_id": 100,
      "status": "present"
    },
    {
      "etudiant_id": 101,
      "status": "absent",
      "justification": "Certificat médical"
    }
  ]
}
```

---

## Plan d'Implémentation

### Étape 1 : Configuration Backend Laravel

#### 1.1 Installer Guzzle HTTP Client
```bash
composer require guzzlehttp/guzzle
```

#### 1.2 Configuration .env
Ajouter dans `.env` :
```env
KLASSCI_API_URL=http://localhost:8001/api/lms
KLASSCI_TIMEOUT=30
```

#### 1.3 Fichier de configuration
Créer `config/klassci.php` :
```php
<?php

return [
    'api_url' => env('KLASSCI_API_URL', 'http://localhost:8001/api/lms'),
    'timeout' => env('KLASSCI_TIMEOUT', 30),
];
```

---

### Étape 2 : Service d'Authentification KLASSCI

Créer `app/Services/KlassciAuthService.php` :

```php
<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class KlassciAuthService
{
    protected $client;
    protected $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('klassci.api_url');
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => config('klassci.timeout'),
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]
        ]);
    }

    /**
     * Connexion via KLASSCI
     */
    public function login(string $username, string $password)
    {
        try {
            $response = $this->client->post('/auth/login', [
                'json' => [
                    'username' => $username,
                    'password' => $password
                ]
            ]);

            $data = json_decode($response->getBody(), true);

            if ($data['success'] ?? false) {
                return $data['data'];
            }

            return null;
        } catch (RequestException $e) {
            Log::error('KLASSCI Auth Error:', [
                'message' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
            return null;
        }
    }

    /**
     * Vérifier le token
     */
    public function checkToken(string $token)
    {
        try {
            $response = $this->client->get('/auth/check', [
                'headers' => [
                    'Authorization' => "Bearer {$token}"
                ]
            ]);

            $data = json_decode($response->getBody(), true);
            return $data['success'] ?? false;
        } catch (RequestException $e) {
            return false;
        }
    }

    /**
     * Récupérer les données utilisateur
     */
    public function getMe(string $token)
    {
        try {
            $response = $this->client->get('/auth/me', [
                'headers' => [
                    'Authorization' => "Bearer {$token}"
                ]
            ]);

            $data = json_decode($response->getBody(), true);
            return $data['data'] ?? null;
        } catch (RequestException $e) {
            return null;
        }
    }

    /**
     * Déconnexion
     */
    public function logout(string $token)
    {
        try {
            $this->client->post('/auth/logout', [
                'headers' => [
                    'Authorization' => "Bearer {$token}"
                ]
            ]);
            return true;
        } catch (RequestException $e) {
            return false;
        }
    }
}
```

---

### Étape 3 : Service de Données KLASSCI

Créer `app/Services/KlassciDataService.php` :

```php
<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Session;

class KlassciDataService
{
    protected $client;
    protected $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('klassci.api_url');
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => config('klassci.timeout'),
        ]);
    }

    /**
     * Obtenir l'en-tête Authorization
     */
    protected function getHeaders()
    {
        $token = Session::get('klassci_token');
        return [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ];
    }

    /**
     * Récupérer les matières
     */
    public function getMatieres(array $filters = [])
    {
        $response = $this->client->get('/matieres', [
            'headers' => $this->getHeaders(),
            'query' => $filters
        ]);

        return json_decode($response->getBody(), true)['data'];
    }

    /**
     * Récupérer les classes
     */
    public function getClasses(array $filters = [])
    {
        $response = $this->client->get('/classes', [
            'headers' => $this->getHeaders(),
            'query' => $filters
        ]);

        return json_decode($response->getBody(), true)['data'];
    }

    /**
     * Récupérer les étudiants d'une classe
     */
    public function getEtudiantsClasse(int $classeId)
    {
        $response = $this->client->get("/classes/{$classeId}/etudiants", [
            'headers' => $this->getHeaders()
        ]);

        return json_decode($response->getBody(), true)['data'];
    }

    /**
     * Récupérer l'emploi du temps
     */
    public function getEmploiTemps(array $filters = [])
    {
        $response = $this->client->get('/emploi-temps', [
            'headers' => $this->getHeaders(),
            'query' => $filters
        ]);

        return json_decode($response->getBody(), true)['data'];
    }

    /**
     * Récupérer les évaluations
     */
    public function getEvaluations(array $filters = [])
    {
        $response = $this->client->get('/evaluations', [
            'headers' => $this->getHeaders(),
            'query' => $filters
        ]);

        return json_decode($response->getBody(), true)['data'];
    }

    /**
     * Sauvegarder des notes
     */
    public function saveNotes(int $evaluationId, array $notes)
    {
        $response = $this->client->post("/evaluations/{$evaluationId}/notes", [
            'headers' => $this->getHeaders(),
            'json' => ['notes' => $notes]
        ]);

        return json_decode($response->getBody(), true);
    }

    /**
     * Enregistrer des présences
     */
    public function savePresences(int $coursId, array $presences)
    {
        $response = $this->client->post("/cours/{$coursId}/presences", [
            'headers' => $this->getHeaders(),
            'json' => ['presences' => $presences]
        ]);

        return json_decode($response->getBody(), true);
    }
}
```

---

### Étape 4 : Contrôleur d'Authentification

Créer `app/Http/Controllers/Auth/KlassciLoginController.php` :

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\KlassciAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class KlassciLoginController extends Controller
{
    protected $klassciAuth;

    public function __construct(KlassciAuthService $klassciAuth)
    {
        $this->klassciAuth = $klassciAuth;
    }

    /**
     * Afficher le formulaire de connexion
     */
    public function showLoginForm()
    {
        return view('auth.klassci-login');
    }

    /**
     * Traiter la connexion
     */
    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string|min:6'
        ]);

        // Appeler l'API KLASSCI
        $result = $this->klassciAuth->login(
            $request->username,
            $request->password
        );

        if (!$result) {
            return back()->withErrors([
                'username' => 'Identifiants incorrects ou compte désactivé.'
            ])->withInput($request->only('username'));
        }

        // Stocker les données en session
        Session::put('klassci_token', $result['token']);
        Session::put('klassci_user', $result['user']);
        Session::put('authenticated', true);

        // Redirection selon le rôle
        return $this->redirectByRole($result['user']['role']);
    }

    /**
     * Déconnexion
     */
    public function logout(Request $request)
    {
        $token = Session::get('klassci_token');

        if ($token) {
            $this->klassciAuth->logout($token);
        }

        Session::flush();

        return redirect()->route('login')
            ->with('message', 'Déconnexion réussie');
    }

    /**
     * Redirection basée sur le rôle
     */
    protected function redirectByRole(string $role)
    {
        // Normaliser les rôles admin (superAdmin et coordinateur sont équivalents)
        if (in_array($role, ['superAdmin', 'coordinateur', 'secretaire'])) {
            return redirect()->route('admin.dashboard');
        }

        if ($role === 'enseignant' || $role === 'teacher') {
            return redirect()->route('teacher.dashboard');
        }

        if ($role === 'etudiant') {
            return redirect()->route('student.dashboard');
        }

        // Par défaut
        return redirect()->route('dashboard');
    }
}
```

---

### Étape 5 : Middleware d'Authentification

Créer `app/Http/Middleware/KlassciAuth.php` :

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\KlassciAuthService;
use Illuminate\Support\Facades\Session;

class KlassciAuth
{
    protected $klassciAuth;

    public function __construct(KlassciAuthService $klassciAuth)
    {
        $this->klassciAuth = $klassciAuth;
    }

    public function handle(Request $request, Closure $next, ...$roles)
    {
        // Vérifier si l'utilisateur est authentifié
        $token = Session::get('klassci_token');
        $user = Session::get('klassci_user');

        if (!$token || !$user) {
            return redirect()->route('login')
                ->with('error', 'Veuillez vous connecter');
        }

        // Vérifier la validité du token
        if (!$this->klassciAuth->checkToken($token)) {
            Session::flush();
            return redirect()->route('login')
                ->with('error', 'Session expirée, veuillez vous reconnecter');
        }

        // Vérifier les rôles autorisés
        if (!empty($roles) && !$this->hasRole($user['role'], $roles)) {
            abort(403, 'Accès non autorisé');
        }

        return $next($request);
    }

    /**
     * Vérifier si l'utilisateur a l'un des rôles requis
     */
    protected function hasRole(string $userRole, array $requiredRoles): bool
    {
        // Gestion équivalence superAdmin = coordinateur
        if (in_array($userRole, ['superAdmin', 'coordinateur'])) {
            if (in_array('superAdmin', $requiredRoles) || in_array('coordinateur', $requiredRoles)) {
                return true;
            }
        }

        // Gestion équivalence enseignant = teacher
        if ($userRole === 'enseignant' || $userRole === 'teacher') {
            if (in_array('enseignant', $requiredRoles) || in_array('teacher', $requiredRoles)) {
                return true;
            }
        }

        return in_array($userRole, $requiredRoles);
    }
}
```

Enregistrer le middleware dans `app/Http/Kernel.php` :

```php
protected $middlewareAliases = [
    // ...
    'klassci.auth' => \App\Http\Middleware\KlassciAuth::class,
];
```

---

### Étape 6 : Routes

Mettre à jour `routes/web.php` :

```php
use App\Http\Controllers\Auth\KlassciLoginController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\TeacherDashboardController;
use App\Http\Controllers\StudentDashboardController;

// Routes publiques
Route::get('/login', [KlassciLoginController::class, 'showLoginForm'])->name('login');
Route::post('/login', [KlassciLoginController::class, 'login']);
Route::post('/logout', [KlassciLoginController::class, 'logout'])->name('logout');

// Routes protégées Admin (superAdmin, coordinateur, secretaire)
Route::middleware(['klassci.auth:superAdmin,coordinateur,secretaire'])
    ->prefix('admin')
    ->group(function () {
        Route::get('/dashboard', [AdminDashboardController::class, 'index'])
            ->name('admin.dashboard');
        // Autres routes admin...
    });

// Routes protégées Enseignant
Route::middleware(['klassci.auth:enseignant,teacher'])
    ->prefix('teacher')
    ->group(function () {
        Route::get('/dashboard', [TeacherDashboardController::class, 'index'])
            ->name('teacher.dashboard');
        // Autres routes enseignant...
    });

// Routes protégées Étudiant
Route::middleware(['klassci.auth:etudiant'])
    ->prefix('student')
    ->group(function () {
        Route::get('/dashboard', [StudentDashboardController::class, 'index'])
            ->name('student.dashboard');
        // Autres routes étudiant...
    });
```

---

## Gestion des Rôles

### Redirections Automatiques par Rôle

| Rôle KLASSCI | Dashboard LMS | Route |
|--------------|---------------|-------|
| `superAdmin` | Dashboard Admin | `/admin/dashboard` |
| `coordinateur` | Dashboard Admin | `/admin/dashboard` |
| `secretaire` | Dashboard Admin | `/admin/dashboard` |
| `enseignant` / `teacher` | Dashboard Enseignant | `/teacher/dashboard` |
| `etudiant` | Dashboard Étudiant | `/student/dashboard` |

### Mapping des Permissions

**Dans le LMS :**

```php
// Middleware pour vérifier les rôles
Route::middleware(['klassci.auth:enseignant,coordinateur'])->group(function () {
    // Routes accessibles aux enseignants ET coordinateurs
});

// Accès admin uniquement
Route::middleware(['klassci.auth:superAdmin,coordinateur,secretaire'])->group(function () {
    // Routes admin
});
```

**Helper Blade :**

Créer `app/Helpers/helpers.php` :

```php
<?php

if (!function_exists('klassciUser')) {
    function klassciUser() {
        return session('klassci_user');
    }
}

if (!function_exists('hasKlassciRole')) {
    function hasKlassciRole($role) {
        $user = session('klassci_user');
        if (!$user) return false;

        $userRole = $user['role'];

        // Gestion équivalences
        if (in_array($userRole, ['superAdmin', 'coordinateur'])) {
            if (in_array($role, ['superAdmin', 'coordinateur', 'admin'])) {
                return true;
            }
        }

        return $userRole === $role;
    }
}
```

Dans `composer.json`, ajouter :

```json
"autoload": {
    "files": [
        "app/Helpers/helpers.php"
    ]
}
```

Puis :
```bash
composer dump-autoload
```

**Utilisation dans les vues Blade :**

```blade
@if(hasKlassciRole('superAdmin'))
    <a href="/admin/settings">Paramètres Admin</a>
@endif

@if(hasKlassciRole('enseignant'))
    <a href="/teacher/classes">Mes Classes</a>
@endif

Bonjour {{ klassciUser()['nom'] }} {{ klassciUser()['prenom'] }}
```

---

## Sécurité

### 1. Gestion des Tokens
- ✅ Stocker le token en **session sécurisée** (pas en localStorage)
- ✅ Vérifier la validité du token à chaque requête critique
- ✅ Implémenter un système de refresh si nécessaire

### 2. CORS (si domaines différents)

Dans KLASSCI, modifier `config/cors.php` :

```php
'paths' => ['api/*', 'sanctum/csrf-cookie'],
'allowed_origins' => ['http://votre-lms.local', 'https://lms.votredomaine.com'],
'supports_credentials' => true,
```

### 3. HTTPS en Production

Dans `.env` de production :

```env
KLASSCI_API_URL=https://klassci.votredomaine.com/api/lms
APP_URL=https://lms.votredomaine.com
SESSION_SECURE_COOKIE=true
```

### 4. Validation des Inputs

Toujours valider les données avant envoi à KLASSCI :

```php
$request->validate([
    'username' => 'required|string|max:255',
    'password' => 'required|string|min:6',
]);
```

### 5. Gestion des Erreurs

```php
try {
    $result = $klassciAuth->login($username, $password);
} catch (\Exception $e) {
    Log::error('KLASSCI Login Error', [
        'error' => $e->getMessage(),
        'username' => $username
    ]);

    return back()->withErrors([
        'username' => 'Erreur de connexion. Veuillez réessayer.'
    ]);
}
```

---

## Checklist d'Implémentation

### Phase 1 : Configuration Initiale
- [ ] Installer Guzzle HTTP Client : `composer require guzzlehttp/guzzle`
- [ ] Créer `config/klassci.php`
- [ ] Ajouter variables KLASSCI dans `.env`
- [ ] Créer `docs/` si nécessaire

### Phase 2 : Services
- [ ] Créer `app/Services/KlassciAuthService.php`
- [ ] Créer `app/Services/KlassciDataService.php`
- [ ] Tester la connexion à l'API KLASSCI

### Phase 3 : Authentification
- [ ] Créer `app/Http/Controllers/Auth/KlassciLoginController.php`
- [ ] Créer `app/Http/Middleware/KlassciAuth.php`
- [ ] Enregistrer le middleware dans `Kernel.php`
- [ ] Créer la vue `resources/views/auth/klassci-login.blade.php`

### Phase 4 : Routes & Redirections
- [ ] Configurer les routes dans `routes/web.php`
- [ ] Créer les contrôleurs de dashboard (Admin, Teacher, Student)
- [ ] Tester les redirections par rôle

### Phase 5 : Intégration des Données
- [ ] Implémenter `KlassciDataService` dans les dashboards
- [ ] Récupérer les matières, classes, étudiants
- [ ] Créer les vues pour afficher les données KLASSCI

### Phase 6 : Tests
- [ ] Tester la connexion avec un compte `superAdmin`
- [ ] Tester la connexion avec un compte `enseignant`
- [ ] Tester la connexion avec un compte `etudiant`
- [ ] Vérifier les permissions par rôle
- [ ] Tester la déconnexion

### Phase 7 : Sécurité
- [ ] Configurer CORS si domaines différents
- [ ] Activer HTTPS en production
- [ ] Valider tous les inputs utilisateur
- [ ] Implémenter la gestion d'erreurs

### Phase 8 : Production
- [ ] Configurer les variables d'environnement de production
- [ ] Tester l'intégration complète
- [ ] Documenter les endpoints utilisés
- [ ] Former les utilisateurs

---

## Résumé Exécutif

### ✅ Ce que vous devez savoir

1. **KLASSCI a déjà tout** : Une API complète d'authentification et de données

2. **Votre LMS n'a PAS besoin de :**
   - Dupliquer la base de données KLASSCI
   - Créer un système d'authentification séparé
   - Gérer les utilisateurs directement

3. **Votre LMS doit seulement :**
   - Consommer l'API KLASSCI via HTTP
   - Stocker le token en session
   - Rediriger selon les rôles

4. **Redirections automatiques :**
   - `superAdmin` / `coordinateur` / `secretaire` → `/admin/dashboard`
   - `enseignant` / `teacher` → `/teacher/dashboard`
   - `etudiant` → `/student/dashboard`

---

## Contact & Support

Pour toute question sur l'intégration KLASSCI ↔ LMS, consulter :
- Documentation API KLASSCI : `/docs/api`
- Logs d'erreur : `storage/logs/laravel.log`
- Tests d'intégration : `php artisan tinker`

---

**Date de dernière mise à jour :** 19 Octobre 2025
**Version :** 1.0.0
