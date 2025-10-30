# Contributing to Pagent

Thank you for considering contributing to Pagent! This document outlines the development workflow and code quality standards.

## Development Setup

### Initial Setup

1. Clone the repository
2. Install dependencies and setup hooks:
   ```bash
   just setup
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
# Setup
just setup              # Install dependencies and git hooks

# Testing
just test               # Run all tests
just coverage           # Run tests with coverage

# Code Quality
just format             # Fix code style (PHP + Markdown)
just analyse            # Run PHPStan static analysis
just pr                 # Prepare for PR (fix, analyse, test)

# Observability Stack
just obs-up             # Start observability tools
just obs-down           # Stop and remove observability stack
```

### Available Commands

#### Just Commands (Recommended)

Run `just --list` to see all available commands.

**Main Commands:**

- `just setup` - Install dependencies and setup git hooks
- `just test` - Run all tests
- `just coverage` - Run tests with coverage report
- `just format` - Fix code style (PHP + Markdown)
- `just analyse` - Run PHPStan static analysis
- `just pr` - Prepare for PR (fix code, analyse, and test)
- `just obs-up` - Start observability stack
- `just obs-down` - Stop and remove observability stack

#### Composer Commands

- `composer test` - Run all tests
- `composer test:coverage` - Run tests with coverage
- `composer test:unit` - Run unit tests only
- `composer test:integration` - Run integration tests only
- `composer analyse` - Run PHPStan analysis
- `composer analyse:baseline` - Generate PHPStan baseline
- `composer analyse:clear` - Clear PHPStan cache
- `composer format` - Auto-fix code style
- `composer format:dry` - Check code style (dry run)
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
just format
# or
composer format
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
just analyse
# or
composer analyse
```

### Testing

Pagent uses [Pest](https://pestphp.com/) for testing.

**Run all tests:**

```bash
just test
# or
composer test
```

**Run with coverage:**

```bash
just coverage
# or
composer test:coverage
```

### Git Hooks

Pre-commit hooks are automatically installed with `just setup`.

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
   just test
   ```

### Before Committing

The git hooks will automatically check your code on commit.

### Before Creating a PR

1. Ensure all quality checks pass:

   ```bash
   just pr
   ```

2. Check test coverage:

   ```bash
   just coverage
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
just pr
```

## Troubleshooting

### PHPStan Cache Issues

Clear the PHPStan cache:

```bash
composer analyse:clear
```

### Pint Conflicts

If Pint makes changes you disagree with, you can:

1. Adjust rules in `pint.json`
2. Discuss in an issue before making changes

### Git Hooks Not Running

Re-setup hooks:

```bash
just setup
```

## Best Practices

1. **Write tests first** - TDD helps design better APIs
2. **Keep methods small** - Single responsibility principle
3. **Use type hints** - PHP 8.3 union types, intersection types
4. **Document complex logic** - PHPDoc for arrays, generics
5. **Run `just test` frequently** - Catch issues early
6. **Never commit without tests passing** - Quality first
7. **Use descriptive commit messages** - Follow conventional commits

## Getting Help

- Check existing [issues](https://github.com/helgesverre/pagent/issues)
- Read the [documentation](README.md)
- Ask questions in discussions

## Pull Request Guidelines

### Before Submitting

1. **Ensure all quality checks pass:**

   ```bash
   just pr  # Runs full quality suite
   ```

2. **Update CHANGELOG.md:**
   - Add entry under `[Unreleased]` section
   - Follow [Keep a Changelog](https://keepachangelog.com/) format
   - Use categories: Added, Changed, Deprecated, Removed, Fixed, Security

3. **Write clear commit messages:**
   - Use [Conventional Commits](https://www.conventionalcommits.org/) format
   - Examples: `feat: add retry logic`, `fix: handle timeout errors`, `docs: update README`

### PR Checklist

- [ ] All quality checks pass (`just pr`)
- [ ] New features have tests (unit + integration if applicable)
- [ ] Documentation is updated (README, guides, PHPDoc)
- [ ] CHANGELOG.md entry added
- [ ] Commit messages follow Conventional Commits
- [ ] No debugging code or commented-out code
- [ ] API changes are backward compatible (or clearly documented)

### PR Title Format

Use Conventional Commits format for PR titles:

- `feat: add WebFetcher built-in tool`
- `fix: handle timeout in tool execution`
- `docs: improve installation guide`
- `chore: update dependencies`

Labels will be automatically applied based on PR title.

## Release Process

1. Update version in relevant files
2. Update CHANGELOG.md
3. Run full quality suite: `just pr`
4. Create release tag
5. Push to GitHub

---

Thank you for contributing to Pagent!
