# Pagent Framework - Quick Start

## Initial Setup (30 seconds)

```bash
just setup
```

That's it! This will:
- Install all dependencies
- Configure git pre-commit hooks
- Initialize testing framework

## Daily Development Commands

### During Development
```bash
just dev          # Fix code style + run tests
just fix          # Auto-fix code style only
just test         # Run tests only
```

### Before Committing
```bash
just check        # Run format check + static analysis
just quick        # Same as check (faster name)
```

### Before Creating PR
```bash
just pr           # Run full quality suite
just test-coverage # Verify 80%+ coverage
```

### Troubleshooting
```bash
just clean        # Clear all caches
just analyse      # Run PHPStan analysis
just baseline     # Regenerate PHPStan baseline
```

## Workflow Example

```bash
# 1. Start new feature
git checkout -b feature/awesome-feature

# 2. Make changes, run frequently:
just dev

# 3. Ready to commit:
just check
git commit -m "feat: add awesome feature"

# 4. Before creating PR:
just pr

# 5. Create PR
git push -u origin feature/awesome-feature
```

## All Available Commands

Run `just help` to see all commands:

```bash
just help
```

## Pre-commit Hooks

Hooks run automatically on commit and check:
1. PHP syntax
2. Code style (auto-fixes)
3. Static analysis

To bypass (emergency only):
```bash
git commit --no-verify
```

## Code Quality

- **PHPStan:** Level 9 (maximum strictness)
- **Pint:** PER preset (modern PHP standards)
- **Pest:** Testing framework
- **Coverage:** Minimum 80%

## Documentation

- [`CONTRIBUTING.md`](../CONTRIBUTING.md) - Full contribution guide
- [`DX_SETUP.md`](../DX_SETUP.md) - Complete setup documentation
- [`DX_OPTIMIZATION_SUMMARY.md`](../DX_OPTIMIZATION_SUMMARY.md) - Optimization overview

## Need Help?

```bash
just help         # Show all commands
composer --help   # Show composer scripts
```

## Success!

You're ready to contribute to Pagent! 🚀

**Remember:** `just dev` is your friend during development.
