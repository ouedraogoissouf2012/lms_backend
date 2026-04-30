# 📋 TODO Liste Révisée - Exemples Concrets

**Format**: Production-grade pour 10+ ans  
**Rigueur**: 100% - Pas d'ambiguïté  
**Example**: 8 items (de 53) révisés complètement

---

## GROUPE 1: TESTING FONDAMENTAL

### PROD-TEST-001: Add E2E tests for authentication flow
```
Status: Pending
Priority: MUST HAVE
Points: 8 (2-3 days)
Category: Testing

DESCRIPTION
───────────
What: End-to-end tests validating complete authentication user journey
Why: Without E2E, we risk production broken auth (login → dashboard)
When: Month 1 (CRITICAL for production)

ACCEPTANCE CRITERIA
───────────────────
- [ ] Test 1: Valid email + password → success, token stored
- [ ] Test 2: Invalid email → error message "Invalid email"
- [ ] Test 3: Invalid password → error message "Invalid password"
- [ ] Test 4: Logged-in user → can access /dashboard
- [ ] Test 5: Logout → token cleared, redirected to /login
- [ ] Test 6: Expired token → redirected to /login with message
- [ ] Test 7: No credentials → cannot access /dashboard
- [ ] Test 8: Token in localStorage persists on page reload
- [ ] Coverage: 100% of /auth/* endpoints touched
- [ ] Browser coverage: Chrome, Firefox, Safari (headless)

DEPENDENCIES
────────────
Blocked by: None (auth API stable)
Blocks: PROD-TEST-002 (E2E dashboard), PROD-SDK-002 (SDK testing)

DEFINITION OF DONE
──────────────────
Code:
- [ ] Tests in /tests/E2E/auth.spec.js
- [ ] Tool: Cypress 13.0+ (not Selenium - flaky)
- [ ] Uses page objects (AuthPage class)
- [ ] No hardcoded waits (use cy.get with reasonable timeout)
- [ ] No test interdependencies (each test independent)

Tests:
- [ ] npm run test:e2e:auth passes
- [ ] Tests pass on fresh DB
- [ ] Tests idempotent (run 5x = same result)
- [ ] No flakiness after 10 runs
- [ ] Screenshot on failure for debugging

Documentation:
- [ ] docs/TESTING.md updated: "Running E2E tests"
- [ ] Code comments for complex selectors
- [ ] CHANGELOG.md entry: "Add auth E2E tests"
- [ ] README.md: "How to contribute tests" link added

Quality:
- [ ] ESLint passes (npm run lint:test)
- [ ] No console.log in production code
- [ ] No hardcoded URLs (use env variables)
- [ ] No test warnings

Deployment:
- [ ] Works in GitHub Actions CI/CD
- [ ] Works headless (no display needed)
- [ ] Video recording on failure (for debugging)
- [ ] Results published to dashboard

Closure:
- [ ] PR reviewed by 1 QA + 1 senior dev
- [ ] PR merged to main
- [ ] GitHub Actions passes
- [ ] Monitored 24h (no flaky failures)

VALIDATION RULES
────────────────
Success = ALL criteria met:
  1. Command: npm run test:e2e:auth → PASS
  2. Coverage: grep -c "it(" auth.spec.js → 8 tests
  3. Flakiness: Run 10x → 100% pass rate
  4. Comments: Check critical selectors documented

Failure = ANY criteria not met → Reopen PR

EFFORT ESTIMATE
───────────────
Points: 8 (Fibonacci scale)
Real time:
  - Setup Cypress: 3 hours
  - Write tests: 8 hours
  - Flakiness fixes: 4 hours
  - Documentation: 2 hours
  TOTAL: 17 hours (2-3 days senior dev)

Resource allocation:
  Day 1: Setup + basic tests
  Day 2: Complete tests + fixes
  Day 3: Documentation + CI/CD

OWNER & REVIEW
──────────────
Assigned to: [TBD - senior dev]
Code review by: [QA lead]
Approval by: [Tech lead]
Start date: [Target week 1]
Target end: [Target end of week 1]
Actual end: [Will fill]

MONITORING
──────────
Post-merge:
- Monitor E2E test pass rate (target: 99%+)
- Alert if any test fails on main
- Review failures within 1 hour
- Disable test if unreliable (temporary)
```

