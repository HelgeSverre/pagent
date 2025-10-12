# Developer Experience Setup

This guide explains the optimized development setup for Pagent.

## Quick Start

### One-Command Setup

```bash
make setup
```

This will:

1. Install all dependencies
2. Configure git pre-commit hooks
3. Initialize testing framework

### Manual Setup

If you prefer manual setup:

```bash
# 1. Install dependencies
composer install

# 2. Setup git hooks
bash .githooks/setup.sh

# 3. Verify installation
make help
```

## What's Included

### Code Quality Tools

#### 1. Laravel Pint (Code Formatter)

- **Preset:** PER (PHP Evolving Recommendation)
- **Config:** `pint.json`
- **Run:** `make fix` or `composer fix`

#### 2. PHPStan (Static Analysis)

- **Level:** 9 (maximum strictness)
- **Config:** `phpstan.neon`
- **Run:** `make analyse` or `composer analyse`
- **Baseline:** `phpstan-baseline.neon` (tracks accepted issues)

#### 3. Pest (Testing Framework)

- **Config:** `phpunit.xml`
- **Run:** `make test` or `composer test`

#### 4. Git Pre-commit Hooks

- Automatic code quality checks before commits
- Location: `.githooks/`
- Checks: PHP syntax, code style, static analysis

### Composer Scripts

The following Composer scripts are available:

```json
{
  "test": "Run all tests",
  "test:coverage": "Run tests with coverage (min 80%)",
  "test:unit": "Run unit tests only",
  "test:integration": "Run integration tests only",
  "analyse": "Run PHPStan analysis",
  "analyse:baseline": "Generate PHPStan baseline",
  "analyse:clear": "Clear PHPStan cache",
  "fix": "Auto-fix code style",
  "fix:dry": "Check code style without fixing",
  "format": "Alias for fix",
  "format:check": "Check formatting without fixing",
  "quality": "Run format check + analyse + tests",
  "quality:fix": "Fix format + analyse + tests",
  "check": "Run format check + analyse (no tests)",
  "insights": "Run PHP Insights for metrics",
  "insights:fix": "Run PHP Insights with auto-fix"
}
```

### Makefile Commands

For easier command execution, use the Makefile:

```bash
make help           # Show all available commands
make install        # Install dependencies
make setup          # Complete initial setup
make test           # Run all tests
make test-coverage  # Run tests with coverage
make test-unit      # Run unit tests
make test-integration # Run integration tests
make analyse        # Run static analysis
make baseline       # Generate PHPStan baseline
make fix            # Auto-fix code style
make format-check   # Check code style
make check          # Run all checks (no tests)
make quality        # Full quality suite
make quality-fix    # Fix + quality suite
make insights       # Run PHP Insights
make clean          # Clean cache files
make ci             # Run CI pipeline
make dev            # Quick dev cycle (fix + test)
make pr             # Prepare for PR
make quick          # Quick check (no tests)
```

## Development Workflows

### Daily Development

```bash
# Start working
git checkout -b feature/my-feature

# Make changes, then frequently run:
make dev  # Runs: fix code style + run tests

# Before committing:
make check  # Runs: format check + static analysis
```

### Before Creating a Pull Request

```bash
# Run full quality suite
make pr
# or
make quality

# Check coverage
make test-coverage

# Review changes
git diff main...HEAD
```

### Fixing Code Quality Issues

```bash
# Auto-fix code style
make fix

# Clear analysis cache and re-run
make clean
make analyse

# Generate baseline for accepted issues
make baseline
```

## Configuration Files

### pint.json

Defines code style rules. Key features:

- PER preset (modern PHP standards)
- Strict types required
- Alphabetically sorted imports
- Modern PHP 8.3 features
- Framework-appropriate rules (not Laravel app specific)

### phpstan.neon

Defines static analysis rules. Key features:

- Level 9 (maximum strictness)
- PHP 8.3 compatibility checks
- Type coverage and consistency
- Dead code detection
- Baseline support for tracking accepted issues

### .githooks/pre-commit

Automatic checks before commits:

1. PHP syntax validation
2. Code style checking (auto-fixes)
3. Static analysis on staged files

Can be bypassed with `git commit --no-verify` (not recommended)

## PHPStan Baseline Workflow

The baseline file (`phpstan-baseline.neon`) tracks issues that are:

- Accepted technical debt
- False positives
- Will be fixed later

