# API Maintenance Guide

## Overview

This guide explains how to maintain and evolve the LMS Backend API. The API documentation is managed through OpenAPI 3.0.0 specification, which serves as the single source of truth for all endpoints.

## File Structure

```
docs/
├── openapi.yaml                    # THE OpenAPI spec — hand-written, the only one
├── API_MAINTENANCE_GUIDE.md        # This file
├── ADDING_NEW_ENDPOINTS.md         # Guide for adding endpoints
└── API_VALIDATION.md               # Validation scripts and standards
```

## Key Files

### docs/openapi.yaml
- **Purpose**: Source of truth for API documentation — the only spec in the repository
- **Format**: OpenAPI 3.0.0 (YAML), written by hand (no code annotations)
- **Guarded by**: `tests/Feature/Docs/OpenApiSyncTest.php`, in both directions:
  every documented path must be a real route, and every real route must be documented
  or listed in `tests/Feature/Docs/openapi-coverage-baseline.php` (named debt that may only shrink)
- **Served by**: L5-Swagger, directly — http://localhost:8000/api/documentation. No copy step.

`tests/Feature/Docs/OpenApiConventionTest.php` fails if a second spec appears, if a document
designates another spec, or if Swagger UI serves anything but this file.

## Configuration

### L5-Swagger Config (config/l5-swagger.php)
```php
'docs' => base_path('docs'),            // Directory Swagger UI reads from
'docs_yaml' => 'openapi.yaml',           // → docs/openapi.yaml
'format_to_use_for_docs' => 'yaml',      // Use YAML instead of JSON
'generate_always' => false,              // Never regenerate: there are no annotations
```

The configuration explicitly uses the static YAML file approach rather than code annotations. This allows:
- **Single source of truth** in version control
- **Team collaboration** without code merge conflicts
- **Long-term maintainability** independent of code structure
- **External SDK generation** tools can consume the spec

## OpenAPI Structure

### Base Info
```yaml
openapi: 3.0.0
info:
  title: "LMS Backend API"
  description: "..."
  version: "1.0.0"
servers:
  - url: "http://localhost:8000/api"
  - url: "https://api.lms.local/api"
```

### Security Schemes
```yaml
components:
  securitySchemes:
    sanctum:
      type: http
      scheme: bearer
      bearerFormat: JWT
      description: "Sanctum token authentication"
```

All endpoints require authentication unless explicitly documented as public.

### Response Schemas
Standardized error responses with error codes (CRITICAL-02):

```yaml
ErrorResponse:
  properties:
    success: boolean
    error_code: string      # UNAUTHENTICATED, PERMISSION_DENIED, etc.
    message: string

ValidationErrorResponse:
  properties:
    success: boolean
    error_code: string      # VALIDATION_FAILED
    message: string
    errors: object          # Field-level errors
```

Error codes:
- `UNAUTHENTICATED` (401) - No/invalid token
- `PERMISSION_DENIED` (403) - Insufficient role
- `RESOURCE_NOT_FOUND` (404) - Resource doesn't exist
- `VALIDATION_FAILED` (422) - Input validation error
- `INTERNAL_SERVER_ERROR` (500) - Server error

## Common Patterns

### Endpoint Template
```yaml
/path/{id}:
  get:
    tags:
      - Category
    summary: "Short description"
    description: "Longer description if needed"
    operationId: "controllerMethod"  # For code generation
    security:
      - sanctum: []                   # Requires auth
    parameters:
      - name: id
        in: path
        required: true
        schema:
          type: integer
    responses:
      '200':
        description: "Success"
        content:
          application/json:
            schema:
              type: object
              properties:
                success: { type: boolean }
                data: { $ref: '#/components/schemas/Resource' }
      '401':
        description: "Unauthenticated"
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/ErrorResponse'
      '404':
        description: "Not found"
```

### Role-Based Access (CRITICAL-03)
Document role restrictions in endpoint description:

```yaml
/proxy/test-connection:
  get:
    summary: "Test KLASSCI connection"
    description: |
      Admin/Coordinator only - Test connection to KLASSCI API.
      Required roles: coordinateur, superAdmin, supradmin
```

