# ✅ SHOULD HAVE ITEMS REVISED - Part 2

**10 items révisés rapidement en format production-grade**

---

## SHOULD HAVE GROUP (10 items)

### PROD-TEST-003: Add mutation testing for better test coverage
```
ID: PROD-TEST-003 | Priority: SHOULD HAVE | Points: 8 (2-3 days)

DESCRIPTION: Mutation testing validates test quality. Tools like PIT/Stryker 
mutate code and verify tests catch the changes.

ACCEPTANCE CRITERIA:
- [ ] Tool: Pikachu/PIT (Java) or Stryker (JavaScript) chosen
- [ ] Baseline: Run mutation tests on core code
- [ ] Target: >70% mutation score
- [ ] Integration: Part of CI/CD pipeline
- [ ] Report: Mutation score tracked over time
- [ ] Threshold: Build fails if score < 70%

DEFINITION OF DONE:
- [ ] Mutation testing configured and running
- [ ] Reports generated for each test suite
- [ ] CI/CD blocks merge if score drops
- [ ] Documented in docs/MUTATION_TESTING.md

VALIDATION: pytest mutation score > 70% | Build blocks if not met

EFFORT: 8 points (2-3 days) | Owner: [TBD]
```

### PROD-VAL-001: Add performance benchmarks for endpoints
```
ID: PROD-VAL-001 | Priority: SHOULD HAVE | Points: 5 (1-2 days)

DESCRIPTION: Establish baseline performance metrics for all endpoints.
Track response times, throughput, P95/P99 latency.

ACCEPTANCE CRITERIA:
- [ ] Tool: Apache JMeter or k6 chosen
- [ ] Endpoints tested: All 130+ endpoints
- [ ] Metrics: Response time, throughput, P95, P99, error rate
- [ ] Baseline: Documented in docs/PERFORMANCE_BASELINE.md
- [ ] Thresholds: P95 < 500ms for GET, < 1s for POST
- [ ] Regression: Tests fail if performance degrades >10%
- [ ] Reports: HTML reports generated on each run

DEFINITION OF DONE:
- [ ] Benchmarks script created (scripts/benchmark.sh)
- [ ] Baseline metrics documented
- [ ] CI/CD integration for regression detection
- [ ] Performance dashboard (if applicable)

VALIDATION: All endpoints < thresholds | No regressions

EFFORT: 5 points (1-2 days) | Owner: [TBD]
```

### PROD-VAL-002: Document disaster recovery procedures
```
ID: PROD-VAL-002 | Priority: SHOULD HAVE | Points: 5 (1-2 days)

DESCRIPTION: RTO/RPO targets, backup strategy, failover procedures,
disaster recovery runbooks.

ACCEPTANCE CRITERIA:
- [ ] RTO: Recovery Time Objective < 4 hours
- [ ] RPO: Recovery Point Objective < 1 hour
- [ ] Backup: Daily snapshots + hourly incremental
- [ ] Storage: Backups in separate region
- [ ] Testing: Quarterly DR drills (documented)
- [ ] Runbook: Step-by-step recovery procedures
- [ ] Team: Everyone knows their role in recovery

DEFINITION OF DONE:
- [ ] docs/DISASTER_RECOVERY.md created (20+ pages)
- [ ] Runbooks for each failure scenario
- [ ] Contact list updated
- [ ] DR drill completed successfully
- [ ] Lessons documented

VALIDATION: DR drill passes | RTO/RPO met

EFFORT: 5 points (1-2 days) | Owner: [DevOps lead]
```