### When to Update Baseline

**Generate initial baseline:**

```bash
make baseline
# or
composer analyse:baseline
```

**After fixing issues:**

```bash
# Fix code issues, then regenerate baseline
make baseline
```

This removes fixed issues from the baseline while keeping unresolved ones.

### Baseline Best Practices

1. Don't add to baseline without review
2. Regularly try to reduce baseline entries
3. Document why issues are in baseline (add comments)
4. Aim to eventually have an empty baseline

## Git Hooks

### Setup Hooks

Hooks are automatically set up with `make setup`, but can be manually configured:

```bash
bash .githooks/setup.sh
```

### Available Hooks

- **pre-commit:** Runs before commit (syntax, style, analysis)

### Bypass Hooks

Only for emergencies:

```bash
git commit --no-verify
```

### Hook Customization

Edit `.githooks/pre-commit` to customize checks.

## CI/CD Integration

The local `make ci` command mirrors the CI pipeline:

```bash
make ci  # Runs: format check + analyse + test
```

This ensures local checks match CI, reducing CI failures.

## Troubleshooting

### Issue: PHPStan reports cached errors

**Solution:**

```bash
make clean
make analyse
```

### Issue: Pint changes keep reverting

**Solution:**
Check if git hooks are modifying files:

```bash
git diff  # See what changed
make format-check  # Verify style issues
```

### Issue: Hooks not running

**Solution:**

```bash
bash .githooks/setup.sh
```

### Issue: Tests failing locally but not in IDE

**Solution:**

```bash
composer dump-autoload
make test
```

### Issue: Memory limit errors in PHPStan

**Solution:**
Already configured with `--memory-limit=1G` in composer scripts.

To increase further, edit `composer.json`:

```json
"analyse": "phpstan analyse --memory-limit=2G"
```

## IDE Integration

### PHPStorm / IntelliJ

1. **Code Style:**
   - Settings → PHP → Quality Tools → PHP CS Fixer
   - Use "Custom" ruleset, point to `pint.json` equivalent
   - Or run `make fix` before commits

2. **PHPStan:**
   - Settings → PHP → Quality Tools → PHPStan
   - Configuration file: `phpstan.neon`
   - Level: 9

3. **Pest Testing:**
   - Settings → PHP → Test Frameworks
   - Select "Pest" as test runner
   - Point to `vendor/bin/pest`

### VS Code

Install extensions:

- `bmewburn.vscode-intelephense-client` (PHP)
- `m1guelpf.better-pest` (Pest testing)
- `swordev.phpstan` (PHPStan)

Create `.vscode/settings.json`:

```json
{
  "php.validate.executablePath": "/usr/bin/php",
  "phpstan.enabled": true,
  "phpstan.configFile": "./phpstan.neon",
  "phpstan.level": "9"
}
```

## Additional Tools

### PHP Insights

For deeper metrics:

```bash
make insights
```

Provides scores for:

- Code quality
- Complexity
- Architecture
- Style

### Watch Mode (Requires `entr`)

Auto-run tests on file changes:

```bash
make watch-test
```

Install `entr`:

- macOS: `brew install entr`
- Linux: `apt-get install entr`

## Environment Variables

Create `.env` for test configuration:

```bash
# API Keys for integration tests
OPENAI_API_KEY=sk-...
ANTHROPIC_API_KEY=sk-ant-...
```

The `.env` file is gitignored for security.

## Performance Tips

1. **Use Makefile commands:** Faster than composer scripts
2. **Run `make quick` during development:** Skips tests for speed
3. **Use `make dev` for quick cycles:** Fix + test only
4. **Clear caches regularly:** `make clean`
5. **Baseline large refactors:** Don't let PHPStan block progress

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for full contribution guidelines.

## Summary

**Quick Commands for Daily Use:**

```bash
make dev      # Quick development cycle
make fix      # Fix code style
make test     # Run tests
make analyse  # Static analysis
make pr       # Prepare for PR
```

**Configuration Files:**

- `pint.json` - Code style
- `phpstan.neon` - Static analysis
- `phpstan-baseline.neon` - Accepted issues
- `composer.json` - Scripts and dependencies
- `Makefile` - Convenient commands
- `.githooks/pre-commit` - Pre-commit checks

**Goals:**

- Zero friction development
- Automatic quality enforcement
- Fast feedback loops
- Consistent code style
- High confidence in changes

Happy coding!
