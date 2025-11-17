# Attribute-Based Tool Definition Plan

**Created:** 2025-10-30
**Target Version:** v0.7.0 or v0.8.0
**Estimated Effort:** 6-8 hours
**Priority:** Medium
**Status:** Planned

---

## Goal

Create a modern PHP attribute-based system for defining LLM tools with automatic JSON schema generation from type hints and attributes. This provides superior DX compared to manual array schemas while maintaining type safety.

---

## Background

### Current Tool Definition (Manual)

```php
final class FileRead extends Tool
{
    public function name(): string
    {
        return 'file_read';
    }

    public function description(): string
    {
        return 'Read the contents of a file';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Path to the file to read',
                ],
            ],
            'required' => ['path'],
        ];
    }

    public function execute(array $params): mixed
    {
        // Implementation
    }
}
```

**Problems:**

- Manual schema definition (error-prone)
- No type safety between parameters() and execute()
- Duplication between docblocks and schemas
- Verbose and repetitive

### Desired Tool Definition (Attribute-Based)

```php
use Pagent\Attributes\Tool;
use Pagent\Attributes\Parameter;

#[Tool(
    name: 'file_read',
    description: 'Read the contents of a file'
)]
final class FileRead extends AttributeTool
{
    public function __invoke(
        #[Parameter(description: 'Path to the file to read')]
        string $path,

        #[Parameter(description: 'Maximum file size in bytes', default: 10485760)]
        int $maxSize = 10485760,
    ): string {
        // Implementation - type-safe!
        // Schema auto-generated from signature
    }
}
```

**Benefits:**

- Automatic schema generation
- Type safety enforced at runtime
- Single source of truth
- Clean, modern syntax
- IDE autocomplete for parameters

---

## Scope

### In Scope

- PHP attribute classes (`#[Tool]`, `#[Parameter]`, `#[Returns]`)
- Schema generator using reflection
- Type mapping (PHP types → JSON schema types)
- Support for:
  - Scalar types (string, int, float, bool)
  - Arrays (typed and untyped)
  - Enums (backed and non-backed)
  - Union types
  - Nullable types
  - Default values
  - Complex objects (via nested properties)
- Validation based on generated schema
- Integration with existing Tool system
- Comprehensive test suite

### Out of Scope

- TOON format integration (handled separately)
- Runtime type coercion (strict validation only)
- Custom validators beyond JSON schema
- Code generation tools

---

## Implementation Phases

### Phase 1: Attribute Classes (Estimated: 1 hour)

- [ ] Create `src/Attributes/Tool.php` attribute
- [ ] Create `src/Attributes/Parameter.php` attribute
- [ ] Create `src/Attributes/Returns.php` attribute
- [ ] Create `src/Attributes/Example.php` attribute (optional)
- [ ] Add proper attribute targets and validation

**Deliverables:**

- 4 attribute classes with documentation
- PHPDoc annotations

### Phase 2: Schema Generator (Estimated: 3-4 hours)

- [ ] Create `src/Schema/Generator.php` class
- [ ] Implement `generateFromClass(string $className): array`
- [ ] Type mapping logic (PHP → JSON Schema)
- [ ] Handle nullable types, unions, defaults
- [ ] Support for enums
- [ ] Array type inference
- [ ] Complex object support

**Deliverables:**

- Schema generator with full type support
- Type mapping utilities

### Phase 3: AttributeTool Base Class (Estimated: 1 hour)

- [ ] Create `src/Tools/AttributeTool.php` abstract class
- [ ] Implement automatic schema extraction
- [ ] Override `parameters()` to use reflection
- [ ] Override `name()` and `description()` from attributes
- [ ] Add validation in `execute()` wrapper

**Deliverables:**

- AttributeTool base class
- Integration with existing Tool interface

### Phase 4: Testing & Examples (Estimated: 1-2 hours)

- [ ] Unit tests for each attribute
- [ ] Schema generator tests (20+ test cases)
- [ ] Integration tests with real tools
- [ ] Example tools using attributes
- [ ] Documentation and migration guide

**Deliverables:**

- 30+ test cases
- 3+ example tools
- Complete documentation

