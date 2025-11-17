# Chapter 6: Introduction to Tool Calling

## What You'll Learn

By the end of this chapter, you'll be able to:
- Define tools with proper JSON schemas for LLM function calling
- Implement automatic tool discovery and registration
- Handle tool execution results and continue conversations
- Debug common tool calling issues
- Build robust error handling for tool failures

## Prerequisites

- Completed Chapters 1-5 of this tutorial
- Understanding of JSON Schema basics
- Familiarity with PHP closures and callbacks
- Basic knowledge of API integration patterns

## Time Estimate: 45 minutes

## Final Result

You'll build a working AI assistant that can:
- Perform calculations using a calculator tool
- Fetch weather data from an API
- Query databases for information
- Perform file system operations safely

---

## Understanding Function Calling in LLMs

Modern LLMs like Claude and GPT-4 support "function calling" or "tool use" - the ability to invoke external functions to extend their capabilities beyond text generation. Think of it like giving the AI hands to interact with the world.

### The Tool Calling Lifecycle

```
User Query → AI Decides to Use Tool → Tool Executes → Result Returns to AI → AI Responds
```

Let's see this in action with a simple example:

```php
use function Pagent\anthropic;

$agent = anthropic()
    ->withTools([
        [
            'name' => 'get_current_time',
            'description' => 'Get the current time',
            'input_schema' => [
                'type' => 'object',
                'properties' => []
            ]
        ]
    ])
    ->withToolHandler(function (string $name, array $params) {
        if ($name === 'get_current_time') {
            return ['time' => date('Y-m-d H:i:s')];
        }
    });

$response = $agent->sendMessage('What time is it?');
echo $response->content; // "The current time is 2024-03-15 10:30:45"
```

The AI recognized it needed the current time, called our tool, and used the result in its response.

## Tool Definition Structure

Every tool needs three essential components:

### 1. Name
A unique identifier for the tool (snake_case by convention):

```php
'name' => 'calculate_sum'
```

### 2. Description
Clear explanation of what the tool does - this helps the AI decide when to use it:

```php
'description' => 'Calculate the sum of two numbers'
```

### 3. Input Schema
A JSON Schema defining the tool's parameters:

```php
'input_schema' => [
    'type' => 'object',
    'properties' => [
        'a' => [
            'type' => 'number',
            'description' => 'First number to add'
        ],
        'b' => [
            'type' => 'number',
            'description' => 'Second number to add'
        ]
    ],
    'required' => ['a', 'b']
]
```

## Building Your First Tool: Calculator

Let's build a complete calculator tool step by step:

```php
<?php
declare(strict_types=1);

namespace App\Tools;

class CalculatorTool
{
    public static function getDefinition(): array
    {
        return [
            'name' => 'calculator',
            'description' => 'Perform basic mathematical operations',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'operation' => [
                        'type' => 'string',
                        'enum' => ['add', 'subtract', 'multiply', 'divide'],
                        'description' => 'The mathematical operation to perform'
                    ],
                    'a' => [
                        'type' => 'number',
                        'description' => 'First operand'
                    ],
                    'b' => [
                        'type' => 'number',
                        'description' => 'Second operand'
                    ]
                ],
                'required' => ['operation', 'a', 'b']
            ]
        ];
    }

    public static function execute(array $params): array
    {
        $a = $params['a'];
        $b = $params['b'];

        $result = match ($params['operation']) {
            'add' => $a + $b,
            'subtract' => $a - $b,
            'multiply' => $a * $b,
            'divide' => $b !== 0 ? $a / $b : null,
        };

        if ($result === null && $params['operation'] === 'divide') {
            return ['error' => 'Division by zero'];
        }

        return ['result' => $result];
    }
}
```

Now integrate it with Pagent:

```php
use function Pagent\anthropic;
use App\Tools\CalculatorTool;

$agent = anthropic()
    ->withTools([CalculatorTool::getDefinition()])
    ->withToolHandler(function (string $name, array $params) {
        if ($name === 'calculator') {
            return CalculatorTool::execute($params);
        }
        return ['error' => 'Unknown tool'];
    });

$response = $agent->sendMessage('What is 25 multiplied by 4?');
echo $response->content; // "25 multiplied by 4 equals 100."
```