---

### PROD-TEST-002: Add security tests (SQL injection, XSS, CSRF)
```
Status: Pending
Priority: MUST HAVE
Points: 13 (3-5 days)
Category: Testing - Security

DESCRIPTION
───────────
What: Automated security tests catching OWASP top 10 vulnerabilities
Why: Production system with user data needs security baseline
When: Month 1 (CRITICAL for compliance)

ACCEPTANCE CRITERIA
───────────────────
SQL Injection Tests:
- [ ] POST /api/evaluations with ' OR 1=1 → rejected
- [ ] POST /api/evaluations with `; DROP TABLE evaluations;` → rejected
- [ ] All user inputs parameterized (no string concatenation in queries)
- [ ] Database error messages sanitized (no table names exposed)

XSS Tests:
- [ ] POST /api/chapters with <script>alert('xss')</script> → escaped
- [ ] Response contains &lt;script&gt; (escaped) not <script>
- [ ] All user input rendered as text (not HTML)
- [ ] CSP headers present in responses

CSRF Tests:
- [ ] POST requests require CSRF token
- [ ] Missing token → 419 Unprocessable Entity
- [ ] Invalid token → 419 Unprocessable Entity
- [ ] Token validated before action executes

Other:
- [ ] No sensitive data in error messages
- [ ] No credentials in logs/responses
- [ ] Rate limiting active on auth endpoints
- [ ] Invalid requests don't crash server

DEPENDENCIES
────────────
Blocked by: PROD-TEST-001 (E2E ready)
Blocks: PROD-SECURITY-001 (Security hardening based on findings)

DEFINITION OF DONE
──────────────────
Code:
- [ ] Tests in /tests/Security/SecurityTests.php
- [ ] Tool: OWASP ZAP integration + manual phpunit tests
- [ ] Every endpoint tested (>=50 test cases)
- [ ] Edge cases covered (empty strings, null, arrays)

Tests:
- [ ] php artisan test tests/Security/ → 100% pass
- [ ] OWASP ZAP scan → 0 high severity issues
- [ ] Manual penetration test results documented
- [ ] No false positives (expected failures documented)

Documentation:
- [ ] docs/SECURITY_TESTING.md created
- [ ] Known vulnerabilities list + timeline to fix
- [ ] CHANGELOG.md entry: "Add security tests"

Quality:
- [ ] All issues logged + prioritized
- [ ] High severity → immediate fix
- [ ] Medium severity → 1 week timeline
- [ ] Low severity → 1 month timeline

Deployment:
- [ ] Tests run in CI/CD (block merge on HIGH severity)
- [ ] Security report generated after each run
- [ ] Slack notification on new vulnerabilities

CLOSURE:
- [ ] Security review by dedicated person
- [ ] All HIGH/CRITICAL issues fixed or planned
- [ ] Timeline for other issues documented

VALIDATION RULES
────────────────
Success = ALL must be true:
  1. php artisan test tests/Security/ → PASS
  2. OWASP ZAP → 0 HIGH severity
  3. SQL injection tests → 5/5 pass
  4. XSS tests → 6/6 pass
  5. CSRF tests → 3/3 pass
  6. Documentation complete
  7. Known issues documented with timeline

EFFORT ESTIMATE
───────────────
Points: 13 (3-5 days)
  Day 1: Setup OWASP ZAP + phpunit tests (8h)
  Day 2: Write security test cases (8h)
  Day 3: Fix found vulnerabilities (8h)
  Day 4: Documentation + report (4h)
  TOTAL: 28 hours (3-5 days senior dev)

OWNER & REVIEW
──────────────
Assigned to: [TBD - security-minded dev]
Code review by: [Security expert]
Approval by: [CTO/Tech lead]
Start date: [Week 1]
Target end: [Week 2]
```

---

## GROUPE 2: DOCUMENTATION & STANDARDS