---

## Technical Approach

### Architecture

```
┌──────────────────────────────────────────┐
│  User Tool Class                         │
│  #[Tool(...)] MyTool extends AttributeTool│
│  #[Parameter(...)] in __invoke()        │
└──────────────┬───────────────────────────┘
               │
┌──────────────▼───────────────────────────┐
│  AttributeTool (Base Class)              │
│  - Reads attributes via Reflection      │
│  - Calls SchemaGenerator                │
│  - Validates params before execute       │
└──────────────┬───────────────────────────┘
               │
┌──────────────▼───────────────────────────┐
│  SchemaGenerator                         │
│  - analyzeMethod(ReflectionMethod)      │
│  - mapPhpTypeToJsonSchema(Type)         │
│  - extractParameterSchema(ReflectionParam)│
└──────────────┬───────────────────────────┘
               │
┌──────────────▼───────────────────────────┐
│  JSON Schema Output                      │
│  { type: 'object', properties: {...} }  │
└──────────────────────────────────────────┘
```

### Key Components

#### 1. Attribute Classes

```php
<?php

namespace Pagent\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Tool
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly ?array $examples = null,
        public readonly ?array $metadata = null,
    ) {}
}
```

```php
<?php

namespace Pagent\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class Parameter
{
    public function __construct(
        public readonly string $description,
        public readonly ?string $format = null,
        public readonly ?string $pattern = null,
        public readonly mixed $default = null,
        public readonly ?array $enum = null,
        public readonly ?int $minLength = null,
        public readonly ?int $maxLength = null,
        public readonly ?float $minimum = null,
        public readonly ?float $maximum = null,
    ) {}
}
```

```php
<?php

namespace Pagent\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final class Returns
{
    public function __construct(
        public readonly string $description,
        public readonly ?string $schema = null, // JSON schema for return value
    ) {}
}
```

#### 2. Schema Generator

