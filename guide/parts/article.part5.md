# Chapter 5: Response Processing

In the previous chapters, we learned how to send prompts to LLMs and manage conversations. But the real challenge often lies in what comes next: processing the responses you receive. LLMs return text, but your application needs structured data, validated content, and reliable formats.

This chapter explores how Pagent handles responses - from understanding the response object structure to transforming outputs with middleware, parsing JSON, and implementing retry patterns for better results. We'll look at the actual APIs Pagent provides and build practical examples that solve real-world response processing challenges.

## Understanding the Response Object

When you call `prompt()` on an agent, you receive a response object with a consistent structure across all providers:

```php
$agent = agent('data-extractor')
    ->provider(anthropic())
    ->build();

$response = $agent->prompt('What is the capital of France?');

// Response object structure
echo $response->content;   // "The capital of France is Paris."
echo $response->model;     // "claude-sonnet-4-20250514"
echo $response->tokens;    // 45 (total tokens used)
echo $response->provider;  // "anthropic"

// Detailed usage statistics
print_r($response->usage);
/*
[
    'input_tokens' => 20,
    'output_tokens' => 25
]
*/
```

The response object provides everything you need to understand what happened with your prompt:
- `content`: The actual text response from the LLM
- `model`: Which model was used (useful for logging and debugging)
- `tokens`: Total token count (input + output combined)
- `provider`: Which provider handled the request
- `usage`: Detailed token breakdown (varies by provider)

This consistent structure works the same whether you're using Anthropic, OpenAI, Ollama, or a mock provider. Your application code doesn't need to change when switching providers.

## Provider-Specific Response Fields

While the core fields are consistent, each provider includes additional metadata specific to their API:

```php
// Anthropic-specific fields
$anthropicAgent = agent('anthropic-bot')
    ->provider(anthropic())
    ->build();

$response = $anthropicAgent->prompt('Write a haiku');

echo $response->stop_reason;  // "end_turn"
print_r($response->raw_content);  // Original content blocks from Anthropic API
/*
[
    [
        'type' => 'text',
        'text' => 'Ancient pond...'
    ]
]
*/

// OpenAI-specific fields
$openaiAgent = agent('openai-bot')
    ->provider(openai())
    ->build();

$response = $openaiAgent->prompt('Write a haiku');

echo $response->finish_reason;  // "stop"
```

Anthropic uses `stop_reason` to indicate why generation stopped (end_turn, max_tokens, stop_sequence), while OpenAI uses `finish_reason` (stop, length, content_filter). Both tell you the same thing in different ways.

The `raw_content` field in Anthropic responses contains the original content blocks from their API. This is useful when you need access to the raw structure before Pagent flattens it into the `content` string.

## Extracting Structured Data

LLMs output text, but applications need data structures. The most reliable approach is to ask the LLM to format its response as JSON, then parse it:

```php
$extractor = agent('data-extractor')
    ->provider(anthropic())
    ->system(
        'Extract structured data from user messages. ' .
        'Always respond with valid JSON only, no additional text. ' .
        'Use this structure: {"field": "value"}'
    )
    ->build();

$response = $extractor->prompt(
    'Extract info from this: John Doe, 30 years old, lives in New York, works as a software engineer'
);

$data = json_decode($response->content, true);

print_r($data);
/*
[
    'name' => 'John Doe',
    'age' => 30,
    'location' => 'New York',
    'occupation' => 'software engineer'
]
*/

// Validate the parsing worked
if (json_last_error() !== JSON_ERROR_NONE) {
    throw new RuntimeException('LLM did not return valid JSON: ' . $response->content);
}
```

This pattern works reliably when you:
1. Explicitly instruct the LLM in the system prompt to output JSON
2. Specify the exact structure you expect
3. Include error handling for malformed JSON

Some models are better at following JSON formatting than others. Claude models (Anthropic) are particularly consistent with structured output. GPT-4 models also perform well.

