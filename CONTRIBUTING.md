# Contributing to Pagent

Thank you for considering contributing to Pagent! This document outlines the development workflow and code quality standards.

## Development Setup

### Initial Setup

1. Clone the repository
2. Install dependencies and setup hooks:
   ```bash
   make setup
   ```
   Or manually:
   ```bash
   composer install
   bash .githooks/setup.sh
   ```

### System Requirements

- PHP 8.3 or higher
- Composer 2.x
- Git

## Development Workflow

### Quick Reference

```bash
# Run tests
make test

# Fix code style
make fix

# Run static analysis
make analyse

# Run full quality suite
make quality

# Quick dev cycle (fix + test)
make dev

# Prepare for PR (format + analyse + test)
make pr
```

### Available Commands

#### Make Commands (Recommended)

- `make help` - Show all available commands
- `make install` - Install dependencies
- `make setup` - Complete initial setup
- `make test` - Run all tests
- `make test-coverage` - Run tests with coverage report
- `make test-unit` - Run only unit tests
- `make test-integration` - Run only integration tests
- `make analyse` - Run PHPStan static analysis
- `make baseline` - Generate PHPStan baseline
- `make fix` - Auto-fix code style issues
- `make format-check` - Check code style without fixing
- `make check` - Run format check + analysis (no tests)
- `make quality` - Run full quality suite (format + analyse + tests)
- `make quality-fix` - Fix code style then run quality suite
- `make insights` - Run PHP Insights for code quality metrics
- `make clean` - Clean cache files
- `make ci` - Run CI pipeline (check + test)
- `make dev` - Quick dev cycle (fix + test)
- `make pr` - Prepare for PR (full quality suite)
- `make quick` - Quick check without tests

#### Composer Commands

- `composer test` - Run all tests
- `composer test:coverage` - Run tests with coverage
- `composer test:unit` - Run unit tests only
- `composer test:integration` - Run integration tests only
- `composer analyse` - Run PHPStan analysis
- `composer analyse:baseline` - Generate PHPStan baseline
- `composer analyse:clear` - Clear PHPStan cache
- `composer fix` - Auto-fix code style
- `composer fix:dry` - Check code style (dry run)
- `composer format` - Alias for fix
- `composer format:check` - Check formatting without fixing
- `composer quality` - Run format check + analyse + test
- `composer quality:fix` - Fix format + analyse + test
- `composer check` - Run format check + analyse (no tests)

## Code Quality Standards

### Code Style

Pagent uses [Laravel Pint](https://laravel.com/docs/pint) with PER (PHP Evolving Recommendation) preset.

Configuration: `pint.json`

Key rules:

- Strict types declaration required
- Short array syntax
- Single space around binary operators
- Alphabetically sorted imports
- Strict comparisons
- PHP 8.3 modern features

**Auto-fix code style:**

```bash
make fix
# or
composer fix
```

**Check without fixing:**

```bash
make format-check
# or
composer format:check
```

### Static Analysis

Pagent uses [PHPStan](https://phpstan.org/) at level 9 (maximum strictness).

Configuration: `phpstan.neon`

Key checks:

- Type coverage and consistency
- Unused code detection
- Dead code detection
- Strict parameter and return types
- PHP 8.3 compatibility checks

**Run analysis:**

```bash
make analyse
# or
composer analyse
```

**Generate baseline for existing issues:**

```bash
make baseline
# or
composer analyse:baseline
```

### Testing

Pagent uses [Pest](https://pestphp.com/) for testing.

**Run all tests:**

```bash
make test
# or
composer test
```

**Run with coverage:**

```bash
make test-coverage
# or
composer test:coverage
```

**Run specific test suites:**

```bash
composer test:unit
composer test:integration
```

### Git Hooks

Pre-commit hooks are automatically installed with `make setup`.

The hook performs:

1. PHP syntax validation
2. Code style checking (auto-fixes if needed)
3. Static analysis on staged files

**Bypass hooks (not recommended):**

```bash
git commit --no-verify
```

## Recommended Development Workflow

### Starting a New Feature

1. Create a new branch:

   ```bash
   git checkout -b feature/my-feature
   ```

2. Make your changes and test frequently:
   ```bash
   make dev  # Runs fix + test
   ```

### Before Committing

Run the pre-commit checks manually:

```bash
make quick  # Quick check without tests
# or
make check  # Check formatting and analysis
```

### Before Creating a PR

1. Ensure all quality checks pass:

   ```bash
   make pr
   # or
   make quality
   ```

2. Check test coverage:

   ```bash
   make test-coverage
   ```

3. Review your changes:
   ```bash
   git diff main...HEAD
   ```

## CI/CD Pipeline

The CI pipeline runs:

1. Code style checks (Pint)
2. Static analysis (PHPStan level 9)
3. Unit tests
4. Integration tests
5. Coverage report

Local equivalent:

```bash
make ci
```

## Troubleshooting

### PHPStan Cache Issues

Clear the PHPStan cache:

```bash
make clean
# or
composer analyse:clear
```

### PHPStan Errors After Updating Code

If PHPStan reports errors that you believe are false positives or will be fixed later:

1. Review the error carefully
2. If it's a legitimate issue, fix it
3. If it's accepted technical debt, add to baseline:
   ```bash
   make baseline
   ```

### Pint Conflicts

If Pint makes changes you disagree with, you can:

1. Adjust rules in `pint.json`
2. Discuss in an issue before making changes

### Git Hooks Not Running

Re-setup hooks:

```bash
bash .githooks/setup.sh
# or
make hooks
```

## PHP Insights (Optional)

For deeper code quality analysis:

```bash
make insights
```

This provides metrics on:

- Code complexity
- Architecture
- Code style
- Security

## Best Practices

1. **Write tests first** - TDD helps design better APIs
2. **Keep methods small** - Single responsibility principle
3. **Use type hints** - PHP 8.3 union types, intersection types
4. **Document complex logic** - PHPDoc for arrays, generics
5. **Run `make dev` frequently** - Catch issues early
6. **Never commit without tests passing** - Quality first
7. **Use descriptive commit messages** - Follow conventional commits

## Getting Help

- Check existing [issues](https://github.com/helgesverre/pagent/issues)
- Read the [documentation](README.md)
- Ask questions in discussions

## Code Review Checklist

Before submitting a PR, ensure:

- [ ] All tests pass (`make test`)
- [ ] Code style is correct (`make format-check`)
- [ ] Static analysis passes (`make analyse`)
- [ ] New features have tests
- [ ] Documentation is updated
- [ ] Commit messages are clear
- [ ] No debugging code left behind
- [ ] CHANGELOG.md is updated

## Release Process

1. Update version in relevant files
2. Update CHANGELOG.md
3. Run full quality suite: `make pr`
4. Create release tag
5. Push to GitHub

---

Thank you for contributing to Pagent!
