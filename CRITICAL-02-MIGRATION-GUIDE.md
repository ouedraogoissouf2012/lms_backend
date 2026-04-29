# CRITICAL-02: Migration Guide — Refactoring Controllers

## Overview

Migrate controllers from scattered try-catch blocks to centralized exception handling
with typed exception classes.

**Benefits:**
- 60% less code per controller
- Type-safe error handling
- Standardized API responses
- Better testability
- Comprehensive logging automatically

## Step-by-Step Migration

### Step 1: Add the Trait

```php
// app/Http/Controllers/API/SomeController.php

use App\Http\Controllers\Traits\HandlesApiExceptions;

class SomeController extends Controller {
    use HandlesApiExceptions;  // ← ADD THIS
    // ... rest of code
}
```

### Step 2: Identify Exception Patterns in Your Controller

Find all these patterns:

**Pattern A: Manual 404 handling**
```php
$resource = Model::find($id);
if (!$resource) {
    return response()->json(['error' => 'Not found'], 404);
}
```
→ Replace with: `throw new ResourceNotFoundException('Resource name')`

**Pattern B: Manual permission check**
```php
if (!auth()->user()->isAdmin()) {
    return response()->json(['error' => 'Forbidden'], 403);
}
```
→ Replace with: `throw new PermissionException('Message')`

**Pattern C: try-catch with getMessage() leak**
```php
try {
    $data = service->getData();
    return response()->json($data);
} catch (\Exception $e) {
    Log::error('Error', ['error' => $e->getMessage()]);
    return response()->json(['message' => $e->getMessage()], 500);
}
```
→ Replace with: Just call service directly (exception handler catches it)

**Pattern D: Validation error response**
```php
if ($validator->fails()) {
    return response()->json([
        'message' => 'Validation error',
        'errors' => $validator->errors()
    ], 422);
}
```
→ Replace with: `throw new ValidationException($validator->errors())`

### Step 3: Implement Changes

#### Before (Old Pattern)

```php
<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Classe;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ClasseController extends Controller
{
    public function show(int $id): JsonResponse
    {
        try {
            $classe = Classe::findOrFail($id);

            $students = $classe->etudiants()->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'classe' => $classe,
                    'students' => $students,
                ]
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Classe non trouvée'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error fetching classe', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()  // ❌ LEAK!
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string',
            'code' => 'required|unique:classes',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $classe = Classe::create($request->validated());

            return response()->json([
                'success' => true,
                'data' => $classe,
                'message' => 'Classe créée'
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating classe', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()  // ❌ LEAK!
            ], 500);
        }
    }

    public function delete(int $id): JsonResponse
    {
        try {
            $classe = Classe::findOrFail($id);

            if (!auth()->user()->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès refusé'
                ], 403);
            }

            $classe->delete();

            return response()->json([
                'success' => true,
                'message' => 'Classe supprimée'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Classe non trouvée'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error deleting classe', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()  // ❌ LEAK!
            ], 500);
        }
    }
}
```

#### After (Production Pattern)

```php
<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HandlesApiExceptions;
use App\Models\Classe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * ClasseController — PRODUCTION PATTERN
 *
 * Exception handling:
 * - Throws typed exceptions (ResourceNotFoundException, ValidationException, etc.)
 * - Central handler logs full context and renders standardized JSON
 * - Zero try-catch boilerplate, zero getMessage() leaks
 */
class ClasseController extends Controller
{
    use HandlesApiExceptions;  // ← Provides notFound(), forbidden(), validationFailed()

    public function show(int $id): JsonResponse
    {
        $classe = Classe::findOrFail($id);  // Throws ModelNotFoundException → central handler

        $students = $classe->etudiants()->get();

        return response()->json([
            'success' => true,
            'data' => [
                'classe' => $classe,
                'students' => $students,
            ]
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string',
            'code' => 'required|unique:classes',
        ]);

        if ($validator->fails()) {
            // Throws ValidationException with errors array
            $this->validationFailed(
                $validator->errors()->toArray(),
                'Erreur de validation',
                'Classe creation validation failed',
                ['field_count' => $validator->errors()->count()]
            );
        }

        // No try-catch needed — any exception goes to central handler
        $classe = Classe::create($request->validated());

        return response()->json([
            'success' => true,
            'data' => $classe,
            'message' => 'Classe créée'
        ], 201);
    }

    public function delete(int $id): JsonResponse
    {
        $classe = Classe::findOrFail($id);  // Throws ModelNotFoundException → 404

        // Check permission
        if (!auth()->user()->isAdmin()) {
            $this->forbidden(
                'Seul un administrateur peut supprimer une classe',
                "User {$request->user()->id} attempted to delete classe {$id}",
                ['user_id' => auth()->id(), 'classe_id' => $id]
            );
        }

        $classe->delete();

        return response()->json([
            'success' => true,
            'message' => 'Classe supprimée'
        ]);
    }
}
```