```php
<?php

namespace Pagent\Schema;

use Pagent\Attributes\Tool;
use Pagent\Attributes\Parameter;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use ReflectionParameter;

final class Generator
{
    public function generateFromClass(string $className): array
    {
        $reflection = new ReflectionClass($className);

        // Get #[Tool] attribute
        $toolAttrs = $reflection->getAttributes(Tool::class);
        if (empty($toolAttrs)) {
            throw new \RuntimeException("Class must have #[Tool] attribute");
        }

        $tool = $toolAttrs[0]->newInstance();

        // Find __invoke method
        if (!$reflection->hasMethod('__invoke')) {
            throw new \RuntimeException("Tool class must have __invoke method");
        }

        $method = $reflection->getMethod('__invoke');

        return [
            'name' => $tool->name,
            'description' => $tool->description,
            'parameters' => $this->generateParametersSchema($method),
            'metadata' => $tool->metadata ?? [],
        ];
    }

    private function generateParametersSchema(ReflectionMethod $method): array
    {
        $parameters = $method->getParameters();
        $properties = [];
        $required = [];

        foreach ($parameters as $param) {
            $paramName = $param->getName();
            $properties[$paramName] = $this->analyzeParameter($param);

            if (!$param->isOptional() && !$param->allowsNull()) {
                $required[] = $paramName;
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ];
    }

    private function analyzeParameter(ReflectionParameter $param): array
    {
        $schema = [];

        // Get #[Parameter] attribute
        $paramAttrs = $param->getAttributes(Parameter::class);
        $paramAttr = $paramAttrs[0]->newInstance() ?? null;

        if ($paramAttr) {
            $schema['description'] = $paramAttr->description;

            if ($paramAttr->format) {
                $schema['format'] = $paramAttr->format;
            }
            if ($paramAttr->pattern) {
                $schema['pattern'] = $paramAttr->pattern;
            }
            if ($paramAttr->enum) {
                $schema['enum'] = $paramAttr->enum;
            }
            if ($paramAttr->minLength !== null) {
                $schema['minLength'] = $paramAttr->minLength;
            }
            if ($paramAttr->maxLength !== null) {
                $schema['maxLength'] = $paramAttr->maxLength;
            }
            if ($paramAttr->minimum !== null) {
                $schema['minimum'] = $paramAttr->minimum;
            }
            if ($paramAttr->maximum !== null) {
                $schema['maximum'] = $paramAttr->maximum;
            }
        }

        // Map PHP type to JSON schema type
        $type = $param->getType();
        if ($type instanceof ReflectionNamedType) {
            $schema = array_merge($schema, $this->mapNamedType($type));
        } elseif ($type instanceof ReflectionUnionType) {
            $schema = array_merge($schema, $this->mapUnionType($type));
        }

        // Handle default value
        if ($param->isDefaultValueAvailable()) {
            $schema['default'] = $param->getDefaultValue();
        }

        return $schema;
    }

    private function mapNamedType(ReflectionNamedType $type): array
    {
        $phpType = $type->getName();
        $isNullable = $type->allowsNull();

        $schema = match ($phpType) {
            'string' => ['type' => 'string'],
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'bool' => ['type' => 'boolean'],
            'array' => ['type' => 'array'],
            default => ['type' => 'object'], // Could be enum or class
        };

        // Check if it's an enum
        if (class_exists($phpType) && enum_exists($phpType)) {
            $schema = $this->mapEnumType($phpType);
        }

        if ($isNullable && $phpType !== 'null') {
            $schema['type'] = [$schema['type'], 'null'];
        }

        return $schema;
    }

    private function mapUnionType(ReflectionUnionType $type): array
    {
        $types = [];
        foreach ($type->getTypes() as $namedType) {
            if ($namedType->getName() === 'null') {
                continue;
            }
            $mapped = $this->mapNamedType($namedType);
            $types[] = $mapped['type'];
        }

        return ['type' => array_unique($types)];
    }

    private function mapEnumType(string $enumClass): array
    {
        $cases = $enumClass::cases();

        // Check if backed enum
        $reflection = new ReflectionClass($enumClass);
        if ($reflection->implementsInterface(\BackedEnum::class)) {
            $values = array_map(fn($case) => $case->value, $cases);
            $firstValue = $values[0] ?? null;
            $type = is_int($firstValue) ? 'integer' : 'string';

            return [
                'type' => $type,
                'enum' => $values,
            ];
        }

        // Non-backed enum (use names)
        return [
            'type' => 'string',
            'enum' => array_map(fn($case) => $case->name, $cases),
        ];
    }
}
```

#### 3. AttributeTool Base Class

```php
<?php

namespace Pagent\Tools;

use Pagent\Schema\Generator;
use Pagent\Attributes\Tool as ToolAttribute;

abstract class AttributeTool extends Tool
{
    private static ?array $cachedSchema = null;

    abstract public function __invoke(mixed ...$params): mixed;

    public function name(): string
    {
        $reflection = new \ReflectionClass($this);
        $attrs = $reflection->getAttributes(ToolAttribute::class);

        if (empty($attrs)) {
            throw new \RuntimeException('Tool must have #[Tool] attribute');
        }

        return $attrs[0]->newInstance()->name;
    }

    public function description(): string
    {
        $reflection = new \ReflectionClass($this);
        $attrs = $reflection->getAttributes(ToolAttribute::class);

        if (empty($attrs)) {
            throw new \RuntimeException('Tool must have #[Tool] attribute');
        }

        return $attrs[0]->newInstance()->description;
    }

    public function parameters(): array
    {
        if (self::$cachedSchema === null) {
            $generator = new Generator();
            $schema = $generator->generateFromClass(static::class);
            self::$cachedSchema = $schema['parameters'];
        }

        return self::$cachedSchema;
    }

    public function execute(array $params): mixed
    {
        // Validate params against schema before calling __invoke
        $this->validateParams($params);

        // Call __invoke with named parameters
        return $this->__invoke(...$params);
    }

    private function validateParams(array $params): void
    {
        $schema = $this->parameters();

        // Check required parameters
        foreach ($schema['required'] ?? [] as $required) {
            if (!isset($params[$required])) {
                throw new \InvalidArgumentException("Missing required parameter: {$required}");
            }
        }

        // Validate types (basic validation)
        foreach ($params as $key => $value) {
            if (!isset($schema['properties'][$key])) {
                throw new \InvalidArgumentException("Unknown parameter: {$key}");
            }

            $this->validateParamType($key, $value, $schema['properties'][$key]);
        }
    }

    private function validateParamType(string $name, mixed $value, array $schema): void
    {
        $expectedType = $schema['type'];

        if (is_array($expectedType)) {
            // Union type or nullable
            $valid = false;
            foreach ($expectedType as $type) {
                if ($this->matchesType($value, $type)) {
                    $valid = true;
                    break;
                }
            }
            if (!$valid) {
                throw new \InvalidArgumentException(
                    "Parameter '{$name}' must be one of: " . implode('|', $expectedType)
                );
            }
        } else {
            if (!$this->matchesType($value, $expectedType)) {
                throw new \InvalidArgumentException(
                    "Parameter '{$name}' must be of type {$expectedType}"
                );
            }
        }
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_numeric($value),
            'boolean' => is_bool($value),
            'array' => is_array($value),
            'object' => is_object($value) || is_array($value),
            'null' => $value === null,
            default => true,
        };
    }
}
```

