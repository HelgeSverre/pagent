# Chapter 7: Building Custom Tools

In Chapter 6, we learned how to add tool calling capabilities to agents using simple closures. But as your applications grow more sophisticated, you'll need tools that are reusable, composable, and well-documented. You'll want to share tools across multiple agents, add validation logic, handle edge cases gracefully, and create libraries of functionality that other developers can use.

This is where custom tool classes come in. Pagent provides a powerful tool system that lets you build professional-grade tools with proper interfaces, automatic schema generation, and built-in validation. In this chapter, we'll explore how to create custom tools from scratch, implement the `ToolInterface`, and build production-ready tool libraries.

## Understanding the Tool Architecture

Pagent offers two approaches to creating tools: quick closures (which we covered in Chapter 6) and custom tool classes. While closures are great for simple, one-off tools, custom classes give you complete control over tool behavior.

The foundation is the `ToolInterface`, which defines the contract every tool must implement:

```php
namespace Pagent\Contracts;

interface ToolInterface
{
    public function name(): string;
    public function description(): string;
    public function execute(array $params): mixed;
    public function toAnthropicSchema(): array;
    public function toOpenAISchema(): array;
}
```

Every tool needs five things: a name, a description, execution logic, and schema definitions for both Anthropic and OpenAI formats. This interface ensures your tools work seamlessly with any provider.

## Creating Your First Custom Tool

Let's build a simple but complete custom tool - a calculator that performs basic arithmetic operations:

```php
use Pagent\Contracts\ToolInterface;

class Calculator implements ToolInterface
{
    public function name(): string
    {
        return 'calculator';
    }

    public function description(): string
    {
        return 'Perform basic arithmetic operations (add, subtract, multiply, divide)';
    }

    public function execute(array $params): mixed
    {
        $operation = $params['operation'];
        $x = $params['x'];
        $y = $params['y'];

        return match($operation) {
            'add' => $x + $y,
            'subtract' => $x - $y,
            'multiply' => $x * $y,
            'divide' => $y !== 0 ? $x / $y : throw new RuntimeException('Division by zero'),
            default => throw new RuntimeException("Unknown operation: {$operation}"),
        };
    }

    public function toAnthropicSchema(): array
    {
        return [
            'name' => $this->name(),
            'description' => $this->description(),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'operation' => [
                        'type' => 'string',
                        'description' => 'The operation to perform: add, subtract, multiply, or divide',
                    ],
                    'x' => [
                        'type' => 'number',
                        'description' => 'First number',
                    ],
                    'y' => [
                        'type' => 'number',
                        'description' => 'Second number',
                    ],
                ],
                'required' => ['operation', 'x', 'y'],
            ],
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
                        'operation' => [
                            'type' => 'string',
                            'description' => 'The operation to perform: add, subtract, multiply, or divide',
                        ],
                        'x' => [
                            'type' => 'number',
                            'description' => 'First number',
                        ],
                        'y' => [
                            'type' => 'number',
                            'description' => 'Second number',
                        ],
                    ],
                    'required' => ['operation', 'x', 'y'],
                ],
            ],
        ];
    }
}
```

Now you can use this tool with any agent:

```php
$agent = agent('math-assistant')
    ->provider(anthropic())
    ->tool(new Calculator())
    ->build();

$response = $agent->prompt('What is 156 multiplied by 23?');
// The agent will automatically call the calculator tool and return: "3,588"
```

The LLM sees the tool's schema, understands what it does, and knows exactly what parameters to provide. When it decides to use the calculator, Pagent automatically calls your `execute()` method with the right parameters.

## Using the Abstract Tool Class

Writing schema definitions for both Anthropic and OpenAI can be repetitive - the schemas are almost identical, just structured differently. Pagent provides an abstract `Tool` class that handles this boilerplate:

```php
use Pagent\Tools\Tool;

class Calculator extends Tool
{
    public function name(): string
    {
        return 'calculator';
    }

    public function description(): string
    {
        return 'Perform basic arithmetic operations (add, subtract, multiply, divide)';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'operation' => [
                    'type' => 'string',
                    'description' => 'The operation to perform: add, subtract, multiply, or divide',
                ],
                'x' => [
                    'type' => 'number',
                    'description' => 'First number',
                ],
                'y' => [
                    'type' => 'number',
                    'description' => 'Second number',
                ],
            ],
            'required' => ['operation', 'x', 'y'],
        ];
    }

    public function execute(array $params): mixed
    {
        $operation = $params['operation'];
        $x = $params['x'];
        $y = $params['y'];

        return match($operation) {
            'add' => $x + $y,
            'subtract' => $x - $y,
            'multiply' => $x * $y,
            'divide' => $y !== 0 ? $x / $y : throw new RuntimeException('Division by zero'),
            default => throw new RuntimeException("Unknown operation: {$operation}"),
        };
    }
}
```

The abstract `Tool` class provides default implementations of `toAnthropicSchema()` and `toOpenAISchema()` that automatically convert your `parameters()` definition into the correct format for each provider. This eliminates duplication while maintaining full compatibility.

## Building Tools with Configuration

Real-world tools often need configuration. A file reader needs to know which directory to allow. A web fetcher needs timeout settings. A database tool needs connection credentials. You can pass this configuration through the constructor:

```php
use Pagent\Tools\Tool;
use RuntimeException;

class FileReader extends Tool
{
    public function __construct(
        private ?string $baseDir = null,
        private int $maxSize = 10 * 1024 * 1024, // 10MB default
    ) {}

    public function name(): string
    {
        return 'file_read';
    }

    public function description(): string
    {
        return 'Read the contents of a file. Returns the full file contents as a string.';
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
        $path = $params['path'] ?? throw new RuntimeException('Path parameter is required');

        // Resolve absolute path
        $absolutePath = $this->resolvePath($path);

        // Check if file exists
        if (!file_exists($absolutePath)) {
            throw new RuntimeException("File not found: {$path}");
        }

        // Check if it's a file (not directory)
        if (!is_file($absolutePath)) {
            throw new RuntimeException("Path is not a file: {$path}");
        }

        // Check file size
        $fileSize = filesize($absolutePath);
        if ($fileSize > $this->maxSize) {
            throw new RuntimeException(
                "File too large: {$fileSize} bytes (max: {$this->maxSize})"
            );
        }

        // Read file
        $contents = file_get_contents($absolutePath);
        if ($contents === false) {
            throw new RuntimeException("Failed to read file: {$path}");
        }

        return $contents;
    }

    private function resolvePath(string $path): string
    {
        // If baseDir is set, resolve relative to it
        if ($this->baseDir !== null) {
            $fullPath = $this->baseDir . DIRECTORY_SEPARATOR . $path;
            $realBaseDir = realpath($this->baseDir);

            if ($realBaseDir === false) {
                throw new RuntimeException('Invalid base directory');
            }

            $normalizedPath = realpath($fullPath);
            if ($normalizedPath === false) {
                throw new RuntimeException("File not found: {$path}");
            }

            // Prevent path traversal attacks
            if (!str_starts_with($normalizedPath, $realBaseDir)) {
                throw new RuntimeException("Path traversal detected: {$path}");
            }

            return $normalizedPath;
        }

        return realpath($path) ?: throw new RuntimeException("File not found: {$path}");
    }
}
```

Now you can configure the tool for different use cases:

```php
// Unrestricted file reader (dangerous in production!)
$agent->tool(new FileReader());

// Restrict to a specific directory
$agent->tool(new FileReader(baseDir: '/var/data/documents'));

// Custom size limit
$agent->tool(new FileReader(baseDir: '/tmp', maxSize: 1024 * 1024)); // 1MB
```

