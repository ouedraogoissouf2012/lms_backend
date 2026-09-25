# Setup Guide - API Documentation Tools

Quick setup for API documentation tools and validation.

## 5-Minute Setup

### 1. Install Required Tools

```bash
# Option A: Using npm (Recommended)
npm install -g swagger-cli @openapitools/openapi-generator-cli

# Option B: Using Docker (No installation needed)
# Use --rm -v to mount current directory
docker run --rm -v ${PWD}:/local openapitools/openapi-generator-cli version
```

### 2. Install Python Validation Script Dependencies

```bash
pip install pyyaml
# or
pip3 install pyyaml
```

### 3. Setup Pre-commit Hook

```bash
# Use the versioned hooks (#702): file size, method length, hard-coded secrets
git config core.hooksPath .githooks
```

The hook runs only fast guards. OpenAPI checks run in CI (job "Docs Sync").

### 4. Verify Setup

```bash
# Test swagger-cli
swagger-cli validate docs/openapi.yaml

# Test Python validator
python scripts/openapi-validator.py docs/openapi.yaml
```

**Expected output:**
- ✅ OpenAPI validation should pass
- ✅ Custom validation should pass with all endpoints listed

## Next Steps

1. **Read [API_MAINTENANCE_GUIDE.md](API_MAINTENANCE_GUIDE.md)** (15 min)
2. **Read [ADDING_NEW_ENDPOINTS.md](ADDING_NEW_ENDPOINTS.md)** (30 min)
3. **Bookmark Swagger UI**: http://localhost:8000/api/documentation

## Common Tasks

### Test an Endpoint

```bash
# Start local server
php artisan serve

# In browser
open http://localhost:8000/api/documentation

# Or with curl
curl -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/api/resource
```

### Add a New Endpoint

```bash
# 1. Create in code (controller + route)
# 2. Add to docs/openapi.yaml (see ADDING_NEW_ENDPOINTS.md)
# 3. Guard and validate
php vendor/bin/phpunit --filter 'OpenApiSyncTest|OpenApiConventionTest'
python scripts/openapi-validator.py docs/openapi.yaml --json

# 4. Test in Swagger UI (it serves docs/openapi.yaml directly)
open http://localhost:8000/api/documentation

# 5. Commit
git add docs/openapi.yaml
git commit -m "feat: Add POST /resource endpoint"
```

### Validate API Documentation

```bash
# What CI runs (job "Docs Sync" in .github/workflows/security.yml)
php vendor/bin/phpunit --filter 'OpenApiSyncTest|OpenApiConventionTest'
python scripts/openapi-validator.py docs/openapi.yaml --json

# Optional local tools:

# YAML syntax
yamllint docs/openapi.yaml

# OpenAPI structure
swagger-cli validate docs/openapi.yaml

# Custom rules
python scripts/openapi-validator.py docs/openapi.yaml
```

### Generate Client SDK

```bash
# All languages
./scripts/generate-sdks.sh

# Specific language
./scripts/generate-sdks.sh typescript-fetch python

# Supported: typescript-fetch, python, javascript, go, swift5, java
```

## Troubleshooting

### "swagger-cli: command not found"

```bash
# Install
npm install -g swagger-cli

# Verify
swagger-cli --version
```

### "openapi-generator-cli: command not found"

```bash
# Install
npm install -g @openapitools/openapi-generator-cli

# Or use Docker instead
docker run --rm -v ${PWD}:/local openapitools/openapi-generator-cli generate ...
```

### "ModuleNotFoundError: No module named 'yaml'"

```bash
# Install pyyaml
pip install pyyaml
# or
pip3 install pyyaml
```

### Pre-commit hook not running

```bash
# Should print: .githooks
git config core.hooksPath
```

### "route(s) API ajoutée(s) sans entrée dans docs/openapi.yaml"

`OpenApiSyncTest` found a route that is neither documented nor listed as debt in
`tests/Feature/Docs/openapi-coverage-baseline.php`. Document it in `docs/openapi.yaml`.
There is no copy to keep in sync: Swagger UI reads `docs/openapi.yaml` directly.

