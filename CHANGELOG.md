# Changelog

All notable changes to `pagent` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.5.0] - 2025-10-12

### Added
- GitHub Actions CI/CD workflow with automated testing
- Release Drafter for automated changelog generation
- SECURITY.md with vulnerability disclosure policy
- Export-ignore configuration for distribution optimization
- Comprehensive CONTRIBUTING.md with development guidelines
- Issue and PR templates for better community engagement
- CODE_OF_CONDUCT.md for community standards

### Changed
- Improved composer.json metadata with better keywords and support links
- Enhanced README with installation instructions and badges

## [0.4.0] - 2025-10-10

### Added
- Multi-agent orchestration patterns (pipeline, handoff, delegation)
- `resolveAgent()` helper function for dynamic agent resolution
- Agent cloning with `->clone()` method
- Conversation export/import functionality
- Usage statistics tracking with `->getStats()`
- Guard statistics monitoring with `->getGuardStats()`
- Reset methods: `clearTools()`, `clearGuards()`, `clearMiddleware()`, `reset()`
- Comprehensive guide documentation (5 different styles)
- 100 article ideas for future content

### Improved
- Better error messages with suggestions for typos
- More robust conversation history management
- Enhanced middleware pipeline

### Metrics
- 169 tests passing (385 assertions)
- 99.4% test pass rate
- PHPStan level 9 compliance
- 9 working examples

## [0.3.0] - 2025-10-05

### Added
- Safety guards (PII detection, content filtering, prompt injection prevention)
- Evaluation framework with datasets and metrics
- HTML/JSON/Markdown report generation
- Middleware pipeline (logging, rate limiting, metrics tracking)
- Tool validation with type checking

## [0.2.0] - 2025-09-28

### Added
- Multi-provider support (Anthropic Claude, OpenAI GPT, Mock)
- Automatic tool calling with JSON schema generation
- Conversation history and context management
- Complete test suite

## [0.1.0] - 2025-09-20

### Added
- Initial release
- Fluent API inspired by PestPHP
- Basic agent creation and interaction
- Simple tool calling support

[Unreleased]: https://github.com/helgesverre/pagent/compare/v0.5.0...HEAD
[0.5.0]: https://github.com/helgesverre/pagent/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/helgesverre/pagent/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/helgesverre/pagent/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/helgesverre/pagent/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/helgesverre/pagent/releases/tag/v0.1.0
