# API Validation Standards & Scripts

This guide provides validation scripts and standards to ensure API documentation quality and consistency.

## Quick Start

```bash
# Install validation tools
npm install -g spectacle-docs swagger-ui swagger-cli

# Validate OpenAPI file
swagger-cli validate docs/openapi-full.yaml

# Check syntax
yamllint docs/openapi-full.yaml

# Lint with custom rules
python scripts/openapi-validator.py docs/openapi-full.yaml
```

## Validation Standards

### 1. OpenAPI Syntax Validation

**Tool**: `swagger-cli`

```bash
# Install
npm install -g swagger-cli

# Validate
swagger-cli validate docs/openapi-full.yaml

# Expected output
docs/openapi-full.yaml is valid ✓
```

**What it checks**:
- Valid YAML syntax
- Required OpenAPI fields
- Schema references are defined
- No circular references

### 2. YAML Format Validation

**Tool**: `yamllint`

```bash
# Install
pip install yamllint

# Validate
yamllint -d relaxed docs/openapi-full.yaml

# Configure (optional) - create .yamllint
extends: relaxed
rules:
  line-length:
    max: 120
  indentation:
    spaces: 2
```

**What it checks**:
- Proper indentation (2 spaces)
- Line length limits
- Quote consistency

### 3. Custom Validation Script

Create: `scripts/openapi-validator.py`

