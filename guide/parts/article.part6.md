# Chapter 6: Introduction to Tool Calling

One of the most powerful features of modern large language models is their ability to call functions or "tools" to extend their capabilities beyond text generation. In Pagent, tool calling transforms your agents from simple conversational interfaces into action-taking systems that can query databases, read files, call APIs, perform calculations, and interact with the real world.

This chapter introduces the fundamentals of tool calling in Pagent: how to define tools, register them with agents, and understand the automatic execution lifecycle that makes tool integration seamless.

## Understanding Tool Calling in LLMs

When you send a prompt to an LLM with tools registered, the model can decide to call one or more of those tools instead of (or in addition to) generating text. The LLM doesn't execute the tool itself—instead, it returns structured data indicating which tool to call and what arguments to pass.

Your application then:

1. Receives the tool call request from the LLM
2. Executes the tool with the provided arguments
3. Sends the tool's result back to the LLM
4. Receives the LLM's final response incorporating that result

Pagent handles this entire lifecycle automatically. You simply register tools, and when the LLM decides to use them, Pagent executes them and manages the conversation flow.

## Your First Tool: A Simple Calculator

The simplest way to add a tool is using the `tool()` method with an inline closure:

```php
use function Pagent\agent;

$agent = agent('calculator')
    ->provider('openai')
    ->system('You are a helpful math assistant.')
    ->tool(
        'add',
        'Add two numbers together',
        fn (int $a, int $b): int => $a + $b
    );

$response = $agent->prompt('What is 15 plus 27?');
echo $response->content; // "15 plus 27 equals 42."
```

Let's break down what happened:

1. **Tool Registration**: The `tool()` method registers a function named `add` with a description and a PHP closure
2. **Automatic Schema Generation**: Pagent inspects the closure's type hints and automatically generates the JSON schema that the LLM needs
3. **LLM Decision**: When you send "What is 15 plus 27?", the LLM recognizes it can use the `add` tool
4. **Automatic Execution**: Pagent receives the tool call request, executes `add(15, 27)`, and returns `42`
5. **Final Response**: The LLM receives the result and generates a natural language response

All of this happens transparently during the single `prompt()` call.

## The Tool Method Signature

The `tool()` method accepts three forms:

```php
// Form 1: Inline closure (most common)
public function tool(
    string $name,
    string $description,
    Closure $callable
): self

// Form 2: ToolInterface instance (for class-based tools)
public function tool(ToolInterface $tool): self
```

**Parameters:**

- `$name`: The tool name the LLM will use to invoke it (e.g., "get_weather", "calculate_distance")
- `$description`: A clear description of what the tool does—this helps the LLM decide when to use it
- `$callable`: A PHP closure that implements the tool's logic

The description is crucial: it's how the LLM understands what your tool does and when to use it. Be specific and clear.

## Type Inference and Schema Generation

Pagent uses PHP's reflection capabilities to automatically infer tool schemas from your closure signatures. Consider this weather tool:

```php
$agent->tool(
    'get_weather',
    'Get the current weather for a location',
    fn (string $location, bool $include_forecast = false): string =>
        fetchWeatherData($location, $include_forecast)
);
```

Pagent automatically extracts:

- **Parameter names**: `location` and `include_forecast`
- **Parameter types**: `string` and `bool`
- **Required vs. optional**: `location` is required, `include_forecast` is optional (has default value)
- **Default values**: `include_forecast` defaults to `false`

This information is converted into JSON Schema format compatible with both OpenAI and Anthropic APIs:

**Anthropic Format:**

```json
{
  "name": "get_weather",
  "description": "Get the current weather for a location",
  "input_schema": {
    "type": "object",
    "properties": {
      "location": { "type": "string" },
      "include_forecast": { "type": "boolean" }
    },
    "required": ["location"]
  }
}
```

**OpenAI Format:**