## IDE Integration

### VS Code

Install extensions:
1. **REST Client** (Huachao Mao) - Test endpoints
2. **OpenAPI (Swagger) Editor** (42Crunch) - Edit YAML with validation

Settings:
```json
{
  "openapi.use-swagger-ui": true,
  "openapi.preview-port": 8080
}
```

### JetBrains (WebStorm, PhpStorm, etc.)

Built-in support:
- Right-click YAML file → "Services" → "OpenAPI" → "View in Swagger UI"
- Inline validation and completion

### Postman

Import from OpenAPI:
1. Open Postman
2. Click "Import"
3. Select "Link" tab
4. Paste: `http://localhost:8000/api/documentation`
5. Import

## Environment Variables

Configure for your environment:

```bash
# .env (example)
APP_URL=http://localhost:8000
API_BASE_URL=http://localhost:8000/api
SWAGGER_UI_URL=http://localhost:8000/api/documentation
```

For production, update in `config/l5-swagger.php`:
```php
'servers' => [
    [
        'url' => env('API_BASE_URL', 'http://localhost:8000/api'),
        'description' => 'Production Server'
    ],
],
```

## Getting Help

- **API Structure**: See [API_MAINTENANCE_GUIDE.md](API_MAINTENANCE_GUIDE.md)
- **Adding Endpoints**: See [ADDING_NEW_ENDPOINTS.md](ADDING_NEW_ENDPOINTS.md)
- **Validation Issues**: See [API_VALIDATION.md](API_VALIDATION.md)
- **SDK Generation**: See [CLIENT_SDK_GENERATION.md](CLIENT_SDK_GENERATION.md)
- **OpenAPI Spec**: https://spec.openapis.org/oas/v3.0.0

## Quick Reference

| Task | Command |
|------|---------|
| Start server | `php artisan serve` |
| View API docs | http://localhost:8000/api/documentation |
| Validate OpenAPI | `swagger-cli validate docs/openapi.yaml` |
| Run custom validation | `python scripts/openapi-validator.py docs/openapi.yaml` |
| Generate SDKs | `./scripts/generate-sdks.sh` |
| Run tests | `php artisan test` |
| Run specific test | `php artisan test tests/Feature/ExceptionHandlerTest.php` |
| Create new database | `php artisan migrate:fresh` — **détruit toutes les données locales** (refusé hors tests sans `DB_ALLOW_DESTRUCTIVE=true`) |

## Tips & Tricks

### Test Endpoint with curl

```bash
# Get token first
TOKEN=$(curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"password"}' \
  | jq -r '.data.token')

# Use token
curl -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/api/auth/me | jq
```

### Batch Validate All Changes

```bash
# CI validates on every pull request (job "Docs Sync"):
# - every documented path is a real route, every route is documented or named debt
# - a single spec exists, and Swagger UI serves it
# - custom rules (scripts/openapi-validator.py)
php vendor/bin/phpunit --filter 'OpenApiSyncTest|OpenApiConventionTest'
python scripts/openapi-validator.py docs/openapi.yaml --json
```

### Compare OpenAPI Versions

```bash
# See what changed (git is the history: no .bak copies)
git diff docs/openapi.yaml
```

### Generate Documentation Website

```bash
# Using ReDoc
npm install -g redoc-cli
redoc-cli build docs/openapi.yaml -o docs/index.html

# Then open in browser
open docs/index.html
```

## Resources

- [OpenAPI 3.0.0 Specification](https://spec.openapis.org/oas/v3.0.0)
- [Swagger UI Documentation](https://swagger.io/tools/swagger-ui/)
- [OpenAPI Generator](https://openapi-generator.tech/)
- [L5-Swagger Package](https://github.com/DarkaOnLine/L5-Swagger)
- [YAML Lint](https://www.yamllint.com/)

---

**Ready to go!** 🚀

Next: Read [API_MAINTENANCE_GUIDE.md](API_MAINTENANCE_GUIDE.md)
