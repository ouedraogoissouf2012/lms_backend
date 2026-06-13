#!/usr/bin/env python3

##############################################################################
# OpenAPI 3.0.0 Validation Script
#
# Validates OpenAPI specification against internal standards.
# Ensures API documentation meets quality requirements.
#
# Usage: python openapi-validator.py [filepath]
#
# Example:
#   python openapi-validator.py docs/openapi-full.yaml
#
##############################################################################

import sys
import re
from typing import List, Dict, Tuple

try:
    import yaml
except ImportError:
    print("[WARNING] PyYAML not installed - skipping validation")
    print("[INFO] Install with: pip install pyyaml")
    sys.exit(0)

class OpenAPIValidator:
    """Validates OpenAPI 3.0.0 specification with custom rules."""

    def __init__(self, filepath: str):
        """Load and parse OpenAPI specification."""
        try:
            with open(filepath, 'r', encoding='utf-8') as f:
                self.spec = yaml.safe_load(f)
            self.filepath = filepath
        except FileNotFoundError:
            print(f"Error: File not found: {filepath}")
            sys.exit(1)
        except yaml.YAMLError as e:
            print(f"Error: Invalid YAML: {e}")
            sys.exit(1)

        self.errors = []
        self.warnings = []
        self.stats = {
            'endpoints': 0,
            'paths': 0,
            'schemas': 0,
            'tags': 0,
        }

    def validate(self, json_output: bool = False) -> bool:
        """Run all validation checks.

        When ``json_output`` is True, emit a single machine-readable JSON
        document (for CI parsing, #220) instead of the human report.
        """
        if not json_output:
            print(f"\n📋 Validating: {self.filepath}\n")

        self.check_required_fields()
        self.check_endpoints()
        self.check_schemas()
        self.check_error_responses()
        self.check_security()

        if json_output:
            self.report_json()
        else:
            self.report()

        return len(self.errors) == 0

    def report_json(self) -> None:
        """Emit a structured JSON report parseable by CI (#220)."""
        import json

        payload = {
            "file": self.filepath,
            "errors": self.errors,
            "warnings": self.warnings,
            "stats": self.stats,
            "passed": len(self.errors) == 0,
        }
        print(json.dumps(payload, indent=2, ensure_ascii=False))

    def check_required_fields(self):
        """Validate required top-level fields."""
        required = ['openapi', 'info', 'paths']
        for field in required:
            if field not in self.spec:
                self.errors.append(f"Missing required field: {field}")

        # Validate info section
        if 'info' in self.spec:
            info_required = ['title', 'version']
            for field in info_required:
                if field not in self.spec['info']:
                    self.errors.append(f"Missing info.{field}")

        # Validate servers
        if 'servers' not in self.spec:
            self.warnings.append("No servers defined (optional but recommended)")

    def check_endpoints(self):
        """Validate all endpoint definitions."""
        if 'paths' not in self.spec:
            return

        paths = self.spec.get('paths', {})
        self.stats['paths'] = len(paths)

        for path, methods in paths.items():
            if isinstance(methods, dict):
                for method, endpoint in methods.items():
                    # Skip OpenAPI extensions
                    if method.startswith('x-'):
                        continue

                    self.stats['endpoints'] += 1
                    self.validate_endpoint(path, method, endpoint)

    def validate_endpoint(self, path: str, method: str, endpoint: Dict):
        """Validate individual endpoint definition."""
        method_upper = method.upper()
        endpoint_id = f"{method_upper} {path}"

        # Check required fields
        if 'operationId' not in endpoint:
            self.warnings.append(
                f"{endpoint_id}: Missing operationId (needed for SDK generation)"
            )

        if 'summary' not in endpoint:
            self.warnings.append(f"{endpoint_id}: Missing summary")

        if 'tags' not in endpoint or not endpoint['tags']:
            self.errors.append(f"{endpoint_id}: Missing tags (required)")

        if 'responses' not in endpoint:
            self.errors.append(f"{endpoint_id}: Missing responses")
        else:
            self.validate_responses(path, method, endpoint)

        # Check security
        has_security = 'security' in endpoint or 'security' in self.spec
        # Skip security check for public paths
        if '/auth/login' not in path and '/auth/refresh' not in path:
            if not has_security and method not in ['options', 'head']:
                # Only warn if it's a protected endpoint
                if not self._is_public_endpoint(path):
                    self.warnings.append(
                        f"{endpoint_id}: No security defined (consider adding auth:sanctum)"
                    )

        # Check request body for POST/PUT/PATCH
        if method in ['post', 'put', 'patch']:
            if 'requestBody' not in endpoint:
                self.warnings.append(f"{endpoint_id}: Missing requestBody")

    def validate_responses(self, path: str, method: str, endpoint: Dict):
        """Validate endpoint response definitions."""
        responses = endpoint.get('responses', {})
        method_upper = method.upper()
        endpoint_id = f"{method_upper} {path}"

        # Check for success response
        success_codes = ['200', '201', '202', '204']
        has_success = any(code in responses for code in success_codes)
        if not has_success:
            self.errors.append(
                f"{endpoint_id}: Missing success response (200/201/202/204)"
            )

        # Check for appropriate error responses
        if endpoint.get('security'):
            if '401' not in responses:
                self.warnings.append(
                    f"{endpoint_id}: Missing 401 (Unauthenticated) - recommended for authenticated endpoints"
                )

        # POST/PUT should have 422 for validation
        if method in ['post', 'put', 'patch']:
            if '422' not in responses:
                self.warnings.append(
                    f"{endpoint_id}: Missing 422 (Validation Failed) - recommended for data modification endpoints"
                )

        # Check error response schemas
        for code in ['401', '403', '404', '422', '500']:
            if code in responses:
                response = responses[code]
                if 'content' in response:
                    content = response['content'].get('application/json', {})
                    schema = content.get('schema', {})
                    if '$ref' in schema:
                        self.validate_schema_ref(schema['$ref'], endpoint_id, code)

    def validate_schema_ref(self, ref: str, endpoint_id: str, code: str):
        """Validate that schema reference exists."""
        if not ref.startswith('#/components/schemas/'):
            self.errors.append(
                f"{endpoint_id} {code}: Invalid schema reference format: {ref}"
            )
            return

        schema_name = ref.split('/')[-1]
        schemas = self.spec.get('components', {}).get('schemas', {})

        if schema_name not in schemas:
            self.errors.append(
                f"{endpoint_id} {code}: Schema '{schema_name}' not found in components.schemas"
            )

    def check_schemas(self):
        """Validate schema definitions."""
        schemas = self.spec.get('components', {}).get('schemas', {})
        self.stats['schemas'] = len(schemas)

        # Check ErrorResponse exists
        if 'ErrorResponse' not in schemas:
            self.errors.append("Missing ErrorResponse schema (required by CRITICAL-02)")

        # Check all schema references are defined
        for schema_name, schema in schemas.items():
            self.validate_schema_properties(schema_name, schema)

    def validate_schema_properties(self, schema_name: str, schema: Dict):
        """Recursively validate schema properties and references."""
        if 'properties' in schema:
            for prop_name, prop_schema in schema['properties'].items():
                self.check_schema_refs(prop_schema, f"schema:{schema_name}.properties.{prop_name}")

        if 'items' in schema:
            self.check_schema_refs(schema['items'], f"schema:{schema_name}.items")

    def check_schema_refs(self, obj, context: str):
        """Recursively check schema references exist."""
        if isinstance(obj, dict):
            if '$ref' in obj:
                ref = obj['$ref']
                if ref.startswith('#/components/schemas/'):
                    schema_name = ref.split('/')[-1]
                    schemas = self.spec.get('components', {}).get('schemas', {})
                    if schema_name not in schemas:
                        self.errors.append(
                            f"{context}: Schema reference '{schema_name}' not found"
                        )

            # Recursively check nested objects
            for key, value in obj.items():
                if key not in ['$ref', 'type', 'format', 'example', 'description']:
                    self.check_schema_refs(value, context)

        elif isinstance(obj, list):
            for item in obj:
                self.check_schema_refs(item, context)

    def check_error_responses(self):
        """Validate error response consistency (CRITICAL-02)."""
        error_response = self.spec.get('components', {}).get('schemas', {}).get('ErrorResponse')

        if not error_response:
            return

        # Check required properties
        required_properties = ['success', 'error_code', 'message']
        props = error_response.get('properties', {})

        for prop in required_properties:
            if prop not in props:
                self.errors.append(
                    f"ErrorResponse missing property: {prop} (required by CRITICAL-02)"
                )

        # Check error_code enum values
        error_code_prop = props.get('error_code', {})
        enum_values = error_code_prop.get('enum', [])
        expected_codes = [
            'UNAUTHENTICATED',
            'PERMISSION_DENIED',
            'RESOURCE_NOT_FOUND',
            'VALIDATION_FAILED',
            'INTERNAL_SERVER_ERROR'
        ]

        for code in expected_codes:
            if code not in enum_values:
                self.warnings.append(
                    f"ErrorResponse error_code enum missing: {code}"
                )

    def check_security(self):
        """Validate security scheme definitions (CRITICAL-03)."""
        schemes = self.spec.get('components', {}).get('securitySchemes', {})

        if not schemes:
            self.warnings.append(
                "No securitySchemes defined (should define at least 'sanctum')"
            )
            return

        if 'sanctum' not in schemes:
            self.warnings.append(
                "securitySchemes missing 'sanctum' (Bearer token) - required for CRITICAL-03"
            )

    def _is_public_endpoint(self, path: str) -> bool:
        """Check if endpoint should be public (no auth required)."""
        public_paths = ['/auth/login', '/health', '/status']
        return any(path.startswith(p) for p in public_paths)

    def report(self):
        """Print formatted validation report."""
        # Count tags
        tags = self.spec.get('tags', [])
        self.stats['tags'] = len(tags)

        # Header
        print("=" * 70)
        print("OpenAPI Validation Report")
        print("=" * 70)

        # Statistics
        print(f"\n📊 Statistics:")
        print(f"   Paths: {self.stats['paths']}")
        print(f"   Endpoints: {self.stats['endpoints']}")
        print(f"   Schemas: {self.stats['schemas']}")
        print(f"   Tags: {self.stats['tags']}")

        # Results
        print()
        if self.errors:
            print(f"❌ ERRORS ({len(self.errors)}):")
            for error in self.errors:
                print(f"   • {error}")
            print()

        if self.warnings:
            print(f"⚠️  WARNINGS ({len(self.warnings)}):")
            for warning in self.warnings:
                print(f"   • {warning}")
            print()

        # Summary
        if not self.errors and not self.warnings:
            print("✅ Validation passed - No issues found!\n")
            print("=" * 70 + "\n")
            return True

        print("=" * 70 + "\n")

        if self.errors:
            return False

        return True


def main():
    """Main entry point."""
    args = [a for a in sys.argv[1:] if a != '--json']
    json_output = '--json' in sys.argv

    if len(args) < 1:
        print("Usage: python openapi-validator.py <filepath> [--json]")
        print()
        print("Validates OpenAPI 3.0.0 specification against internal standards.")
        print()
        print("Options:")
        print("  --json    Emit a machine-readable JSON report (for CI, #220).")
        print()
        print("Example:")
        print("  python openapi-validator.py docs/openapi.yaml --json")
        sys.exit(1)

    filepath = args[0]
    validator = OpenAPIValidator(filepath)

    if not validator.validate(json_output=json_output):
        sys.exit(1)

    sys.exit(0)


if __name__ == '__main__':
    main()
