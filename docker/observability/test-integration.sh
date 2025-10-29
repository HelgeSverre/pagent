#!/usr/bin/env bash

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
COMPOSE_FILE="docker-compose.observability.yml"
MAX_WAIT=120
CHECK_INTERVAL=5

echo -e "${BLUE}╔════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║         Pagent OpenTelemetry Observability Stack Test          ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════════════════╝${NC}"
echo ""

# Function to check if Docker is running
check_docker() {
    if ! docker info > /dev/null 2>&1; then
        echo -e "${RED}✗ Docker is not running. Please start Docker and try again.${NC}"
        exit 1
    fi
    echo -e "${GREEN}✓ Docker is running${NC}"
}

# Function to check if docker-compose file exists
check_compose_file() {
    if [ ! -f "$COMPOSE_FILE" ]; then
        echo -e "${RED}✗ Docker Compose file not found: $COMPOSE_FILE${NC}"
        echo -e "${YELLOW}  Make sure you're running this from the project root directory.${NC}"
        exit 1
    fi
    echo -e "${GREEN}✓ Docker Compose file found${NC}"
}

# Function to start services
start_services() {
    echo ""
    echo -e "${BLUE}Starting observability services...${NC}"
    docker-compose -f "$COMPOSE_FILE" up -d
    echo -e "${GREEN}✓ Services started${NC}"
}

# Function to check service health
check_service_health() {
    local service=$1
    local url=$2
    local max_attempts=$((MAX_WAIT / CHECK_INTERVAL))
    local attempt=1

    echo -n -e "${YELLOW}  Waiting for $service to be healthy...${NC}"

    while [ $attempt -le $max_attempts ]; do
        if docker-compose -f "$COMPOSE_FILE" ps "$service" | grep -q "healthy\|running"; then
            if curl -sf "$url" > /dev/null 2>&1; then
                echo -e " ${GREEN}✓${NC}"
                return 0
            fi
        fi
        echo -n "."
        sleep $CHECK_INTERVAL
        ((attempt++))
    done

    echo -e " ${RED}✗ (timeout)${NC}"
    return 1
}

# Function to wait for all services
wait_for_services() {
    echo ""
    echo -e "${BLUE}Checking service health...${NC}"

    local all_healthy=true

    check_service_health "jaeger" "http://localhost:16686" || all_healthy=false
    check_service_health "phoenix" "http://localhost:6006" || all_healthy=false
    check_service_health "langfuse-db" "http://localhost:5432" || all_healthy=false
    check_service_health "langfuse" "http://localhost:3000/api/public/health" || all_healthy=false
    check_service_health "opik-clickhouse" "http://localhost:8123" || all_healthy=false
    check_service_health "opik-backend" "http://localhost:8080/health" || all_healthy=false
    check_service_health "opik-frontend" "http://localhost:5173" || all_healthy=false

    if [ "$all_healthy" = false ]; then
        echo ""
        echo -e "${YELLOW}⚠ Some services did not become healthy within the timeout.${NC}"
        echo -e "${YELLOW}  This might be okay - check the logs for more details:${NC}"
        echo -e "${YELLOW}  docker-compose -f $COMPOSE_FILE logs${NC}"
    else
        echo ""
        echo -e "${GREEN}✓ All services are healthy${NC}"
    fi
}

# Function to create test PHP script
create_test_script() {
    local script_path="/tmp/pagent-otel-test.php"

    cat > "$script_path" << 'EOF'
<?php

require __DIR__ . '/vendor/autoload.php';

use function Pagent\telemetry_otlp;
use function Pagent\agent;

// Get endpoint from environment or use default (Jaeger)
$endpoint = getenv('OTEL_ENDPOINT') ?: 'http://localhost:4318/v1/traces';

echo "Configuring OpenTelemetry endpoint: $endpoint\n";

// Configure telemetry
telemetry_otlp($endpoint, [
    'service.name' => 'pagent-integration-test',
    'service.version' => '0.7.0-dev',
    'deployment.environment' => 'test',
]);

echo "Creating agent...\n";

// Create a mock agent for testing (no API key required)
$agent = agent('mock')
    ->model('mock-model')
    ->systemPrompt('You are a helpful assistant for testing.')
    ->build();

echo "Sending test request...\n";

// Make a test request
$response = $agent->ask('What is 2 + 2?');

echo "Response received: " . substr($response, 0, 100) . "...\n";
echo "\n";
echo "✓ Test completed successfully!\n";
echo "  Traces should now be visible in the observability tools.\n";
EOF

    echo "$script_path"
}