## Working with OpenAI's JSON Mode

OpenAI provides a built-in JSON mode that guarantees valid JSON output. Pagent supports this through provider-specific options:

```php
$extractor = agent('openai-extractor')
    ->provider(openai(['model' => 'gpt-4o']))
    ->system('Extract contact information as JSON with fields: name, email, phone')
    ->build();

// Enable JSON mode via options
$response = $extractor->prompt(
    'Contact: Sarah Chen, sarah.chen@example.com, (555) 123-4567',
    ['response_format' => ['type' => 'json_object']]
);

// Guaranteed valid JSON when using JSON mode
$contact = json_decode($response->content, true);
echo $contact['email']; // sarah.chen@example.com
```

The `response_format` option is specific to OpenAI's API. Pagent passes it through directly via the provider's option handling. This is documented in OpenAI's provider implementation where additional options are passed through for OpenAI-specific features.

Important: When using `response_format`, you must include "JSON" somewhere in your system prompt or user message. OpenAI requires this to enable the mode.

## Parsing and Validating Responses

Beyond JSON, you often need to validate that the response meets specific criteria. Here's a practical example extracting and validating email addresses:

```php
$emailExtractor = agent('email-extractor')
    ->provider(anthropic())
    ->system(
        'Extract email addresses from text. ' .
        'Return them as a comma-separated list, nothing else. ' .
        'If no emails found, return "NONE".'
    )
    ->build();

$response = $emailExtractor->prompt(
    'Contact us at support@example.com or sales@example.com for assistance.'
);

// Parse the response
$emailList = $response->content;

if ($emailList === 'NONE') {
    $emails = [];
} else {
    $emails = array_map('trim', explode(',', $emailList));

    // Validate each email
    foreach ($emails as $email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException("Invalid email format: {$email}");
        }
    }
}

print_r($emails);
// ['support@example.com', 'sales@example.com']
```

This approach combines LLM extraction with PHP validation. The LLM does the hard work of understanding context and identifying emails, while your code ensures the output meets technical requirements.

## Implementing Retry Logic

Sometimes LLMs don't get it right the first time. They might return invalid JSON, miss required fields, or produce output that doesn't match your criteria. Implementing retry logic is essential for production applications:

```php
function extractWithRetry($agent, $prompt, $validator, $maxRetries = 3)
{
    $attempts = 0;
    $lastError = null;

    while ($attempts < $maxRetries) {
        $attempts++;

        try {
            $response = $agent->prompt($prompt);

            // Validate the response
            $result = $validator($response->content);

            // Success - return the result
            return $result;

        } catch (Exception $e) {
            $lastError = $e;

            // On failure, add feedback to conversation
            if ($attempts < $maxRetries) {
                $agent->prompt(
                    "That response had an error: {$e->getMessage()}. " .
                    "Please try again, ensuring you follow the format exactly."
                );
            }
        }
    }

    // All retries failed
    throw new RuntimeException(
        "Failed after {$maxRetries} attempts. Last error: " . $lastError->getMessage()
    );
}

// Usage example
$agent = agent('json-extractor')
    ->provider(anthropic())
    ->system('Extract data as valid JSON with fields: title, author, year')
    ->build();

$result = extractWithRetry(
    $agent,
    'Extract info: "1984 by George Orwell, published 1949"',
    function($content) {
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON format');
        }

        if (!isset($data['title'], $data['author'], $data['year'])) {
            throw new Exception('Missing required fields');
        }

        return $data;
    }
);

print_r($result);
// ['title' => '1984', 'author' => 'George Orwell', 'year' => 1949]
```

This retry pattern:
1. Attempts the prompt up to `$maxRetries` times
2. Validates the response with a custom validator function
3. Provides feedback to the LLM about what went wrong
4. Uses the conversation history to help the LLM improve
5. Returns the result on success or throws after exhausting retries

The key insight is that the LLM sees the error message in the conversation history. This often helps it correct its mistakes on the next attempt.