```python
#!/usr/bin/env python3
"""
OpenAPI 3.0.0 validation script with custom rules.
Ensures API documentation meets internal standards.
"""

import yaml
import sys
import re
from typing import List, Dict

class OpenAPIValidator:
    def __init__(self, filepath: str):
        with open(filepath, 'r') as f:
            self.spec = yaml.safe_load(f)
        self.errors = []
        self.warnings = []

    def validate(self) -> bool:
        """Run all validation checks."""
        self.check_required_fields()
        self.check_endpoints()
        self.check_schemas()
        self.check_error_responses()
        self.check_security()
        
        self.report()
        return len(self.errors) == 0

    def check_required_fields(self):
        """Validate required top-level fields."""
        required = ['openapi', 'info', 'paths']
        for field in required:
            if field not in self.spec:
                self.errors.append(f"Missing required field: {field}")
        
        if 'info' in self.spec:
            info_required = ['title', 'version']
            for field in info_required:
                if field not in self.spec['info']:
                    self.errors.append(f"Missing info.{field}")

    def check_endpoints(self):
        """Validate all endpoint definitions."""
        if 'paths' not in self.spec:
            return
        
        for path, methods in self.spec['paths'].items():
            for method, endpoint in methods.items():
                if method.startswith('x-'):  # Skip extensions
                    continue
                
                # Check operationId
                if 'operationId' not in endpoint:
                    self.warnings.append(
                        f"{method.upper()} {path}: Missing operationId "
                        "(needed for SDK generation)"
                    )
                
                # Check summary
                if 'summary' not in endpoint:
                    self.warnings.append(
                        f"{method.upper()} {path}: Missing summary"
                    )
                
                # Check tags
                if 'tags' not in endpoint:
                    self.errors.append(
                        f"{method.upper()} {path}: Missing tags (required)"
                    )
                
                # Check responses
                if 'responses' not in endpoint:
                    self.errors.append(
                        f"{method.upper()} {path}: Missing responses"
                    )
                else:
                    self.check_endpoint_responses(path, method, endpoint)
                
                # Check security for protected endpoints
                if method != 'get' or '/auth/' not in path:
                    if 'security' not in endpoint and \
                       'security' not in self.spec:
                        self.warnings.append(
                            f"{method.upper()} {path}: No security defined"
                        )

    def check_endpoint_responses(self, path: str, method: str, endpoint: Dict):
        """Validate endpoint response definitions."""
        responses = endpoint.get('responses', {})
        
        # Check for success response
        success_codes = ['200', '201', '202', '204']
        has_success = any(code in responses for code in success_codes)
        if not has_success:
            self.errors.append(
                f"{method.upper()} {path}: Missing success response (200/201/etc)"
            )
        
        # Check for error responses (all endpoints should have them)
        error_codes = ['401', '403', '404', '422', '500']
        
        # For authenticated endpoints, require 401
        if endpoint.get('security'):
            if '401' not in responses:
                self.warnings.append(
                    f"{method.upper()} {path}: Missing 401 (Unauthenticated)"
                )
        
        # For data modification, require 422
        if method in ['post', 'put', 'patch']:
            if '422' not in responses:
                self.warnings.append(
                    f"{method.upper()} {path}: Missing 422 (Validation errors)"
                )
        
        # Validate error response schemas
        for code in ['401', '403', '404', '422', '500']:
            if code in responses:
                response = responses[code]
                if 'content' in response:
                    schema = response['content'].get('application/json', {}).get('schema', {})
                    if '$ref' in schema:
                        # Verify reference exists
                        ref = schema['$ref'].split('/')[-1]
                        if ref not in self.spec.get('components', {}).get('schemas', {}):
                            self.errors.append(
                                f"{method.upper()} {path} {code}: "
                                f"Schema reference ${ref} not found"
                            )

    def check_schemas(self):
        """Validate schema definitions."""
        schemas = self.spec.get('components', {}).get('schemas', {})
        
        # Check ErrorResponse exists
        if 'ErrorResponse' not in schemas:
            self.errors.append("Missing ErrorResponse schema")
        
        # Check all schema references are defined
        for path, methods in self.spec.get('paths', {}).items():
            for method, endpoint in methods.items():
                self.check_schema_refs(endpoint, path, method)

    def check_schema_refs(self, obj, path: str, method: str):
        """Recursively check schema references exist."""
        if isinstance(obj, dict):
            if '$ref' in obj:
                ref = obj['$ref'].split('/')[-1]
                schemas = self.spec.get('components', {}).get('schemas', {})
                if ref not in schemas:
                    self.errors.append(
                        f"{method.upper()} {path}: "
                        f"Schema reference ${ref} not found in components.schemas"
                    )
            
            for value in obj.values():
                self.check_schema_refs(value, path, method)
        
        elif isinstance(obj, list):
            for item in obj:
                self.check_schema_refs(item, path, method)

    def check_error_responses(self):
        """Validate error response consistency (CRITICAL-02)."""
        error_response = self.spec.get('components', {}).get('schemas', {}).get('ErrorResponse')
        
        if not error_response:
            return
        
        required_properties = ['success', 'error_code', 'message']
        props = error_response.get('properties', {})
        
        for prop in required_properties:
            if prop not in props:
                self.errors.append(
                    f"ErrorResponse missing property: {prop}"
                )
        
        # Check error_code enum
        error_code = props.get('error_code', {})
        enum_values = error_code.get('enum', [])
        expected = [
            'UNAUTHENTICATED',
            'PERMISSION_DENIED',
            'RESOURCE_NOT_FOUND',
            'VALIDATION_FAILED',
            'INTERNAL_SERVER_ERROR'
        ]
        for code in expected:
            if code not in enum_values:
                self.warnings.append(
                    f"ErrorResponse error_code missing enum value: {code}"
                )

    def check_security(self):
        """Validate security scheme definitions (CRITICAL-03)."""
        schemes = self.spec.get('components', {}).get('securitySchemes', {})
        
        if not schemes:
            self.warnings.append("No securitySchemes defined")
            return
        
        if 'sanctum' not in schemes:
            self.warnings.append(
                "securitySchemes missing 'sanctum' (bearer token)"
            )

    def report(self):
        """Print validation report."""
        print("\n" + "="*60)
        print("OpenAPI Validation Report")
        print("="*60 + "\n")
        
        if self.errors:
            print(f"❌ ERRORS ({len(self.errors)}):")
            for error in self.errors:
                print(f"   - {error}")
            print()
        
        if self.warnings:
            print(f"⚠️  WARNINGS ({len(self.warnings)}):")
            for warning in self.warnings:
                print(f"   - {warning}")
            print()
        
        if not self.errors and not self.warnings:
            print("✅ All checks passed!\n")
        
        print("="*60 + "\n")


if __name__ == '__main__':
    if len(sys.argv) < 2:
        print("Usage: python openapi-validator.py <filepath>")
        sys.exit(1)
    
    validator = OpenAPIValidator(sys.argv[1])
    if not validator.validate():
        sys.exit(1)
```

