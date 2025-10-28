# Pagent - Agent Instructions

## Commands

- **Test all**: `./vendor/bin/pest` or `composer test`
- **Test single file**: `./vendor/bin/pest tests/Unit/AgentTest.php`
- **Test unit only** (no API calls): `./vendor/bin/pest --exclude-group=api`
- **Test API integration** (requires API keys): `./vendor/bin/pest --group=api`
- **Type check**: `./vendor/bin/phpstan analyse` or `composer analyse`
- **Format code**: `./vendor/bin/pint` or `composer format`

## Architecture

- **PHP 8.3+** library for LLM interaction with Pest-inspired fluent API
- **src/**: Core code with PSR-4 autoloading (`Pagent\` namespace)
  - `Agent.php`, `AgentBuilder.php`, `Registry.php`: Core agent management
  - `functions.php`: Global helper functions (`agent()`, `anthropic()`, `openai()`, `mock()`)
  - `Contracts/Provider.php`: Provider interface
  - `Providers/`: Anthropic, OpenAI, Mock implementations
- **tests/**: Pest tests with `Tests\` namespace, split into Unit/ and Integration/
- **No database**, stateless library, conversation history tracked in-memory per agent

## Code Style

- **PHP 8.3** features, strict types (`declare(strict_types=1);` on every file)
- **PER preset** with Laravel Pint, strict comparison, final classes, Yoda style
- **Imports**: Fully qualified with alpha sorting, global namespace imports enabled
- **Spacing**: Concat with spaces (`'hello' . 'world'`), not operator with space
- **Methods**: Return type declarations required, void returns explicit
- **PHPStan level 9**: Maximum strictness, ignores Pest function calls
- **Naming**: Camelcase methods, snake_case for arrays/config keys

## Documentation Policy

- **DO NOT create new files** without explicit user approval
- **Consolidate information** into existing files (DEVELOPMENT_ROADMAP.md, ROADMAP.md, etc.)
- **Avoid fragmentation** - use existing structure rather than creating new documents
- **Update existing files** when adding related information