### PROD-DOCS-001: Document breaking changes policy
```
Status: Pending
Priority: SHOULD HAVE
Points: 3 (1-2 days)
Category: Documentation

DESCRIPTION
───────────
What: Written policy for how/when/how to handle API breaking changes
Why: Prevents surprises for API consumers, enables SDK versioning
When: Month 1 (before v2 planning)

ACCEPTANCE CRITERIA
───────────────────
Policy defines:
- [ ] What is a "breaking change" (exact definition)
- [ ] Deprecation timeline (minimum 6 months notice)
- [ ] How to communicate (email, changelog, major version)
- [ ] Backwards compatibility period (how long to support old)
- [ ] Migration path documentation (step-by-step for users)
- [ ] Versioning strategy (semantic versioning v1/v2/v3)
- [ ] Examples of breaking vs non-breaking changes
- [ ] Process: PR review checklist for breaking changes

Documentation:
- [ ] docs/BREAKING_CHANGES_POLICY.md (complete)
- [ ] Examples: "v1.0 → v2.0: 6 months notice given"
- [ ] Timeline: Clear dates for each deprecation
- [ ] Approval: CTO approval in document

DEPENDENCIES
────────────
Blocked by: None
Blocks: PROD-API-001 (API v2 design)

DEFINITION OF DONE
──────────────────
Code:
- [ ] Markdown file created
- [ ] Linked from README.md, API docs
- [ ] Version in frontmatter

Documentation:
- [ ] Policy reviewed by CTO
- [ ] Examples provided (4-5)
- [ ] Timeline template included
- [ ] CHANGELOG entry added

Quality:
- [ ] No typos (spell check)
- [ ] Links verified
- [ ] Format consistent with other docs

Deployment:
- [ ] Published on docs site
- [ ] Communicated to API consumers
- [ ] Version tracked (v1.0, v1.1, etc)

VALIDATION RULES
────────────────
Success = Policy covers:
  1. Breaking change definition ✓
  2. Deprecation timeline (6+ months) ✓
  3. Communication strategy ✓
  4. Migration guide requirement ✓
  5. Examples ✓
  6. CTO approval ✓

EFFORT ESTIMATE
───────────────
Points: 3 (1-2 days)
  Hours: 4-6 hours
  - Research: 1h (what other APIs do)
  - Write: 3h (clear examples)
  - Review: 1h (CTO feedback)
  - Finalize: 1h (links, formatting)

OWNER & REVIEW
──────────────
Assigned to: [Tech lead]
Review by: [CTO]
Approval by: [CTO]
Start date: [Week 1]
Target end: [Week 1]
```

---

### PROD-DOCS-002: Add examples for all OpenAPI endpoints
```
Status: Pending
Priority: SHOULD HAVE
Points: 21 (1 week)
Category: Documentation

DESCRIPTION
───────────
What: Complete request/response examples for every endpoint
Why: Examples prevent API misuse, reduce support questions
When: Month 1-2 (high value, moderate effort)

ACCEPTANCE CRITERIA
───────────────────
For every endpoint:
- [ ] Request example (with valid values)
- [ ] Response example (success case)
- [ ] Error example (400/401/403/404/422 case)
- [ ] Real data (not fake "test123" values)
- [ ] curl command (copy-paste ready)
- [ ] JavaScript example (fetch)
- [ ] Python example (requests)

Coverage:
- [ ] All 130+ endpoints covered
- [ ] Authentication endpoints (2 examples: with/without token)
- [ ] Pagination endpoints (show page=2 example)
- [ ] Error scenarios (show actual error response)

Quality:
- [ ] Examples executable (actually work)
- [ ] No credentials exposed
- [ ] Real values match schema types
- [ ] Consistent format across all

DEPENDENCIES
────────────
Blocked by: OpenAPI spec finalized
Blocks: SDK testing, documentation site

DEFINITION OF DONE
──────────────────
Code:
- [ ] Examples in docs/openapi-full.yaml
- [ ] Each endpoint has 3-5 examples
- [ ] Examples marked executable

Tests:
- [ ] Script tests examples (make sure they work)
- [ ] All curl commands execute without error
- [ ] Responses match documented schema

Documentation:
- [ ] docs/EXAMPLES.md guide created
- [ ] "How to test with examples" documented
- [ ] CHANGELOG entry

Quality:
- [ ] Linter verifies example format
- [ ] Examples reviewed by API expert
- [ ] No stale data in examples

VALIDATION RULES
────────────────
Success = ALL true:
  1. Count: 130+ endpoints with examples
  2. Coverage: All 3 example types (request/response/error)
  3. Executable: ./scripts/test-examples.sh → PASS
  4. Review: API expert approved
  5. Format: Consistent across all

EFFORT ESTIMATE
───────────────
Points: 21 (1 week for 2 developers)
  
  Developer 1 (Writing): 40 hours
    - Setup script (2h)
    - Write examples (35h @ ~15 min per endpoint)
    - Review & fixes (3h)
    
  Developer 2 (Testing/QA): 20 hours
    - Test examples (15h)
    - Format fixes (5h)

OWNER & REVIEW
──────────────
Assigned to: [2 developers]
Review by: [API lead]
Approval by: [Tech lead]
Start date: [Week 2]
Target end: [Week 3]
```