---

## Testing Strategy

### Unit Tests

#### Test: Attribute Classes

```php
<?php

namespace Tests\Unit\Attributes;

use Pagent\Attributes\Tool;
use Pagent\Attributes\Parameter;
use PHPUnit\Framework\TestCase;

class AttributesTest extends TestCase
{
    public function test_tool_attribute_can_be_instantiated(): void
    {
        $tool = new Tool(
            name: 'test_tool',
            description: 'A test tool'
        );

        $this->assertEquals('test_tool', $tool->name);
        $this->assertEquals('A test tool', $tool->description);
        $this->assertNull($tool->examples);
        $this->assertNull($tool->metadata);
    }

    public function test_parameter_attribute_can_be_instantiated(): void
    {
        $param = new Parameter(
            description: 'Test parameter',
            format: 'email',
            minLength: 5,
            maxLength: 100
        );

        $this->assertEquals('Test parameter', $param->description);
        $this->assertEquals('email', $param->format);
        $this->assertEquals(5, $param->minLength);
        $this->assertEquals(100, $param->maxLength);
    }

    public function test_tool_attribute_has_correct_target(): void
    {
        $reflection = new \ReflectionClass(Tool::class);
        $attrs = $reflection->getAttributes(\Attribute::class);

        $this->assertNotEmpty($attrs);
        $attr = $attrs[0]->newInstance();

        $this->assertEquals(\Attribute::TARGET_CLASS, $attr->flags);
    }

    public function test_parameter_attribute_has_correct_target(): void
    {
        $reflection = new \ReflectionClass(Parameter::class);
        $attrs = $reflection->getAttributes(\Attribute::class);

        $this->assertNotEmpty($attrs);
        $attr = $attrs[0]->newInstance();

        $this->assertEquals(\Attribute::TARGET_PARAMETER, $attr->flags);
    }
}
```

#### Test: Schema Generator - Basic Types