```json
{
  "type": "function",
  "function": {
    "name": "get_weather",
    "description": "Get the current weather for a location",
    "parameters": {
      "type": "object",
      "properties": {
        "location": { "type": "string" },
        "include_forecast": { "type": "boolean" }
      },
      "required": ["location"]
    }
  }
}
```

Pagent automatically uses the correct schema format based on your configured provider.

## Supported PHP Types

Pagent maps PHP type hints to JSON Schema types:

| PHP Type             | JSON Schema Type |
| -------------------- | ---------------- |
| `string`             | `"string"`       |
| `int`                | `"integer"`      |
| `float`              | `"number"`       |
| `bool`               | `"boolean"`      |
| `array`              | `"array"`        |
| `object`, `stdClass` | `"object"`       |

**Example with multiple types:**

```php
$agent->tool(
    'process_user',
    'Process user data',
    function (
        string $name,
        int $age,
        float $score,
        bool $active,
        array $tags
    ): array {
        return [
            'processed' => true,
            'user' => compact('name', 'age', 'score', 'active', 'tags')
        ];
    }
);
```

Each parameter type is correctly mapped to its JSON Schema equivalent, ensuring the LLM provides properly-typed arguments.

## Registering Multiple Tools

Agents can have multiple tools registered. The LLM will choose which tool(s) to call based on the conversation context:

```php
$agent = agent('assistant')
    ->provider('anthropic')
    ->system('You are a helpful assistant with access to various tools.')
    ->tool('add', 'Add two numbers', fn (int $a, int $b) => $a + $b)
    ->tool('multiply', 'Multiply two numbers', fn (int $a, int $b) => $a * $b)
    ->tool('greet', 'Greet someone by name', fn (string $name) => "Hello, {$name}!");

// The LLM will choose the appropriate tool based on the prompt
$response = $agent->prompt('What is 5 plus 3, then multiply by 2?');
```

In this example, the LLM might call `add(5, 3)` first, then `multiply(8, 2)`, before generating the final response: "The answer is 16."

You can also register multiple tools at once using the `tools()` method:

```php
use Pagent\Tool\Tool;

$tools = [
    Tool::fromClosure('add', 'Add numbers', fn (int $a, int $b) => $a + $b),
    Tool::fromClosure('subtract', 'Subtract numbers', fn (int $a, int $b) => $a - $b),
    Tool::fromClosure('multiply', 'Multiply numbers', fn (int $a, int $b) => $a * $b),
];

$agent->tools($tools);
```

## Tool Execution Lifecycle

Understanding the tool execution lifecycle helps you debug and optimize your tools. Here's what happens under the hood:

### 1. Registration Phase

When you call `$agent->tool()`, Pagent:

- Creates a `Tool` instance using `Tool::fromClosure()`
- Uses PHP reflection to extract parameter information
- Stores the tool in the agent's internal tool registry
- Caches the JSON schema for performance

### 2. Prompt Phase

When you call `$agent->prompt()`, Pagent:

- Includes tool schemas in the API request
- Sends your prompt along with available tools to the LLM

### 3. LLM Response Phase

The LLM can respond in two ways:

**Option A: Direct text response**

```php
// LLM decides no tool is needed
{
  "content": "Hello! I can help you with calculations."
}
```

**Option B: Tool call request**

```php
// LLM requests to call a tool
{
  "tool_calls": [
    {
      "id": "call_abc123",
      "name": "add",
      "arguments": {"a": 15, "b": 27}
    }
  ]
}
```

### 4. Automatic Tool Execution

When the LLM requests a tool call, Pagent automatically:

1. Validates the tool exists
2. Validates the arguments match the tool's signature
3. Executes the tool: `$result = $tool->execute([15, 27])`
4. Formats the result for the LLM
5. Sends the result back to the LLM in the conversation

### 5. Final Response

The LLM receives the tool result and generates a final response:

```php
// After receiving tool result: 42
{
  "content": "15 plus 27 equals 42."
}
```

All of this happens during a single `prompt()` call. Pagent manages the conversation flow automatically.

