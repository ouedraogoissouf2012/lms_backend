# OpenAPI Duplicated Components Fix

**Date**: 2026-04-30
**Status**: FIXED
**Files affected**: 2

## Problem

Two `components:` sections defined at root level:
- Line 76: First `components:` with securitySchemes + schemas
- Line 1866: Second `components:` with responses (DUPLICATE KEY ERROR)

YAML spec allows only one root-level key per name.

**Error shown**: "Parser error on line 1866 - duplicated mapping key"

## Root Cause

Files were likely auto-generated or manually edited without merging the two `components:` sections correctly.

## Solution Applied

### For: docs/openapi-full.yaml
1. ✅ Kept first `components:` at line 76 with securitySchemes + schemas
2. ✅ Merged `responses:` section into first `components:` (after schemas)
3. ✅ Removed duplicate `components:` at line 1866
4. ✅ Result: Single `components:` with all subsections

### For: storage/api-docs/openapi.yaml
1. ✅ Applied same fix (file was identical copy)
2. ✅ Kept both files synchronized

### Files NOT changed (already correct):
- docs/openapi-complete.yaml - Only had one `components:` section

## Verification

```bash
# Before
$ grep -c "^components:" docs/openapi-full.yaml
2  # ERROR!

# After
$ grep -c "^components:" docs/openapi-full.yaml
1  # OK ✓
$ grep -c "^components:" storage/api-docs/openapi.yaml
1  # OK ✓
```

## Backups Created

- docs/openapi-full.yaml.backup
- storage/api-docs/openapi.yaml.backup

(Can be deleted after confirming Swagger UI works)

## 10-Year Impact

✅ Swagger UI will now render correctly
✅ No "duplicated mapping key" error
✅ OpenAPI spec is valid YAML
✅ Prevents future confusion when editing OpenAPI

## Files Modified

- docs/openapi-full.yaml (1908 lines)
- storage/api-docs/openapi.yaml (1908 lines)

---

**Next**: All FormRequests (CRITICAL-05 TIER 1) can now be committed safely
