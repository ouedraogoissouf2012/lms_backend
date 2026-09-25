# LMS Backend API Documentation

Complete documentation system for the LMS Backend API, including OpenAPI specification, maintenance guides, validation standards, and SDK generation.

## 📚 Documentation Files

### Core Files
- **[openapi.yaml](openapi.yaml)** - The OpenAPI 3.0.0 specification: the only one in the repository.
  Written by hand, guarded by CI (`OpenApiSyncTest`), served as-is by Swagger UI.
  Routes not yet documented are listed as named debt in
  `tests/Feature/Docs/openapi-coverage-baseline.php`; that list may only shrink.

### Guides for Team

1. **[API_MAINTENANCE_GUIDE.md](API_MAINTENANCE_GUIDE.md)** ⭐ START HERE
   - How the documentation system works
   - File structure and configuration
   - Common maintenance tasks
   - Troubleshooting Swagger UI

2. **[ADDING_NEW_ENDPOINTS.md](ADDING_NEW_ENDPOINTS.md)**
   - Step-by-step guide for adding new endpoints
   - OpenAPI field reference
   - Code examples and templates
   - Common mistakes to avoid

3. **[API_VALIDATION.md](API_VALIDATION.md)**
   - Validation standards and tools
   - Validation scripts (Python, Bash)
   - CI/CD integration
   - Pre-commit hooks setup

4. **[CLIENT_SDK_GENERATION.md](CLIENT_SDK_GENERATION.md)**
   - Generate client SDKs in any language
   - TypeScript, Python, JavaScript, Go, Swift, Java examples
   - Automated generation workflow
   - Publishing to package managers

## 🚀 Quick Start

### For Developers Adding Endpoints

1. Create your endpoint in code (controller + route)
2. Follow the template in [ADDING_NEW_ENDPOINTS.md](ADDING_NEW_ENDPOINTS.md)
3. Add endpoint to `docs/openapi.yaml`
4. Run the guards: `php vendor/bin/phpunit --filter 'OpenApiSyncTest|OpenApiConventionTest'`
5. Run validation: `python scripts/openapi-validator.py docs/openapi.yaml --json`
6. Verify in Swagger UI: http://localhost:8000/api/documentation
7. Commit `docs/openapi.yaml` with your code

### For New Team Members

1. Read [API_MAINTENANCE_GUIDE.md](API_MAINTENANCE_GUIDE.md) (15 min)
2. Check [ADDING_NEW_ENDPOINTS.md](ADDING_NEW_ENDPOINTS.md) when adding endpoints (30 min)
3. Enable the versioned hooks: `git config core.hooksPath .githooks` — OpenAPI checks run in CI, see [API_VALIDATION.md](API_VALIDATION.md)

### For SDK Consumers

See [CLIENT_SDK_GENERATION.md](CLIENT_SDK_GENERATION.md) to:
- Generate client libraries in your language
- Import and use the SDK
- Keep SDKs synchronized with API

## 🔍 Accessing the API

### Swagger UI (Interactive Documentation)
```
http://localhost:8000/api/documentation
```
- Live endpoint testing
- Request/response examples
- Authentication with Bearer token
- Error code reference

### OpenAPI Specification
```
docs/openapi.yaml   (edit this — Swagger UI serves it directly, no copy)
```

## 🔐 Security Standards

All endpoints follow CRITICAL-03 security guidelines:

### Authentication
- All endpoints require `auth:sanctum` (Bearer token)
- Token obtained from `/auth/login` endpoint
- Sanctum tokens stored in `Authorization: Bearer <token>` header

### Authorization (Role-Based)
- Student (étudiant)
- Teacher (enseignant)
- Coordinator (coordinateur)
- Admin (admin)
- Super Admin (superAdmin, supradmin)

**Example role-restricted endpoint**:
```yaml
/proxy/test-connection:
  get:
    description: |
      Admin/Coordinator only - Test connection to KLASSCI API.
      Required roles: coordinateur, superAdmin, supradmin
    responses:
      '403':
        description: "Permission denied - requires admin/coordinator role"
```

## ⚠️ Error Response Format

All errors follow CRITICAL-02 standard:

```json
{
  "success": false,
  "error_code": "ERROR_TYPE",
  "message": "Human-readable message"
}
```

### Error Codes
- `UNAUTHENTICATED` (401) - Missing or invalid token
- `PERMISSION_DENIED` (403) - Insufficient role/permissions
- `RESOURCE_NOT_FOUND` (404) - Resource doesn't exist
- `VALIDATION_FAILED` (422) - Input validation error
- `INTERNAL_SERVER_ERROR` (500) - Server error

## 📋 Common Tasks

### Adding a New Endpoint

```bash
# 1. Create endpoint in code
# 2. Add to docs/openapi.yaml (see ADDING_NEW_ENDPOINTS.md)
# 3. Guard and validate
php vendor/bin/phpunit --filter 'OpenApiSyncTest|OpenApiConventionTest'
python scripts/openapi-validator.py docs/openapi.yaml --json

# 4. Verify
open http://localhost:8000/api/documentation

# 5. Commit
git add docs/openapi.yaml
git commit -m "feat: Add POST /resource endpoint"
```

### Updating an Endpoint

```bash
# 1. Modify controller/route in code
# 2. Update description/parameters in docs/openapi.yaml
# 3. Guard and validate
php vendor/bin/phpunit --filter 'OpenApiSyncTest|OpenApiConventionTest'
python scripts/openapi-validator.py docs/openapi.yaml --json

# 4. Test and commit
```