## Recursive Tool Calling

LLMs can call multiple tools in sequence. Pagent supports recursive tool calling with a safety limit:

```php
// The agent can call tools multiple times
$agent = agent('multi-step')
    ->provider('openai')
    ->tool('add', 'Add numbers', fn (int $a, int $b) => $a + $b)
    ->tool('multiply', 'Multiply numbers', fn (int $a, int $b) => $a * $b)
    ->tool('subtract', 'Subtract numbers', fn (int $a, int $b) => $a - $b);

// This might trigger multiple tool calls in sequence
$response = $agent->prompt('Calculate (10 + 5) * 3 - 8');
```

Pagent has a built-in safety mechanism to prevent infinite loops. The maximum tool call depth is 10 (defined as `MAX_TOOL_CALL_DEPTH` in the `Agent` class). If an agent attempts to call more than 10 tools in a single prompt cycle, Pagent throws a `RuntimeException`:

```php
// If this happens, you'll see:
// RuntimeException: Maximum tool call depth exceeded (10 calls).
// Possible infinite loop detected.
```

This protects against scenarios where the LLM might get stuck in a loop, repeatedly calling tools without reaching a final response.

## Manual Tool Execution

While Pagent handles tool execution automatically during `prompt()`, you can also execute tools manually for testing or direct invocation:

```php
$agent = agent('calculator')
    ->tool('add', 'Add numbers', fn (int $a, int $b) => $a + $b);

// Execute the tool directly
$result = $agent->executeTool('add', [10, 5]);
echo $result; // 15
```

This is particularly useful for:

- Testing tool implementations
- Debugging tool logic
- Building custom orchestration flows
- Pre-computing values before a prompt

## Inspecting Registered Tools

You can inspect which tools are registered on an agent:

```php
$agent = agent('inspector')
    ->tool('add', 'Add numbers', fn (int $a, int $b) => $a + $b)
    ->tool('multiply', 'Multiply numbers', fn (int $a, int $b) => $a * $b);

$tools = $agent->getTools();

foreach ($tools as $tool) {
    echo "Tool: {$tool->name}\n";
    echo "Description: {$tool->description}\n";
    echo "Arguments: " . count($tool->arguments) . "\n\n";
}
```

This returns an array of `ToolInterface` instances, each with:

- `name`: The tool's name
- `description`: The tool's description
- `arguments`: Array of `ToolArgument` objects with type information
- `callable`: The closure that executes the tool

## Error Handling: Unknown Tools

If the LLM requests a tool that doesn't exist (which shouldn't happen if schemas are correct, but can occur with custom implementations), Pagent throws a helpful exception:

```php
// If you try to execute a non-existent tool:
$agent->executeTool('unknown_tool', []);

// RuntimeException: Tool 'unknown_tool' not found.
// Available tools: add, multiply, greet
```

Pagent even provides **tool name suggestions** using Levenshtein distance to help you spot typos:

```php
$agent->executeTool('ad', []); // Typo: should be 'add'

// RuntimeException: Tool 'ad' not found. Did you mean: add?
// Available tools: add, multiply, greet
```

This intelligent error handling makes debugging faster and more intuitive.

## Clearing Tools

If you need to remove all tools from an agent (for example, when transitioning between different conversation phases):

```php
$agent = agent('dynamic')
    ->tool('add', 'Add', fn (int $a, int $b) => $a + $b)
    ->tool('multiply', 'Multiply', fn (int $a, int $b) => $a * $b);

// Later, remove all tools
$agent->clearTools();

echo count($agent->getTools()); // 0
```

The `clearTools()` method:

- Removes all registered tools
- Clears the internal schema cache
- Allows you to start fresh with new tools

## Class-Based Tools

While inline closures are convenient for simple tools, Pagent also supports class-based tools by implementing the `ToolInterface`:

```php
use Pagent\Contracts\ToolInterface;

class DatabaseQuery implements ToolInterface
{
    public function name(): string
    {
        return 'query_database';
    }

    public function description(): string
    {
        return 'Execute a SQL query against the database';
    }

    public function execute(array $params): mixed
    {
        $query = $params['query'] ?? throw new RuntimeException('Query required');
        // Execute query logic here
        return $results;
    }

    public function toAnthropicSchema(): array
    {
        return [
            'name' => $this->name(),
            'description' => $this->description(),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'SQL query to execute']
                ],
                'required' => ['query']
            ]
        ];
    }

    public function toOpenAISchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'SQL query to execute']
                    ],
                    'required' => ['query']
                ]
            ]
        ];
    }
}

// Register the class-based tool
$agent->tool(new DatabaseQuery());
```

Class-based tools give you more control over:

- Schema definition (for complex parameter structures)
- Error handling and validation
- Dependency injection (constructor arguments)
- State management across invocations
- Reusability across multiple agents

Pagent includes built-in class-based tools like `FileRead` and `FileWrite` that extend the abstract `Tool` base class for convenience.

## Practical Example: Weather Agent

Let's build a practical weather agent that demonstrates multiple concepts:

```php
use function Pagent\agent;

// Mock weather API for demonstration
function fetchWeatherData(string $city): array
{
    // In production, this would call a real API
    return [
        'city' => $city,
        'temperature' => rand(15, 30),
        'condition' => ['Sunny', 'Cloudy', 'Rainy'][rand(0, 2)],
        'humidity' => rand(40, 80)
    ];
}

$agent = agent('weather-assistant')
    ->provider('anthropic')
    ->model('claude-sonnet-4-20250514')
    ->system('You are a helpful weather assistant. Provide clear and concise weather information.')
    ->tool(
        'get_weather',
        'Get current weather data for a city',
        function (string $city): string {
            $data = fetchWeatherData($city);
            return json_encode($data, JSON_PRETTY_PRINT);
        }
    )
    ->tool(
        'convert_temperature',
        'Convert temperature between Celsius and Fahrenheit',
        function (float $temp, string $from_unit, string $to_unit): float {
            if ($from_unit === 'celsius' && $to_unit === 'fahrenheit') {
                return ($temp * 9/5) + 32;
            }
            if ($from_unit === 'fahrenheit' && $to_unit === 'celsius') {
                return ($temp - 32) * 5/9;
            }
            return $temp; // Same unit
        }
    );

// Use the agent
$response = $agent->prompt('What\'s the weather in Paris?');
echo $response->content;
// "The current weather in Paris is Sunny with a temperature of 22°C and 65% humidity."

$response = $agent->prompt('Convert 25 Celsius to Fahrenheit');
echo $response->content;
// "25°C is equal to 77°F."
```

This agent demonstrates:

- Multiple related tools working together
- Tools that call external functions (simulated API)
- Different parameter types (string, float)
- Real-world use case with practical utility

## Key Takeaways

In this chapter, you learned:

1. **Tool Definition**: Use the `tool()` method with name, description, and closure
2. **Automatic Schema Generation**: Pagent infers schemas from PHP type hints
3. **Automatic Execution**: Tools are executed automatically when the LLM requests them
4. **Multiple Tools**: Register multiple tools for complex capabilities
5. **Type Mapping**: PHP types map to JSON Schema types
6. **Safety Limits**: Recursive tool calling is limited to depth 10
7. **Manual Execution**: Use `executeTool()` for testing and debugging
8. **Tool Inspection**: Use `getTools()` to inspect registered tools
9. **Error Handling**: Pagent provides helpful error messages with suggestions
10. **Class-Based Tools**: Implement `ToolInterface` for reusable tool classes

Tool calling transforms your agents from conversational systems into action-taking systems. With tools, your agents can query databases, read files, call APIs, perform calculations, and interact with external systems—all while maintaining natural language interaction.

In the next chapter, we'll dive deeper into building custom tools with validation, error handling, and advanced patterns for production use.
