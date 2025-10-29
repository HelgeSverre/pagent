# AI Documentation Archive

This folder contains development documentation, implementation summaries, and internal notes created during the development of Pagent. These files are primarily intended for AI-assisted development sessions and historical reference.

---

## Folder Structure

```
ai-docs/
├── README.md                                    # This file
├── FEATURES.md                                  # Feature list and capabilities
├── DEVELOPMENT_ROADMAP.md                       # Feature roadmap and priorities
├── RELEASE_CHECKLIST.md                         # Release process and checklist
├── orchestration-and-evaluation-guide.md        # Technical guide for orchestration & eval
├── test-coverage-roadmap.md                     # Test coverage implementation plan
│
├── _templates/                                  # Document templates
│   ├── report-template.md                       # Standard format for reports
│   └── plan-template.md                         # Standard format for plans
│
├── reports/                                     # Point-in-time reports (dated)
│   ├── 2025-10-28-implementation-status-v0.6.0.md
│   ├── 2025-10-28-phase-1-test-coverage.md
│   └── ...                                      # Historical snapshots with dates
│
├── specs/                                       # Technical specifications
│   ├── workflow-orchestration.md                # Workflow patterns architecture
│   └── tool-architecture-analysis.md            # Tool system analysis
│
├── plans/                                       # Implementation plans (forward-looking)
│   ├── test-coverage-suggestions.md             # Test improvement ideas
│   └── dx-setup-guide.md                        # Developer experience setup
│
├── archive/                                     # Long-running progress tracking
│   └── session-progress.md                      # Development session logs
│
└── future/                                      # Ideas for future work
    └── article-ideas.md                         # Blog post and content ideas
```

---

## Document Categories

### Root Level Documents

**Keep these at the root for quick access:**

- **FEATURES.md** - Current feature list and capabilities
- **ROADMAP.md** - High-level roadmap and version planning
- **RELEASE_CHECKLIST.md** - Steps for releasing new versions
- **orchestration-and-evaluation-guide.md** - Technical guide explaining core systems
- **test-coverage-roadmap.md** - Comprehensive test coverage plan and progress

### \_templates/

**Document templates for consistency:**

Templates with standard formats for reports and plans. All reports should follow the report-template.md format with timestamps at the top.

**Usage:** Copy template when creating new report or plan document.

### reports/

**Point-in-time reports and summaries:**

Historical snapshots of implementation status, phase completions, and feature summaries. These documents capture state at a specific point in time.

**Naming convention:** `YYYY-MM-DD-descriptive-name.md`

**Requirements:**

- MUST include date in filename (YYYY-MM-DD prefix)
- MUST include timestamp at top of document
- SHOULD include version/phase in filename when applicable
- SHOULD follow report-template.md format

**Examples:**

- `2025-10-28-implementation-status-v0.6.0.md` - Status report for v0.6.0
- `2025-10-28-phase-1-test-coverage.md` - Phase 1 completion report

### specs/

**Technical specifications and architectural documents:**

Feature specifications, proposals, and technical analysis documents. These are "living documents" that describe how systems work or should work.

**Contents:**

- Architecture proposals
- Feature specifications
- System design documents
- Technical analysis reports

**NOT for:** Implementation status, progress tracking, or historical reports

### plans/

**Forward-looking implementation plans:**

Planning documents for future work, improvement suggestions, and setup guides. These are "active" documents that guide upcoming development.

**Contents:**

- Test coverage plans
- Feature implementation plans
- Setup and configuration guides
- Improvement suggestions

**NOT for:** Progress tracking, historical reports, or completed work

### archive/

**Long-running progress tracking:**

Documents that are updated over time to track ongoing progress. These are "living logs" that span multiple sessions.

**Contents:**

- Session progress logs
- Long-term tracking documents
- Historical conversations

**Use sparingly:** Most progress should be in git commits and pull requests, not separate tracking files.

### future/

**Ideas and brainstorming:**

Content ideas, feature brainstorming, and "maybe someday" items. These are low-priority ideas for future consideration.

