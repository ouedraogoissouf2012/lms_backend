# API Maintenance Guide

## Overview

This guide explains how to maintain and evolve the LMS Backend API. The API documentation is managed through OpenAPI 3.0.0 specification, which serves as the single source of truth for all endpoints.

## File Structure

```
docs/
├── openapi.yaml                    # Main OpenAPI spec (generated)
├── openapi-full.yaml              # Source OpenAPI spec (edit this)
├── API_MAINTENANCE_GUIDE.md        # This file
├── ADDING_NEW_ENDPOINTS.md         # Guide for adding endpoints
└── API_VALIDATION.md               # Validation scripts and standards
```

## Key Files

### docs/openapi-full.yaml
- **Purpose**: Source of truth for API documentation
- **Format**: OpenAPI 3.0.0 (YAML)
- **Current coverage**: 130+ endpoints across 18 controllers
- **Maintenance**: Update this file whenever endpoints change
- **Deployment**: Copy to `storage/api-docs/openapi.yaml` for Swagger UI

### storage/api-docs/openapi.yaml
- **Purpose**: Served by L5-Swagger for interactive Swagger UI
- **Access**: http://localhost:8000/api/documentation
- **Auto-sync**: Manually copy from `docs/openapi-full.yaml` after changes

## Configuration

### L5-Swagger Config (config/l5-swagger.php)
```php
'docs_yaml' => 'openapi.yaml',           // Filename in storage/api-docs
'format_to_use_for_docs' => 'yaml',      // Use YAML instead of JSON
'generate_always' => false,              // Don't regenerate from annotations
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
5. **Validate OpenAPI** with scripts (see API_VALIDATION.md)
6. **Update storage/api-docs/openapi.yaml**:
   ```bash
   cp docs/openapi-full.yaml storage/api-docs/openapi.yaml
   ```
7. **Verify Swagger UI** at http://localhost:8000/api/documentation
8. **Commit**: Include both files in git commit

### When You Modify an Endpoint

1. **Update route/controller** as needed
2. **Update OpenAPI spec** to match new behavior
3. **Run validation** scripts
4. **Copy to storage**: `cp docs/openapi-full.yaml storage/api-docs/openapi.yaml`
5. **Test Swagger UI** reflects changes
6. **Update changelog** (optional but recommended)

### When You Delete an Endpoint

1. **Remove from routes** and controller
2. **Remove from OpenAPI** spec
3. **Remove deprecated section** (if any)
4. **Copy to storage**: `cp docs/openapi-full.yaml storage/api-docs/openapi.yaml`
5. **Verify Swagger** no longer shows endpoint

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
3. **CI integration**: Pre-commit hooks validate OpenAPI syntax

## Exporting & Using the Spec

The OpenAPI spec can be used for:

1. **Swagger UI** (automatic) - http://localhost:8000/api/documentation
2. **Client SDK generation**:
   ```bash
   # OpenAPI Generator can create SDKs in multiple languages
   openapi-generator-cli generate -i docs/openapi-full.yaml -g typescript-fetch -o client-sdk/
   ```
3. **Documentation sites** (ReDoc, Swagger Petstore, etc.)
4. **API testing tools** (Insomnia, Postman import)

## Version Control

- **Always commit** both `docs/openapi-full.yaml` and `storage/api-docs/openapi.yaml`
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
- **Fix**: Ensure `storage/api-docs/openapi.yaml` is updated
- **Verify**: `diff docs/openapi-full.yaml storage/api-docs/openapi.yaml`
- **Action**: `cp docs/openapi-full.yaml storage/api-docs/openapi.yaml`

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