## Comparison

| Aspect | Before | After |
|--------|--------|-------|
| **Code lines** | 90+ | 45 |
| **Try-catch blocks** | 6 | 0 |
| **Error handling** | Manual JSON crafting | Automated via trait |
| **getMessage() leaks** | 3 locations | 0 |
| **Server logging** | Partial, inconsistent | Complete with context |
| **Error format** | Varies per controller | Standardized |
| **Type safety** | Strings everywhere | Typed exceptions |
| **Testability** | Hard to test errors | Easy, can throw in tests |

## Traits and Helper Methods

The `HandlesApiExceptions` trait provides:

```php
// 404 Resource Not Found
$this->notFound(
    resource: 'Classe',  // will show as "Classe non trouvée"
    logMessage: 'Optional detailed log message',
    context: ['classe_id' => $id]  // logged server-side
);

// 403 Permission Denied
$this->forbidden(
    message: 'Seul un admin peut...',
    logMessage: 'User attempted unauthorized action',
    context: ['user_id' => auth()->id()]
);

// 401 Unauthenticated
$this->unauthenticated(
    message: 'Vous devez être authentifié',
    logMessage: 'Unauthenticated request to protected route',
    context: ['path' => request()->path()]
);

// 422 Validation Failed
$this->validationFailed(
    errors: $validator->errors()->toArray(),
    message: 'Erreur de validation',
    logMessage: 'Validation errors: name, email',
    context: ['field_count' => 2]
);

// 500 Server Error
$this->serverError(
    logMessage: 'PDF generation failed',
    exception: $pdfException,  // optional
    context: ['file_size' => 50000000]
);
```

## Migration Checklist

- [ ] Add `use HandlesApiExceptions;` trait
- [ ] Remove all try-catch blocks that return error JSON
- [ ] Replace `if (!$resource)` with `$this->notFound(...)`
- [ ] Replace permission checks with `$this->forbidden(...)`
- [ ] Replace validation error responses with `$this->validationFailed(...)`
- [ ] Remove all `Log::error(...getMessage()...)` calls (exception handler logs them)
- [ ] Remove all response JSON error crafting
- [ ] Test: verify error responses have error_code, not getMessage()
- [ ] Test: verify logs contain full exception context
- [ ] Review: ensure no sensitive data in error messages

## Testing

```php
public function test_classe_not_found_returns_error_code() {
    $response = $this->getJson('/api/classes/99999');

    $this->assertEquals(404, $response->status());
    $response->assertJson([
        'success' => false,
        'error_code' => 'RESOURCE_NOT_FOUND',
        'message' => 'Classe non trouvée'
    ]);

    // MUST NOT contain exception details
    $this->assertStringNotContainsString('Exception', $response->getContent());
}

public function test_validation_error_includes_errors_array() {
    $response = $this->postJson('/api/classes', [
        'code' => 'DUPE',  // Already exists
    ]);

    $this->assertEquals(422, $response->status());
    $response->assertJson([
        'success' => false,
        'error_code' => 'VALIDATION_FAILED',
        'errors' => [...]  // Should have errors array
    ]);
}
```

## Rollout Strategy

1. **Week 1**: API controllers (AuthController, ProxyController, LMSDataController)
2. **Week 2**: Remaining controllers (EvaluationController, ChapterController, etc.)
3. **Week 3**: Integration testing, log verification
4. **Week 4**: Production deployment

Each controller is independent — can be migrated incrementally without breaking others.

---

**Status**: Ready to implement  
**Priority**: P0 (Security compliance)  
**Effort**: ~30 minutes per controller
