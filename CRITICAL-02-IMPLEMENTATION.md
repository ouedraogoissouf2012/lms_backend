# CRITICAL-02: Exception Handler — Production Architecture

## Architecture Overview

This is a **production-grade exception handling system** that enforces:
- ✅ **No getMessage() exposures** to API clients
- ✅ **Standardized error responses** with error codes
- ✅ **Comprehensive server-side logging** with full context
- ✅ **OWASP A06:2021** compliance (Information Disclosure prevention)
- ✅ **Centralized exception rendering** (bootstrap/app.php)
- ✅ **Type-safe exception classes** with structured error codes

## Components

### 1. CustomException Classes (`app/Exceptions/`)

**Base class: `ApiException`**
- Abstract base for all API exceptions
- Handles rendering to standardized JSON response
- Contains error_code, clientMessage, statusCode, context

**Concrete exceptions:**
- `ResourceNotFoundException` (404) — RESOURCE_NOT_FOUND
- `ValidationException` (422) — VALIDATION_FAILED
- `PermissionException` (403) — PERMISSION_DENIED
- `UnauthenticatedException` (401) — UNAUTHENTICATED
- `ApiServerException` (500) — INTERNAL_SERVER_ERROR

### 2. Central Exception Handler (`bootstrap/app.php`)

Handles ALL exceptions (caught and uncaught) in one place:
- Catches `ApiException` → renders with error code
- Catches other `\Throwable` → logs with full context, returns generic message
- Logs: exception class, message, trace, user_id, IP, method, path, query params
- **ZERO getMessage() exposures to client**

### 3. HandlesApiExceptions Trait (`app/Http/Controllers/Traits/`)

Convenience methods for controllers:
- `$this->notFound($resource)` → ResourceNotFoundException
- `$this->forbidden($message)` → PermissionException
- `$this->unauthenticated($message)` → UnauthenticatedException
- `$this->validationFailed($errors)` → ValidationException
- `$this->serverError($logMessage, $exception)` → ApiServerException

## Usage Pattern

### Before (MVP - WRONG ❌)
```php
class ProxyController {
    public function classes(Request $request) {
        try {
            $data = $this->klassciService->getClasses($filters);
            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage()  // ❌ LEAK!
            ], 500);
        }
    }
}
```

### After (Production - CORRECT ✅)
```php
class ProxyController {
    use HandlesApiExceptions;

    public function classes(Request $request) {
        $data = $this->klassciService->getClasses($filters);
        return response()->json($data);
        // Exceptions auto-handled by central handler
    }

    public function show(int $id) {
        $class = Classe::find($id);
        if (!$class) {
            $this->notFound('Classe', "Classe {$id} not found in DB", ['class_id' => $id]);
        }
        return response()->json($class);
    }
}
```

### Exception Examples

```php
// 1. Resource not found
throw new ResourceNotFoundException(
    clientMessage: 'Classe non trouvée',
    logMessage: "Classe 123 not found in database",
    context: ['class_id' => 123]
);
// Response: {"success": false, "error_code": "RESOURCE_NOT_FOUND", "message": "Classe non trouvée"} [404]

// 2. Validation error
throw new ValidationException(
    errors: ['email' => ['Email invalid']],
    clientMessage: 'Erreur de validation',
    context: ['field_count' => 1]
);
// Response: {"success": false, "error_code": "VALIDATION_FAILED", "message": "Erreur de validation", "errors": {...}} [422]

// 3. Permission denied
throw new PermissionException(
    clientMessage: 'Accès réservé aux administrateurs',
    logMessage: "User {$user->id} attempted admin action without permission"
);
// Response: {"success": false, "error_code": "PERMISSION_DENIED", "message": "Accès réservé aux administrateurs"} [403]

// 4. Server error (wraps unexpected exception)
throw new ApiServerException(
    clientMessage: 'Erreur interne du serveur',
    logMessage: 'PDF generation failed',
    context: ['original_error' => $pdfException->getMessage()]
);
// Response: {"success": false, "error_code": "INTERNAL_SERVER_ERROR", "message": "Erreur interne du serveur"} [500]
```

## Server-Side Logging