**Run it**:
```bash
python scripts/openapi-validator.py docs/openapi-full.yaml
```

**Output Example**:
```
============================================================
OpenAPI Validation Report
============================================================

❌ ERRORS (1):
   - POST /evaluations: Schema reference $Evaluation not found

⚠️  WARNINGS (3):
   - POST /evaluations: Missing operationId
   - GET /evaluations/{id}: Missing 422 (Validation errors)
   - DELETE /evaluations/{id}: No security defined

============================================================
```

### 4. Endpoint Naming Consistency

**Standard Patterns**:

```
GET    /resource              # List all
GET    /resource/{id}         # Get single
POST   /resource              # Create
PUT    /resource/{id}         # Full update
PATCH  /resource/{id}         # Partial update
DELETE /resource/{id}         # Delete

GET    /resource/{id}/sub     # Get sub-resources
POST   /resource/{id}/sub     # Create sub-resource
DELETE /resource/{id}/sub/{id}# Delete sub-resource

POST   /resource/{id}/action  # Perform action
```

**Validation Regex** (use in CI):
```bash
# Ensure paths follow pattern
grep -E '^\s+/(api/)?[a-z0-9/-]+:' docs/openapi-full.yaml | \
  grep -v -E '(api|v[0-9]|resource|auth|proxy|evaluation|chapter|lesson|file|notification|forum|quiz|dashboard)'
# Should return 0 non-matching patterns
```

### 5. Error Code Consistency

**Standard Format** (CRITICAL-02):

```yaml
401 Unauthenticated:
  success: false
  error_code: "UNAUTHENTICATED"
  message: "Unauthenticated."

403 Permission Denied:
  success: false
  error_code: "PERMISSION_DENIED"
  message: "Permission denied."

404 Not Found:
  success: false
  error_code: "RESOURCE_NOT_FOUND"
  message: "Resource not found."

422 Validation Failed:
  success: false
  error_code: "VALIDATION_FAILED"
  message: "[Field] field is required."
  errors: { ... }

500 Internal Error:
  success: false
  error_code: "INTERNAL_SERVER_ERROR"
  message: "Internal server error."
```

**Validation Script**:
```bash
# Check all 401 responses use correct error_code
grep -A 5 "'401':" docs/openapi-full.yaml | \
  grep -c "UNAUTHENTICATED"
# Should match number of 401 responses

grep -A 5 "'403':" docs/openapi-full.yaml | \
  grep -c "PERMISSION_DENIED"
# Should match number of 403 responses
```

## CI/CD Integration

### GitHub Actions Example

Create: `.github/workflows/validate-api.yml`

```yaml
name: API Documentation Validation

on:
  pull_request:
    paths:
      - 'docs/openapi-full.yaml'
      - 'storage/api-docs/openapi.yaml'
      - 'routes/api.php'
      - 'app/Http/Controllers/**'

jobs:
  validate:
    runs-on: ubuntu-latest
    
    steps:
      - uses: actions/checkout@v3
      
      - name: Install dependencies
        run: |
          npm install -g swagger-cli yamllint
          pip install pyyaml
      
      - name: Validate OpenAPI syntax
        run: swagger-cli validate docs/openapi-full.yaml
      
      - name: Check YAML format
        run: yamllint -d relaxed docs/openapi-full.yaml
      
      - name: Run custom validation
        run: python scripts/openapi-validator.py docs/openapi-full.yaml
      
      - name: Verify both files sync
        run: |
          diff docs/openapi-full.yaml storage/api-docs/openapi.yaml
          if [ $? -ne 0 ]; then
            echo "ERROR: docs/openapi-full.yaml and storage/api-docs/openapi.yaml are out of sync"
            echo "Run: cp docs/openapi-full.yaml storage/api-docs/openapi.yaml"
            exit 1
          fi
```

