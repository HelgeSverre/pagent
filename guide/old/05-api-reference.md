# Pagent API Reference

**Complete Technical Documentation**

---

## Table of Contents

- [Global Functions](#global-functions)
- [Agent Class](#agent-class)
- [AgentBuilder Class](#agentbuilder-class)
- [Providers](#providers)
- [Guards](#guards)
- [Tools](#tools)
- [Orchestration](#orchestration)
- [Evaluation](#evaluation)
- [Response Object](#response-object)

---

## Global Functions

### `agent(string $name): Agent|AgentBuilder`

Create or retrieve an agent by name.

**Parameters:**

- `$name` (string) - Unique identifier for the agent

**Returns:**

- `AgentBuilder` if agent doesn't exist
- `Agent` if agent is already registered

**Example:**

```php
agent('assistant')->provider(anthropic())->system('You are helpful.');
$agent = agent('assistant'); // Retrieves registered agent
```

---

### `agents(): array`

Get all registered agents.

**Returns:** `array<string, Agent>`

**Example:**

```php
$allAgents = agents();
foreach ($allAgents as $name => $agent) {
    echo $name . "\n";
}
```

---

### `clearAgents(): void`

Clear all registered agents. Useful for testing.

**Example:**

```php
clearAgents();
```

---

### `anthropic(array $config = []): Anthropic`

Create an Anthropic provider instance.

**Parameters:**

- `$config` (array) - Configuration options
  - `api_key` (string) - Anthropic API key (default: `ANTHROPIC_API_KEY` env var)

**Returns:** `Pagent\Providers\Anthropic`

**Example:**

```php
$provider = anthropic(['api_key' => 'sk-ant-...']);
```

---

### `openai(array $config = []): OpenAI`

Create an OpenAI provider instance.

**Parameters:**

- `$config` (array) - Configuration options
  - `api_key` (string) - OpenAI API key (default: `OPENAI_API_KEY` env var)

**Returns:** `Pagent\Providers\OpenAI`

**Example:**

```php
$provider = openai(['api_key' => 'sk-...']);
```

---

### `mock(array $responses = []): Mock`

Create a mock provider for testing.

**Parameters:**

- `$responses` (array) - Key-value pairs mapping prompts to responses

**Returns:** `Pagent\Providers\Mock`

**Example:**

```php
$provider = mock([
    'Hello' => 'Hi there!',
    'Goodbye' => 'See you later!'
]);
```

---

### `evaluate(string $agentName): Evaluator`

Create an evaluator for an agent.

**Parameters:**

- `$agentName` (string) - Name of the agent to evaluate

**Returns:** `Pagent\Evaluation\Evaluator`

**Example:**

```php
$evaluator = evaluate('support')
    ->dataset($data)
    ->metric('keyword', new KeywordMetric())
    ->run();
```

---

### `pipeline(string $name): Pipeline`

Create a pipeline for sequential agent execution.

**Parameters:**

- `$name` (string) - Pipeline identifier

**Returns:** `Pagent\Orchestration\Pipeline`

**Example:**

```php
$result = pipeline('content')
    ->agent('writer')
    ->agent('editor')
    ->run('Create a blog post');
```

---

### `resolveAgent(string|Agent $agent): Agent|AgentBuilder`

Resolve an agent from a string name or Agent instance.

**Parameters:**

- `$agent` (string|Agent) - Agent name or instance

**Returns:** `Agent|AgentBuilder`

**Example:**

```php
$resolved = resolveAgent('assistant');
```

---

## Agent Class

### Properties

```php
public array $messages;    // Conversation history
public ?Provider $provider; // LLM provider instance
```

### Methods

#### `setProvider(Provider $provider): self`

Set the LLM provider.

**Parameters:**

- `$provider` (Provider) - Provider instance

**Returns:** `self`

---

#### `system(string $message): self`

Set the system message (agent personality/instructions).

**Parameters:**

- `$message` (string) - System message content

**Returns:** `self`

**Example:**

```php
agent('bot')->system('You are a helpful coding assistant.');
```

---

#### `model(string $model): self`

Set the model to use.

**Parameters:**

- `$model` (string) - Model identifier (e.g., `claude-3-5-sonnet-20241022`, `gpt-4o`)

**Returns:** `self`

**Example:**

```php
agent('bot')->model('claude-3-5-sonnet-20241022');
```

---

#### `temperature(float $temperature): self`

Set the temperature (0.0 - 1.0).

**Parameters:**

- `$temperature` (float) - Higher values = more creative, lower = more deterministic

**Returns:** `self`

**Example:**

```php
agent('creative')->temperature(0.9);
agent('factual')->temperature(0.1);
```

---

#### `maxTokens(int $tokens): self`

Set maximum output tokens.

**Parameters:**

- `$tokens` (int) - Maximum number of tokens to generate

**Returns:** `self`

**Example:**

```php
agent('bot')->maxTokens(500);
```

---

#### `prompt(string $message, array $options = []): object`

Send a prompt and get a response.

**Parameters:**

- `$message` (string) - The user message
- `$options` (array) - Additional options (merged with agent config)

**Returns:** `object` - Response object with properties:

- `content` (string) - Response text
- `model` (string) - Model used
- `tokens` (int) - Total tokens used
- `provider` (string) - Provider name
- `stop_reason` (string) - Why generation stopped
- `usage` (object) - Token usage details

**Throws:** `RuntimeException` if no provider is set

**Example:**

```php
$response = agent('bot')->prompt('What is PHP?');
echo $response->content;
```

---

#### `tool(string|ToolInterface $nameOrTool, ?string $description = null, ?Closure $callable = null): self`

Register a tool (function) the agent can call.

**Parameters:**

- `$nameOrTool` (string|ToolInterface) - Tool name or ToolInterface instance
- `$description` (string|null) - Tool description (required for closure-based tools)
- `$callable` (Closure|null) - Function to execute (required for closure-based tools)

**Returns:** `self`

**Example:**

```php
// Closure-based tool
agent('calc')->tool('add', 'Add two numbers', function (int $a, int $b): int {
    return $a + $b;
});

// Class-based tool
agent('assistant')->tool(new FileRead(baseDir: '/project'));
```

---

#### `tools(array $tools): self`

Add multiple tools at once for convenience.

**Parameters:**

- `$tools` (ToolInterface[]) - Array of tool instances implementing ToolInterface

**Returns:** `self`

**Example:**

```php
use Pagent\Tools\{FileRead, FileWrite, Glob, Grep};

agent('file-assistant')
    ->tools([
        new FileRead(baseDir: '/project'),
        new FileWrite(baseDir: '/project'),
        new Glob(baseDir: '/project'),
        new Grep(baseDir: '/project'),
    ])
    ->prompt('List all PHP files');
```

---

#### `guard(Guard|Closure|string $guard): self`

Add a safety guard.

**Parameters:**

- `$guard` (Guard|Closure|string) - Guard instance, closure, or class name

**Returns:** `self`

**Throws:** `RuntimeException` if guard class doesn't exist

**Example:**

```php
use Pagent\Guards\PIIGuard;

agent('secure')->guard(new PIIGuard());
agent('custom')->guard(fn($input) => strlen($input) < 1000);
agent('named')->guard(PIIGuard::class);
```

---

#### `fallback(Closure $handler): self`

Set a fallback handler for guard violations.

**Parameters:**

- `$handler` (Closure) - Function to handle violations

**Returns:** `self`

**Example:**

```php
agent('safe')
    ->guard(new PIIGuard())
    ->fallback(fn($input, $guard) => "Blocked by {$guard->name()}");
```

---

#### `middleware(Middleware|Closure|string $middleware): self`

Add middleware to the agent.

**Parameters:**

- `$middleware` (Middleware|Closure|string) - Middleware instance, closure, or class name

**Returns:** `self`

**Example:**

```php
use Pagent\Middleware\RateLimitMiddleware;

agent('bot')->middleware(new RateLimitMiddleware(10, 60));
```

---

#### `handoff(): Handoff`

Create a handoff to transfer control to another agent.

**Returns:** `Pagent\Orchestration\Handoff`

**Example:**

```php
$specialized = agent('general')
    ->handoff()
    ->to('specialist')
    ->because('User needs technical help')
    ->transfer();
```

---

#### `delegate(string $task): Delegation`

Delegate a task to a worker agent.

**Parameters:**

- `$task` (string) - Task description

**Returns:** `Pagent\Orchestration\Delegation`

**Example:**

```php
$result = agent('manager')
    ->delegate('Research PHP frameworks')
    ->to('researcher')
    ->execute();
```

---

## AgentBuilder Class

Fluent builder for configuring agents. All methods return `self` and forward to the underlying `Agent` instance.

### Methods

- `provider(Provider $provider): self`
- `system(string $message): self`
- `model(string $model): self`
- `temperature(float $temp): self`
- `maxTokens(int $tokens): self`
- `tool(string $name, callable $fn): self`
- `guard(Guard|Closure|string $guard): self`
- `fallback(Closure $handler): self`
- `middleware(Middleware|Closure|string $mw): self`

**Note:** Builder is automatically destroyed and registers the agent after configuration.

---

## Providers

### Anthropic Provider

```php
namespace Pagent\Providers;

class Anthropic implements Provider
{
    public function __construct(array $config = []);
    public function prompt(string $prompt, array $options = []): object;
}
```

**Configuration:**

- `api_key` (string) - API key
- `model` (string) - Default model (default: `claude-3-5-sonnet-20241022`)
- `max_tokens` (int) - Default max tokens (default: `1024`)

**Supported Models:**

- `claude-3-5-sonnet-20241022`
- `claude-3-haiku-20240307`
- `claude-3-opus-20240229`

---

### OpenAI Provider

```php
namespace Pagent\Providers;

class OpenAI implements Provider
{
    public function __construct(array $config = []);
    public function prompt(string $prompt, array $options = []): object;
}
```

**Configuration:**

- `api_key` (string) - API key
- `model` (string) - Default model (default: `gpt-4o`)
- `max_tokens` (int) - Default max tokens (default: `1024`)

**Supported Models:**

- `gpt-4o`
- `gpt-4-turbo`
- `gpt-3.5-turbo`

---

### Mock Provider

```php
namespace Pagent\Providers;

class Mock implements Provider
{
    public function __construct(array $config = []);
    public function prompt(string $prompt, array $options = []): object;
    public function setResponses(array $responses): void;
}
```

**Configuration:**

- `responses` (array) - Map of prompts to responses

---

## Guards

All guards implement `Pagent\Contracts\Guard`.

### PIIGuard

Detects personally identifiable information.

**Detects:**

- Social Security Numbers (SSN)
- Credit card numbers
- Email addresses
- Phone numbers

**Example:**

```php
use Pagent\Guards\PIIGuard;

agent('secure')->guard(new PIIGuard());
```

---

### ContentFilterGuard

Blocks harmful or inappropriate content.

**Detects:**

- Profanity
- Harmful instructions
- Security bypass attempts

**Example:**

```php
use Pagent\Guards\ContentFilterGuard;

agent('safe')->guard(new ContentFilterGuard());
```

---

### PromptInjectionGuard

Prevents prompt injection attacks.

**Detects:**

- "Ignore previous instructions"
- "Forget everything"
- Role override attempts
- System prompt injection

**Example:**

```php
use Pagent\Guards\PromptInjectionGuard;

agent('protected')->guard(new PromptInjectionGuard());
```

---

## Tools

Tools are automatically validated based on PHP type hints.

### Tool Definition

```php
function toolName(
    Type $requiredParam,
    ?Type $optionalParam = null
): ReturnType {
    // Implementation
}
```

### Supported Types

- `string`, `int`, `float`, `bool`, `array`
- Nullable types: `?string`
- Default values for optional parameters

### Example

```php
function getWeather(string $city, ?string $units = 'metric'): array
{
    return [
        'temperature' => 22,
        'units' => $units,
        'city' => $city,
    ];
}

agent('weather')->tool('getWeather', getWeather(...));
```

---

## Orchestration

### Pipeline

Sequential execution of multiple agents.

```php
namespace Pagent\Orchestration;

class Pipeline
{
    public function __construct(string $name);
    public function agent(string|Agent $agent, ?Closure $transform = null): self;
    public function onError(Closure $handler): self;
    public function run(mixed $input): mixed;
    public function getResults(): array;
}
```

**Example:**

```php
$output = pipeline('flow')
    ->agent('writer')
    ->agent('editor', fn($output) => strtoupper($output)) // Transform
    ->onError(fn($e) => "Failed: {$e->getMessage()}")
    ->run('Input text');
```

---

### Handoff

Transfer context between agents.

```php
namespace Pagent\Orchestration;

class Handoff
{
    public function __construct(Agent $fromAgent);
    public function to(string|Agent $targetAgent): self;
    public function because(string $reason): self;
    public function transfer(): Agent;
}
```

**Example:**

```php
$nextAgent = agent('support')
    ->handoff()
    ->to('specialist')
    ->because('Needs technical expertise')
    ->transfer();
```

---

### Delegation

Manager-worker pattern.

```php
namespace Pagent\Orchestration;

class Delegation
{
    public function __construct(Agent $manager, string $task);
    public function to(string|Agent $worker): self;
    public function supervise(?Closure $supervisor = null): self;
    public function onComplete(Closure $callback): self;
    public function execute(): object;
}
```

**Example:**

```php
$result = agent('manager')
    ->delegate('Write a summary')
    ->to('writer')
    ->supervise(fn($output) => strlen($output) > 50)
    ->onComplete(fn($result) => log($result))
    ->execute();
```

---

## Evaluation

### Evaluator

```php
namespace Pagent\Evaluation;

class Evaluator
{
    public function __construct(string $agentName);
    public function dataset(Dataset|string $dataset): self;
    public function metric(string $name, Metric|Closure $metric): self;
    public function run(): EvaluationResult;
}
```

---

### Dataset

```php
namespace Pagent\Evaluation;

class Dataset
{
    public static function fromArray(array $items): self;
    public static function fromJson(string $path): self;
    public static function fromCsv(string $path): self;
    public function filter(Closure $callback): self;
    public function map(Closure $callback): self;
}
```

---

### Metrics

#### KeywordMetric

```php
new KeywordMetric(array $keywords, string $mode = 'all');
```

**Modes:**

- `all` - All keywords must be present
- `any` - At least one keyword must be present

---

#### LengthMetric

```php
new LengthMetric(?int $min = null, ?int $max = null);
```

---

#### SimilarityMetric

```php
new SimilarityMetric(string $expected, float $threshold = 0.8);
```

---

## Response Object

```php
object {
    public string $content;        // Generated text
    public string $model;          // Model identifier
    public int $tokens;            // Total tokens used
    public string $provider;       // Provider name
    public string $stop_reason;    // Completion reason
    public object $usage;          // Detailed token usage
    public ?array $tool_calls;     // Tool calls made (if any)
}
```

### Usage Object

```php
object {
    public int $input_tokens;     // Tokens in prompt
    public int $output_tokens;    // Tokens in response
    public int $total_tokens;     // Sum of both
}
```

---

## Constants & Defaults

### Default Models

- **Anthropic:** `claude-3-5-sonnet-20241022`
- **OpenAI:** `gpt-4o`

### Default Settings

- **Max Tokens:** `1024`
- **Temperature:** `0.7` (provider default)

### Environment Variables

- `ANTHROPIC_API_KEY`
- `OPENAI_API_KEY`

---

**See Also:**

- [Conceptual Guide](04-concepts-deep-dive.md) for design explanations
- [Examples](/examples) for working code samples