```php
<?php

namespace Tests\Unit\Schema;

use Pagent\Schema\Generator;
use Pagent\Attributes\Tool;
use Pagent\Attributes\Parameter;
use PHPUnit\Framework\TestCase;

#[Tool(name: 'string_tool', description: 'Test string parameter')]
class StringToolMock
{
    public function __invoke(
        #[Parameter(description: 'A string value')]
        string $value
    ): void {}
}

#[Tool(name: 'int_tool', description: 'Test integer parameter')]
class IntToolMock
{
    public function __invoke(
        #[Parameter(description: 'An integer value')]
        int $count
    ): void {}
}

#[Tool(name: 'float_tool', description: 'Test float parameter')]
class FloatToolMock
{
    public function __invoke(
        #[Parameter(description: 'A float value')]
        float $amount
    ): void {}
}

#[Tool(name: 'bool_tool', description: 'Test boolean parameter')]
class BoolToolMock
{
    public function __invoke(
        #[Parameter(description: 'A boolean value')]
        bool $enabled
    ): void {}
}

class SchemaGeneratorBasicTypesTest extends TestCase
{
    private Generator $generator;

    protected function setUp(): void
    {
        $this->generator = new Generator();
    }

    public function test_generates_schema_for_string_parameter(): void
    {
        $schema = $this->generator->generateFromClass(StringToolMock::class);

        $this->assertEquals('string_tool', $schema['name']);
        $this->assertEquals('Test string parameter', $schema['description']);
        $this->assertArrayHasKey('parameters', $schema);

        $params = $schema['parameters'];
        $this->assertEquals('object', $params['type']);
        $this->assertArrayHasKey('value', $params['properties']);
        $this->assertEquals('string', $params['properties']['value']['type']);
        $this->assertEquals('A string value', $params['properties']['value']['description']);
        $this->assertContains('value', $params['required']);
    }

    public function test_generates_schema_for_integer_parameter(): void
    {
        $schema = $this->generator->generateFromClass(IntToolMock::class);

        $this->assertEquals('integer', $schema['parameters']['properties']['count']['type']);
        $this->assertContains('count', $schema['parameters']['required']);
    }

    public function test_generates_schema_for_float_parameter(): void
    {
        $schema = $this->generator->generateFromClass(FloatToolMock::class);

        $this->assertEquals('number', $schema['parameters']['properties']['amount']['type']);
    }

    public function test_generates_schema_for_boolean_parameter(): void
    {
        $schema = $this->generator->generateFromClass(BoolToolMock::class);

        $this->assertEquals('boolean', $schema['parameters']['properties']['enabled']['type']);
    }
}
```

#### Test: Schema Generator - Advanced Types

```php
<?php

namespace Tests\Unit\Schema;

use Pagent\Schema\Generator;
use Pagent\Attributes\Tool;
use Pagent\Attributes\Parameter;
use PHPUnit\Framework\TestCase;

enum Status: string
{
    case PENDING = 'pending';
    case ACTIVE = 'active';
    case COMPLETED = 'completed';
}

#[Tool(name: 'nullable_tool', description: 'Test nullable parameter')]
class NullableToolMock
{
    public function __invoke(
        #[Parameter(description: 'An optional string')]
        ?string $value = null
    ): void {}
}

#[Tool(name: 'default_tool', description: 'Test default values')]
class DefaultToolMock
{
    public function __invoke(
        #[Parameter(description: 'A string with default')]
        string $name = 'default',

        #[Parameter(description: 'An int with default')]
        int $count = 10
    ): void {}
}

#[Tool(name: 'enum_tool', description: 'Test enum parameter')]
class EnumToolMock
{
    public function __invoke(
        #[Parameter(description: 'A status enum')]
        Status $status
    ): void {}
}

#[Tool(name: 'union_tool', description: 'Test union type')]
class UnionToolMock
{
    public function __invoke(
        #[Parameter(description: 'String or int')]
        string|int $value
    ): void {}
}

#[Tool(name: 'array_tool', description: 'Test array parameter')]
class ArrayToolMock
{
    public function __invoke(
        #[Parameter(description: 'An array of items')]
        array $items
    ): void {}
}

class SchemaGeneratorAdvancedTypesTest extends TestCase
{
    private Generator $generator;

    protected function setUp(): void
    {
        $this->generator = new Generator();
    }

    public function test_generates_schema_for_nullable_parameter(): void
    {
        $schema = $this->generator->generateFromClass(NullableToolMock::class);

        $valueType = $schema['parameters']['properties']['value']['type'];
        $this->assertIsArray($valueType);
        $this->assertContains('string', $valueType);
        $this->assertContains('null', $valueType);
        $this->assertNotContains('value', $schema['parameters']['required']);
    }

    public function test_generates_schema_for_default_values(): void
    {
        $schema = $this->generator->generateFromClass(DefaultToolMock::class);

        $this->assertEquals('default', $schema['parameters']['properties']['name']['default']);
        $this->assertEquals(10, $schema['parameters']['properties']['count']['default']);
        $this->assertEmpty($schema['parameters']['required']);
    }

    public function test_generates_schema_for_enum_parameter(): void
    {
        $schema = $this->generator->generateFromClass(EnumToolMock::class);

        $status = $schema['parameters']['properties']['status'];
        $this->assertEquals('string', $status['type']);
        $this->assertArrayHasKey('enum', $status);
        $this->assertEquals(['pending', 'active', 'completed'], $status['enum']);
    }

    public function test_generates_schema_for_union_type(): void
    {
        $schema = $this->generator->generateFromClass(UnionToolMock::class);

        $valueType = $schema['parameters']['properties']['value']['type'];
        $this->assertIsArray($valueType);
        $this->assertContains('string', $valueType);
        $this->assertContains('integer', $valueType);
    }

    public function test_generates_schema_for_array_parameter(): void
    {
        $schema = $this->generator->generateFromClass(ArrayToolMock::class);

        $this->assertEquals('array', $schema['parameters']['properties']['items']['type']);
    }
}
```

