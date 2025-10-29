# Pagent Framework - Development Commands
# https://just.systems/man/en/

# Load environment variables from .env
set dotenv-load

# Show available commands by default (first recipe is default)
help:
    @just --list

# === Setup ===

[group('setup')]
install:
    @echo "Installing PHP dependencies..."
    composer install

[group('setup')]
hooks:
    @echo "Setting up git hooks..."
    bash .githooks/setup.sh

[group('setup')]
[doc('Complete initial setup (install + hooks)')]
setup: install hooks
    @echo "Setup complete!"
    @echo ""
    @echo "Try running: just quality"

# === Testing ===

[group('test')]
[doc('Run all tests')]
test:
    @echo "Running tests..."
    composer test

[group('test')]
[doc('Run tests with coverage report')]
test-coverage:
    #!/usr/bin/env bash
    set -euo pipefail
    if command -v herd &> /dev/null; then
        echo "Running tests with coverage using Herd..."
        herd coverage vendor/bin/pest --coverage --min=80
    else
        echo "Running tests with coverage using composer..."
        echo "Note: Requires Xdebug or PCOV to be installed"
        composer test:coverage
    fi

[group('test')]
[doc('Run only unit tests')]
test-unit:
    @echo "Running unit tests..."
    composer test:unit

[group('test')]
[doc('Run only integration tests')]
test-integration:
    @echo "Running integration tests..."
    composer test:integration

[group('test')]
[doc('Watch files and run tests on change (requires entr)')]
watch-test:
    @echo "Watching for changes... (press Ctrl+C to stop)"
    @find src tests -name '*.php' | entr -c composer test

# === Static Analysis ===

[group('analyse')]
[doc('Run PHPStan static analysis')]
analyse:
    @echo "Running PHPStan analysis..."
    composer analyse

[group('analyse')]
[doc('Generate PHPStan baseline')]
baseline:
    @echo "Generating PHPStan baseline..."
    composer analyse:baseline
    @echo "Baseline generated: phpstan-baseline.neon"

# === Code Style ===

[group('format')]
[doc('Auto-fix code style issues (PHP + Markdown)')]
fix:
    @echo "Fixing PHP code style..."
    composer format
    @echo "Formatting markdown files..."
    @just _format-markdown write

[group('format')]
[doc('Alias for fix')]
format: fix

[group('format')]
[doc('Check code style without fixing (PHP + Markdown)')]
format-check:
    @echo "Checking PHP code style..."
    composer format:check
    @echo "Checking markdown formatting..."
    @just _format-markdown check

# Private helper to format markdown with available package manager
[private]
_format-markdown mode:
    #!/usr/bin/env bash
    set -euo pipefail

    # Check for available package managers in order of preference
    if command -v bunx &> /dev/null; then
        if [ "{{mode}}" = "write" ]; then
            bunx prettier **/*.md --write --log-level warn 2>/dev/null || true
        else
            bunx prettier **/*.md --check 2>/dev/null || true
        fi
    elif command -v pnpm &> /dev/null; then
        if [ "{{mode}}" = "write" ]; then
            pnpm dlx prettier **/*.md --write --log-level warn 2>/dev/null || true
        else
            pnpm dlx prettier **/*.md --check 2>/dev/null || true
        fi
    elif command -v yarn &> /dev/null; then
        if [ "{{mode}}" = "write" ]; then
            yarn dlx prettier **/*.md --write --log-level warn 2>/dev/null || true
        else
            yarn dlx prettier **/*.md --check 2>/dev/null || true
        fi
    elif command -v npx &> /dev/null; then
        if [ "{{mode}}" = "write" ]; then
            npx prettier **/*.md --write --log-level warn 2>/dev/null || true
        else
            npx prettier **/*.md --check 2>/dev/null || true
        fi
    else
        echo "⚠️  No package manager found (bun/pnpm/yarn/npm) - skipping markdown formatting"
    fi

# === Quality Checks ===

[group('quality')]
[doc('Run all checks (format + analyse) without tests')]
check:
    @echo "Running all checks..."
    composer check

