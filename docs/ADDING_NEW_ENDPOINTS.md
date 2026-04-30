# Adding New Endpoints Guide

This guide walks you through adding a new endpoint from code to documentation.

## Quick Checklist

- [ ] Create controller method
- [ ] Add route in routes/api.php
- [ ] Test endpoint locally
- [ ] Add OpenAPI documentation in docs/openapi-full.yaml
- [ ] Run validation scripts
- [ ] Copy to storage/api-docs/openapi.yaml
- [ ] Test in Swagger UI
- [ ] Commit both code and documentation

## Step-by-Step Example

We'll add: `POST /evaluations` - Create a new evaluation

### Step 1: Create the Controller Method

File: `app/Http/Controllers/EvaluationController.php`

```php
public function store(Request $request)
{
    $validated = $request->validate([
        'title' => 'required|string|max:255',
        'description' => 'nullable|string',
        'type' => 'required|in:qcm,reponse_courte,dissertation,mixte',
    ]);

    $evaluation = Evaluation::create($validated);

    return response()->json([
        'success' => true,
        'data' => $evaluation,
    ], 201);
}
```

### Step 2: Add the Route

File: `routes/api.php`

```php
// In appropriate route group with authentication
Route::post('/evaluations', [EvaluationController::class, 'store'])
    ->middleware('auth:sanctum');
```

### Step 3: Test Locally

```bash
# Start server
php artisan serve

# Test endpoint with curl
curl -X POST http://localhost:8000/api/evaluations \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Quiz 1",
    "description": "Mid-term assessment",
    "type": "qcm"
  }'

# Expected response
{
  "success": true,
  "data": {
    "id": 1,
    "title": "Quiz 1",
    "description": "Mid-term assessment",
    "type": "qcm",
    "created_at": "2026-04-30T10:00:00Z",
    "updated_at": "2026-04-30T10:00:00Z"
  }
}
```

### Step 4: Document in OpenAPI

Edit: `docs/openapi-full.yaml`

Find the evaluations section under `paths:` and add:

```yaml
  /evaluations:
    post:
      tags:
        - Evaluations
      summary: "Create evaluation"
      description: "Create a new evaluation with title, optional description, and type"
      operationId: "createEvaluation"
      security:
        - sanctum: []
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              properties:
                title:
                  type: string
                  example: "Quiz 1"
                  description: "Evaluation title"
                description:
                  type: string
                  example: "Mid-term assessment"
                  description: "Optional detailed description"
                type:
                  type: string
                  enum: [qcm, reponse_courte, dissertation, mixte]
                  example: "qcm"
                  description: "Evaluation type"
              required:
                - title
                - type
      responses:
        '201':
          description: "Evaluation created successfully"
          content:
            application/json:
              schema:
                type: object
                properties:
                  success:
                    type: boolean
                    example: true
                  data:
                    type: object
                    properties:
                      id:
                        type: integer
                        example: 1
                      title:
                        type: string
                      description:
                        type: string
                      type:
                        type: string
                      created_at:
                        type: string
                        format: date-time
                      updated_at:
                        type: string
                        format: date-time
        '401':
          description: "Unauthenticated"
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ErrorResponse'
        '422':
          description: "Validation error"
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ValidationErrorResponse'
              examples:
                missing_title:
                  value:
                    success: false
                    error_code: "VALIDATION_FAILED"
                    message: "The title field is required."
                    errors:
                      title:
                        - "The title field is required."
                invalid_type:
                  value:
                    success: false
                    error_code: "VALIDATION_FAILED"
                    message: "The type must be one of: qcm, reponse_courte, dissertation, mixte."
                    errors:
                      type:
                        - "The type must be one of: qcm, reponse_courte, dissertation, mixte."
```

## OpenAPI Field Reference

### Required Fields (Always Include)

| Field | Example | Notes |
|-------|---------|-------|
| `tags` | `- Evaluations` | Category for grouping |
| `summary` | `"Create evaluation"` | One-line description |
| `operationId` | `"createEvaluation"` | For SDK generation |
| `security` | `- sanctum: []` | Or omit for public endpoints |
| `responses` | See section | At least 200/201, 401, error codes |