#### Test: Schema Generator - Validation Constraints

```php
<?php

namespace Tests\Unit\Schema;

use Pagent\Schema\Generator;
use Pagent\Attributes\Tool;
use Pagent\Attributes\Parameter;
use PHPUnit\Framework\TestCase;

#[Tool(name: 'constraints_tool', description: 'Test validation constraints')]
class ConstraintsToolMock
{
    public function __invoke(
        #[Parameter(
            description: 'Email address',
            format: 'email',
            minLength: 5,
            maxLength: 100
        )]
        string $email,

        #[Parameter(
            description: 'Age in years',
            minimum: 0,
            maximum: 150
        )]
        int $age,

        #[Parameter(
            description: 'Role selection',
            enum: ['admin', 'user', 'guest']
        )]
        string $role,

        #[Parameter(
            description: 'Phone number',
            pattern: '^\+?[0-9]{10,15}$'
        )]
        string $phone
    ): void {}
}

class SchemaGeneratorConstraintsTest extends TestCase
{
    private Generator $generator;

    protected function setUp(): void
    {
        $this->generator = new Generator();
    }

    public function test_generates_string_format_constraint(): void
    {
        $schema = $this->generator->generateFromClass(ConstraintsToolMock::class);

        $email = $schema['parameters']['properties']['email'];
        $this->assertEquals('email', $email['format']);
        $this->assertEquals(5, $email['minLength']);
        $this->assertEquals(100, $email['maxLength']);
    }

    public function test_generates_numeric_range_constraints(): void
    {
        $schema = $this->generator->generateFromClass(ConstraintsToolMock::class);

        $age = $schema['parameters']['properties']['age'];
        $this->assertEquals(0, $age['minimum']);
        $this->assertEquals(150, $age['maximum']);
    }

    public function test_generates_enum_constraint(): void
    {
        $schema = $this->generator->generateFromClass(ConstraintsToolMock::class);

        $role = $schema['parameters']['properties']['role'];
        $this->assertEquals(['admin', 'user', 'guest'], $role['enum']);
    }

    public function test_generates_pattern_constraint(): void
    {
        $schema = $this->generator->generateFromClass(ConstraintsToolMock::class);

        $phone = $schema['parameters']['properties']['phone'];
        $this->assertEquals('^\+?[0-9]{10,15}$', $phone['pattern']);
    }
}
```

#### Test: AttributeTool Integration