### Pre-commit Hook

Create: `.git/hooks/pre-commit`

```bash
#!/bin/bash

# Check if OpenAPI files were modified
if git diff --cached --name-only | grep -E '(openapi|routes/api|Controllers)' > /dev/null; then
    echo "Validating OpenAPI documentation..."
    
    # Validate syntax
    if ! swagger-cli validate docs/openapi-full.yaml 2>/dev/null; then
        echo "❌ OpenAPI validation failed"
        exit 1
    fi
    
    # Run custom validation
    if ! python scripts/openapi-validator.py docs/openapi-full.yaml > /dev/null 2>&1; then
        echo "❌ Custom validation failed"
        python scripts/openapi-validator.py docs/openapi-full.yaml
        exit 1
    fi
    
    # Check files are in sync
    if ! diff -q docs/openapi-full.yaml storage/api-docs/openapi.yaml > /dev/null 2>&1; then
        echo "❌ OpenAPI files are out of sync"
        echo "Run: cp docs/openapi-full.yaml storage/api-docs/openapi.yaml"
        exit 1
    fi
    
    echo "✅ API documentation validation passed"
fi

exit 0
```

**Setup hook**:
```bash
chmod +x .git/hooks/pre-commit
```

## Testing Checklist

Before committing endpoint changes:

- [ ] **Syntax Valid**: `swagger-cli validate docs/openapi-full.yaml`
- [ ] **YAML Format**: `yamllint docs/openapi-full.yaml`
- [ ] **Custom Rules**: `python scripts/openapi-validator.py docs/openapi-full.yaml`
- [ ] **Files Synced**: `diff docs/openapi-full.yaml storage/api-docs/openapi.yaml`
- [ ] **Swagger UI Displays**: http://localhost:8000/api/documentation
- [ ] **Endpoint Works**: Test in Swagger UI or with curl
- [ ] **Error Codes Match**: Check against CRITICAL-02 standard
- [ ] **Security Defined**: All endpoints have proper `security:` and responses
- [ ] **No Refs Broken**: All `$ref:` point to defined schemas

## Troubleshooting Validation

### "Schema reference X not found"
```yaml
# Bad
responses:
  '200':
    schema:
      $ref: '#/components/schemas/User'  # User not defined

# Fix: Add to components.schemas or use correct name
```

### "Missing required fields"
```yaml
# Bad
get:
  summary: "..."
  # Missing tags, operationId, responses

# Good
get:
  operationId: "getEvaluation"
  summary: "..."
  tags:
    - Evaluations
  responses:
    '200': { ... }
```

### "Files out of sync"
```bash
# storage/api-docs/openapi.yaml differs from docs/openapi-full.yaml
cp docs/openapi-full.yaml storage/api-docs/openapi.yaml
git add both files
git commit -m "sync: Update OpenAPI files"
```

## Continuous Improvement

### Monthly Review
1. Check for documented but unimplemented endpoints
2. Verify all error codes match actual behavior
3. Update deprecated endpoints
4. Review for breaking changes

### Metrics to Track
- Total endpoints documented
- Coverage percentage (documented/total)
- Average response time to add new endpoint
- Documentation debt (outdated descriptions)

## References

- [Swagger CLI](https://github.com/APIDevTools/swagger-cli)
- [YAML Linter](https://www.yamllint.com/)
- [OpenAPI 3.0.0 Spec](https://spec.openapis.org/oas/v3.0.0)
- CRITICAL-02: Error handling (bootstrap/app.php)
- CRITICAL-03: Security (routes/api.php)
