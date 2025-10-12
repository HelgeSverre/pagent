.PHONY: help install setup test analyse fix quality check hooks baseline clean

# Colors for pretty output
YELLOW := \033[1;33m
GREEN := \033[0;32m
RED := \033[0;31m
NC := \033[0m # No Color

# Default target
.DEFAULT_GOAL := help

help: ## Display this help message
	@echo "$(GREEN)Pagent Framework - Development Commands$(NC)"
	@echo ""
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(YELLOW)%-15s$(NC) %s\n", $$1, $$2}'
	@echo ""

install: ## Install dependencies
	@echo "$(GREEN)Installing dependencies...$(NC)"
	@composer install

setup: install hooks ## Complete initial setup (install + hooks)
	@echo "$(GREEN)Setup complete!$(NC)"
	@echo ""
	@echo "Try running: make quality"

hooks: ## Setup git pre-commit hooks
	@echo "$(GREEN)Setting up git hooks...$(NC)"
	@bash .githooks/setup.sh

test: ## Run all tests
	@echo "$(GREEN)Running tests...$(NC)"
	@composer test

test-coverage: ## Run tests with coverage report
	@echo "$(GREEN)Running tests with coverage...$(NC)"
	@composer test:coverage

test-unit: ## Run only unit tests
	@echo "$(GREEN)Running unit tests...$(NC)"
	@composer test:unit

test-integration: ## Run only integration tests
	@echo "$(GREEN)Running integration tests...$(NC)"
	@composer test:integration

analyse: ## Run static analysis
	@echo "$(GREEN)Running PHPStan analysis...$(NC)"
	@composer analyse

baseline: ## Generate PHPStan baseline
	@echo "$(YELLOW)Generating PHPStan baseline...$(NC)"
	@composer analyse:baseline
	@echo "$(GREEN)Baseline generated: phpstan-baseline.neon$(NC)"

fix: ## Auto-fix code style issues
	@echo "$(GREEN)Fixing code style...$(NC)"
	@composer fix

format: fix ## Alias for fix

format-check: ## Check code style without fixing
	@echo "$(GREEN)Checking code style...$(NC)"
	@composer format:check

check: ## Run all checks (format + analyse) without tests
	@echo "$(GREEN)Running all checks...$(NC)"
	@composer check

quality: ## Run full quality suite (format + analyse + tests)
	@echo "$(GREEN)Running full quality checks...$(NC)"
	@composer quality

quality-fix: ## Fix code style then run full quality suite
	@echo "$(GREEN)Fixing code and running quality checks...$(NC)"
	@composer quality:fix

insights: ## Run PHP Insights for code quality metrics
	@echo "$(GREEN)Running PHP Insights...$(NC)"
	@composer insights

insights-fix: ## Run PHP Insights with auto-fix
	@echo "$(GREEN)Running PHP Insights with auto-fix...$(NC)"
	@composer insights:fix

clean: ## Clean cache and generated files
	@echo "$(YELLOW)Cleaning cache files...$(NC)"
	@composer analyse:clear
	@rm -rf vendor/bin/.phpunit.result.cache
	@rm -rf .phpunit.cache
	@echo "$(GREEN)Cache cleaned!$(NC)"

ci: check test ## Run CI pipeline (check + test)
	@echo "$(GREEN)CI pipeline complete!$(NC)"

watch-test: ## Watch files and run tests on change (requires entr)
	@echo "$(YELLOW)Watching for changes... (press Ctrl+C to stop)$(NC)"
	@find src tests -name '*.php' | entr -c composer test

# Development workflow shortcuts
dev: fix test ## Quick dev cycle: fix code style and run tests
	@echo "$(GREEN)Development cycle complete!$(NC)"

pr: quality ## Prepare for PR: run full quality suite
	@echo "$(GREEN)Ready for PR!$(NC)"

quick: format-check analyse ## Quick check without running tests
	@echo "$(GREEN)Quick check complete!$(NC)"