[group('quality')]
[doc('Run full quality suite (format + analyse + tests)')]
quality:
    @echo "Running full quality checks..."
    composer quality

[group('quality')]
[doc('Fix code style then run full quality suite')]
quality-fix:
    @echo "Fixing code and running quality checks..."
    composer quality:fix

[group('quality')]
[doc('Run PHP Insights for code quality metrics')]
insights:
    @echo "Running PHP Insights..."
    composer insights

[group('quality')]
[doc('Run PHP Insights with auto-fix')]
insights-fix:
    @echo "Running PHP Insights with auto-fix..."
    composer insights:fix

# === Cleanup ===

[group('workflow')]
[doc('Clean cache and generated files')]
clean:
    @echo "Cleaning cache files..."
    composer analyse:clear
    @rm -rf vendor/bin/.phpunit.result.cache
    @rm -rf .phpunit.cache
    @echo "Cache cleaned!"

# === CI/CD ===

[group('ci')]
[doc('Run CI pipeline (check + test)')]
ci: check test
    @echo "CI pipeline complete!"

# === Observability Stack ===

[group('observability')]
[doc('Start observability stack (Jaeger, Phoenix, Langfuse, Opik, Helicone)')]
observability-up:
    @echo "Starting observability stack..."
    docker compose -f docker-compose.observability.yml up -d
    @echo ""
    @just observability-urls

[group('observability')]
[doc('Stop observability stack')]
observability-down:
    @echo "Stopping observability stack..."
    docker compose -f docker-compose.observability.yml down
    @echo "Observability stack stopped!"

[group('observability')]
[doc('View observability stack logs')]
observability-logs:
    docker compose -f docker-compose.observability.yml logs -f

[group('observability')]
[doc('Show observability service URLs')]
observability-urls:
    #!/usr/bin/env bash
    echo ""
    echo "╔════════════════════════════════════════════════════════════════╗"
    echo "║          Observability Stack - Service URLs                   ║"
    echo "╠════════════════════════════════════════════════════════════════╣"
    echo "║ Jaeger (Distributed Tracing)                                  ║"
    echo "║   UI:      http://localhost:16686                             ║"
    echo "║   OTLP:    http://localhost:4318 (HTTP), :4317 (gRPC)         ║"
    echo "╠════════════════════════════════════════════════════════════════╣"
    echo "║ Phoenix (LLM Observability - Arize)                           ║"
    echo "║   UI:      http://localhost:6006                              ║"
    echo "║   OTLP:    http://localhost:6007                              ║"
    echo "╠════════════════════════════════════════════════════════════════╣"
    echo "║ Langfuse (LLM Monitoring & Prompts)                           ║"
    echo "║   UI:      http://localhost:3000                              ║"
    echo "╠════════════════════════════════════════════════════════════════╣"
    echo "║ Helicone (LLM Cost Tracking)                                  ║"
    echo "║   UI:      http://localhost:3001                              ║"
    echo "║   Gateway: http://localhost:8585                              ║"
    echo "╠════════════════════════════════════════════════════════════════╣"
    echo "║ Opik (LLM Experiment Tracking - Comet)                        ║"
    echo "║   UI:      http://localhost:5173                              ║"
    echo "║   API:     http://localhost:8080                              ║"
    echo "╚════════════════════════════════════════════════════════════════╝"
    echo ""

[group('observability')]
[doc('Restart observability stack')]
observability-restart:
    @just observability-down
    @just observability-up

[group('observability')]
[doc('Run observability integration tests')]
observability-test:
    @echo "Starting observability stack..."
    @just observability-up
    @echo "Waiting for services to be ready..."
    @sleep 10
    @echo "Running observability tests..."
    composer test:observability

# === Development Workflows ===

[group('workflow')]
[doc('Quick dev cycle: fix code style and run tests')]
dev: fix test
    @echo "Development cycle complete!"

[group('workflow')]
[doc('Prepare for PR: run full quality suite')]
pr: quality
    @echo "Ready for PR!"

[group('workflow')]
[doc('Quick check without running tests')]
quick: format-check analyse
    @echo "Quick check complete!"