---

## GROUPE 3: INFRASTRUCTURE & AUTOMATION

### PROD-INFRA-001: Implement code-docs synchronization tests
```
Status: Pending
Priority: MUST HAVE
Points: 5 (1 day)
Category: Testing - Infrastructure

DESCRIPTION
───────────
What: Automated tests ensuring routes match OpenAPI, errors match specs
Why: Prevents documentation drift, catches API changes without doc updates
When: Month 1 (critical for long-term maintenance)

ACCEPTANCE CRITERIA
───────────────────
Route Validation:
- [ ] Test: Every routes/api.php route has OpenAPI entry
- [ ] Test: Every OpenAPI path has implementation
- [ ] Test: Parameter names match (case-sensitive)
- [ ] Test: Required parameters match

Error Code Validation:
- [ ] Test: Error codes used match CRITICAL-02 list
- [ ] Test: HTTP status codes match error types (401/403/404/422/500)
- [ ] Test: No unknown error codes in code

Schema Validation:
- [ ] Test: Response data matches OpenAPI schema
- [ ] Test: All required fields present in responses
- [ ] Test: No extra fields in responses

Documentation:
- [ ] Tests in tests/Feature/CodeDocsConsistencyTest.php
- [ ] Runs in CI/CD pipeline (block merge if fails)
- [ ] Clear error messages on failure

DEPENDENCIES
────────────
Blocked by: None (runs against existing code)
Blocks: None (but helps all endpoint changes)

DEFINITION OF DONE
──────────────────
Code:
- [ ] Test file: tests/Feature/CodeDocsConsistencyTest.php
- [ ] Parses routes/api.php and docs/openapi-full.yaml
- [ ] 5-8 test methods covering all checks
- [ ] Clear assertion messages

Tests:
- [ ] php artisan test tests/Feature/CodeDocsConsistencyTest.php → PASS
- [ ] All comparisons verified
- [ ] Edge cases handled (optional params, wildcards)

Documentation:
- [ ] docs/CODE_DOCS_CONSISTENCY.md created
- [ ] Explains what tests check
- [ ] How to fix common failures

Quality:
- [ ] Fast (<5 seconds runtime)
- [ ] No false positives
- [ ] Clear error messages

Deployment:
- [ ] Runs in CI/CD before merge
- [ ] Fails with helpful message
- [ ] Reports what's out of sync

VALIDATION RULES
────────────────
Success = ALL true:
  1. php artisan test tests/Feature/CodeDocsConsistencyTest.php → PASS
  2. Test coverage: routes, errors, schemas
  3. Runtime < 5 seconds
  4. Documentation complete

EFFORT ESTIMATE
───────────────
Points: 5 (1 day, 4-6 hours)
  - Setup: 1h
  - Implement tests: 3h
  - Fix failures: 1h
  - Documentation: 1h

OWNER & REVIEW
──────────────
Assigned to: [Senior dev]
Review by: [Code reviewer]
Approval by: [Tech lead]
Start date: [Week 1]
Target end: [Week 1]
```

---