# Function to run test with specific endpoint
run_test_with_endpoint() {
    local name=$1
    local endpoint=$2

    echo ""
    echo -e "${BLUE}Testing with $name...${NC}"

    if [ ! -f "vendor/autoload.php" ]; then
        echo -e "${YELLOW}  ⚠ Composer dependencies not found. Skipping test execution.${NC}"
        echo -e "${YELLOW}    Run 'composer install' to enable test execution.${NC}"
        return
    fi

    local script_path=$(create_test_script)

    if OTEL_ENDPOINT="$endpoint" php "$script_path" 2>&1; then
        echo -e "${GREEN}  ✓ Test completed successfully${NC}"
    else
        echo -e "${RED}  ✗ Test failed${NC}"
    fi

    rm -f "$script_path"
}

# Function to run integration tests
run_tests() {
    echo ""
    echo -e "${BLUE}╔════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${BLUE}║                     Running Integration Tests                  ║${NC}"
    echo -e "${BLUE}╚════════════════════════════════════════════════════════════════╝${NC}"

    # Test with each backend
    run_test_with_endpoint "Jaeger (OTLP HTTP)" "http://localhost:4318/v1/traces"
    run_test_with_endpoint "Phoenix" "http://localhost:6007/v1/traces"
    run_test_with_endpoint "Opik" "http://localhost:8080/v1/traces"

    echo ""
    echo -e "${YELLOW}Note: Langfuse requires authentication setup before use.${NC}"
    echo -e "${YELLOW}      Visit http://localhost:3000 to create an account first.${NC}"
}

# Function to display access information
show_access_info() {
    echo ""
    echo -e "${BLUE}╔════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${BLUE}║                      Service Access URLs                       ║${NC}"
    echo -e "${BLUE}╚════════════════════════════════════════════════════════════════╝${NC}"
    echo ""
    echo -e "${GREEN}Jaeger (Distributed Tracing)${NC}"
    echo -e "  UI:              http://localhost:16686"
    echo -e "  OTLP HTTP:       http://localhost:4318/v1/traces"
    echo -e "  OTLP gRPC:       http://localhost:4317"
    echo ""
    echo -e "${GREEN}Phoenix (LLM Observability)${NC}"
    echo -e "  UI:              http://localhost:6006"
    echo -e "  Collector:       http://localhost:6007/v1/traces"
    echo ""
    echo -e "${GREEN}Langfuse (LLM Monitoring)${NC}"
    echo -e "  UI:              http://localhost:3000"
    echo -e "  API:             http://localhost:3000/api/public"
    echo -e "  ${YELLOW}Note: Requires account creation on first use${NC}"
    echo ""
    echo -e "${GREEN}Opik (Experiment Tracking)${NC}"
    echo -e "  Frontend:        http://localhost:5173"
    echo -e "  Backend API:     http://localhost:8080"
    echo ""
}

# Function to show useful commands
show_commands() {
    echo -e "${BLUE}╔════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${BLUE}║                        Useful Commands                         ║${NC}"
    echo -e "${BLUE}╚════════════════════════════════════════════════════════════════╝${NC}"
    echo ""
    echo -e "${GREEN}View service status:${NC}"
    echo -e "  docker-compose -f $COMPOSE_FILE ps"
    echo ""
    echo -e "${GREEN}View logs:${NC}"
    echo -e "  docker-compose -f $COMPOSE_FILE logs -f [service-name]"
    echo ""
    echo -e "${GREEN}Stop all services:${NC}"
    echo -e "  docker-compose -f $COMPOSE_FILE down"
    echo ""
    echo -e "${GREEN}Stop and remove all data:${NC}"
    echo -e "  docker-compose -f $COMPOSE_FILE down -v"
    echo ""
    echo -e "${GREEN}Restart a specific service:${NC}"
    echo -e "  docker-compose -f $COMPOSE_FILE restart [service-name]"
    echo ""
    echo -e "${GREEN}View resource usage:${NC}"
    echo -e "  docker stats"
    echo ""
}

# Function to check service status
check_status() {
    echo ""
    echo -e "${BLUE}╔════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${BLUE}║                       Service Status                           ║${NC}"
    echo -e "${BLUE}╚════════════════════════════════════════════════════════════════╝${NC}"
    echo ""
    docker-compose -f "$COMPOSE_FILE" ps
}

# Main execution
main() {
    # Check prerequisites
    check_docker
    check_compose_file

    # Start services
    start_services

    # Wait for services to be healthy
    wait_for_services

    # Run integration tests
    run_tests

    # Show access information
    show_access_info

    # Show useful commands
    show_commands

    # Show current status
    check_status

    echo ""
    echo -e "${GREEN}╔════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║              Observability Stack Ready!                        ║${NC}"
    echo -e "${GREEN}╚════════════════════════════════════════════════════════════════╝${NC}"
    echo ""
}

# Run main function
main