## Using Middleware for Response Transformation

Pagent's middleware system provides a clean way to transform responses consistently across all prompts. Middleware can intercept responses and modify them before they're returned to your code:

```php
use Pagent\Contracts\Middleware;

// Create custom middleware to trim whitespace
class TrimMiddleware implements Middleware
{
    public function before(string $message, array $options): array
    {
        // No preprocessing needed
        return $options;
    }

    public function after(object $response): object
    {
        // Trim whitespace from content
        $response->content = trim($response->content);
        return $response;
    }
}

$agent = agent('trimmed-bot')
    ->provider(anthropic())
    ->middleware(new TrimMiddleware())
    ->build();

$response = $agent->prompt('What is 2+2?');
// Response content is automatically trimmed
```

The middleware interface has two methods:
- `before(string $message, array $options): array` - Called before the prompt is sent, can modify options
- `after(object $response): object` - Called after the response is received, can transform it

Multiple middleware can be chained:

```php
class JsonParsingMiddleware implements Middleware
{
    public function before(string $message, array $options): array
    {
        return $options;
    }

    public function after(object $response): object
    {
        // Try to parse JSON in content
        $decoded = json_decode($response->content, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            $response->parsed_json = $decoded;
        }

        return $response;
    }
}

$agent = agent('json-bot')
    ->provider(anthropic())
    ->middleware(new TrimMiddleware())
    ->middleware(new JsonParsingMiddleware())
    ->build();

$response = $agent->prompt('Return JSON: {"status": "ok"}');

// Access the parsed JSON
if (isset($response->parsed_json)) {
    echo $response->parsed_json['status']; // "ok"
}
```

Middleware executes in the order you add it. In this example, responses are first trimmed, then JSON parsing is attempted on the trimmed content.

## Built-in Middleware

Pagent includes several useful middleware implementations:

```php
use Pagent\Middleware\LoggingMiddleware;
use Pagent\Middleware\MetricsMiddleware;
use Pagent\Middleware\RateLimitMiddleware;

// Logging middleware
$agent = agent('logged-bot')
    ->provider(anthropic())
    ->middleware('logging') // String-based registration
    ->build();

// Logs each prompt and response with PSR-3 logger

// Metrics middleware
$metrics = new MetricsMiddleware();
$agent = agent('metrics-bot')
    ->provider(anthropic())
    ->middleware($metrics)
    ->build();

$agent->prompt('First query');
$agent->prompt('Second query');

// Get collected metrics
$stats = $metrics->getMetrics();
echo "Average duration: {$metrics->getAverageDuration()}ms\n";
echo "Total tokens: {$metrics->getTotalTokens()}\n";

// Rate limit middleware
$rateLimit = new RateLimitMiddleware(
    maxRequests: 10,
    windowSeconds: 60
);

$agent = agent('rate-limited-bot')
    ->provider(anthropic())
    ->middleware($rateLimit)
    ->build();

// Throws RuntimeException after 10 requests within 60 seconds
```

These middleware provide production-ready functionality:
- `LoggingMiddleware`: Logs all interactions using PSR-3 compatible loggers
- `MetricsMiddleware`: Collects duration and token usage statistics
- `RateLimitMiddleware`: Enforces request rate limits to prevent API abuse

You can instantiate them directly or use string-based registration for built-in middleware types.

## Practical Example: Form Data Extraction

Let's build a practical system that extracts structured data from free-text form submissions:

```php
class FormExtractor
{
    private Agent $agent;

    public function __construct()
    {
        $this->agent = agent('form-extractor')
            ->provider(anthropic())
            ->system(
                'Extract form data from user text. Return valid JSON only. ' .
                'Required fields: name, email, phone, message. ' .
                'If a field is missing, use null for the value.'
            )
            ->build();
    }

    public function extract(string $text): array
    {
        $response = $this->agent->prompt($text);

        $data = json_decode($response->content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Failed to parse LLM response as JSON');
        }

        // Validate structure
        $required = ['name', 'email', 'phone', 'message'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $data)) {
                throw new RuntimeException("Missing required field: {$field}");
            }
        }

        // Validate email if present
        if ($data['email'] !== null && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException("Invalid email format: {$data['email']}");
        }

        return $data;
    }
}

// Usage
$extractor = new FormExtractor();

$submission =
    "Hi, my name is Alice Johnson and I need help with my order. " .
    "You can reach me at alice.j@example.com or call me at 555-0123. " .
    "I ordered item #4829 but it hasn't arrived yet.";

$formData = $extractor->extract($submission);

print_r($formData);
/*
[
    'name' => 'Alice Johnson',
    'email' => 'alice.j@example.com',
    'phone' => '555-0123',
    'message' => 'I ordered item #4829 but it hasn\'t arrived yet.'
]
*/
```

This extractor:
1. Uses a clear system prompt defining expected output format
2. Parses JSON response with proper error handling
3. Validates the structure matches requirements
4. Performs PHP-level validation (email format)
5. Returns clean, validated data

## Practical Example: Sentiment Analysis Pipeline

Here's a complete sentiment analysis system with response processing:

```php
class SentimentAnalyzer
{
    private Agent $agent;

    public function __construct()
    {
        $this->agent = agent('sentiment-analyzer')
            ->provider(anthropic())
            ->system(
                'Analyze sentiment of text. Respond with JSON: ' .
                '{"sentiment": "positive|negative|neutral", "confidence": 0.0-1.0, "reason": "explanation"}'
            )
            ->build();
    }

    public function analyze(string $text): array
    {
        // Try up to 3 times
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $response = $this->agent->prompt("Analyze this: {$text}");

            $result = json_decode($response->content, true);

            // Validate JSON
            if (json_last_error() !== JSON_ERROR_NONE) {
                if ($attempt < 3) {
                    $this->agent->prompt(
                        'Invalid JSON. Please respond with valid JSON only, no markdown.'
                    );
                    continue;
                }
                throw new RuntimeException('Failed to get valid JSON after 3 attempts');
            }

            // Validate structure
            if (!isset($result['sentiment'], $result['confidence'], $result['reason'])) {
                if ($attempt < 3) {
                    $this->agent->prompt(
                        'Missing fields. Include: sentiment, confidence, reason'
                    );
                    continue;
                }
                throw new RuntimeException('Invalid response structure');
            }

            // Validate sentiment value
            if (!in_array($result['sentiment'], ['positive', 'negative', 'neutral'], true)) {
                if ($attempt < 3) {
                    $this->agent->prompt(
                        'sentiment must be: positive, negative, or neutral'
                    );
                    continue;
                }
                throw new RuntimeException('Invalid sentiment value');
            }

            // Validate confidence range
            if ($result['confidence'] < 0 || $result['confidence'] > 1) {
                if ($attempt < 3) {
                    $this->agent->prompt('confidence must be between 0.0 and 1.0');
                    continue;
                }
                throw new RuntimeException('Invalid confidence range');
            }

            // Success
            return $result;
        }

        throw new RuntimeException('Analysis failed after maximum retries');
    }
}

// Usage
$analyzer = new SentimentAnalyzer();

$reviews = [
    "This product is amazing! Best purchase ever.",
    "Completely disappointed. Waste of money.",
    "It's okay. Nothing special but it works.",
];

foreach ($reviews as $review) {
    $analysis = $analyzer->analyze($review);

    printf(
        "Review: %s\nSentiment: %s (%.1f%% confidence)\nReason: %s\n\n",
        $review,
        $analysis['sentiment'],
        $analysis['confidence'] * 100,
        $analysis['reason']
    );
}
```

This analyzer demonstrates:
- Retry logic with conversation-based feedback
- Comprehensive validation (structure, types, ranges)
- Clear error messages at each validation stage
- Production-ready error handling

