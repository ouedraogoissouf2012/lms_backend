# Client SDK Generation from OpenAPI

This guide explains how to automatically generate client SDKs in multiple languages from the OpenAPI specification.

## Overview

Having a well-maintained OpenAPI spec enables automatic generation of type-safe client libraries for any language. This reduces duplication, improves consistency, and accelerates client development.

## Available Tools

### OpenAPI Generator (Recommended)

Universal tool supporting 50+ languages and frameworks.

**Installation**:
```bash
# Via homebrew (macOS)
brew install openapi-generator

# Via npm
npm install -g @openapitools/openapi-generator-cli

# Via Docker (no installation needed)
docker run --rm -v ${PWD}:/local openapitools/openapi-generator-cli generate \
  -i /local/docs/openapi-full.yaml \
  -g typescript-fetch \
  -o /local/client-sdk
```

## Generate TypeScript SDK

**Use Case**: Web frontend, Node.js backend, React Native

```bash
# Install generator
npm install -g @openapitools/openapi-generator-cli

# Generate
openapi-generator-cli generate \
  -i docs/openapi-full.yaml \
  -g typescript-fetch \
  -o client-sdk/typescript \
  --additional-properties="npmName=@lms/api,npmVersion=1.0.0,supportsES6=true"
```

**Output Structure**:
```
client-sdk/typescript/
├── models/
│   ├── ErrorResponse.ts
│   ├── User.ts
│   ├── Evaluation.ts
│   └── ...
├── apis/
│   ├── AuthenticationApi.ts
│   ├── EvaluationsApi.ts
│   ├── ProxyApi.ts
│   └── ...
├── index.ts
├── package.json
└── README.md
```

**Usage**:
```typescript
import { AuthenticationApi, Configuration } from '@lms/api';

const config = new Configuration({
  basePath: 'http://localhost:8000/api',
  accessToken: 'your-sanctum-token',
});

const auth = new AuthenticationApi(config);

// Fully typed
const user = await auth.getAuthenticatedUser();
console.log(user.data.email);
```

## Generate Python SDK

**Use Case**: Data science, backend services, automation scripts

```bash
openapi-generator-cli generate \
  -i docs/openapi-full.yaml \
  -g python \
  -o client-sdk/python \
  --additional-properties="packageName=lms_api,packageVersion=1.0.0"
```

**Usage**:
```python
from lms_api.api.authentication_api import AuthenticationApi
from lms_api.configuration import Configuration

config = Configuration(
    host='http://localhost:8000/api',
    access_token='your-sanctum-token',
)

auth = AuthenticationApi(config)
user = auth.get_authenticated_user()
print(user.data.email)
```

## Generate JavaScript/Node.js SDK

**Use Case**: JavaScript/Node.js backends, full-stack development

```bash
openapi-generator-cli generate \
  -i docs/openapi-full.yaml \
  -g javascript \
  -o client-sdk/javascript \
  --additional-properties="npmName=lms-api,npmVersion=1.0.0"
```

**Usage**:
```javascript
const { AuthenticationApi, Configuration } = require('lms-api');

const config = new Configuration({
  basePath: 'http://localhost:8000/api',
  accessToken: 'your-sanctum-token',
});

const auth = new AuthenticationApi(config);

auth.getAuthenticatedUser().then(user => {
  console.log(user.data.email);
});
```

## Generate Go SDK

**Use Case**: High-performance services, system tools

```bash
openapi-generator-cli generate \
  -i docs/openapi-full.yaml \
  -g go \
  -o client-sdk/go \
  --additional-properties="packageName=lmsapi,packageVersion=1.0.0"
```

**Usage**:
```go
package main

import (
  "fmt"
  lmsapi "github.com/example/lms-api"
)

func main() {
  config := lmsapi.NewConfiguration()
  config.Host = "http://localhost:8000/api"
  config.AccessToken = "your-sanctum-token"
  
  client := lmsapi.NewAPIClient(config)
  user, _, _ := client.AuthenticationAPI.GetAuthenticatedUser(context.Background()).Execute()
  
  fmt.Println(user.Data.Email)
}
```

## Generate Java SDK

**Use Case**: Android apps, enterprise Java applications

