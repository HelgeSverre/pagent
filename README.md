# Pagent 🩸

**A Pest-inspired LLM Agent Framework for PHP**

Build intelligent agents with automatic tool calling, multi-provider support, safety guards, and multi-agent orchestration—all with a clean, fluent API.

[![Latest Version](https://img.shields.io/packagist/v/helgesverre/pagent.svg?style=flat-square&v=1735257600)](https://packagist.org/packages/helgesverre/pagent)
[![Tests](https://img.shields.io/github/actions/workflow/status/helgesverre/pagent/tests.yml?branch=main&label=tests&style=flat-square&v=1735257600)](https://github.com/helgesverre/pagent/actions)
[![Total Downloads](https://img.shields.io/packagist/dt/helgesverre/pagent.svg?style=flat-square&v=1735257600)](https://packagist.org/packages/helgesverre/pagent)
[![PHP Version](https://img.shields.io/packagist/php-v/helgesverre/pagent.svg?style=flat-square&v=1735257600)](https://packagist.org/packages/helgesverre/pagent)
[![License](https://img.shields.io/github/license/HelgeSverre/pagent?style=flat-square&v=1735257600)](https://github.com/HelgeSverre/pagent/blob/main/LICENSE)

---

## Why Pagent?

- **🧪 Pest-Inspired API** - Fluent, expressive syntax that feels natural
- **🌊 Real-Time Streaming** - SSE streaming for ChatGPT-like experiences
- **💾 Memory & Persistence** - SQLite, File, and custom storage adapters
- **🔧 Automatic Tool Calling** - JSON schema generation from PHP functions
- **🤖 Multi-Provider** - Anthropic Claude, OpenAI GPT, Ollama (local), Mock (for testing)
- **🛡️ Safety Guards** - PII detection, content filtering, prompt injection prevention
- **📊 Evaluation Framework** - Test datasets with automated metrics and reports
- **🔄 Multi-Agent Orchestration** - Pipeline, handoff, and delegation patterns
- **📡 Observability & Tracing** - OpenTelemetry instrumentation with Jaeger, Zipkin, OTLP support
- **⚡ Production Ready** - 630+ tests, PHPStan level 9, PHP 8.3+ type safety

---

## Installation

```bash
composer require helgesverre/pagent
```

**Requirements:**

- PHP 8.3 or higher
- Composer 2.x

## Quick Start

```php
// Configure an agent
agent('assistant')
    ->provider('anthropic')
    ->system('You are a helpful assistant')
    ->temperature(0.7);

// Use the agent
$response = agent('assistant')->prompt('Hello!');
echo $response->content;

// Or stream responses in real-time
agent('assistant')->streamTo('Tell me a story', function ($chunk) {
    if ($chunk->isText()) {
        echo $chunk->content;
        flush();
    }
});

// Persist conversations across sessions
agent('support')
    ->memory('sqlite', ['path' => 'storage/conversations.db'])
    ->sessionId('user-123')
    ->contextWindow(100000)
    ->prompt('Hello');
```

**📖 Explore:** [Streaming Guide](docs/streaming.md) | [Memory & Persistence](docs/memory-persistence.md)

## Providers

### Mock Provider (for testing)

```php
$mock = mock([
    'Hello' => 'Hi there!',
    'How are you?' => 'I am doing great!'
]);

$response = $mock->prompt('Hello');
echo $response->content; // "Hi there!"
```

### Anthropic (Claude)

```bash
export ANTHROPIC_API_KEY="your-key"
```

```php
$claude = anthropic();
$response = $claude->prompt('Hello!', [
    'model' => 'claude-3-sonnet-20240229',
    'max_tokens' => 100
]);
```

### OpenAI (GPT)

```bash
export OPENAI_API_KEY="your-key"
```

```php
$gpt = openai();
$response = $gpt->prompt('Hello!', [
    'model' => 'gpt-4',
    'temperature' => 0.8
]);
```

### Ollama (Local LLMs)

Run models locally with complete privacy and zero API costs:

```bash
# Install Ollama and pull models
ollama pull qwen3:8b
ollama pull gpt-oss:20b
ollama serve
```

```php
$ollama = ollama();
$response = $ollama->prompt('Hello!', [
    'model' => 'qwen3:8b',
    'temperature' => 0.7
]);
```

**Benefits:**

- 🔒 Complete privacy - all data stays local
- 💰 Zero API costs
- ⚡ Low latency
- 🛠️ Full tool calling support (qwen3, llama3.1, mistral)
- 📡 NDJSON streaming

**📖 Full Guide:** [Ollama Integration](docs/ollama-integration.md)

## Agent Pattern

Agents provide a higher-level abstraction with conversation history:

```php
// Define an agent
agent('support')
    ->provider('anthropic')
    ->system('You are a customer support agent')
    ->model('claude-3-haiku-20240307')
    ->temperature(0.3);

// Have a conversation
$agent = agent('support');
$agent->prompt('I need help with my order');
$agent->prompt('Order number is 12345');

// Access conversation history
foreach ($agent->messages as $message) {
    echo "[{$message['role']}]: {$message['content']}\n";
}
```

## Provider Configuration

Pagent supports two ways to configure providers:

### String-Based (Simple)

Use provider names for quick setup with default configuration:

```php
agent('assistant')
    ->provider('anthropic')  // String name
    ->system('You are helpful');

// With config options
agent('custom')
    ->provider('ollama', ['base_url' => 'http://custom:11434', 'timeout' => 180])
    ->model('qwen3:8b');
```

### Instance-Based (Advanced)

Use helper functions or direct instantiation for custom configuration:

```php
// Using helper functions
agent('assistant')
    ->provider(anthropic(['api_key' => 'custom-key']))
    ->prompt('Hello');

agent('local')
    ->provider(ollama(['timeout' => 300, 'base_url' => 'http://10.0.0.5:11434']))
    ->model('llama3.1');

// Direct instantiation
use Pagent\Providers\OpenAI;

agent('custom')
    ->provider(new OpenAI(['api_key' => getenv('CUSTOM_KEY')]))
    ->prompt('Hello');
```

**When to use each:**

- **String-based**: Quick setup, standard configuration
- **Instance-based**: Custom config, multiple providers with same name, testing

## Provider-Specific Features

The library is intentionally "leaky" - you can use provider-specific features:

```php
// Anthropic-specific models
$response = anthropic()->prompt('Complex analysis task', [
    'model' => 'claude-3-opus-20240229',
    'max_tokens' => 4096
]);

// OpenAI-specific features
$response = openai()->prompt('Generate JSON data', [
    'model' => 'gpt-3.5-turbo-1106',
    'response_format' => ['type' => 'json_object']
]);
```

## Tool Calling

Define tools using PHP closures with automatic JSON schema generation:

```php
use Pagent\Tool\Tool;

// Create a tool from a closure
$weatherTool = Tool::fromClosure(
    'get_weather',
    'Get the current weather for a location',
    fn(string $location, bool $include_forecast = false) => "Weather data..."
);

// Add tools to an agent
$agent = agent('assistant')
    ->provider('anthropic')
    ->tool('calculate', 'Perform calculations', fn(int $a, int $b) => $a + $b)
    ->tool('get_time', 'Get current time', fn(string $tz = 'UTC') => date('H:i:s'));

// Execute tools
$result = $agent->executeTool('calculate', [10, 5]); // 15

// Generate provider-specific schemas
$anthropicSchema = $weatherTool->toAnthropicSchema();
$openaiSchema = $weatherTool->toOpenAISchema();
```

Type hints are automatically converted to JSON schema types:

- `string` → `"string"`
- `int` → `"integer"`
- `float` → `"number"`
- `bool` → `"boolean"`
- `array` → `"array"`

### Class-Based Tools

Pagent includes 9 production-ready class-based tools in the `Pagent\Tools` namespace:

```php
use Pagent\Tools\FileRead;
use Pagent\Tools\FileWrite;
use Pagent\Tools\WebFetch;
use Pagent\Tools\Bash;
use Pagent\Tools\Grep;
use Pagent\Tools\Glob;
use Pagent\Tools\PdfReader;
use Pagent\Tools\DataExtract;
use Pagent\Tools\SearchTool;

// Use class-based tools with agents
$agent = agent('assistant')
    ->provider('anthropic')
    ->tool(new FileRead())
    ->tool(new WebFetch())
    ->prompt('Read the file data.json and fetch https://api.example.com/data');

// Add multiple tools at once
$agent = agent('file-assistant')
    ->provider('anthropic')
    ->tools([
        new FileRead(baseDir: '/project'),
        new FileWrite(baseDir: '/project'),
        new Glob(baseDir: '/project'),
        new Grep(baseDir: '/project'),
    ])
    ->prompt('List all PHP files and show me the config');

// Create custom class-based tools
use Pagent\Tools\Tool;

class DatabaseQuery extends Tool
{
    public function name(): string
    {
        return 'query_database';
    }

    public function description(): string
    {
        return 'Execute a database query and return results';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'SQL query to execute'],
                'limit' => ['type' => 'integer', 'description' => 'Maximum rows to return'],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $params): mixed
    {
        // Your implementation here
        return ['results' => []];
    }
}

// Use your custom tool
agent('assistant')->tool(new DatabaseQuery());
```

Both closure-based and class-based tools implement `ToolInterface` and work seamlessly with all providers.

### SearchTool - Full-Text Search

The `SearchTool` provides powerful full-text search capabilities powered by TNTSearch, enabling agents to search through documents, files, and databases:

```php
use Pagent\Tools\SearchTool;

// Search through an array of documents (RAG pattern)
$documents = [
    ['id' => 1, 'title' => 'PHP Guide', 'content' => 'Learn PHP programming...'],
    ['id' => 2, 'title' => 'Laravel Tutorial', 'content' => 'Build web apps with Laravel...'],
];

agent('docs-assistant')
    ->tools([searchDocuments($documents)])
    ->prompt('Find information about PHP');

// Search through files in a directory
agent('codebase-helper')
    ->tools([new SearchTool(paths: ['docs/', 'src/'])])
    ->prompt('Find all documentation about API endpoints');

// Use a pre-built search index
agent('knowledge-bot')
    ->tools([searchIndex('knowledge/docs.index')])
    ->prompt('Search the knowledge base for deployment guides');

// Database-backed search
agent('db-search')
    ->tools([
        new SearchTool(
            query: 'SELECT id, title, content FROM articles',
            connection: ['driver' => 'mysql', 'host' => 'localhost', 'database' => 'mydb']
        )
    ])
    ->prompt('Find articles about Laravel');
```

**Key Features:**

- **Multiple Document Sources**: Arrays, files, directories, databases, or pre-built indexes
- **Fuzzy Matching**: Handles typos and approximate matches
- **BM25 Ranking**: Industry-standard relevance scoring
- **Flexible Returns**: Get just IDs or full document content
- **Fast Performance**: Sub-millisecond to millisecond search times
- **UTF-8 Support**: Works with international characters

**Configuration Options:**

```php
new SearchTool(
    documents: $docs,           // Array of documents to index
    returnContent: true,        // Return full documents vs just IDs
    fuzzy: true,                // Enable fuzzy search
    fuzziness: 2,               // Levenshtein distance (1-3)
    maxResults: 20,             // Max results to return
    storage: ':memory:',        // Index storage location
    stemmer: PorterStemmer::class  // Custom stemmer class
);
```

**Search Results:**

```php
[
    'hits' => 3,
    'execution_time' => '1.5ms',
    'results' => [
        ['id' => 1, 'score' => 4.2, 'document' => [...]],
        ['id' => 2, 'score' => 3.8, 'document' => [...]],
    ]
]
```

Perfect for building:

- RAG (Retrieval-Augmented Generation) systems
- Documentation search agents
- Knowledge base assistants
- Semantic code search
- Content discovery tools

## Observability & Distributed Tracing

Pagent includes comprehensive OpenTelemetry instrumentation for monitoring and debugging your LLM agents in production.

### Quick Start

```php
use function Pagent\{agent, telemetry_console};

// Enable console telemetry for debugging
telemetry_console(verbose: true);

agent('assistant')
    ->provider('anthropic')
    ->telemetry(true)  // Enable tracing for this agent
    ->prompt('Hello!');

// Console shows:
// ┌─ Span: agent.prompt
// │  Duration: 1.23s
// │  Attributes:
// │    - gen_ai.system: anthropic
// │    - gen_ai.usage.total_tokens: 125
// └─
```

### Production Monitoring

Connect to Jaeger, Zipkin, or any OpenTelemetry-compatible platform:

```php
use function Pagent\{agent, telemetry_jaeger};

// Export to Jaeger
telemetry_jaeger('http://localhost:14268/api/traces');

// All operations automatically traced
agent('support')
    ->telemetry(true)
    ->tool('search', 'Search knowledge base', $searchFn)
    ->prompt('Help me find documentation');

// View traces at http://localhost:16686
```

### What Gets Traced

- **Agent Operations** - Every prompt, stream, and tool execution
- **LLM Requests** - Provider calls with token usage
- **Tool Executions** - Arguments, results, and duration
- **Guard Checks** - Security validations
- **Memory Operations** - Load/save operations
- **Workflows** - Multi-agent pipeline orchestration

### Supported Platforms

- **Jaeger** - Open-source distributed tracing
- **Zipkin** - Distributed tracing system
- **OTLP** - Generic protocol (Datadog, New Relic, Honeycomb, etc.)
- **Console** - Local debugging output

### Multi-Agent Workflow Tracing

```php
use function Pagent\{agent, pipeline, telemetry_jaeger};

telemetry_jaeger('http://localhost:14268/api/traces');

// Enable telemetry on agents
agent('researcher')->provider('anthropic')->telemetry(true);
agent('writer')->provider('anthropic')->telemetry(true);

// Run workflow - creates hierarchical trace
pipeline('content-creation')
    ->step('research', agent('researcher'))
    ->step('write', agent('writer'))
    ->run('Write article about PHP');

// Trace shows:
// workflow.pipeline
//   ├─ workflow.step (research)
//   │  └─ agent.prompt → llm.request → tool.execute
//   └─ workflow.step (write)
//      └─ agent.prompt → llm.request
```

### Benefits

- **Debug Complex Workflows** - Visualize multi-agent interactions
- **Performance Monitoring** - Track latency and bottlenecks
- **Token Usage Tracking** - Real-time token consumption
- **Cost Visibility** - Understand API usage patterns
- **Compliance** - Complete audit trail

**📖 Full Guide:** [Observability Documentation](docs/observability.md)

## Development

### Quick Commands

```bash
# Setup project
just setup              # Install dependencies and git hooks

# Testing
just test               # Run all tests
just coverage           # Run tests with coverage report

# Code Quality
just format             # Fix code style (PHP + Markdown)
just analyse            # Run PHPStan static analysis
just pr                 # Prepare for PR (fix, analyse, test)

# Observability Stack
just obs-up             # Start observability tools (Jaeger, Phoenix, Langfuse, etc.)
just obs-down           # Stop and remove observability stack
```

### Manual Testing

```bash
# Run unit tests (no API calls)
./vendor/bin/pest --exclude-group=api

# Run API integration tests (requires API keys)
cp .env.example .env    # Add your keys to .env
./vendor/bin/pest --group=api
```

## Documentation

### The Complete Guide

The **[Pagent Guide](guide/complete.md)** is a comprehensive 28-chapter tutorial covering everything from basics to advanced patterns:

- **Part 1: Foundations** (Chapters 1-5) - Core concepts and basic usage
- **Part 2: Tool Integration** (Chapters 6-9) - External system interactions
- **Part 3: Real-Time Interaction** (Chapters 10-11) - Streaming responses
- **Part 4: Persistence and State** (Chapters 12-13) - Memory and conversations
- **Part 5: Reliability and Safety** (Chapters 14-15) - Production robustness
- **Part 6: Multi-Agent Orchestration** (Chapters 16-19) - Agent coordination
- **Part 7: Quality Assurance** (Chapters 20-21) - Testing and evaluation
- **Part 8: Observability** (Chapters 22-23) - Monitoring and debugging
- **Part 9: Integration** (Chapters 24-25) - Framework integration
- **Part 10: Production Excellence** (Chapters 26-28) - Optimization and deployment

See **[guide/README.md](guide/README.md)** for learning paths based on your experience level.

### Integration Guides

Learn how to integrate Pagent into your application:

- **[Vanilla PHP](docs/vanilla-php.md)** - Pure PHP integration without frameworks
- **[Slim Framework Integration](docs/slim-integration.md)** - Complete Slim 4.x setup with DI and middleware
- **[Laravel Integration](docs/laravel-integration.md)** - Laravel setup with service providers and facades
- **[Symfony Integration](docs/symfony-integration.md)** - Symfony bundle integration with DI container

### Feature Guides

Deep-dive into specific features:

- **[Streaming Guide](docs/streaming.md)** - Real-time SSE streaming implementation
- **[Memory & Persistence](docs/memory-persistence.md)** - SQLite, File, and custom storage adapters
- **[Orchestration Workflows](docs/orchestration-workflows.md)** - Multi-agent patterns: pipelines, handoffs, delegation

See the [docs/](docs/) folder for all guides.

---

## Contributing

We welcome contributions! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details on:

- Development setup
- Running tests
- Code style guidelines
- Pull request process

Read our [Code of Conduct](CODE_OF_CONDUCT.md) and [Security Policy](SECURITY.md).

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for recent changes.

## License

MIT License. See [LICENSE](LICENSE) for details.

## Credits

Created by [Helge Sverre](https://helgesver.re).

Inspired by [Pest](https://pestphp.com)'s elegant API design.