### Testing the Calculator

Let's test various operations:

```php
// Test addition
$response = $agent->sendMessage('Add 15 and 27');
assert(str_contains($response->content, '42'));

// Test division by zero handling
$response = $agent->sendMessage('Divide 10 by 0');
assert(str_contains($response->content, 'error') ||
       str_contains($response->content, 'cannot'));

// Test complex calculation
$response = $agent->sendMessage(
    'Calculate: (10 + 5) * 3. Show me step by step.'
);
// AI will make multiple tool calls
```

## Weather API Integration

Now let's build a more complex tool that integrates with an external API:

```php
<?php
declare(strict_types=1);

namespace App\Tools;

use GuzzleHttp\Client;

class WeatherTool
{
    private Client $client;
    private string $apiKey;

    public function __construct(string $apiKey)
    {
        $this->client = new Client([
            'base_uri' => 'https://api.openweathermap.org/data/2.5/',
            'timeout' => 5.0,
        ]);
        $this->apiKey = $apiKey;
    }

    public function getDefinition(): array
    {
        return [
            'name' => 'get_weather',
            'description' => 'Get current weather for a city',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'city' => [
                        'type' => 'string',
                        'description' => 'City name'
                    ],
                    'country_code' => [
                        'type' => 'string',
                        'description' => 'Optional ISO 3166 country code',
                        'pattern' => '^[A-Z]{2}$'
                    ],
                    'units' => [
                        'type' => 'string',
                        'enum' => ['metric', 'imperial'],
                        'default' => 'metric',
                        'description' => 'Temperature units'
                    ]
                ],
                'required' => ['city']
            ]
        ];
    }

    public function execute(array $params): array
    {
        try {
            $query = [
                'q' => $params['city'] .
                       (isset($params['country_code'])
                        ? ',' . $params['country_code']
                        : ''),
                'units' => $params['units'] ?? 'metric',
                'appid' => $this->apiKey
            ];

            $response = $this->client->get('weather', [
                'query' => $query
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return [
                'temperature' => $data['main']['temp'],
                'feels_like' => $data['main']['feels_like'],
                'description' => $data['weather'][0]['description'],
                'humidity' => $data['main']['humidity'],
                'wind_speed' => $data['wind']['speed']
            ];
        } catch (\Exception $e) {
            return [
                'error' => 'Failed to fetch weather: ' . $e->getMessage()
            ];
        }
    }
}
```

### Using the Weather Tool

```php
$weatherTool = new WeatherTool($_ENV['OPENWEATHER_API_KEY']);

$agent = anthropic()
    ->withTools([$weatherTool->getDefinition()])
    ->withToolHandler(function (string $name, array $params) use ($weatherTool) {
        if ($name === 'get_weather') {
            return $weatherTool->execute($params);
        }
        return ['error' => 'Unknown tool'];
    });

$response = $agent->sendMessage(
    "What's the weather like in London? Should I bring an umbrella?"
);
// AI fetches weather and provides advice based on conditions
```

## Database Query Tool

Let's create a tool for safe database queries:

```php
<?php
declare(strict_types=1);

namespace App\Tools;

use PDO;

class DatabaseTool
{
    private PDO $pdo;
    private array $allowedTables;

    public function __construct(PDO $pdo, array $allowedTables = [])
    {
        $this->pdo = $pdo;
        $this->allowedTables = $allowedTables;
    }

    public function getDefinition(): array
    {
        return [
            'name' => 'query_database',
            'description' => 'Query the database for information',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'table' => [
                        'type' => 'string',
                        'enum' => $this->allowedTables,
                        'description' => 'Table to query'
                    ],
                    'conditions' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'column' => ['type' => 'string'],
                                'operator' => [
                                    'type' => 'string',
                                    'enum' => ['=', '>', '<', '>=', '<=', 'LIKE']
                                ],
                                'value' => ['type' => ['string', 'number', 'boolean']]
                            ],
                            'required' => ['column', 'operator', 'value']
                        ],
                        'description' => 'WHERE conditions'
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 100,
                        'default' => 10
                    ]
                ],
                'required' => ['table']
            ]
        ];
    }

    public function execute(array $params): array
    {
        try {
            // Build safe query
            $query = "SELECT * FROM {$params['table']}";
            $bindings = [];

            if (!empty($params['conditions'])) {
                $whereClauses = [];
                foreach ($params['conditions'] as $i => $condition) {
                    $placeholder = ":param{$i}";
                    $whereClauses[] = "{$condition['column']} {$condition['operator']} {$placeholder}";
                    $bindings[$placeholder] = $condition['value'];
                }
                $query .= " WHERE " . implode(' AND ', $whereClauses);
            }

            $query .= " LIMIT " . ($params['limit'] ?? 10);

            $stmt = $this->pdo->prepare($query);
            $stmt->execute($bindings);

            return [
                'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC),
                'count' => $stmt->rowCount()
            ];
        } catch (\Exception $e) {
            return ['error' => 'Database query failed: ' . $e->getMessage()];
        }
    }
}
```

## Automatic Tool Discovery

For larger applications, manually registering each tool becomes cumbersome. Let's implement automatic tool discovery:

```php
<?php
declare(strict_types=1);

namespace App\Tools;

class ToolRegistry
{
    private array $tools = [];
    private array $handlers = [];

    public function discover(string $directory): void
    {
        $files = glob($directory . '/*Tool.php');

        foreach ($files as $file) {
            $className = $this->getClassNameFromFile($file);

            if (class_exists($className)) {
                $this->registerClass($className);
            }
        }
    }

    private function registerClass(string $className): void
    {
        // Check if class implements our tool interface
        if (!method_exists($className, 'getDefinition') ||
            !method_exists($className, 'execute')) {
            return;
        }

        $instance = new $className();
        $definition = $instance->getDefinition();

        $this->tools[] = $definition;
        $this->handlers[$definition['name']] = [$instance, 'execute'];
    }

    private function getClassNameFromFile(string $file): string
    {
        $contents = file_get_contents($file);

        // Extract namespace
        preg_match('/namespace\s+([^;]+);/', $contents, $nsMatch);
        $namespace = $nsMatch[1] ?? '';

        // Extract class name
        preg_match('/class\s+(\w+)/', $contents, $classMatch);
        $className = $classMatch[1] ?? '';

        return $namespace . '\\' . $className;
    }

    public function getTools(): array
    {
        return $this->tools;
    }

    public function handleToolCall(string $name, array $params): array
    {
        if (!isset($this->handlers[$name])) {
            return ['error' => "Unknown tool: {$name}"];
        }

        try {
            return call_user_func($this->handlers[$name], $params);
        } catch (\Exception $e) {
            return ['error' => "Tool execution failed: " . $e->getMessage()];
        }
    }
}
```

Using automatic discovery:

```php
$registry = new ToolRegistry();
$registry->discover(__DIR__ . '/Tools');

$agent = anthropic()
    ->withTools($registry->getTools())
    ->withToolHandler([$registry, 'handleToolCall']);

// Now all tools in the Tools directory are available!
```

## Debugging Tool Calling Issues

Tool calling can fail in subtle ways. Here's how to debug common issues:

### 1. Tool Not Being Called

Add debug output to see what the AI is thinking:

```php
$agent = anthropic()
    ->withSystemPrompt('You have access to tools. Always explain your reasoning before using them.')
    ->withTools([$weatherTool->getDefinition()])
    ->withToolHandler(function (string $name, array $params) {
        echo "Tool called: {$name}\n";
        echo "Parameters: " . json_encode($params, JSON_PRETTY_PRINT) . "\n";
        // ... handle tool
    });
```

### 2. Schema Validation Failures

Validate your schemas before using them:

```php
function validateToolSchema(array $tool): array
{
    $errors = [];

    if (!isset($tool['name'])) {
        $errors[] = 'Missing tool name';
    }

    if (!isset($tool['description'])) {
        $errors[] = 'Missing tool description';
    }

    if (!isset($tool['input_schema'])) {
        $errors[] = 'Missing input schema';
    } elseif ($tool['input_schema']['type'] !== 'object') {
        $errors[] = 'Input schema must be of type object';
    }

    return $errors;
}

// Use it
$errors = validateToolSchema($toolDefinition);
if (!empty($errors)) {
    throw new \InvalidArgumentException(
        'Invalid tool schema: ' . implode(', ', $errors)
    );
}
```

### 3. Tool Execution Monitoring

Track tool performance and failures:

```php
class ToolMonitor
{
    private array $metrics = [];

    public function recordExecution(
        string $tool,
        float $startTime,
        bool $success,
        ?string $error = null
    ): void {
        $duration = microtime(true) - $startTime;

        if (!isset($this->metrics[$tool])) {
            $this->metrics[$tool] = [
                'calls' => 0,
                'failures' => 0,
                'total_time' => 0,
                'errors' => []
            ];
        }

        $this->metrics[$tool]['calls']++;
        $this->metrics[$tool]['total_time'] += $duration;

        if (!$success) {
            $this->metrics[$tool]['failures']++;
            $this->metrics[$tool]['errors'][] = $error;
        }
    }

    public function getReport(): array
    {
        $report = [];
        foreach ($this->metrics as $tool => $data) {
            $report[$tool] = [
                'success_rate' => ($data['calls'] - $data['failures']) / $data['calls'],
                'avg_duration' => $data['total_time'] / $data['calls'],
                'total_calls' => $data['calls'],
                'recent_errors' => array_slice($data['errors'], -3)
            ];
        }
        return $report;
    }
}
```

## Error Handling for Tools

Robust error handling ensures your assistant remains helpful even when tools fail:

```php
class ResilientToolHandler
{
    private array $handlers;
    private int $maxRetries;

    public function __construct(array $handlers, int $maxRetries = 2)
    {
        $this->handlers = $handlers;
        $this->maxRetries = $maxRetries;
    }

    public function handle(string $name, array $params): array
    {
        if (!isset($this->handlers[$name])) {
            return $this->errorResponse("Unknown tool: {$name}");
        }

        $lastError = null;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                $result = call_user_func($this->handlers[$name], $params);

                // Validate result structure
                if (!is_array($result)) {
                    throw new \RuntimeException('Tool must return array');
                }

                if (isset($result['error'])) {
                    // Tool returned an error
                    if ($attempt < $this->maxRetries) {
                        sleep(1); // Brief delay before retry
                        continue;
                    }
                    return $result;
                }

                return $result;

            } catch (\Exception $e) {
                $lastError = $e->getMessage();

                if ($attempt < $this->maxRetries) {
                    sleep(1); // Brief delay before retry
                    continue;
                }
            }
        }

        return $this->errorResponse(
            "Tool failed after {$this->maxRetries} attempts: {$lastError}"
        );
    }

    private function errorResponse(string $message): array
    {
        return [
            'error' => $message,
            'fallback' => 'I encountered an issue with that tool. Let me try to help another way.'
        ];
    }
}
```

## Complete Example: Multi-Tool Assistant

Let's put it all together with a file system operations tool:

```php
<?php
declare(strict_types=1);

namespace App\Tools;

class FileSystemTool
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/');
    }

    public function getDefinition(): array
    {
        return [
            'name' => 'file_system',
            'description' => 'Read and list files in the allowed directory',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'enum' => ['read', 'list', 'exists'],
                        'description' => 'Operation to perform'
                    ],
                    'path' => [
                        'type' => 'string',
                        'description' => 'Relative path from base directory'
                    ]
                ],
                'required' => ['action', 'path']
            ]
        ];
    }

    public function execute(array $params): array
    {
        // Sanitize path to prevent directory traversal
        $path = $this->sanitizePath($params['path']);
        $fullPath = $this->basePath . '/' . $path;

        // Verify path is within allowed directory
        if (!$this->isPathAllowed($fullPath)) {
            return ['error' => 'Access denied: Path outside allowed directory'];
        }

        return match ($params['action']) {
            'read' => $this->readFile($fullPath),
            'list' => $this->listDirectory($fullPath),
            'exists' => ['exists' => file_exists($fullPath)],
            default => ['error' => 'Unknown action']
        };
    }

    private function sanitizePath(string $path): string
    {
        // Remove any .. or absolute paths
        $path = str_replace('..', '', $path);
        $path = ltrim($path, '/');
        return $path;
    }

    private function isPathAllowed(string $path): bool
    {
        $realPath = realpath($path);
        $realBase = realpath($this->basePath);

        if ($realPath === false || $realBase === false) {
            return false;
        }

        return strpos($realPath, $realBase) === 0;
    }

    private function readFile(string $path): array
    {
        if (!is_file($path)) {
            return ['error' => 'Not a file'];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return ['error' => 'Failed to read file'];
        }

        return [
            'content' => $content,
            'size' => filesize($path),
            'modified' => date('Y-m-d H:i:s', filemtime($path))
        ];
    }

    private function listDirectory(string $path): array
    {
        if (!is_dir($path)) {
            return ['error' => 'Not a directory'];
        }

        $items = scandir($path);
        if ($items === false) {
            return ['error' => 'Failed to list directory'];
        }

        $result = [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . '/' . $item;
            $result[] = [
                'name' => $item,
                'type' => is_dir($itemPath) ? 'directory' : 'file',
                'size' => is_file($itemPath) ? filesize($itemPath) : null
            ];
        }

        return ['items' => $result];
    }
}

// Create the complete assistant
$calculator = new CalculatorTool();
$weather = new WeatherTool($_ENV['WEATHER_KEY']);
$filesystem = new FileSystemTool(__DIR__ . '/data');

$tools = [
    $calculator::getDefinition(),
    $weather->getDefinition(),
    $filesystem->getDefinition()
];

$handlers = [
    'calculator' => [$calculator, 'execute'],
    'get_weather' => [$weather, 'execute'],
    'file_system' => [$filesystem, 'execute']
];

$toolHandler = new ResilientToolHandler($handlers);

$agent = anthropic()
    ->withTools($tools)
    ->withToolHandler([$toolHandler, 'handle'])
    ->withSystemPrompt(
        'You are a helpful assistant with access to calculation, weather, and file system tools. ' .
        'Use them whenever they would be helpful to answer the user\'s questions.'
    );

// Test the complete system
$response = $agent->sendMessage(
    "Check if report.txt exists in the data folder. If it does, read it and tell me " .
    "the word count. Also, what's 15% of the word count?"
);

echo $response->content;
// The assistant will:
// 1. Check if file exists using file_system tool
// 2. Read the file if it exists
// 3. Count words in the content
// 4. Calculate 15% using the calculator tool
// 5. Provide a comprehensive response
```

## Summary

You've learned how to:
- Define tools with JSON schemas for LLM function calling
- Create tool handlers that execute when the AI needs them
- Implement automatic tool discovery for scalability
- Debug tool calling issues with monitoring and logging
- Build robust error handling with retries and fallbacks

Key takeaways:
- **Clear descriptions** help the AI choose the right tool
- **Schema validation** prevents runtime errors
- **Error handling** keeps your assistant helpful even when tools fail
- **Security considerations** are crucial for file and database operations
- **Monitoring** helps you improve tool reliability over time

## Next Steps

In Chapter 7, we'll explore streaming responses and real-time interactions. You'll learn to:
- Stream responses as they're generated
- Handle partial tool calls in streams
- Build interactive CLI applications
- Implement progress indicators for long operations

## Practice Exercises

1. **Email Tool**: Create a tool that can search and read emails (mock data)
2. **Unit Converter**: Build a tool for converting between units (metric/imperial)
3. **Translation Tool**: Integrate a translation API as a tool
4. **Code Executor**: Create a safe Python/JavaScript code execution tool

## Additional Resources

- [Anthropic Tool Use Documentation](https://docs.anthropic.com/claude/docs/tool-use)
- [OpenAI Function Calling Guide](https://platform.openai.com/docs/guides/function-calling)
- [JSON Schema Specification](https://json-schema.org/specification.html)
- [Pagent Tool Examples](https://github.com/pagent/pagent/tree/main/examples/tools)