```bash
openapi-generator-cli generate \
  -i docs/openapi-full.yaml \
  -g java \
  -o client-sdk/java \
  --additional-properties="packageName=com.lms.api,artifactVersion=1.0.0"
```

**Usage**:
```java
import com.lms.api.client.ApiClient;
import com.lms.api.client.api.AuthenticationApi;

public class App {
  public static void main(String[] args) {
    ApiClient client = new ApiClient();
    client.setBasePath("http://localhost:8000/api");
    client.setAccessToken("your-sanctum-token");
    
    AuthenticationApi auth = new AuthenticationApi(client);
    AuthResponse response = auth.getAuthenticatedUser();
    System.out.println(response.getData().getEmail());
  }
}
```

## Generate Swift SDK

**Use Case**: iOS/macOS applications

```bash
openapi-generator-cli generate \
  -i docs/openapi-full.yaml \
  -g swift5 \
  -o client-sdk/swift \
  --additional-properties="packageName=LmsApi"
```

**Usage**:
```swift
import LmsApi

let config = OpenAPIClientConfiguration(host: "http://localhost:8000/api")
config.accessToken = "your-sanctum-token"

AuthenticationAPI.getAuthenticatedUser { response, error in
  if let user = response?.data {
    print(user.email)
  }
}
```

## Automated SDK Generation Workflow

### Setup in CI/CD

Create: `.github/workflows/generate-sdks.yml`

```yaml
name: Generate Client SDKs

on:
  push:
    branches:
      - main
    paths:
      - 'docs/openapi-full.yaml'

jobs:
  generate:
    runs-on: ubuntu-latest
    
    strategy:
      matrix:
        sdk: [typescript-fetch, python, javascript, go, swift5, java]
    
    steps:
      - uses: actions/checkout@v3
      
      - name: Generate ${{ matrix.sdk }} SDK
        uses: openapi-generators/openapi-generator-action@v1
        with:
          openapi-file: docs/openapi-full.yaml
          generator: ${{ matrix.sdk }}
          command-args: |
            --additional-properties="packageName=lms-api"
      
      - name: Publish SDK
        run: |
          # For NPM packages
          if [ "${{ matrix.sdk }}" = "typescript-fetch" ] || [ "${{ matrix.sdk }}" = "javascript" ]; then
            cd client-sdk/${{ matrix.sdk }}
            npm publish
          fi
          
          # For Python packages
          if [ "${{ matrix.sdk }}" = "python" ]; then
            cd client-sdk/python
            python -m pip install --upgrade setuptools wheel twine
            python setup.py sdist bdist_wheel
            twine upload dist/*
          fi
```

### Manual Generation Script

Create: `scripts/generate-sdks.sh`

```bash
#!/bin/bash

set -e

SPEC="docs/openapi-full.yaml"

# Languages to generate
GENERATORS=(
  "typescript-fetch"
  "python"
  "javascript"
  "go"
  "swift5"
  "java"
)

echo "🔧 Generating Client SDKs from OpenAPI spec..."
echo "Spec file: $SPEC"
echo ""

# Validate spec first
echo "📋 Validating OpenAPI spec..."
swagger-cli validate "$SPEC" || exit 1
echo "✅ Spec is valid"
echo ""

# Generate each SDK
for generator in "${GENERATORS[@]}"; do
  output_dir="client-sdk/${generator%-*}"  # Remove suffix for cleaner dir names
  
  echo "🚀 Generating $generator SDK..."
  openapi-generator-cli generate \
    -i "$SPEC" \
    -g "$generator" \
    -o "$output_dir" \
    --skip-validate-spec \
    -DpackageName=lms-api \
    -DpackageVersion=1.0.0
  
  if [ $? -eq 0 ]; then
    echo "✅ Generated: $output_dir"
  else
    echo "❌ Failed to generate: $generator"
    exit 1
  fi
done

echo ""
echo "✨ All SDKs generated successfully!"
echo ""
echo "📦 Generated SDKs:"
ls -la client-sdk/
```

**Run it**:
```bash
chmod +x scripts/generate-sdks.sh
./scripts/generate-sdks.sh
```

## Best Practices

### 1. Version SDK Separately from API