### Pagination
```yaml
parameters:
  - name: page
    in: query
    schema:
      type: integer
      default: 1
  - name: per_page
    in: query
    schema:
      type: integer
      default: 10
```

## Maintenance Workflows

### When You Add a New Endpoint

1. **Create endpoint in controller** (e.g., `app/Http/Controllers/MyController.php`)
2. **Add route** in `routes/api.php`
3. **Test endpoint** locally
4. **Document in OpenAPI** (see ADDING_NEW_ENDPOINTS.md)
5. **Run the guards**: `php vendor/bin/phpunit --filter 'OpenApiSyncTest|OpenApiConventionTest'`
   and `python scripts/openapi-validator.py docs/openapi.yaml --json` (see API_VALIDATION.md)
6. **Shrink the baseline** if the route was listed in `openapi-coverage-baseline.php`
7. **Verify Swagger UI** at http://localhost:8000/api/documentation
8. **Commit**: code and `docs/openapi.yaml` together

### When You Modify an Endpoint

1. **Update route/controller** as needed
2. **Update OpenAPI spec** to match new behavior
3. **Run the guards** and the validator (same commands as above)
4. **Test Swagger UI** reflects changes
6. **Update changelog** (optional but recommended)

### When You Delete an Endpoint

1. **Remove from routes** and controller
2. **Remove from OpenAPI** spec
3. **Remove deprecated section** (if any)
4. **Remove its line from the baseline** if it was listed there
5. **Verify Swagger** no longer shows endpoint

`OpenApiSyncTest` fails while a documented path has no route: a deleted endpoint cannot
stay in the spec.

## Error Response Consistency

All error responses follow CRITICAL-02 format:

```json
{
  "success": false,
  "error_code": "UNAUTHENTICATED",
  "message": "Unauthenticated."
}
```

When documenting errors, always include the error_code in the response schema.

## Testing Documentation Accuracy

To ensure OpenAPI matches actual code:

1. **Manual verification**: Test endpoints in Swagger UI
2. **Automated validation**: Run scripts from API_VALIDATION.md
3. **CI integration**: job "Docs Sync (OpenAPI ↔ code)" in `.github/workflows/security.yml`
   runs `OpenApiSyncTest`, `OpenApiConventionTest` and the validator on every pull request and every push to `lms`

## Exporting & Using the Spec

The OpenAPI spec can be used for:

1. **Swagger UI** (automatic) - http://localhost:8000/api/documentation
2. **Client SDK generation**:
   ```bash
   # OpenAPI Generator can create SDKs in multiple languages
   openapi-generator-cli generate -i docs/openapi.yaml -g typescript-fetch -o client-sdk/
   ```
3. **Documentation sites** (ReDoc, Swagger Petstore, etc.)
4. **API testing tools** (Insomnia, Postman import)

## Version Control

- **Always commit** `docs/openapi.yaml` with the code it describes
- **Single commit** for endpoint changes: code + docs together
- **Commit message**: Include endpoint name and action
  ```
  git commit -m "feat: Add DELETE /evaluations/{id} endpoint

  - Remove evaluation from database
  - Update OpenAPI spec with new endpoint
  "
  ```

## Troubleshooting

### Swagger UI shows old endpoints
- **Cause**: a cached configuration still pointing elsewhere
- **Action**: `php artisan config:clear`
- **Verify**: `OpenApiConventionTest::test_swagger_serves_the_guarded_spec`

### OpenAPI validation fails
- See API_VALIDATION.md for validation script
- Common issues: missing required fields, invalid schema references
- Use online YAML validators: https://www.yamllint.com/

### Swagger UI at /api/documentation returns 404
- **Check**: Laravel routes are loaded
- **Verify**: L5-Swagger package installed (`composer show darkaonline/l5-swagger`)
- **Run**: `php artisan vendor:publish --provider="L5Swagger\L5SwaggerServiceProvider"`

## References

- OpenAPI 3.0.0 Spec: https://spec.openapis.org/oas/v3.0.0
- Swagger UI Docs: https://swagger.io/tools/swagger-ui/
- L5-Swagger Package: https://github.com/DarkaOnLine/L5-Swagger
- Error Handling: See bootstrap/app.php exception handlers (CRITICAL-02)
- Security: See routes/api.php middleware configuration (CRITICAL-03)