## Practical Example: Code Generation Validator

When generating code, validation is critical. Here's a system that generates and validates SQL queries:

```php
class SqlQueryGenerator
{
    private Agent $agent;

    public function __construct()
    {
        $this->agent = agent('sql-generator')
            ->provider(anthropic())
            ->system(
                'Generate SQL queries. Return ONLY the SQL query, no explanations. ' .
                'Use proper SQL syntax. Always end with semicolon.'
            )
            ->build();
    }

    public function generate(string $description): string
    {
        $response = $this->agent->prompt($description);
        $sql = trim($response->content);

        // Basic validation
        if (empty($sql)) {
            throw new RuntimeException('Empty SQL query generated');
        }

        // Must end with semicolon
        if (!str_ends_with($sql, ';')) {
            throw new RuntimeException('SQL query must end with semicolon');
        }

        // Check for dangerous operations in SELECT queries
        if (stripos($description, 'select') !== false) {
            if (preg_match('/\b(DROP|DELETE|UPDATE|INSERT|ALTER|CREATE)\b/i', $sql)) {
                throw new RuntimeException(
                    'SELECT query contains dangerous operation: ' . $sql
                );
            }
        }

        // Validate basic SQL structure
        $keywords = ['SELECT', 'FROM', 'WHERE', 'INSERT', 'UPDATE', 'DELETE', 'CREATE'];
        $hasKeyword = false;
        foreach ($keywords as $keyword) {
            if (stripos($sql, $keyword) !== false) {
                $hasKeyword = true;
                break;
            }
        }

        if (!$hasKeyword) {
            throw new RuntimeException('Generated text does not appear to be SQL: ' . $sql);
        }

        return $sql;
    }
}

// Usage
$generator = new SqlQueryGenerator();

try {
    $query = $generator->generate(
        'Select all users who registered in the last 7 days'
    );

    echo "Generated SQL:\n{$query}\n";
    // SELECT * FROM users WHERE created_at >= NOW() - INTERVAL 7 DAY;

    // Safe to execute (after additional validation)

} catch (RuntimeException $e) {
    echo "Validation failed: {$e->getMessage()}\n";
}
```

This generator:
1. Validates non-empty response
2. Checks SQL syntax requirements (semicolon)
3. Prevents SQL injection attempts in SELECT contexts
4. Verifies the response contains SQL keywords
5. Returns validated SQL safe for further processing

## Best Practices for Response Processing

Based on what we've covered, here are key patterns to follow:

**Always validate LLM responses.** Never trust that an LLM will return exactly what you expect. Parse, validate, and handle errors gracefully.

**Use structured output when you need data.** JSON is the most reliable format for extracting structured data. Use clear system prompts to specify the exact structure.

**Implement retry logic for critical operations.** LLMs are probabilistic - they sometimes fail. Build retry logic that provides feedback to help the LLM correct itself.

**Leverage middleware for cross-cutting concerns.** Instead of repeating response processing logic, use middleware for logging, metrics, and transformations that apply to all prompts.

**Validate at multiple levels.** Check JSON parsing, verify structure, validate business rules. Each layer catches different types of failures.

**Provide clear error messages.** When validation fails, give specific feedback both for debugging and for retry prompts to the LLM.

**Consider provider-specific features.** OpenAI's JSON mode, Anthropic's content blocks - use provider-specific features when they solve your problem more elegantly.

## What's Next

We've explored the complete lifecycle of a prompt: creating agents, configuring providers, managing conversations, designing effective prompts, and now processing responses. These five chapters form the foundation of building reliable LLM-powered applications with Pagent.

In the next chapter, we'll expand agent capabilities dramatically by introducing tool calling - the ability for agents to execute functions, call APIs, and interact with external systems. This is where agents transform from conversational interfaces into autonomous systems that can take action in the world.

You'll learn how to define tools, handle tool execution, and build agents that seamlessly combine conversation with capability. Let's explore that next.