```php
<?php

namespace Tests\Integration;

use Pagent\Tools\AttributeTool;
use Pagent\Attributes\Tool;
use Pagent\Attributes\Parameter;
use PHPUnit\Framework\TestCase;

#[Tool(name: 'calculator', description: 'Perform arithmetic operations')]
class CalculatorTool extends AttributeTool
{
    public function __invoke(
        #[Parameter(description: 'First operand')]
        float $a,

        #[Parameter(description: 'Second operand')]
        float $b,

        #[Parameter(
            description: 'Operation to perform',
            enum: ['add', 'subtract', 'multiply', 'divide']
        )]
        string $operation = 'add'
    ): float {
        return match ($operation) {
            'add' => $a + $b,
            'subtract' => $a - $b,
            'multiply' => $a * $b,
            'divide' => $b !== 0.0 ? $a / $b : throw new \InvalidArgumentException('Division by zero'),
        };
    }
}

class AttributeToolTest extends TestCase
{
    private CalculatorTool $tool;

    protected function setUp(): void
    {
        $this->tool = new CalculatorTool();
    }

    public function test_tool_name_from_attribute(): void
    {
        $this->assertEquals('calculator', $this->tool->name());
    }

    public function test_tool_description_from_attribute(): void
    {
        $this->assertEquals('Perform arithmetic operations', $this->tool->description());
    }

    public function test_parameters_schema_generated_automatically(): void
    {
        $schema = $this->tool->parameters();

        $this->assertEquals('object', $schema['type']);
        $this->assertArrayHasKey('a', $schema['properties']);
        $this->assertArrayHasKey('b', $schema['properties']);
        $this->assertArrayHasKey('operation', $schema['properties']);

        $this->assertEquals('number', $schema['properties']['a']['type']);
        $this->assertEquals('number', $schema['properties']['b']['type']);
        $this->assertEquals('string', $schema['properties']['operation']['type']);
        $this->assertEquals(['add', 'subtract', 'multiply', 'divide'], $schema['properties']['operation']['enum']);

        $this->assertContains('a', $schema['required']);
        $this->assertContains('b', $schema['required']);
        $this->assertNotContains('operation', $schema['required']); // Has default
    }

    public function test_execute_with_valid_parameters(): void
    {
        $result = $this->tool->execute([
            'a' => 10.0,
            'b' => 5.0,
            'operation' => 'add',
        ]);

        $this->assertEquals(15.0, $result);
    }

    public function test_execute_with_default_operation(): void
    {
        $result = $this->tool->execute([
            'a' => 10.0,
            'b' => 5.0,
        ]);

        $this->assertEquals(15.0, $result); // Default is 'add'
    }

    public function test_execute_throws_on_missing_required_parameter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required parameter: a');

        $this->tool->execute([
            'b' => 5.0,
        ]);
    }

    public function test_execute_throws_on_invalid_parameter_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->tool->execute([
            'a' => 'not a number',
            'b' => 5.0,
        ]);
    }

    public function test_execute_throws_on_unknown_parameter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown parameter: unknown');

        $this->tool->execute([
            'a' => 10.0,
            'b' => 5.0,
            'unknown' => 'test',
        ]);
    }

    public function test_toAnthropicSchema(): void
    {
        $schema = $this->tool->toAnthropicSchema();

        $this->assertEquals('calculator', $schema['name']);
        $this->assertEquals('Perform arithmetic operations', $schema['description']);
        $this->assertArrayHasKey('input_schema', $schema);
        $this->assertEquals('object', $schema['input_schema']['type']);
    }

    public function test_toOpenAISchema(): void
    {
        $schema = $this->tool->toOpenAISchema();

        $this->assertEquals('function', $schema['type']);
        $this->assertEquals('calculator', $schema['function']['name']);
        $this->assertEquals('Perform arithmetic operations', $schema['function']['description']);
        $this->assertArrayHasKey('parameters', $schema['function']);
    }
}
```

### Example Tools