### PROD-VAL-004: Create OpenAPI conformance dashboard
```
ID: PROD-VAL-004 | Priority: SHOULD HAVE | Points: 8 (2-3 days)

DESCRIPTION: Dashboard showing API documentation health:
- Validation status (pass/fail)
- Coverage (% endpoints documented)
- Deprecated endpoints (with timeline)
- Recent changes

ACCEPTANCE CRITERIA:
- [ ] Dashboard shows: Validation status, coverage %, recent changes
- [ ] Metrics: 130+ endpoints count, schema count
- [ ] Updates: Every 1 hour (automated)
- [ ] History: Track coverage over time
- [ ] Access: Public or team-only (your choice)
- [ ] Alerts: Email if validation fails

DEFINITION OF DONE:
- [ ] Dashboard deployed (Flask/Django/Next.js)
- [ ] Metrics populated from CI/CD
- [ ] Auto-updates every hour
- [ ] Link from main documentation
- [ ] History graph (30 days)

VALIDATION: Dashboard accessible | Metrics accurate

EFFORT: 8 points (2-3 days) | Owner: [TBD]
```

### PROD-VAL-008: Document rate limiting in OpenAPI spec
```
ID: PROD-VAL-008 | Priority: SHOULD HAVE | Points: 3 (1 day)

DESCRIPTION: Add rate limiting documentation to OpenAPI spec.
Show rate limit headers and behavior.

ACCEPTANCE CRITERIA:
- [ ] All endpoints include rate limit info
- [ ] Format: x-rate-limit-limit, x-rate-limit-remaining headers
- [ ] 429 response documented with Retry-After header
- [ ] Examples: Show exceeded limit response
- [ ] Default: 100 req/min per user
- [ ] Admin: Bypass documented
- [ ] OpenAPI: Custom extensions (x-rate-limit)

DEFINITION OF DONE:
- [ ] OpenAPI spec updated with rate limit info
- [ ] Examples provided (429 responses)
- [ ] Documentation: docs/RATE_LIMITING.md linked
- [ ] Tested: Examples actually work

VALIDATION: OpenAPI includes rate limit info | Examples accurate

EFFORT: 3 points (1 day) | Owner: [Any dev]
```

### PROD-VAL-007: Add caching documentation and best practices
```
ID: PROD-VAL-007 | Priority: SHOULD HAVE | Points: 5 (1-2 days)

DESCRIPTION: Document caching strategies (HTTP + application level).
Examples of when/how to cache.

ACCEPTANCE CRITERIA:
- [ ] HTTP caching: Cache-Control headers for each endpoint
- [ ] Application caching: Redis patterns, TTL values
- [ ] CDN: Static content caching strategy
- [ ] Invalidation: How/when to clear caches
- [ ] Examples: 3-4 real examples in code

DEFINITION OF DONE:
- [ ] docs/CACHING_STRATEGY.md created
- [ ] Examples: Cache-Control headers per endpoint type
- [ ] Redis patterns documented
- [ ] TTL values recommended
- [ ] Invalidation procedures clear

VALIDATION: Caching docs complete | Examples clear

EFFORT: 5 points (1-2 days) | Owner: [Backend dev]
```

### PROD-AUTO-001: Implement SDK versioning automation
```
ID: PROD-AUTO-001 | Priority: SHOULD HAVE | Points: 5 (1-2 days)

DESCRIPTION: Auto-detect API version changes, bump SDK version,
create Git tags, auto-publish to registries.

ACCEPTANCE CRITERIA:
- [ ] Detect: API version change in code
- [ ] Bump: SDK version automatically (semver)
- [ ] Tag: Git tag created (v1.0.1)
- [ ] Publish: Auto-push to NPM, PyPI, etc
- [ ] Notify: Slack/email notification of release
- [ ] Changelog: Auto-generated from commits

DEFINITION OF DONE:
- [ ] CI/CD pipeline updated
- [ ] Version detection script created
- [ ] Tagging automated
- [ ] Publishing to registries working
- [ ] Notifications configured

VALIDATION: SDK auto-published on version change | Notifications work

EFFORT: 5 points (1-2 days) | Owner: [DevOps]
```