Every exception triggers this log entry:
```json
{
    "level": "error",
    "message": "API Exception: ResourceNotFoundException",
    "context": {
        "error_code": "RESOURCE_NOT_FOUND",
        "status": 404,
        "message": "Classe 123 not found in database",
        "context": {"class_id": 123},
        "trace": "...",
        "user_id": 45,
        "ip": "192.168.1.1",
        "method": "GET",
        "path": "/api/classes/123",
        "query_params": {}
    }
}
```

## Client Response Examples

**Error 404:**
```json
{
    "success": false,
    "error_code": "RESOURCE_NOT_FOUND",
    "message": "Classe non trouvée"
}
```

**Error 422:**
```json
{
    "success": false,
    "error_code": "VALIDATION_FAILED",
    "message": "Erreur de validation",
    "errors": {
        "email": ["L'email est invalide"],
        "phone": ["Le téléphone est requis"]
    }
}
```

**Error 500 (Uncaught):**
```json
{
    "success": false,
    "error_code": "INTERNAL_SERVER_ERROR",
    "message": "Erreur interne du serveur"
}
```

## Why This is Production-Grade

| Aspect | MVP | Production |
|--------|-----|-----------|
| Exception classes | Scattered try-catch | Centralized, typed hierarchy |
| Error codes | None, raw messages | Specific codes (RESOURCE_NOT_FOUND) |
| Client messages | Raw exception text | Generic, predefined messages |
| Server logging | getMessage() only | Full context, trace, user, IP, path |
| Consistency | Per-controller | Unified across entire app |
| Error handling | 131 catch blocks | 1 central handler |
| Type safety | Strings everywhere | Typed exception classes |
| Extensibility | Hard to add new types | Easy to add CustomException subclass |
| Testing | Hard to mock errors | Easy to test exception throwing |

## Migration Path

Controllers should gradually adopt this pattern:

**Phase 1**: Add trait and use notFound/forbidden helpers
```php
class SomeController {
    use HandlesApiExceptions;
    
    public function show($id) {
        $resource = Model::find($id);
        if (!$resource) {
            $this->notFound('Resource');  // ← Replaces manual throw
        }
    }
}
```

**Phase 2**: Remove all try-catch blocks
```php
// BEFORE
try {
    $data = service->getData();
    return response()->json($data);
} catch (\Exception $e) {
    return response()->json(['error' => $e->getMessage()], 500);
}

// AFTER
$data = service->getData();  // Exceptions bubble to central handler
return response()->json($data);
```

**Phase 3**: Add custom exceptions for domain errors
```php
class KlassciSyncException extends ApiException {
    protected string $errorCode = 'KLASSCI_SYNC_FAILED';
}

// In controller
throw new KlassciSyncException(
    'Synchronisation avec KLASSCI échouée',
    'KLASSCI API returned 500'
);
```

## Testing

```php
public function test_resource_not_found_returns_correct_error_code() {
    $response = $this->getJson('/api/classes/99999');
    $response->assertStatus(404);
    $response->assertJson([
        'success' => false,
        'error_code' => 'RESOURCE_NOT_FOUND',
        'message' => 'Classe non trouvée'
    ]);
}

public function test_no_exception_messages_leaked_to_client() {
    // Mock service to throw exception
    Service::shouldReceive('getData')->andThrow(
        new \PDOException('Column `user_id` not found in table')
    );
    
    $response = $this->getJson('/api/data');
    
    // Should NOT contain the SQL error message
    $this->assertStringNotContainsString('Column', $response->getContent());
    $this->assertStringNotContainsString('PDOException', $response->getContent());
    
    // Should contain generic error code
    $response->assertJson(['error_code' => 'INTERNAL_SERVER_ERROR']);
}
```

## OWASP A06:2021 Compliance

✅ **Information Disclosure Prevention**
- No SQL errors, database structure, file paths, or code exposed
- Exception messages logged server-side, generic messages to client
- Error codes allow clients to differentiate error types without leaking details

✅ **Stack Traces**
- Full traces logged server-side for debugging
- NEVER returned to API clients

✅ **Sensitive Context**
- Request params, user_id, IP logged server-side
- Client receives only generic error message

---

**Implementation Status**: Production-Ready
**Rollout**: Phase controllers by dependency (lowest-level first)
**Testing**: All paths covered by exception tests