```php
<?php

// examples/16-attribute-based-tools.php

require __DIR__ . '/../vendor/autoload.php';

use Pagent\Tools\AttributeTool;
use Pagent\Attributes\Tool;
use Pagent\Attributes\Parameter;

// Example 1: File Reader Tool
#[Tool(
    name: 'file_read',
    description: 'Read the contents of a file from disk'
)]
class FileReadTool extends AttributeTool
{
    public function __invoke(
        #[Parameter(
            description: 'Path to the file to read',
            pattern: '^[a-zA-Z0-9/._-]+$'
        )]
        string $path,

        #[Parameter(
            description: 'Maximum file size in bytes',
            minimum: 1,
            maximum: 10485760
        )]
        int $maxSize = 10485760
    ): string {
        if (!file_exists($path)) {
            throw new \RuntimeException("File not found: {$path}");
        }

        $size = filesize($path);
        if ($size > $maxSize) {
            throw new \RuntimeException("File too large: {$size} bytes");
        }

        return file_get_contents($path);
    }
}

// Example 2: Weather API Tool
enum TemperatureUnit: string
{
    case CELSIUS = 'celsius';
    case FAHRENHEIT = 'fahrenheit';
}

#[Tool(
    name: 'get_weather',
    description: 'Get current weather data for a location'
)]
class WeatherTool extends AttributeTool
{
    public function __invoke(
        #[Parameter(description: 'City name or coordinates')]
        string $location,

        #[Parameter(description: 'Temperature unit')]
        TemperatureUnit $unit = TemperatureUnit::CELSIUS,

        #[Parameter(description: 'Include 7-day forecast')]
        bool $includeForecast = false
    ): array {
        // Mock implementation
        return [
            'location' => $location,
            'temperature' => 22,
            'unit' => $unit->value,
            'conditions' => 'Sunny',
            'forecast' => $includeForecast ? ['Day 1', 'Day 2'] : null,
        ];
    }
}

// Example 3: Database Query Tool
#[Tool(
    name: 'db_query',
    description: 'Execute a database query and return results'
)]
class DatabaseQueryTool extends AttributeTool
{
    public function __invoke(
        #[Parameter(
            description: 'SQL query to execute',
            maxLength: 5000
        )]
        string $query,

        #[Parameter(description: 'Query parameters for prepared statement')]
        array $params = [],

        #[Parameter(
            description: 'Maximum rows to return',
            minimum: 1,
            maximum: 1000
        )]
        int $limit = 100
    ): array {
        // Mock implementation
        return [
            'rows' => [],
            'count' => 0,
            'query' => $query,
        ];
    }
}

// Usage examples
$fileReader = new FileReadTool();
print_r($fileReader->parameters());

$weather = new WeatherTool();
$result = $weather->execute([
    'location' => 'Oslo',
    'unit' => 'celsius',
    'includeForecast' => true,
]);
print_r($result);

// Use with agent
$agent = agent('assistant')
    ->tool($fileReader)
    ->tool($weather)
    ->tool(new DatabaseQueryTool())
    ->prompt('What tools are available?');
```

---

## Risks & Mitigation

| Risk                                 | Impact | Mitigation                                  |
| ------------------------------------ | ------ | ------------------------------------------- |
| Performance overhead from reflection | Medium | Cache schemas per class, measure benchmarks |
| Complex types hard to map            | Medium | Start with primitives, document limitations |
| Breaking changes to existing tools   | High   | New base class, optional migration          |
| Validation logic becomes complex     | Medium | Use JSON schema validators, keep simple     |
| Developer confusion about tool types | Low    | Clear docs showing Tool vs AttributeTool    |

---

## Dependencies

- PHP 8.3+ (for attributes and typed properties)
- No external dependencies for core functionality
- Optional: `justinrainbow/json-schema` for advanced validation

---

## Success Criteria

- [ ] All attribute classes implemented and tested
- [ ] Schema generator handles all PHP types correctly
- [ ] AttributeTool base class working with providers
- [ ] 30+ tests passing
- [ ] 3+ example tools using attributes
- [ ] Documentation with migration guide
- [ ] No PHPStan errors
- [ ] Performance within 10% of manual schema definition
- [ ] 100% backward compatibility with existing Tool class

---

## Timeline

| Phase              | Duration  | Target Date  |
| ------------------ | --------- | ------------ |
| Attribute Classes  | 1 hour    | Week 1 Day 1 |
| Schema Generator   | 3-4 hours | Week 1 Day 2 |
| AttributeTool      | 1 hour    | Week 1 Day 3 |
| Testing & Examples | 1-2 hours | Week 1 Day 3 |
| **Total**          | **6-8h**  | **Week 1**   |

---

## Future Enhancements

- [ ] IDE plugin for attribute autocomplete
- [ ] Code generator from OpenAPI schemas → AttributeTools
- [ ] Automatic example generation from parameter constraints
- [ ] Support for complex nested objects
- [ ] Array type hints (e.g., `string[]`, `int[]`)
- [ ] Custom validation via `#[Validate]` attribute
- [ ] Tool composition (tools calling other tools)

---

**Created:** 2025-10-30
**Last Updated:** 2025-10-30
**Status:** Planned