**Contents:**

- Article and blog post ideas
- Feature brainstorms
- "Nice to have" improvements
- Long-term vision documents

---

## Usage Guidelines for AI Assistants

### When Creating New Documents

1. **Check if existing document should be updated** instead of creating new file
2. **Use templates** from `_templates/` for reports and plans
3. **Follow naming conventions** (especially date prefixes for reports)
4. **Place in correct folder** based on document type
5. **Include timestamps** at the top of reports

### Naming Conventions

**Reports:** `YYYY-MM-DD-descriptive-name.md`

- ✅ `2025-10-28-streaming-implementation.md`
- ✅ `2025-11-15-phase-3-summary.md`
- ❌ `streaming-report.md` (missing date)
- ❌ `report-2025-10-28.md` (date should be prefix)

**Specs:** `descriptive-name.md` (no date)

- ✅ `workflow-orchestration.md`
- ✅ `memory-architecture.md`
- ❌ `2025-10-28-workflow-spec.md` (specs shouldn't be dated)

**Plans:** `descriptive-name-plan.md` or `descriptive-guide.md`

- ✅ `test-coverage-suggestions.md`
- ✅ `dx-setup-guide.md`
- ✅ `feature-x-implementation-plan.md`

### Decision Tree: Where Does This Document Go?

**Is it a point-in-time snapshot of status/progress?**
→ `reports/` with date prefix

**Is it describing how something works or should work?**
→ `specs/` if technical architecture
→ Root level if high-level guide

**Is it planning future work?**
→ `plans/`

**Is it tracking ongoing progress over time?**
→ `archive/` (use sparingly)

**Is it an idea for the future?**
→ `future/`

**Is it project-wide important?**
→ Root level (FEATURES.md, ROADMAP.md, etc.)

### Avoid Creating These

❌ **Redundant status files** - Use git commits and PRs for tracking
❌ **Duplicate architecture docs** - Update existing specs instead
❌ **Session summaries for every session** - Only create when significant work completed
❌ **Multiple roadmaps** - Keep one canonical ROADMAP.md
❌ **Overlapping plans** - Consolidate similar plans into one document

### What "Archiving" Means

**Archiving = Moving to `ai-docs/archive/`, NOT deleting.**

When documents become outdated but should be preserved for historical reference:

- Move to `ai-docs/archive/` directory
- Keep the filename and date prefix intact
- Add a note at the top if superseded by newer document
- **Never delete files** - the user handles manual deletion if truly needed
- This keeps active folders clean while preserving history

### Updating Existing Documents

**Reports** (in `reports/`) → Should NOT be updated after creation (point-in-time snapshots)

**Specs** (in `specs/`) → CAN and SHOULD be updated as designs evolve

**Plans** (in `plans/`) → CAN be updated as plans change, note "Last Updated" date

**Root documents** → SHOULD be kept current, update frequently

---

## Purpose

These documents serve as:

1. **Historical record** of development decisions and implementation details
2. **Context for AI assistants** working on the project
3. **Internal documentation** not meant for end users
4. **Development artifacts** that complement the main user-facing documentation

---

## User-Facing Documentation

For user-facing documentation, see:

- `/README.md` - Main project documentation
- `/docs/` - Integration guides and feature documentation
- `/guide/` - Learning guides and tutorials (if exists)
- `/examples/` - Example code and use cases

---

## Maintenance

This folder is excluded from the main documentation tree and is not published to documentation sites. Files here may become outdated as the project evolves - always refer to the main README and `/docs/` folder for current, accurate information.

**Periodic cleanup recommended:**

- **Archive** (move to `archive/`, don't delete) very old reports that are no longer relevant
- **Archive** outdated specs that have been superseded by newer documents
- **Consolidate** similar planning documents into one comprehensive plan
- **Update** root-level documents to stay current
- **NEVER delete files** - the user handles manual deletion if truly needed
- Archiving = moving to `ai-docs/archive/` to preserve history while keeping active folders clean
