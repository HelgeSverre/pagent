# Git Hooks

This directory contains git hooks for the Pagent framework to ensure code quality before commits.

## Setup

Run the setup script to configure git to use these hooks:

```bash
bash .githooks/setup.sh
```

Or use the Justfile:

```bash
just hooks
```

This configures git's `core.hooksPath` to point to this directory.

## Available Hooks

### pre-commit

Runs before each commit to ensure code quality:

1. **PHP Syntax Check** - Validates PHP syntax for all staged files
2. **Code Style** - Runs Pint to check/fix code formatting
3. **Static Analysis** - Runs PHPStan on staged files

The hook will:
- Auto-fix code style issues and re-stage files
- Block commits if PHPStan finds issues
- Provide clear error messages and suggestions

## Bypass Hooks

To bypass hooks for a single commit (not recommended):

```bash
git commit --no-verify
```

Only use this in emergencies. It's better to fix the issues.

## Customization

To customize the pre-commit checks, edit `.githooks/pre-commit`.

## Troubleshooting

### Hooks not running

Re-run the setup:
```bash
bash .githooks/setup.sh
```

### False positives in PHPStan

1. Review the error carefully
2. If it's a legitimate issue, fix it
3. If it's accepted technical debt, add to baseline:
   ```bash
   just baseline
   ```

### Code style keeps changing

Ensure your IDE is configured to use the same Pint rules, or let the hook auto-fix the issues.

## Benefits

- ✅ Prevents committing broken code
- ✅ Ensures consistent code style
- ✅ Catches type errors early
- ✅ Reduces CI failures
- ✅ Maintains code quality standards
