# Team Checklist - API Documentation Quality

Checklists for maintaining API documentation quality and consistency.

## ✅ Before Each Commit with API Changes

Use this checklist when adding, modifying, or deleting endpoints:

- [ ] **Code Implementation**
  - [ ] Endpoint implemented in controller
  - [ ] Route added in routes/api.php with correct middleware
  - [ ] All parameter validation added
  - [ ] All error cases handled
  - [ ] Code tested manually (curl or Swagger UI)

- [ ] **OpenAPI Documentation**
  - [ ] Endpoint added to docs/openapi-full.yaml
  - [ ] Summary (one-line description) filled
  - [ ] Description (longer detail) filled if complex
  - [ ] operationId matches pattern (camelCase)
  - [ ] Tags match existing categories (don't create new tags)
  - [ ] Security defined (auth:sanctum, roles if needed)
  - [ ] Parameters documented (name, type, required)
  - [ ] Request body schema defined (for POST/PUT/PATCH)
  - [ ] Response schemas defined for all status codes
  - [ ] Error responses included (401, 403, 404, 422, 500 as applicable)
  - [ ] Error codes match CRITICAL-02 standard
  - [ ] Examples provided in responses

- [ ] **Validation**
  - [ ] YAML syntax valid: `yamllint docs/openapi-full.yaml`
  - [ ] OpenAPI structure valid: `swagger-cli validate docs/openapi-full.yaml`
  - [ ] Custom rules pass: `python scripts/openapi-validator.py docs/openapi-full.yaml`
  - [ ] No schema references broken
  - [ ] No circular references in schemas

- [ ] **Synchronization**
  - [ ] Run: `cp docs/openapi-full.yaml storage/api-docs/openapi.yaml`
  - [ ] Verify both files exist and are identical

- [ ] **Testing**
  - [ ] Swagger UI displays correctly at http://localhost:8000/api/documentation
  - [ ] Endpoint appears in Swagger UI
  - [ ] "Try it out" works in Swagger UI
  - [ ] Response matches documented schema
  - [ ] Error codes are as documented
  - [ ] Role restrictions work (if applicable)

- [ ] **Commit**
  - [ ] Feature branch (not main)
  - [ ] Meaningful commit message with endpoint name
  - [ ] Both YAML files included in commit
  - [ ] Code review completed
  - [ ] All tests pass: `php artisan test`

---

## 📋 Before Each Release

Use this checklist before deploying to production:

- [ ] **Documentation Completeness**
  - [ ] All endpoints implemented are documented
  - [ ] All documented endpoints are implemented
  - [ ] No "TODO" or "FIXME" comments in OpenAPI spec
  - [ ] All error codes documented
  - [ ] All role restrictions documented

- [ ] **Validation Full Pass**
  - [ ] Swagger CLI validation: `swagger-cli validate docs/openapi-full.yaml`
  - [ ] YAML linting: `yamllint docs/openapi-full.yaml`
  - [ ] Custom validation: `python scripts/openapi-validator.py docs/openapi-full.yaml`
  - [ ] Pre-commit hook: `git commit --allow-empty -m "test"`
  - [ ] No warnings or errors

- [ ] **SDK Generation**
  - [ ] All SDKs regenerate successfully: `./scripts/generate-sdks.sh`
  - [ ] No breaking changes introduced
  - [ ] SDKs committed to version control
  - [ ] SDK documentation updated (if applicable)

- [ ] **Integration Testing**
  - [ ] Run full test suite: `php artisan test`
  - [ ] All exception handler tests pass (CRITICAL-02)
  - [ ] All security tests pass (CRITICAL-03)
  - [ ] No regressions in existing endpoints

- [ ] **Documentation Review**
  - [ ] README.md updated with new endpoints
  - [ ] API statistics in README.md updated
  - [ ] CHANGELOG.md updated (if applicable)
  - [ ] Known issues/limitations documented

- [ ] **Deployment Readiness**
  - [ ] storage/api-docs/openapi.yaml synced
  - [ ] L5-Swagger config correct for environment
  - [ ] Swagger UI accessible on target server
  - [ ] No sensitive data in documentation
  - [ ] No hardcoded URLs (use env variables)

---

## 🔍 Weekly Review

Perform this check once per week:

- [ ] **Check for Orphaned Code**
  - [ ] Any endpoints in code but not documented?
  - [ ] Any endpoints documented but not in code?
  - [ ] Any routes that should be public but are secured?
  - [ ] Any routes that should be secured but are public?

- [ ] **Review Recent Changes**
  - [ ] Check git log for API changes
  - [ ] Verify OpenAPI was updated with code changes
  - [ ] Check validation didn't report issues

- [ ] **Swagger UI Health Check**
  - [ ] Visit http://localhost:8000/api/documentation
  - [ ] Verify all endpoints visible and grouped
  - [ ] Test 2-3 random endpoints in Swagger UI
  - [ ] Check for broken links or missing schemas

- [ ] **Update Statistics**
  - [ ] Count endpoints: `grep -E "^\s+(get|post|put|patch|delete):" docs/openapi-full.yaml | wc -l`
  - [ ] Count schemas: `grep -E "^\s+\w+:\s*$" docs/openapi-full.yaml | grep -A 1 "components:" | wc -l`
  - [ ] Update counts in README.md

---

## 🐛 When Adding Error Handling

When catching a new error type or changing error behavior:

- [ ] **Code Change**
  - [ ] Exception handled in bootstrap/app.php
  - [ ] Error code assigned (CRITICAL-02)
  - [ ] Error message is generic (no sensitive data)

- [ ] **Documentation Update**
  - [ ] OpenAPI response updated with new status code
  - [ ] New error code added to ErrorResponse enum
  - [ ] All affected endpoints updated
  - [ ] Examples show error code

- [ ] **Testing**
  - [ ] Error code test in ExceptionHandlerTest.php
  - [ ] Test verifies code, not message details
  - [ ] Test verifies no sensitive data leaked

- [ ] **Validation**
  - [ ] Custom validation passes
  - [ ] OpenAPI still valid

---

## 🔐 When Changing Security (CRITICAL-03)

When modifying authentication or authorization:

- [ ] **Code Change**
  - [ ] routes/api.php middleware updated
  - [ ] Role permissions applied correctly
  - [ ] Tests in ProxyRouteSecurityTest.php updated

- [ ] **Documentation Update**
  - [ ] Security scheme in components.securitySchemes updated
  - [ ] Every affected endpoint updated
  - [ ] Role requirements documented in description
  - [ ] 401/403 responses documented

- [ ] **Testing**
  - [ ] Test unauthenticated access (should 401)
  - [ ] Test insufficient role (should 403)
  - [ ] Test authorized access (should succeed)
  - [ ] Test public endpoints still accessible

- [ ] **Communication**
  - [ ] SDK consumers notified of security changes
  - [ ] Deprecated auth methods documented
  - [ ] Migration guide provided if breaking change

---

## 📚 Monthly Review

Perform this comprehensive check monthly:

- [ ] **Documentation Debt**
  - [ ] Review ADDING_NEW_ENDPOINTS.md - still accurate?
  - [ ] Review API_MAINTENANCE_GUIDE.md - still accurate?
  - [ ] Any outdated examples?
  - [ ] Any missing sections?

- [ ] **Endpoint Deprecation**
  - [ ] Any endpoints marked for removal?
  - [ ] Deprecation timeline clear?
  - [ ] Clients notified of deprecations?
  - [ ] Removal completed if time has passed?

- [ ] **Version Control**
  - [ ] All changes documented in CHANGELOG
  - [ ] API version bumped if breaking changes?
  - [ ] Client SDK versions updated?

- [ ] **Performance Check**
  - [ ] Any endpoints with high latency?
  - [ ] Any endpoints with high error rate?
  - [ ] Documentation reflects real behavior?

- [ ] **Quality Metrics**
  - [ ] Number of endpoints: _____ (track trend)
  - [ ] Lines in openapi-full.yaml: _____ (track growth)
  - [ ] Validation warnings: _____ (should be 0)
  - [ ] Test coverage: _____ %

---

## 🎓 When Onboarding New Team Members

Use this to help new developers get productive:

- [ ] **Day 1**
  - [ ] Clone repository
  - [ ] Run SETUP.md
  - [ ] Verify tools installed
  - [ ] Read API_MAINTENANCE_GUIDE.md (15 min)

- [ ] **Day 1-2**
  - [ ] Read ADDING_NEW_ENDPOINTS.md
  - [ ] View Swagger UI at http://localhost:8000/api/documentation
  - [ ] Test 5 random endpoints manually
  - [ ] Review 1-2 recent commits that modified API

- [ ] **Day 2-3**
  - [ ] Add a simple new endpoint (with guidance)
  - [ ] Follow full checklist from "Before Each Commit"
  - [ ] Get PR review from senior developer
  - [ ] Learn from feedback

- [ ] **Week 2+**
  - [ ] Add endpoints independently
  - [ ] Help others with reviews
  - [ ] Suggest documentation improvements

---

## 🆘 Troubleshooting Checklist

When documentation is broken or inconsistent:

- [ ] **Swagger UI Not Loading**
  - [ ] OpenAPI syntax valid? `swagger-cli validate docs/openapi-full.yaml`
  - [ ] storage/api-docs/openapi.yaml exists?
  - [ ] L5-Swagger config correct in config/l5-swagger.php?
  - [ ] Cache cleared? `php artisan config:clear`
  - [ ] Server restarted? `php artisan serve`

- [ ] **Endpoint Not in Swagger**
  - [ ] Endpoint exists in docs/openapi-full.yaml?
  - [ ] YAML syntax valid around endpoint?
  - [ ] storage/api-docs/openapi.yaml updated?
  - [ ] Run: `cp docs/openapi-full.yaml storage/api-docs/openapi.yaml`

- [ ] **Schema Reference Broken**
  - [ ] Schema name correct in definition?
  - [ ] Correct reference format? `$ref: '#/components/schemas/Name'`
  - [ ] Schema exists in components.schemas?
  - [ ] Custom validation: `python scripts/openapi-validator.py docs/openapi-full.yaml`

- [ ] **Files Out of Sync**
  - [ ] Fix: `cp docs/openapi-full.yaml storage/api-docs/openapi.yaml`
  - [ ] Verify: `diff docs/openapi-full.yaml storage/api-docs/openapi.yaml`
  - [ ] Stage both: `git add docs/openapi-full.yaml storage/api-docs/openapi.yaml`

- [ ] **Validation Fails**
  - [ ] Check error message carefully
  - [ ] See [API_VALIDATION.md](API_VALIDATION.md) troubleshooting section
  - [ ] Run each validator separately to isolate issue
  - [ ] Ask on team Slack if stuck

---

## 📊 Metrics to Track

Track these metrics to maintain quality:

| Metric | Target | Check Frequency |
|--------|--------|-----------------|
| Validation warnings | 0 | Every commit |
| Test pass rate | 100% | Every commit |
| Endpoint coverage | 100% | Monthly |
| Documentation currency | Current | Monthly |
| Response time (avg) | <200ms | Weekly |
| Error rate | <1% | Weekly |
| SDK generation success | 100% | Release |

---

## 💡 Pro Tips

1. **Use pre-commit hook** - Never worry about validation again
2. **Keep endpoints simple** - Simpler docs, fewer bugs
3. **Test in Swagger UI** - Most realistic way to test
4. **Review diffs** - Always check what changed in YAML
5. **Name things clearly** - Endpoint names should be self-documenting
6. **Document first** - Write OpenAPI before code for API design
7. **Automate validation** - Let machines catch mechanical issues
8. **Share knowledge** - Document why in comments if reason non-obvious

---

## 📖 Reference

- [API Maintenance Guide](API_MAINTENANCE_GUIDE.md)
- [Adding New Endpoints](ADDING_NEW_ENDPOINTS.md)
- [API Validation](API_VALIDATION.md)
- [Setup Guide](SETUP.md)
- [OpenAPI 3.0.0 Spec](https://spec.openapis.org/oas/v3.0.0)

---

**Print this checklist and keep it handy!** 📋

---

**Version**: 1.0.0  
**Last Updated**: April 30, 2026  
**Maintained By**: LMS Development Team