### Method-Specific Fields

#### GET (Retrieve)
```yaml
GET /resource/{id}:
  parameters:
    - name: id
      in: path
      required: true
      schema:
        type: integer
  responses:
    '200': { ... }
    '401': { ... }
    '404': { ... }
```

#### POST (Create)
```yaml
POST /resource:
  requestBody:
    required: true
    content:
      application/json:
        schema:
          type: object
          properties: { ... }
          required: [field1, field2]
  responses:
    '201': { ... }
    '401': { ... }
    '422': { ... }
```

#### PUT (Update)
```yaml
PUT /resource/{id}:
  parameters:
    - name: id
      in: path
      required: true
      schema:
        type: integer
  requestBody:
    required: true
    content:
      application/json:
        schema: { ... }
  responses:
    '200': { ... }
    '401': { ... }
    '403': { ... }
    '404': { ... }
    '422': { ... }
```

#### DELETE (Remove)
```yaml
DELETE /resource/{id}:
  parameters:
    - name: id
      in: path
      required: true
      schema:
        type: integer
  responses:
    '200': { ... }
    '401': { ... }
    '403': { ... }
    '404': { ... }
```

## Role-Based Access (CRITICAL-03)

If endpoint requires specific roles:

### In routes/api.php:
```php
Route::post('/evaluations', [EvaluationController::class, 'store'])
    ->middleware('auth:sanctum,role:enseignant,coordinateur');
```

### In OpenAPI:
```yaml
  /evaluations:
    post:
      tags:
        - Evaluations
      summary: "Create evaluation"
      description: |
        Create a new evaluation.
        
        **Required roles**: enseignant (teacher), coordinateur (coordinator)
        
        Students cannot create evaluations.
      security:
        - sanctum: []
      responses:
        '201': { ... }
        '401': { ... }
        '403':
          description: "Permission denied - requires enseignant or coordinateur role"
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ErrorResponse'
        '422': { ... }
```

## Error Response Patterns

### CRITICAL-02 Error Format

All errors follow this standard:

```yaml
ErrorResponse:
  type: object
  properties:
    success:
      type: boolean
      example: false
    error_code:
      type: string
      enum: [
        UNAUTHENTICATED,
        PERMISSION_DENIED,
        RESOURCE_NOT_FOUND,
        VALIDATION_FAILED,
        INTERNAL_SERVER_ERROR
      ]
    message:
      type: string
      example: "Unauthenticated."
  required:
    - success
    - error_code
    - message
```

### Mapping HTTP Status to Error Code

| Status | Error Code | When |
|--------|-----------|------|
| 401 | UNAUTHENTICATED | No/invalid token |
| 403 | PERMISSION_DENIED | Role/permission check fails |
| 404 | RESOURCE_NOT_FOUND | Resource doesn't exist |
| 422 | VALIDATION_FAILED | Input validation error |
| 500 | INTERNAL_SERVER_ERROR | Unhandled exception |

Every endpoint should document all possible error responses.

## Pagination Pattern

For list endpoints:

```yaml
  /evaluations:
    get:
      tags:
        - Evaluations
      summary: "List evaluations"
      parameters:
        - name: page
          in: query
          schema:
            type: integer
            default: 1
            minimum: 1
          description: "Page number"
        - name: per_page
          in: query
          schema:
            type: integer
            default: 10
            minimum: 1
            maximum: 100
          description: "Items per page"
      responses:
        '200':
          description: "List of evaluations"
          content:
            application/json:
              schema:
                type: object
                properties:
                  success:
                    type: boolean
                  data:
                    type: array
                    items:
                      $ref: '#/components/schemas/Evaluation'
                  meta:
                    type: object
                    properties:
                      current_page:
                        type: integer
                      per_page:
                        type: integer
                      total:
                        type: integer
                      last_page:
                        type: integer
```

## Query Parameters Pattern

