# Pagent Framework - Development Commands
# https://just.systems/man/en/

# Load environment variables from .env
set dotenv-load

# Show available commands by default (first recipe is default)
help:
    @just --list

# === Setup ===

[group('setup')]
[doc('Install dependencies and setup git hooks')]
setup:
    @echo "Installing PHP dependencies..."
    composer install
    @echo "Setting up git hooks..."
    bash .githooks/setup.sh
    @echo "Setup complete!"

# === Testing ===

[group('test')]
[doc('Run all tests')]
test:
    @echo "Running tests..."
    composer test

[group('test')]
[doc('Run tests with coverage report')]
coverage:
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

[private]
test-unit:
    @echo "Running unit tests..."
    composer test:unit

[private]
test-integration:
    @echo "Running integration tests..."
    composer test:integration

# === Static Analysis ===

[group('quality')]
[doc('Run PHPStan static analysis')]
analyse:
    @echo "Running PHPStan analysis..."
    composer analyse

[private]
baseline:
    @echo "Generating PHPStan baseline..."
    composer analyse:baseline
    @echo "Baseline generated: phpstan-baseline.neon"

# === Code Style ===

[group('quality')]
[doc('Fix code style (PHP + Markdown)')]
format:
    @echo "Fixing PHP code style..."
    composer format
    @echo "Formatting markdown files..."
    @just _format-markdown write

[private]
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
[doc('Prepare for PR: fix code, analyse, and test')]
pr:
    @echo "Fixing code and running quality checks..."
    composer quality:fix

[private]
check:
    @echo "Running all checks..."
    composer check

[private]
quality:
    @echo "Running full quality checks..."
    composer quality

[private]
clean:
    @echo "Cleaning cache files..."
    composer analyse:clear
    @rm -rf vendor/bin/.phpunit.result.cache
    @rm -rf .phpunit.cache
    @echo "Cache cleaned!"

# === GitHub Actions ===

[group('ci')]
[doc('List all GitHub Actions workflows')]
actions-list:
    @echo "Available workflows:"
    @act -l --container-architecture linux/amd64

[group('ci')]
[doc('Test GitHub Actions workflows locally (dry-run)')]
actions-test:
    @echo "Testing GitHub Actions workflows locally..."
    @echo ""
    @echo "Testing tests workflow (push event)..."
    act push -n -W .github/workflows/tests.yml --container-architecture linux/amd64 || true
    @echo ""
    @echo "Testing release-drafter workflow (push event)..."
    act push -n -W .github/workflows/release-drafter.yml --container-architecture linux/amd64 || true

[group('ci')]
[doc('Run GitHub Actions tests workflow locally')]
actions-run-tests:
    @echo "Running tests workflow locally..."
    act push -W .github/workflows/tests.yml --container-architecture linux/amd64

[group('ci')]
[doc('Run specific GitHub Actions job')]
actions-run job:
    @echo "Running job: {{job}}"
    act -j {{job}} --container-architecture linux/amd64

# === MCP Test Server ===

[group('mcp')]
[doc('Start MCP test server (Everything server on port 3333)')]
mcp-up:
    #!/usr/bin/env bash
    set -euo pipefail
    echo "Starting MCP test server (Everything server) on port 3333..."

    # Check if mcp-proxy is installed
    if ! command -v mcp-proxy &> /dev/null; then
        echo "❌ mcp-proxy not found. Install with: pip install mcp-proxy"
        exit 1
    fi

    # Check if server is already running
    if curl -s http://localhost:3333/sse -H "Accept: text/event-stream" --max-time 1 2>/dev/null | grep -q "event: endpoint"; then
        echo "✓ MCP server already running on port 3333"
        exit 0
    fi

    # Start the server in background
    nohup mcp-proxy --port 3333 -- npx -y @modelcontextprotocol/server-everything > /tmp/mcp-server.log 2>&1 &
    echo $! > /tmp/mcp-server.pid

    # Wait for server to be ready
    echo "Waiting for server to start..."
    for i in {1..15}; do
        if curl -s http://localhost:3333/sse -H "Accept: text/event-stream" --max-time 1 2>/dev/null | grep -q "event: endpoint"; then
            echo "✓ MCP test server started successfully!"
            echo "  URL: http://localhost:3333"
            echo "  Log: /tmp/mcp-server.log"
            exit 0
        fi
        sleep 1
    done

    echo "❌ Failed to start MCP server. Check /tmp/mcp-server.log"
    exit 1

[group('mcp')]
[doc('Stop MCP test server')]
mcp-down:
    #!/usr/bin/env bash
    set -euo pipefail
    echo "Stopping MCP test server..."

    if [ -f /tmp/mcp-server.pid ]; then
        pid=$(cat /tmp/mcp-server.pid)
        if kill -0 "$pid" 2>/dev/null; then
            kill "$pid" 2>/dev/null || true
            rm -f /tmp/mcp-server.pid
            echo "✓ MCP server stopped (PID: $pid)"
        else
            rm -f /tmp/mcp-server.pid
            echo "✓ MCP server was not running"
        fi
    else
        # Try to find and kill any mcp-proxy process on port 3333
        pkill -f "mcp-proxy.*3333" 2>/dev/null || true
        echo "✓ MCP server cleanup complete"
    fi

[group('mcp')]
[doc('Show MCP test server status')]
mcp-status:
    #!/usr/bin/env bash
    echo "MCP Test Server Status:"
    echo ""
    if curl -s http://localhost:3333/sse -H "Accept: text/event-stream" --max-time 1 2>/dev/null | grep -q "event: endpoint"; then
        echo "✓ Server is RUNNING on http://localhost:3333"
        if [ -f /tmp/mcp-server.pid ]; then
            echo "  PID: $(cat /tmp/mcp-server.pid)"
        fi
    else
        echo "✗ Server is NOT RUNNING"
        echo ""
        echo "Start with: just mcp-up"
    fi

# === Observability Stack ===

[group('observability')]
[doc('Start observability stack')]
obs-up:
    @echo "Starting observability stack..."
    docker compose -f docker-compose.observability.yml --profile all up -d
    @echo ""
    @just _obs-urls

[group('observability')]
[doc('Stop and remove observability stack')]
obs-down:
    @echo "Stopping observability stack..."
    docker compose -f docker-compose.observability.yml --profile all down -v
    @echo "Observability stack stopped and volumes removed!"

[private]
_obs-urls:
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