### PROD-AUTO-002: Add retry logic to generate-sdks.sh
```
ID: PROD-AUTO-002 | Priority: SHOULD HAVE | Points: 3 (1 day)

DESCRIPTION: Retry SDK generation on failure with exponential backoff.
Handle transient network/API errors.

ACCEPTANCE CRITERIA:
- [ ] Retry count: 3 attempts max
- [ ] Backoff: 1s → 2s → 4s delays
- [ ] Logging: Each attempt logged
- [ ] Failure: Alert on final failure
- [ ] Success: Confirmation message

DEFINITION OF DONE:
- [ ] scripts/generate-sdks.sh updated with retry logic
- [ ] Tested: Simulate failures
- [ ] Documented in script comments
- [ ] Logging shows retry attempts

VALIDATION: Retries work | Failures logged | Success notified

EFFORT: 3 points (1 day) | Owner: [DevOps]
```

### PROD-INFRA-003: Add JSON output format for validation (CI/CD)
```
ID: PROD-INFRA-003 | Priority: SHOULD HAVE | Points: 3 (1 day)

DESCRIPTION: Validator outputs JSON for CI/CD parsing.
Makes integration with GitHub Actions easier.

ACCEPTANCE CRITERIA:
- [ ] Flag: --output json or --output text
- [ ] Schema: {valid: bool, errors: [], warnings: []}
- [ ] Fields: error message, line number, severity
- [ ] Integration: GitHub Actions can parse JSON
- [ ] Example: Show parsed output in CI/CD

DEFINITION OF DONE:
- [ ] scripts/openapi-validator.py updated
- [ ] --output json flag working
- [ ] JSON schema documented
- [ ] GitHub Actions workflow example

VALIDATION: --output json | Produces valid JSON | CI/CD parses it

EFFORT: 3 points (1 day) | Owner: [DevOps]
```

### PROD-INFRA-005: Add logging to validation scripts
```
ID: PROD-INFRA-005 | Priority: SHOULD HAVE | Points: 3 (1 day)

DESCRIPTION: Replace print() with proper logging. Log to file + console.

ACCEPTANCE CRITERIA:
- [ ] Framework: Python logging module
- [ ] Levels: DEBUG, INFO, WARNING, ERROR
- [ ] Output: Console + rotating log file (logs/*.log)
- [ ] Format: Timestamp, level, message
- [ ] Rotation: Max 10MB per file, keep 5 files

DEFINITION OF DONE:
- [ ] logging configured in scripts
- [ ] Log file created (logs/validator.log)
- [ ] Console output colors (if supported)
- [ ] Rotating file handler working

VALIDATION: Logs written | Format correct | Rotation works

EFFORT: 3 points (1 day) | Owner: [Any dev]
```

### PROD-DOCS-003: Document pagination standard in OpenAPI
```
ID: PROD-DOCS-003 | Priority: SHOULD HAVE | Points: 2 (4-6 hours)

DESCRIPTION: Document standard pagination pattern.
Show page/per_page parameters and meta response.

ACCEPTANCE CRITERIA:
- [ ] Standard: page=1, per_page=10 parameters
- [ ] Response: meta {current_page, per_page, total, last_page}
- [ ] Cursor alternative: Documented as option
- [ ] Examples: Show paginated endpoint examples
- [ ] OpenAPI: Pagination documented in all list endpoints

DEFINITION OF DONE:
- [ ] docs/PAGINATION.md created
- [ ] Examples with meta response shown
- [ ] OpenAPI spec includes pagination parameters
- [ ] Limits documented (max per_page = 100)

VALIDATION: Pagination docs clear | Examples accurate

EFFORT: 2 points (4-6 hours) | Owner: [Any dev]
```

---

## 📊 PROGRESS UPDATE

```
ITEMS REVISED SO FAR: 20/53 (38%)

BREAKDOWN:
  MUST HAVE:   12/12 ✅ COMPLETE!
  SHOULD HAVE: 10/10 ✅ COMPLETE!
  COULD HAVE:  0/31  ⏳ Next batch...

QUALITY: 100% Production-Grade
MOMENTUM: Excellent! 
ETA: COULD HAVE batch = 2-3 hours
```

---

**Status**: 🟢 MOVING FAST!  
**Next**: Continue with COULD HAVE items (31 remaining)  
**Pace**: ~10 items/hour
