#!/bin/bash

##############################################################################
# OpenAPI Client SDK Generation Script
#
# Generates client SDKs in multiple languages from the OpenAPI specification.
# Usage: ./scripts/generate-sdks.sh [language]
#
# Examples:
#   ./scripts/generate-sdks.sh                 # Generate all SDKs
#   ./scripts/generate-sdks.sh typescript-fetch # Generate only TypeScript
#
##############################################################################

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
SPEC="docs/openapi-full.yaml"
GENERATORS=(
  "typescript-fetch"
  "python"
  "javascript"
  "go"
  "swift5"
  "java"
)

# Parse arguments
SELECTED_GENERATORS=()
if [ $# -gt 0 ]; then
  SELECTED_GENERATORS=("$@")
else
  SELECTED_GENERATORS=("${GENERATORS[@]}")
fi

##############################################################################
# Functions
##############################################################################

log_header() {
  echo -e "\n${BLUE}============================================================${NC}"
  echo -e "${BLUE}$1${NC}"
  echo -e "${BLUE}============================================================${NC}\n"
}

log_info() {
  echo -e "${BLUE}ℹ️  $1${NC}"
}

log_success() {
  echo -e "${GREEN}✅ $1${NC}"
}

log_warning() {
  echo -e "${YELLOW}⚠️  $1${NC}"
}

log_error() {
  echo -e "${RED}❌ $1${NC}"
}

check_prerequisites() {
  log_header "Checking Prerequisites"

  # Check OpenAPI spec exists
  if [ ! -f "$SPEC" ]; then
    log_error "OpenAPI spec not found: $SPEC"
    exit 1
  fi
  log_success "Found OpenAPI spec: $SPEC"

  # Check for swagger-cli
  if ! command -v swagger-cli &> /dev/null; then
    log_warning "swagger-cli not found. Installing..."
    npm install -g swagger-cli
  else
    log_success "swagger-cli is installed"
  fi

  # Check for openapi-generator-cli
  if ! command -v openapi-generator-cli &> /dev/null; then
    log_warning "openapi-generator-cli not found. Installing..."
    npm install -g @openapitools/openapi-generator-cli
  else
    log_success "openapi-generator-cli is installed"
  fi
}

validate_spec() {
  log_header "Validating OpenAPI Specification"

  if swagger-cli validate "$SPEC" 2>&1 | grep -q "is valid"; then
    log_success "OpenAPI spec is valid"
  else
    log_error "OpenAPI spec validation failed"
    swagger-cli validate "$SPEC"
    exit 1
  fi
}

generate_sdk() {
  local generator="$1"
  local output_dir="client-sdk/${generator%-*}"

  # Map generator names to output directories
  case "$generator" in
    typescript-fetch)
      output_dir="client-sdk/typescript"
      ;;
    javascript)
      output_dir="client-sdk/javascript"
      ;;
    python)
      output_dir="client-sdk/python"
      ;;
    go)
      output_dir="client-sdk/go"
      ;;
    swift5)
      output_dir="client-sdk/swift"
      ;;
    java)
      output_dir="client-sdk/java"
      ;;
  esac

  log_info "Generating $generator SDK..."
  log_info "Output directory: $output_dir"

  # Generate SDK
  if openapi-generator-cli generate \
    -i "$SPEC" \
    -g "$generator" \
    -o "$output_dir" \
    --skip-validate-spec \
    --additional-properties="packageName=lms-api,packageVersion=1.0.0" \
    > /dev/null 2>&1; then

    log_success "Generated: $output_dir"
    return 0
  else
    log_error "Failed to generate: $generator"
    openapi-generator-cli generate \
      -i "$SPEC" \
      -g "$generator" \
      -o "$output_dir" \
      --skip-validate-spec \
      --additional-properties="packageName=lms-api,packageVersion=1.0.0"
    return 1
  fi
}

generate_all_sdks() {
  local failed=()
  local generated=()

  log_header "Generating Client SDKs"

  for generator in "${SELECTED_GENERATORS[@]}"; do
    # Validate generator exists
    if [[ ! " ${GENERATORS[@]} " =~ " ${generator} " ]]; then
      log_warning "Unknown generator: $generator"
      log_info "Available generators: ${GENERATORS[*]}"
      continue
    fi

    if generate_sdk "$generator"; then
      generated+=("$generator")
    else
      failed+=("$generator")
    fi
  done

  # Summary
  log_header "Generation Summary"

  if [ ${#generated[@]} -gt 0 ]; then
    log_success "Successfully generated ${#generated[@]} SDK(s):"
    for sdk in "${generated[@]}"; do
      echo "  ✓ $sdk"
    done
    echo ""
  fi

  if [ ${#failed[@]} -gt 0 ]; then
    log_error "Failed to generate ${#failed[@]} SDK(s):"
    for sdk in "${failed[@]}"; do
      echo "  ✗ $sdk"
    done
    echo ""
    return 1
  fi

  return 0
}

show_usage() {
  echo "Usage: $0 [language...]"
  echo ""
  echo "Generates client SDKs from OpenAPI specification."
  echo ""
  echo "Examples:"
  echo "  $0                              # Generate all SDKs"
  echo "  $0 typescript-fetch python      # Generate specific SDKs"
  echo ""
  echo "Available languages:"
  for gen in "${GENERATORS[@]}"; do
    echo "  - $gen"
  done
  echo ""
  echo "Requirements:"
  echo "  - npm (for openapi-generator-cli)"
  echo "  - swagger-cli (validates OpenAPI spec)"
  echo ""
}

##############################################################################
# Main Script
##############################################################################

# Show help if requested
if [[ "$1" == "-h" || "$1" == "--help" ]]; then
  show_usage
  exit 0
fi

# Run generation process
check_prerequisites
validate_spec
generate_all_sdks

exit $?