```bash
# API version in openapi.yaml
info:
  version: "1.0.0"  # API version

# SDK version in generation config
--additional-properties="packageVersion=1.5.2"  # SDK version can differ
```

This allows SDKs to have their own release cycles and bug fixes.

### 2. Regenerate Before Publishing

Always regenerate SDKs before releasing a new API version:

```bash
# Validate OpenAPI
swagger-cli validate docs/openapi-full.yaml

# Generate all SDKs
./scripts/generate-sdks.sh

# Commit generated files
git add client-sdk/
git commit -m "chore: Regenerate SDKs for API v1.0.0"
```

### 3. Keep Generated Code in Version Control

While generated, include SDKs in git to:
- Track changes between API versions
- Enable diff review of SDK changes
- Ensure reproducible builds

```bash
git add client-sdk/
git commit -m "chore: Update generated SDKs"
```

### 4. Document SDK-Specific Configuration

Create README for each SDK:

```markdown
# LMS API - TypeScript SDK

Generated from OpenAPI 3.0.0 spec.

## Installation

npm install @lms/api

## Usage

import { AuthenticationApi, Configuration } from '@lms/api';

const config = new Configuration({
  basePath: 'http://localhost:8000/api',
  accessToken: token,
});

const api = new AuthenticationApi(config);
const user = await api.getAuthenticatedUser();
```

### 5. Use Consistent Package Naming

Across all languages, use consistent naming:
- **NPM**: `@lms/api`
- **PyPI**: `lms-api`
- **Maven**: `com.lms:api`
- **Go**: `github.com/example/lms-api`

## Validation Before Generation

Always validate before generating:

```bash
# 1. Check syntax
swagger-cli validate docs/openapi-full.yaml

# 2. Check for generation issues
openapi-generator-cli validate \
  -i docs/openapi-full.yaml \
  -g typescript-fetch

# 3. Custom validation
python scripts/openapi-validator.py docs/openapi-full.yaml
```

## Troubleshooting Generation

### "Schema X not found"
Caused by broken `$ref:` in OpenAPI spec.

```yaml
# Fix: Ensure all references exist
responses:
  '200':
    schema:
      $ref: '#/components/schemas/Evaluation'  # Evaluation must exist
```

### "Model generation failed"
Usually means invalid schema definition.

```yaml
# Bad
properties:
  id
    type: integer

# Good
properties:
  id:
    type: integer
```

### Generated code doesn't compile
Update generator to latest version:

```bash
npm install -g @openapitools/openapi-generator-cli@latest
openapi-generator-cli update
```

## SDK Features

Generated SDKs include:

✅ **Type safety** - Full type checking for models and responses
✅ **Request/response models** - Auto-generated from schemas
✅ **API methods** - One method per endpoint
✅ **Error handling** - Typed error responses
✅ **Documentation** - Generated from OpenAPI descriptions
✅ **Examples** - Sample usage in README
✅ **Tests** - Basic test stubs

## Publishing SDKs

### NPM (TypeScript/JavaScript)
```bash
cd client-sdk/typescript
npm publish --access public
```

### PyPI (Python)
```bash
cd client-sdk/python
python setup.py sdist bdist_wheel
twine upload dist/*
```

### Maven Central (Java)
Follow Maven Central publishing guide with generated `pom.xml`.

### GitHub Releases (Go)
Create release with SDK as asset.

## Long-term Benefits

By maintaining a quality OpenAPI spec and auto-generating SDKs:

📈 **Reduce bugs** - Type-safe generated code vs. hand-written clients
📚 **Speed up development** - No manual API client coding
🔄 **Stay in sync** - SDKs always match current API
🌍 **Support any language** - Generate whatever your customers need
📖 **Better documentation** - SDK examples generated from spec
🤝 **Improve DX** - Consistent APIs across languages

## References

- [OpenAPI Generator](https://openapi-generator.tech/)
- [OpenAPI 3.0.0 Spec](https://spec.openapis.org/oas/v3.0.0)
- [Generator Config Options](https://openapi-generator.tech/docs/generators)
- API Maintenance: [API_MAINTENANCE_GUIDE.md](API_MAINTENANCE_GUIDE.md)
- Adding Endpoints: [ADDING_NEW_ENDPOINTS.md](ADDING_NEW_ENDPOINTS.md)