```yaml
parameters:
  - name: search
    in: query
    schema:
      type: string
    description: "Search by title"
    example: "Quiz"
  - name: type
    in: query
    schema:
      type: string
      enum: [qcm, reponse_courte, dissertation, mixte]
    description: "Filter by type"
  - name: sort_by
    in: query
    schema:
      type: string
      enum: [created_at, updated_at, title]
      default: created_at
    description: "Sort field"
  - name: sort_order
    in: query
    schema:
      type: string
      enum: [asc, desc]
      default: desc
    description: "Sort direction"
```

## File Upload Pattern

```yaml
  /files:
    post:
      tags:
        - Files
      summary: "Upload file"
      requestBody:
        required: true
        content:
          multipart/form-data:
            schema:
              type: object
              properties:
                file:
                  type: string
                  format: binary
                  description: "File to upload"
              required:
                - file
      responses:
        '201':
          description: "File uploaded"
          content:
            application/json:
              schema:
                type: object
                properties:
                  success:
                    type: boolean
                  data:
                    type: object
                    properties:
                      id:
                        type: integer
                      filename:
                        type: string
                      size:
                        type: integer
                      mime_type:
                        type: string
                      url:
                        type: string
                      created_at:
                        type: string
                        format: date-time
```

## Step 5: Run Validation

See API_VALIDATION.md for validation scripts.

Quick check:
```bash
# Check YAML syntax
yamllint docs/openapi-full.yaml

# View in browser
open http://localhost:8000/api/documentation
```

## Step 6: Copy to Storage

After validation passes:

```bash
cp docs/openapi-full.yaml storage/api-docs/openapi.yaml
```

## Step 7: Test in Swagger UI

1. Go to http://localhost:8000/api/documentation
2. Find your endpoint (search or scroll)
3. Click "Try it out"
4. Enter test data
5. Click "Execute"
6. Verify response matches documentation

## Step 8: Commit

```bash
git add app/Http/Controllers/EvaluationController.php
git add routes/api.php
git add docs/openapi-full.yaml
git add storage/api-docs/openapi.yaml

git commit -m "feat: Add POST /evaluations endpoint

- Create evaluation with title, description, and type
- Required roles: enseignant, coordinateur
- Returns 201 with evaluation data on success
- Returns 422 with validation errors
"
```

## Common Mistakes to Avoid

### ❌ Missing operationId
```yaml
# Bad
post:
  summary: "..."
  # operationId missing - SDK generators can't use this

# Good
post:
  operationId: "createEvaluation"
  summary: "..."
```

### ❌ Incomplete response schemas
```yaml
# Bad
responses:
  '200':
    description: "Success"
    # No content/schema defined

# Good
responses:
  '200':
    description: "Success"
    content:
      application/json:
        schema:
          type: object
          properties: { ... }
```

### ❌ Missing error responses
```yaml
# Bad
responses:
  '200': { ... }
  # Missing 401, 422, etc.

# Good
responses:
  '201': { ... }
  '401': { ... }
  '422': { ... }
```

### ❌ Not documenting role restrictions
```yaml
# Bad
post:
  summary: "Create evaluation"
  # Role restriction not documented

# Good
post:
  summary: "Create evaluation"
  description: "Required roles: enseignant, coordinateur"
  responses:
    '403':
      description: "Permission denied - requires enseignant or coordinateur role"
```

### ❌ Inconsistent error codes
```yaml
# Bad
500:
  schema:
    properties:
      error: string  # Not standard error_code

# Good
500:
  schema:
    $ref: '#/components/schemas/ErrorResponse'  # Uses standard format
```

## Testing Your Documentation

### Manual Testing
1. Read endpoint description
2. Test exact scenarios described
3. Verify response format matches schema
4. Check all error codes are achievable

### Automated Validation
Run validation scripts from API_VALIDATION.md

### Real-world Testing
- Use Swagger UI "Try it out"
- Use external tools (Insomnia, Postman)
- Test with actual client code if available

## Reference

- [OpenAPI 3.0.0 Specification](https://spec.openapis.org/oas/v3.0.0)
- [API Maintenance Guide](API_MAINTENANCE_GUIDE.md)
- [API Validation](API_VALIDATION.md)
- CRITICAL-02: Exception handling (bootstrap/app.php)
- CRITICAL-03: Security (routes/api.php)