### PROD-INFRA-002: Add commit message validation to pre-commit hook
```
Status: Pending
Priority: SHOULD HAVE
Points: 3 (1 day)
Category: Infrastructure

DESCRIPTION
───────────
What: Pre-commit hook enforcing Conventional Commits format
Why: Clean git history, enables auto-changelog, better PR reviews
When: Month 1 (quick win, high value)

ACCEPTANCE CRITERIA
───────────────────
Format enforcement:
- [ ] Reject commits without type prefix (feat:, fix:, docs:, etc)
- [ ] Reject commits with invalid type
- [ ] Allow: "feat: description" ✓
- [ ] Reject: "add feature" ✗
- [ ] Reject: "FIX: description" ✗ (lowercase only)

Types allowed:
- [ ] feat: new feature
- [ ] fix: bug fix
- [ ] docs: documentation
- [ ] style: code style (prettier, not logic)
- [ ] refactor: code refactoring
- [ ] test: tests only
- [ ] chore: build, dependencies

Enforcement:
- [ ] Blocks commits that don't match format
- [ ] Shows helpful error message
- [ ] Suggests correct format
- [ ] Can bypass with SKIP_COMMIT_CHECK=1 (logged)

DEPENDENCIES
────────────
Blocked by: None
Blocks: PROD-INFRA-003 (auto-changelog)

DEFINITION OF DONE
──────────────────
Code:
- [ ] .git/hooks/pre-commit updated
- [ ] Validation function added
- [ ] Error message helpful (shows examples)

Tests:
- [ ] Valid message: git commit -m "feat: add login" → passes ✓
- [ ] Invalid message: git commit -m "add stuff" → fails ✗
- [ ] Shows error with examples
- [ ] Can bypass: SKIP_COMMIT_CHECK=1 → logs & commits ✓

Documentation:
- [ ] docs/COMMIT_CONVENTION.md created
- [ ] Examples of valid/invalid
- [ ] Why this matters
- [ ] CHANGELOG entry

Quality:
- [ ] Hook executable (chmod +x)
- [ ] Works on Windows/Mac/Linux
- [ ] No false positives
- [ ] Fast execution (<1s)

VALIDATION RULES
────────────────
Success = ALL true:
  1. Hook installed & executable
  2. Valid commits pass
  3. Invalid commits fail
  4. Error messages helpful
  5. Bypass works & logged

EFFORT ESTIMATE
───────────────
Points: 3 (1 day, 3-4 hours)
  - Implement: 2h
  - Test: 1h
  - Document: 1h

OWNER & REVIEW
──────────────
Assigned to: [Any dev]
Review by: [DevOps]
Start date: [Week 1]
Target end: [Week 1]
```

---

## 📊 SUMMARY TABLE

| ID | Title | Priority | Points | Days | Status |
|-------|-------|----------|--------|------|--------|
| PROD-TEST-001 | E2E auth tests | MUST | 8 | 2-3 | Pending |
| PROD-TEST-002 | Security tests | MUST | 13 | 3-5 | Pending |
| PROD-DOCS-001 | Breaking changes policy | SHOULD | 3 | 1 | Pending |
| PROD-DOCS-002 | Examples for endpoints | SHOULD | 21 | 1w | Pending |
| PROD-INFRA-001 | Code-docs sync tests | MUST | 5 | 1 | Pending |
| PROD-INFRA-002 | Commit msg validation | SHOULD | 3 | 1 | Pending |
| ... | ... | ... | ... | ... | ... |

---

## 🎯 TEMPLATE POUR TOUS LES AUTRES ITEMS

Chaque item doit avoir EXACTEMENT cette structure:
- DESCRIPTION (What/Why/When)
- ACCEPTANCE CRITERIA (3-5 mesurables)
- DEPENDENCIES (bloque/bloqué par)
- DEFINITION OF DONE (checklist complète)
- VALIDATION RULES (comment vérifier le succès)
- EFFORT ESTIMATE (story points)
- OWNER & REVIEW (qui fait quoi)

---

**Version**: 1.0.0 (Revised Example)  
**Rigueur**: 10/10 - Production-grade  
**Tous les 53 items doivent suivre ce format**