This pattern lets you create flexible tools that adapt to different security requirements and operational constraints.

## Implementing Robust Validation

While Pagent provides automatic validation for tool arguments through the `ToolValidator` class, you'll often want additional validation logic specific to your tool's business rules. The `execute()` method is where you implement this validation:

```php
use Pagent\Tools\Tool;
use RuntimeException;

class EmailSender extends Tool
{
    public function __construct(
        private string $smtpHost,
        private int $smtpPort,
        private string $username,
        private string $password,
    ) {}

    public function name(): string
    {
        return 'send_email';
    }

    public function description(): string
    {
        return 'Send an email to a recipient';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'to' => [
                    'type' => 'string',
                    'description' => 'Recipient email address',
                ],
                'subject' => [
                    'type' => 'string',
                    'description' => 'Email subject',
                ],
                'body' => [
                    'type' => 'string',
                    'description' => 'Email body',
                ],
            ],
            'required' => ['to', 'subject', 'body'],
        ];
    }

    public function execute(array $params): mixed
    {
        $to = $params['to'];
        $subject = $params['subject'];
        $body = $params['body'];

        // Validate email format
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException("Invalid email address: {$to}");
        }

        // Validate subject isn't empty
        if (trim($subject) === '') {
            throw new RuntimeException('Subject cannot be empty');
        }

        // Validate body length
        if (strlen($body) > 10000) {
            throw new RuntimeException('Email body too long (max 10,000 characters)');
        }

        // Additional business logic: check against spam patterns
        if ($this->looksLikeSpam($body)) {
            throw new RuntimeException('Email rejected: spam detected');
        }

        // Send email (implementation omitted for brevity)
        return $this->sendViaSmtp($to, $subject, $body);
    }

    private function looksLikeSpam(string $body): bool
    {
        $spamPhrases = ['click here now', 'limited time offer', 'act now'];
        $lowerBody = strtolower($body);

        foreach ($spamPhrases as $phrase) {
            if (str_contains($lowerBody, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private function sendViaSmtp(string $to, string $subject, string $body): array
    {
        // SMTP implementation here
        return [
            'success' => true,
            'message_id' => uniqid('email_'),
            'sent_at' => date('Y-m-d H:i:s'),
        ];
    }
}
```

By throwing `RuntimeException` when validation fails, you provide clear error messages that Pagent can pass back to the LLM. The LLM can then adjust its approach and try again with valid parameters.

## Understanding Type Mappings

When building custom tools, you need to map PHP types to JSON Schema types. Pagent's `ToolArgument` class provides automatic type conversion when you use `Tool::fromClosure()`, but for custom tools you define schemas manually.

Here are the standard type mappings:

```php
// PHP Type -> JSON Schema Type
'string'   -> 'string'
'int'      -> 'integer'
'float'    -> 'number'
'bool'     -> 'boolean'
'array'    -> 'array'
'object'   -> 'object'
```

Example with multiple types:

```php
public function parameters(): array
{
    return [
        'type' => 'object',
        'properties' => [
            'name' => [
                'type' => 'string',
                'description' => 'User name',
            ],
            'age' => [
                'type' => 'integer',
                'description' => 'User age',
            ],
            'score' => [
                'type' => 'number',
                'description' => 'Test score (can be decimal)',
            ],
            'active' => [
                'type' => 'boolean',
                'description' => 'Whether the user is active',
            ],
            'tags' => [
                'type' => 'array',
                'description' => 'List of tags',
            ],
            'metadata' => [
                'type' => 'object',
                'description' => 'Additional metadata',
            ],
        ],
        'required' => ['name', 'age'],
    ];
}
```

The `required` array specifies which parameters are mandatory. Parameters not in this array are optional and may be omitted by the LLM.

## Tool Return Values

Tools can return any type of data - strings, numbers, arrays, or objects. The LLM receives this return value and incorporates it into its response to the user:

```php
public function execute(array $params): mixed
{
    // Return a string
    return "File contents here...";

    // Return a number
    return 42;

    // Return an array
    return [
        'success' => true,
        'data' => ['item1', 'item2'],
        'count' => 2,
    ];

    // Return an object
    return (object) [
        'status' => 'completed',
        'result' => 'Data processed',
    ];
}
```

For complex results, returning structured arrays or objects is often better than formatting data as strings. The LLM can parse structured data more reliably and extract exactly what it needs.

## Building Tool Libraries

As you build more tools, you'll want to organize them into reusable libraries. Here's a pattern for creating a cohesive tool collection:

```php
namespace App\Tools\FileSystem;

use Pagent\Tools\Tool;

class FileRead extends Tool
{
    // Implementation from earlier
}

class FileWrite extends Tool
{
    public function __construct(
        private ?string $baseDir = null,
    ) {}

    public function name(): string
    {
        return 'file_write';
    }

    public function description(): string
    {
        return 'Write content to a file. Creates the file if it does not exist.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Path to the file',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Content to write',
                ],
            ],
            'required' => ['path', 'content'],
        ];
    }

    public function execute(array $params): mixed
    {
        // Implementation details...
        return ['success' => true, 'bytes_written' => strlen($params['content'])];
    }
}

class FileDelete extends Tool
{
    public function __construct(
        private ?string $baseDir = null,
    ) {}

    public function name(): string
    {
        return 'file_delete';
    }

    public function description(): string
    {
        return 'Delete a file from the filesystem.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Path to the file to delete',
                ],
            ],
            'required' => ['path'],
        ];
    }

    public function execute(array $params): mixed
    {
        // Implementation details...
        return ['success' => true];
    }
}
```

Now you can create agents with the entire toolkit:

```php
$baseDir = '/var/data/workspace';

$agent = agent('file-manager')
    ->provider(anthropic())
    ->tool(new FileRead(baseDir: $baseDir))
    ->tool(new FileWrite(baseDir: $baseDir))
    ->tool(new FileDelete(baseDir: $baseDir))
    ->build();

$agent->prompt('Read the file "notes.txt", fix any spelling errors, and save it back');
```

The agent now has a complete set of file operations and can orchestrate them intelligently to accomplish complex tasks.

## Examining Built-in Tools

Pagent ships with several built-in tools that demonstrate best practices. These tools provide real-world examples of proper validation, error handling, and security considerations.

The `Bash` tool shows how to execute shell commands safely:

```php
use Pagent\Tools\Bash;

// Unrestricted (dangerous!)
$agent->tool(new Bash());

// Restricted to specific commands
$agent->tool(new Bash(
    workingDir: '/app',
    timeout: 30,
    allowedCommands: ['ls', 'pwd', 'cat'],
));
```

The `WebFetch` tool demonstrates SSRF protection and allow/disallow lists:

```php
use Pagent\Tools\WebFetch;

// Basic usage with SSRF protection
$agent->tool(new WebFetch());

// Whitelist mode: only allow specific domains
$agent->tool(new WebFetch(
    allowList: ['*.company.com', 'api.partner.com'],
));

// Blacklist mode: block specific domains
$agent->tool(new WebFetch(
    disallowList: ['competitor.com', 'spam-site.com'],
));
```

These tools are located in `src/Tools/` and serve as excellent references when building your own tools.

## Error Handling Best Practices

When tools encounter errors, they should throw descriptive exceptions that help the LLM understand what went wrong:

```php
public function execute(array $params): mixed
{
    // Bad: Generic error
    if ($problem) {
        throw new RuntimeException('Error occurred');
    }

    // Good: Specific error with context
    if (!$this->apiKeyValid($params['key'])) {
        throw new RuntimeException(
            "Invalid API key format. Expected 32 alphanumeric characters, got: " .
            strlen($params['key']) . " characters"
        );
    }

    // Good: Actionable error message
    if ($this->rateLimitExceeded()) {
        throw new RuntimeException(
            'Rate limit exceeded. Maximum 100 requests per hour. Please try again in ' .
            $this->getRetryAfterMinutes() . ' minutes'
        );
    }
}
```

