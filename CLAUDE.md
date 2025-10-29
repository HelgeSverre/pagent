# Pagent - AI Assistant Instructions

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
  - `Providers/`: Anthropic, OpenAI, Ollama, Mock implementations
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
- **Consolidate information** into existing files (DEVELOPMENT_ROADMAP.md, FEATURES.md, etc.)
- **Avoid fragmentation** - use existing structure rather than creating new documents
- **Update existing files** when adding related information

---

## AI-INSTRUCTIONS: Using the ai-docs/ Folder

The `ai-docs/` folder contains development documentation for AI-assisted sessions. **Read `ai-docs/README.md` for full guidelines.** Here are the key rules:

### Folder Structure

```
ai-docs/
├── README.md                           # Complete usage guide (READ THIS FIRST)
├── FEATURES.md                         # Feature list (root)
├── DEVELOPMENT_ROADMAP.md              # Main roadmap (root)
├── RELEASE_CHECKLIST.md                # Release process (root)
├── orchestration-and-evaluation-guide.md  # Technical guide (root)
├── test-coverage-roadmap.md            # Test roadmap (root)
├── _templates/                         # Document templates
├── reports/                            # Point-in-time reports (dated)
├── specs/                              # Technical specifications
├── plans/                              # Implementation plans
├── archive/                            # Long-running progress tracking
└── future/                             # Ideas for future work
```

### Critical Rules

1. **Always check `ai-docs/README.md` first** before creating new documents
2. **Use templates** from `_templates/` for reports and plans
3. **Follow naming conventions**:
   - Reports: `YYYY-MM-DD-descriptive-name.md` (with timestamp at top)
   - Specs: `descriptive-name.md` (no date)
   - Plans: `descriptive-name-plan.md` or `descriptive-guide.md`
4. **Place in correct folder** based on document type
5. **Don't create redundant files** - update existing documents when possible

### Decision Tree: Where Does This Document Go?

| Document Type | Location | Example |
|--------------|----------|---------|
| Point-in-time status/report | `reports/` with `YYYY-MM-DD-` prefix | `2025-10-28-implementation-status-v0.6.0.md` |
| Technical specification | `specs/` | `workflow-orchestration.md` |
| Implementation plan | `plans/` | `test-coverage-suggestions.md` |
| Long-running progress log | `archive/` | `session-progress.md` |
| Future idea/brainstorm | `future/` | `article-ideas.md` |
| Project-wide important | Root level | `FEATURES.md`, `DEVELOPMENT_ROADMAP.md` |

### What NOT to Create

❌ **Redundant status files** - Use git commits and PRs for tracking
❌ **Duplicate architecture docs** - Update existing specs instead
❌ **Session summaries for every session** - Only when significant work completed
❌ **Multiple roadmaps** - Keep one canonical DEVELOPMENT_ROADMAP.md
❌ **Overlapping plans** - Consolidate similar plans into one document

### Archiving Old Documents

**"Archiving" means moving to `ai-docs/archive/`, NOT deleting.**

- Very old reports that are no longer relevant can be moved to `ai-docs/archive/`
- Outdated specs that have been superseded can be moved to `ai-docs/archive/`
- Long-running progress logs naturally live in `ai-docs/archive/`
- **NEVER delete files** - the user does manual cleanup if needed
- Moving to archive preserves history while keeping active folders clean

### Document Lifecycle

- **Reports** (`reports/`) → Immutable snapshots, DO NOT update after creation
- **Specs** (`specs/`) → Living documents, SHOULD be updated as designs evolve
- **Plans** (`plans/`) → Can be updated, note "Last Updated" date
- **Root documents** → MUST be kept current, update frequently

### Before Creating a New Document

Ask yourself:

1. **Can I update an existing document instead?**
2. **Does this follow the naming convention?**
3. **Am I placing it in the correct folder?**
4. **Have I used the appropriate template from `_templates/`?**
5. **For reports: Have I included the date prefix and timestamp?**

### Quick Reference

**Creating a Report:**
1. Copy `ai-docs/_templates/report-template.md`
2. Name it `YYYY-MM-DD-descriptive-name.md`
3. Place in `ai-docs/reports/`
4. Fill in timestamp at top
5. Follow template structure

**Creating a Plan:**
1. Copy `ai-docs/_templates/plan-template.md`
2. Name it `descriptive-name-plan.md` or `descriptive-guide.md`
3. Place in `ai-docs/plans/`
4. Fill in created date and status
5. Follow template structure

**Creating a Spec:**
1. Name it `descriptive-name.md` (no date)
2. Place in `ai-docs/specs/`
3. Include status section if describing implementation
4. Keep updated as design evolves

**Updating Root Documents:**
- `FEATURES.md` - Add/update feature descriptions
- `DEVELOPMENT_ROADMAP.md` - Update version plans and priorities
- `RELEASE_CHECKLIST.md` - Update release process
- `orchestration-and-evaluation-guide.md` - Update technical guides
- `test-coverage-roadmap.md` - Update test plans and progress

### Example Scenarios

**Scenario: Just completed Phase 2 of feature implementation**
→ Create `ai-docs/reports/2025-10-29-phase-2-completion.md` using report template

**Scenario: Planning a new feature**
→ Create `ai-docs/plans/feature-x-implementation-plan.md` using plan template

**Scenario: Documenting architecture decisions**
→ Create or update spec in `ai-docs/specs/feature-architecture.md`

**Scenario: Brainstorming content ideas**
→ Update existing `ai-docs/future/article-ideas.md` or create new in `future/`

**Scenario: Want to track session progress**
→ Update `ai-docs/archive/session-progress.md` (use sparingly)

**Scenario: Feature roadmap changes**
→ Update `ai-docs/DEVELOPMENT_ROADMAP.md` (root level, not new file)

---

## Summary for AI Assistants

1. **Read `ai-docs/README.md`** before working with ai-docs/
2. **Use templates** from `_templates/` for consistency
3. **Follow naming conventions** strictly (especially date prefixes)
4. **Place files correctly** based on document type
5. **Update existing docs** instead of creating new ones when possible
6. **Keep root documents current** (FEATURES.md, DEVELOPMENT_ROADMAP.md, etc.)
7. **Reports are immutable** snapshots, don't update after creation
8. **Specs are living docs**, update as designs evolve
9. **Avoid creating redundant** files

**When in doubt, check `ai-docs/README.md` for the complete usage guide.**