### Generating Client SDK

```bash
# See CLIENT_SDK_GENERATION.md for full options
./scripts/generate-sdks.sh

# Or individual language
openapi-generator-cli generate \
  -i docs/openapi.yaml \
  -g typescript-fetch \
  -o client-sdk/typescript
```

## 🧪 Testing

### Manual Testing
1. Go to http://localhost:8000/api/documentation
2. Find your endpoint
3. Click "Try it out"
4. Enter test data and token
5. Click "Execute"
6. Verify response

### Automated Validation
```bash
# Syntax check
swagger-cli validate docs/openapi.yaml

# Format check
yamllint docs/openapi.yaml

# Custom rules
python scripts/openapi-validator.py docs/openapi.yaml

# Test endpoint works
curl -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/api/resource
```

### Running Tests
```bash
# Feature tests
php artisan test tests/Feature/

# Specific test file
php artisan test tests/Feature/ProxyRouteSecurityTest.php

# With coverage
php artisan test --coverage
```

## 📊 API Statistics

- **Security Schemes**: Sanctum (Bearer JWT)
- **Response Format**: JSON
- **OpenAPI Version**: 3.0.0
- **Authentication Method**: Bearer tokens
- **Supported Languages** (SDK): TypeScript, Python, JavaScript, Go, Swift, Java

### Documentation coverage

Measured, never maintained by hand: a hand-kept count is how this page came to claim
130+ documented endpoints while the guarded spec held 47. `OpenApiSyncTest` prints it on
every run:

```
[OpenAPI coverage] 47/194 routes documentées (24%). Non documentées : 147   ← 2026-09-25
```

## 🔗 Related Documentation

- **Exception Handling**: See `bootstrap/app.php` (CRITICAL-02)
- **Route Security**: See `routes/api.php` (CRITICAL-03)
- **Tests**: See `tests/Feature/`
- **Controllers**: See `app/Http/Controllers/`

## 📞 Support

### For Documentation Questions
1. Check the relevant guide (ADDING_NEW_ENDPOINTS.md, API_MAINTENANCE_GUIDE.md)
2. See Troubleshooting sections
3. Check Swagger UI examples

### For API Issues
1. Verify endpoint exists in OpenAPI spec
2. Check authentication (Bearer token provided)
3. Check role permissions (see CRITICAL-03)
4. Check request body matches schema
5. Review error response (CRITICAL-02 format)

### For Integration Issues
1. Generate client SDK (see CLIENT_SDK_GENERATION.md)
2. Use generated SDK instead of manual HTTP calls
3. Check SDK examples for your language
4. Ensure OpenAPI spec is up-to-date

## 🔄 Keeping Documentation Fresh

### Weekly
- Check for new endpoints added to code but not documented
- Verify Swagger UI reflects latest changes

### Before Each Release
- Run full validation suite
- Regenerate all client SDKs
- Update version numbers
- Test all endpoints in Swagger UI

### Monthly
- Review for outdated descriptions
- Update error codes if changed
- Check for orphaned endpoints
- Review analytics/usage patterns

## 📚 References

### External Resources
- [OpenAPI 3.0.0 Specification](https://spec.openapis.org/oas/v3.0.0)
- [Swagger UI Documentation](https://swagger.io/tools/swagger-ui/)
- [OpenAPI Generator](https://openapi-generator.tech/)
- [L5-Swagger Package](https://github.com/DarkaOnLine/L5-Swagger)

### Internal Standards
- **CRITICAL-02**: Exception handler with error codes (bootstrap/app.php)
- **CRITICAL-03**: Proxy route security (routes/api.php)
- **REFACTORING_ROADMAP.md**: Long-term improvements

## ✅ Checklist Before Committing Endpoint Changes

- [ ] Endpoint implemented and tested in code
- [ ] Route added with proper middleware (auth:sanctum, roles)
- [ ] OpenAPI endpoint documented in docs/openapi.yaml
- [ ] All required fields present (tags, summary, operationId, responses)
- [ ] Error responses documented (401, 403, 404, 422, 500)
- [ ] Example request/response provided
- [ ] Validation passes: `swagger-cli validate docs/openapi.yaml`
- [ ] Custom validation passes: `python scripts/openapi-validator.py docs/openapi.yaml`
- [ ] Guards pass: `php vendor/bin/phpunit --filter 'OpenApiSyncTest|OpenApiConventionTest'`
- [ ] Baseline line removed if the route was listed in `openapi-coverage-baseline.php`
- [ ] Swagger UI displays correctly at http://localhost:8000/api/documentation
- [ ] Endpoint tested in Swagger UI "Try it out"
- [ ] `docs/openapi.yaml` committed in the same commit as the code

## 🎯 Goals

This documentation system enables:

✅ **Single Source of Truth** - OpenAPI spec defines API contract
✅ **Team Collaboration** - Clear guides for adding/modifying endpoints
✅ **Long-term Maintenance** - Versioned documentation with validation
✅ **Multiple Platforms** - SDK generation for any language
✅ **Type Safety** - Generated code prevents runtime errors
✅ **Developer Experience** - Interactive Swagger UI, clear examples
✅ **Quality Assurance** - Automated validation before commits
✅ **Knowledge Sharing** - Comprehensive guides for new team members

---

**Last Updated**: April 30, 2026  
**Documentation Version**: 1.0.0  
**API Version**: 1.0.0  
**Maintained By**: LMS Development Team