Descriptive errors help the LLM adjust its strategy. If the error message is clear, the LLM can fix the problem and retry with corrected parameters.

## Testing Custom Tools

Custom tools should be thoroughly tested before using them in production. Here's a simple test structure:

```php
use PHPUnit\Framework\TestCase;

class CalculatorTest extends TestCase
{
    public function test_it_adds_numbers(): void
    {
        $calculator = new Calculator();

        $result = $calculator->execute([
            'operation' => 'add',
            'x' => 5,
            'y' => 3,
        ]);

        $this->assertEquals(8, $result);
    }

    public function test_it_prevents_division_by_zero(): void
    {
        $calculator = new Calculator();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Division by zero');

        $calculator->execute([
            'operation' => 'divide',
            'x' => 10,
            'y' => 0,
        ]);
    }

    public function test_it_has_valid_schema(): void
    {
        $calculator = new Calculator();

        $schema = $calculator->toAnthropicSchema();

        $this->assertEquals('calculator', $schema['name']);
        $this->assertArrayHasKey('input_schema', $schema);
        $this->assertArrayHasKey('properties', $schema['input_schema']);
        $this->assertEquals(
            ['operation', 'x', 'y'],
            $schema['input_schema']['required']
        );
    }
}
```

Testing ensures your tools behave correctly and provide accurate schemas to LLMs.

## Building Stateful Tools

While tools are generally stateless (each execution is independent), you can build stateful tools by injecting dependencies:

```php
class DatabaseQuery extends Tool
{
    public function __construct(
        private PDO $connection,
    ) {}

    public function name(): string
    {
        return 'database_query';
    }

    public function description(): string
    {
        return 'Execute a SELECT query against the database';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'SQL SELECT query to execute',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $params): mixed
    {
        $query = $params['query'];

        // Validate it's a SELECT query
        if (!preg_match('/^\s*SELECT/i', $query)) {
            throw new RuntimeException('Only SELECT queries are allowed');
        }

        $statement = $this->connection->prepare($query);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Usage
$pdo = new PDO('mysql:host=localhost;dbname=myapp', 'user', 'pass');
$agent->tool(new DatabaseQuery($pdo));
```

The tool maintains a connection to the database, allowing it to execute queries without reconnecting each time.

## Creating Composable Tools

Tools can wrap other services or libraries, making external functionality available to your agents:

```php
use GuzzleHttp\Client;

class HttpRequest extends Tool
{
    public function __construct(
        private Client $httpClient,
    ) {}

    public function name(): string
    {
        return 'http_request';
    }

    public function description(): string
    {
        return 'Make an HTTP GET request to a URL';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'URL to request',
                ],
            ],
            'required' => ['url'],
        ];
    }

    public function execute(array $params): mixed
    {
        $response = $this->httpClient->get($params['url']);

        return [
            'status' => $response->getStatusCode(),
            'body' => $response->getBody()->getContents(),
            'headers' => $response->getHeaders(),
        ];
    }
}

// Usage with Guzzle
$client = new Client(['timeout' => 10]);
$agent->tool(new HttpRequest($client));
```

This pattern lets you integrate any PHP library into your agent's capabilities.

## Next Steps

You now understand how to build production-ready custom tools with proper validation, configuration, and error handling. You know how to implement the `ToolInterface`, use the abstract `Tool` class to reduce boilerplate, and organize tools into reusable libraries.

In the next chapter, we'll explore recursive tool execution - how agents can chain multiple tool calls together, handle complex multi-step workflows, and avoid infinite loops. You'll learn how Pagent's automatic recursion handling makes it easy to build agents that break down complex tasks into a series of tool calls.
