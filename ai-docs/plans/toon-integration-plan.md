# TOON Integration Plan

**Created:** 2025-10-30
**Target Version:** v0.7.0
**Estimated Effort:** 3-4 hours
**Priority:** Medium
**Status:** Planned

---

## Goal

Integrate [helgesverre/toon-php](https://github.com/HelgeSverre/toon-php) to enable attribute-based tool definition with automatic JSON schema generation, providing a modern DX for defining tools while maintaining backward compatibility with the existing tool system.

**Note:** This focuses on using TOON as a **data format optimization** for LLM communication, NOT as an attribute-based tool definition library (that would require a different approach with PHP attributes).

---

## Background

### What is TOON?

TOON (Token-Oriented Object Notation) is a human-readable data format designed to reduce token consumption when sending structured data to LLMs:

- **30-60% token savings** compared to JSON
- **Indentation-based nesting** (like YAML)
- **Tabular format** for uniform arrays (CSV-like)
- **Selective quoting** (only when necessary)
- **Explicit metadata** (array lengths, field declarations)

### Why Integrate TOON?

1. **Cost Savings** - Reduce token usage by 30-60% for tool schemas, examples, and context
2. **Better Context Utilization** - Fit more tools/context in the same window
3. **Improved Readability** - More human-friendly than JSON for debugging
4. **LLM-Friendly** - Explicit array lengths and field markers help validation

### Use Cases in Pagent

1. **Tool Schema Optimization** - Send tool definitions in TOON format instead of JSON
2. **Example Data** - Use TOON for few-shot examples (more examples in same tokens)
3. **Context/Memory** - Store conversation history more efficiently
4. **Evaluation Datasets** - More compact test data representation
5. **RAG Context** - Optimize retrieved document formatting

---

## Scope

### In Scope

- Composer integration of `helgesverre/toon`
- TOON encoder wrapper class (`Pagent\Format\Toon`)
- Configuration option to use TOON for tool schemas (opt-in)
- TOON support for memory/context serialization (opt-in)
- Documentation and examples
- Performance comparison tests (JSON vs TOON)

### Out of Scope

- **PHP Attribute-based tool definition** (requires separate library/approach)
  - The roadmap example showing `#[Tool]` attributes is a separate feature
  - TOON-PHP only handles encoding, not schema generation from attributes
- TOON decoding (library is encode-only)
- Changing default format to TOON (opt-in only for v0.7.0)
- Automatic format detection/negotiation

---

## Implementation Phases

### Phase 1: Core Integration (Estimated: 1 hour)

- [x] Install dependency: `composer require helgesverre/toon`
- [ ] Create `src/Format/Toon.php` wrapper class
- [ ] Add `useToon(bool $enabled = true)` method to `AgentBuilder`
- [ ] Add `toonOptions(?EncodeOptions $options)` configuration method
- [ ] Internal flag `$useToonFormat` in Agent class

**Deliverables:**

- TOON library installed
- Wrapper class with helper methods
- Agent configuration methods

### Phase 2: Tool Schema Integration (Estimated: 1 hour)

- [ ] Modify `Tool::toAnthropicSchema()` to support TOON format
- [ ] Modify `Tool::toOpenAISchema()` to support TOON format
- [ ] Add TOON representation method: `Tool::toToonSchema()`
- [ ] Update provider classes to handle TOON-formatted schemas
- [ ] Ensure backward compatibility (JSON by default)

**Deliverables:**

- Tools can export schemas in TOON format
- Providers can send TOON-formatted tool definitions
- Tests validating schema conversion

### Phase 3: Memory/Context Optimization (Estimated: 0.5 hours)

- [ ] Add TOON serialization support in `Message` class
- [ ] Configuration option for memory adapters: `serializationFormat`
- [ ] Update `SqliteMemoryAdapter` to support TOON storage
- [ ] Update `FileMemoryAdapter` to support TOON storage

**Deliverables:**

- Memory can be stored in TOON format
- Configuration to choose JSON vs TOON serialization

### Phase 4: Documentation & Examples (Estimated: 0.5-1 hour)

- [ ] Create `docs/toon-integration.md` guide
- [ ] Add example: `examples/14-toon-tools.php` (tool schemas)
- [ ] Add example: `examples/15-toon-memory.php` (memory optimization)
- [ ] Update README with TOON feature
- [ ] Add performance comparison metrics to docs

**Deliverables:**

- Comprehensive documentation
- 2+ working examples
- Performance benchmarks

---

## Technical Approach

### Architecture

```
┌─────────────────────────────────────────────┐
│            Pagent Agent                     │
│  ┌──────────────────────────────────────┐  │
│  │  Configuration: useToon(true)        │  │
│  │  toonOptions(EncodeOptions::compact)│  │
│  └──────────────────────────────────────┘  │
└─────────────────┬───────────────────────────┘
                  │
    ┌─────────────▼──────────────┐
    │  Format Detection          │
    │  (JSON vs TOON)            │
    └─────────────┬──────────────┘
                  │
    ┌─────────────▼──────────────┐
    │  Pagent\Format\Toon        │
    │  - encode()                │
    │  - encodeToolSchema()      │
    │  - encodeMessage()         │
    └─────────────┬──────────────┘
                  │
    ┌─────────────▼──────────────┐
    │  helgesverre/toon          │
    │  Toon::encode($data)       │
    └────────────────────────────┘
```

### Key Components

#### 1. **Pagent\Format\Toon** - Wrapper Class

```php
<?php

namespace Pagent\Format;

use HelgeSverre\Toon\Toon as ToonEncoder;
use HelgeSverre\Toon\EncodeOptions;

final class Toon
{
    public function __construct(
        private ?EncodeOptions $options = null
    ) {}

    public function encode(mixed $data): string
    {
        return ToonEncoder::encode($data, $this->options);
    }

    public function encodeCompact(mixed $data): string
    {
        return ToonEncoder::encode($data, EncodeOptions::compact());
    }

    public function encodeReadable(mixed $data): string
    {
        return ToonEncoder::encode($data, EncodeOptions::readable());
    }

    public function compare(mixed $data): array
    {
        return toon_compare($data);
    }

    public function estimateTokens(mixed $data): int
    {
        return toon_estimate_tokens($data);
    }
}
```

#### 2. **Tool Schema Conversion**

Extend `Tool` abstract class:

```php
abstract class Tool implements ToolInterface
{
    // Existing methods...

    public function toToonSchema(): string
    {
        return toon_compact([
            'name' => $this->name(),
            'description' => $this->description(),
            'parameters' => $this->parameters(),
        ]);
    }

    public function toAnthropicSchema(): array
    {
        // If TOON enabled, return TOON string in description
        if ($this->shouldUseToon()) {
            return [
                'name' => $this->name(),
                'description' => $this->description() . "\n\nSchema:\n" . $this->toToonSchema(),
                'input_schema' => $this->parameters(), // Keep JSON for validation
            ];
        }

        return [
            'name' => $this->name(),
            'description' => $this->description(),
            'input_schema' => $this->parameters(),
        ];
    }
}
```

#### 3. **Agent Configuration**

```php
// In AgentBuilder.php
public function useToon(bool $enabled = true): self
{
    $this->useToon = $enabled;
    return $this;
}

public function toonOptions(EncodeOptions $options): self
{
    $this->toonOptions = $options;
    return $this;
}
```

#### 4. **Memory Serialization**

```php
// In Message.php
public function toToon(): string
{
    return toon_compact([
        'role' => $this->role,
        'content' => $this->content,
        'metadata' => $this->metadata,
    ]);
}

// In SqliteMemoryAdapter.php
public function save(string $sessionId, array $messages): void
{
    $serialized = $this->format === 'toon'
        ? array_map(fn($m) => $m->toToon(), $messages)
        : json_encode($messages);

    // ... storage logic
}
```

---

## Testing Strategy

### Unit Tests

- `tests/Unit/Format/ToonTest.php` - Wrapper class functionality
- `tests/Unit/Tools/ToonSchemaTest.php` - Tool schema conversion
- Token savings comparison tests

### Integration Tests

- `tests/Integration/ToonToolsTest.php` - Tools with TOON schemas
- `tests/Integration/ToonMemoryTest.php` - Memory storage with TOON
- Provider compatibility tests (Anthropic, OpenAI with TOON)

### Performance Tests

- Benchmark JSON vs TOON encoding speed
- Measure actual token reduction percentages
- Memory overhead comparison

### Test Coverage Goals

- 15+ new tests
- Coverage for all TOON-enabled code paths
- Backward compatibility validation

---

## Risks & Mitigation

| Risk                                  | Impact | Mitigation                                   |
| ------------------------------------- | ------ | -------------------------------------------- |
| LLMs misunderstand TOON format        | High   | Make opt-in; test with multiple models       |
| Performance overhead from encoding    | Medium | Benchmark; use only for large schemas        |
| Breaking changes in toon-php library  | Medium | Pin version; comprehensive integration tests |
| Schema validation issues              | Medium | Keep JSON schema for validation              |
| Developer confusion about when to use | Low    | Clear documentation with decision tree       |

---

## Dependencies

- `helgesverre/toon-php` ^1.0 (Composer package)
- No changes to existing dependencies
- Requires PHP 8.3+ (already met)

---

## Success Criteria

- [ ] TOON library integrated and working
- [ ] Tools can export schemas in TOON format
- [ ] Agent has `useToon()` configuration
- [ ] Memory can be serialized with TOON
- [ ] 15+ tests passing (unit + integration)
- [ ] Documentation complete with examples
- [ ] Performance benchmarks showing 30%+ token savings
- [ ] Backward compatibility maintained (JSON default)
- [ ] No PHPStan errors introduced

---

## API Usage Examples

### Basic TOON Tool Schema

```php
agent('analyst')
    ->useToon(true)
    ->tool('calculate', 'Perform calculations', fn($expr) => eval("return $expr;"))
    ->prompt('What is 42 * 1337?');

// Tool schema sent in TOON format instead of JSON
```

### Custom TOON Options

```php
use HelgeSverre\Toon\EncodeOptions;

agent('bot')
    ->useToon()
    ->toonOptions(EncodeOptions::readable()) // Human-friendly formatting
    ->tool(new FileRead())
    ->prompt('Read config.json');
```

### Memory with TOON Serialization

```php
agent('chatbot')
    ->memory(new SqliteMemoryAdapter('db.sqlite', [
        'serialization' => 'toon', // Use TOON instead of JSON
    ]))
    ->sessionId('user-123')
    ->prompt('Hello!');
```

### Performance Comparison

```php
use Pagent\Format\Toon;

$tool = new FileRead();
$jsonSchema = json_encode($tool->toOpenAISchema());
$toonSchema = (new Toon())->encode($tool->toOpenAISchema());

$comparison = toon_compare($tool->toOpenAISchema());
// ['json_bytes' => 450, 'toon_bytes' => 280, 'savings' => '37.8%']
```

---

## Timeline

| Phase               | Duration | Target Date |
| ------------------- | -------- | ----------- |
| Core Integration    | 1 hour   | Week 1 Day 1|
| Tool Schema         | 1 hour   | Week 1 Day 1|
| Memory/Context      | 0.5 hour | Week 1 Day 2|
| Docs & Examples     | 1 hour   | Week 1 Day 2|
| **Total**           | **3.5h** | **Week 1**  |

---

## Future Enhancements (Post v0.7.0)

### Attribute-based Tool Definition (Separate Feature)

To achieve the roadmap's vision of attribute-based tools, we'd need:

1. **PHP Attributes Library** (could be a separate `pagent/attributes` package)
   - `#[Tool(name: '...', description: '...')]` class attribute
   - `#[Parameter(description: '...', type: '...')]` property attributes
   - Reflection-based schema generator

2. **Schema Generator from Attributes**
   ```php
   #[Tool(name: 'get_weather', description: 'Get weather data')]
   class GetWeatherTool extends Tool
   {
       public function __invoke(
           #[Parameter(description: 'City name')]
           string $location,

           #[Parameter(description: 'Include forecast')]
           bool $includeForecast = false
       ): array {
           // Implementation
       }
   }
   ```

3. **Integration**
   - Tool registry scans for `#[Tool]` attributes
   - Auto-generates JSON/TOON schemas from attributes
   - Type validation from parameter attributes

**Effort:** 6-8 hours (separate from TOON integration)

---

**Created:** 2025-10-30
**Last Updated:** 2025-10-30
**Status:** Planned
