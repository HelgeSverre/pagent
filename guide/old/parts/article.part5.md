# Chapter 5: Response Processing

## What You'll Learn

After completing this chapter, you'll be able to:
- Parse and validate LLM responses with confidence
- Extract structured data from unstructured text
- Handle different response formats (JSON, markdown, plain text)
- Implement robust retry logic for improved results
- Process partial and streaming responses effectively

**Prerequisites:** Completion of Chapters 1-4, understanding of basic Pagent operations
**Time Estimate:** 30-40 minutes
**Final Result:** A robust response processing pipeline that handles various formats and edge cases

## Understanding Response Processing

When working with LLMs, the response you receive is just the beginning. Raw text needs to be parsed, validated, and transformed into usable data structures. Think of response processing like refining crude oil—the raw material has value, but it needs processing to become truly useful.

### The Response Processing Pipeline

Every LLM response goes through several stages:

1. **Reception**: Raw text arrives from the API
2. **Validation**: Ensuring the response meets expectations
3. **Extraction**: Pulling out structured data
4. **Transformation**: Converting to application-specific formats
5. **Error Handling**: Managing failures gracefully

Let's explore each stage with practical examples.

## Basic Response Validation

Start with the simplest case—validating that a response exists and contains expected content:

```php
<?php

use Pagent\Pagent;

$agent = agent()
    ->withProvider(anthropic())
    ->withPrompt('List 3 benefits of exercise');

$response = $agent->send();

// Basic validation
if (empty($response)) {
    throw new RuntimeException('Empty response received');
}

// Content validation
if (! str_contains($response, 'benefit')) {
    throw new RuntimeException('Response does not mention benefits');
}

// Length validation
if (strlen($response) < 50) {
    throw new RuntimeException('Response too short to be meaningful');
}

echo "Valid response received:\n{$response}";
```

Run this to see basic validation in action. Now let's intentionally break it:

```php
// This will likely fail validation
$agent = agent()
    ->withProvider(anthropic())
    ->withPrompt('Say exactly one word')
    ->withMaxTokens(5);

$response = $agent->send();

// This validation will probably fail
if (strlen($response) < 50) {
    echo "Response too short: '{$response}'\n";
    // Handle the short response appropriately
}
```

## Extracting Structured Data

Real applications need structured data, not just text. Let's build a form data extractor:

```php
<?php

declare(strict_types=1);

use Pagent\Pagent;

class FormDataExtractor
{
    private $agent;

    public function __construct()
    {
        $this->agent = agent()->withProvider(anthropic());
    }

    public function extractFromText(string $userInput): array
    {
        $prompt = <<<PROMPT
        Extract contact information from the following text.
        Return ONLY a JSON object with these fields:
        - name (string)
        - email (string or null)
        - phone (string or null)
        - company (string or null)

        Text: {$userInput}

        Response format: {"name": "...", "email": "...", "phone": "...", "company": "..."}
        PROMPT;

        $response = $this->agent
            ->withPrompt($prompt)
            ->withMaxTokens(200)
            ->send();

        return $this->parseJsonResponse($response);
    }

    private function parseJsonResponse(string $response): array
    {
        // Extract JSON from response (might be wrapped in text)
        preg_match('/\{[^}]+\}/', $response, $matches);

        if (empty($matches)) {
            throw new RuntimeException('No JSON found in response');
        }

        $json = json_decode($matches[0], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid JSON: ' . json_last_error_msg());
        }

        // Validate required fields
        if (! isset($json['name'])) {
            throw new RuntimeException('Missing required field: name');
        }

        // Normalize data
        return [
            'name' => $json['name'] ?? '',
            'email' => $json['email'] ?? null,
            'phone' => $json['phone'] ?? null,
            'company' => $json['company'] ?? null,
        ];
    }
}

// Test the extractor
$extractor = new FormDataExtractor();

$testInputs = [
    "Hi, I'm John Smith from Acme Corp. You can reach me at john@acme.com",
    "Jane Doe here. Call me at 555-1234",
    "Bob Wilson, bob.wilson@example.org, TechStart Inc",
];

foreach ($testInputs as $input) {
    try {
        $data = $extractor->extractFromText($input);
        echo "Extracted from: '{$input}'\n";
        print_r($data);
        echo "\n";
    } catch (Exception $e) {
        echo "Failed to extract from: '{$input}'\n";
        echo "Error: {$e->getMessage()}\n\n";
    }
}
```

Notice how we handle various failure modes—missing JSON, invalid format, and missing fields. This defensive approach is crucial when processing LLM responses.

## Working with JSON Mode

Many providers offer JSON mode for guaranteed valid JSON responses. Here's how to use it effectively:

```php
<?php

declare(strict_types=1);

use Pagent\Pagent;

class SentimentAnalyzer
{
    private $agent;

    public function __construct()
    {
        $this->agent = agent()->withProvider(openai());
    }

    public function analyze(string $text): array
    {
        $systemPrompt = <<<PROMPT
        You are a sentiment analysis system.
        Analyze text and return JSON with:
        - sentiment: "positive", "negative", or "neutral"
        - confidence: float between 0 and 1
        - keywords: array of important words
        - summary: one-sentence summary
        PROMPT;

        $response = $this->agent
            ->withSystemPrompt($systemPrompt)
            ->withPrompt("Analyze this text: {$text}")
            ->withOptions([
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0.1,  // Low temperature for consistency
            ])
            ->withMaxTokens(300)
            ->send();

        return $this->processJsonResponse($response);
    }

    private function processJsonResponse(string $response): array
    {
        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // Even with JSON mode, validate!
            throw new RuntimeException('JSON decode failed: ' . json_last_error_msg());
        }

        // Validate sentiment value
        $validSentiments = ['positive', 'negative', 'neutral'];
        if (! in_array($data['sentiment'] ?? '', $validSentiments)) {
            $data['sentiment'] = 'neutral';  // Default fallback
        }

        // Validate confidence range
        $data['confidence'] = max(0, min(1, $data['confidence'] ?? 0.5));

        // Ensure keywords is an array
        if (! is_array($data['keywords'] ?? null)) {
            $data['keywords'] = [];
        }

        return $data;
    }

    public function analyzeBatch(array $texts): array
    {
        $results = [];

        foreach ($texts as $text) {
            try {
                $results[] = [
                    'text' => $text,
                    'analysis' => $this->analyze($text),
                    'success' => true,
                ];
            } catch (Exception $e) {
                $results[] = [
                    'text' => $text,
                    'error' => $e->getMessage(),
                    'success' => false,
                ];
            }
        }

        return $results;
    }
}

// Test sentiment analysis
$analyzer = new SentimentAnalyzer();

$testTexts = [
    "This product exceeded all my expectations! Absolutely fantastic!",
    "The service was okay, nothing special but not bad either.",
    "Terrible experience. Would not recommend to anyone.",
];

$results = $analyzer->analyzeBatch($testTexts);

foreach ($results as $result) {
    if ($result['success']) {
        echo "Text: {$result['text']}\n";
        echo "Sentiment: {$result['analysis']['sentiment']}\n";
        echo "Confidence: {$result['analysis']['confidence']}\n";
        echo "Keywords: " . implode(', ', $result['analysis']['keywords']) . "\n";
        echo "Summary: {$result['analysis']['summary']}\n\n";
    } else {
        echo "Failed to analyze: {$result['text']}\n";
        echo "Error: {$result['error']}\n\n";
    }
}
```

## Handling Markdown and Code

LLMs often return formatted content. Here's a code generation validator:

```php
<?php

declare(strict_types=1);

use Pagent\Pagent;

class CodeGenerationValidator
{
    private $agent;

    public function __construct()
    {
        $this->agent = agent()->withProvider(anthropic());
    }

    public function generateCode(string $description, string $language = 'php'): array
    {
        $prompt = <<<PROMPT
        Generate {$language} code for: {$description}

        Requirements:
        - Include necessary imports
        - Add inline comments
        - Follow PSR standards (for PHP)
        - Wrap code in markdown code blocks
        PROMPT;

        $response = $this->agent
            ->withPrompt($prompt)
            ->withMaxTokens(1000)
            ->send();

        return $this->extractAndValidateCode($response, $language);
    }

    private function extractAndValidateCode(string $response, string $language): array
    {
        $result = [
            'raw_response' => $response,
            'code_blocks' => [],
            'validation' => [],
        ];

        // Extract code blocks
        $pattern = '/```' . preg_quote($language, '/') . '\n(.*?)```/s';
        preg_match_all($pattern, $response, $matches);

        if (empty($matches[1])) {
            // Try generic code blocks
            preg_match_all('/```\n(.*?)```/s', $response, $matches);
        }

        foreach ($matches[1] ?? [] as $index => $code) {
            $codeBlock = [
                'code' => trim($code),
                'valid' => true,
                'errors' => [],
            ];

            // Language-specific validation
            if ($language === 'php') {
                $codeBlock = $this->validatePhpCode($codeBlock);
            } elseif ($language === 'json') {
                $codeBlock = $this->validateJsonCode($codeBlock);
            }

            $result['code_blocks'][] = $codeBlock;
        }

        // Overall validation
        $result['validation']['has_code'] = ! empty($result['code_blocks']);
        $result['validation']['all_valid'] = ! empty($result['code_blocks']) &&
            array_reduce($result['code_blocks'], fn($carry, $block) => $carry && $block['valid'], true);

        return $result;
    }

    private function validatePhpCode(array $codeBlock): array
    {
        $code = $codeBlock['code'];

        // Check for PHP opening tag
        if (! str_starts_with($code, '<?php')) {
            $codeBlock['errors'][] = 'Missing <?php opening tag';
            $codeBlock['valid'] = false;
        }

        // Check for syntax errors using token_get_all
        $tokens = @token_get_all($code);
        if ($tokens === false) {
            $codeBlock['errors'][] = 'PHP syntax error detected';
            $codeBlock['valid'] = false;
        }

        // Check for strict types declaration
        if (! str_contains($code, 'declare(strict_types=1)')) {
            $codeBlock['errors'][] = 'Missing strict types declaration';
            // This is a warning, not a failure
        }

        return $codeBlock;
    }

    private function validateJsonCode(array $codeBlock): array
    {
        json_decode($codeBlock['code']);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $codeBlock['errors'][] = 'Invalid JSON: ' . json_last_error_msg();
            $codeBlock['valid'] = false;
        }

        return $codeBlock;
    }
}

// Test code generation
$validator = new CodeGenerationValidator();

$tasks = [
    'Create a function to calculate fibonacci numbers',
    'Write a class that validates email addresses',
];

foreach ($tasks as $task) {
    echo "Task: {$task}\n";
    echo str_repeat('-', 50) . "\n";

    $result = $validator->generateCode($task);

    if ($result['validation']['has_code']) {
        foreach ($result['code_blocks'] as $index => $block) {
            echo "Code Block #" . ($index + 1) . ":\n";
            echo $block['code'] . "\n\n";

            if (! $block['valid']) {
                echo "Validation Errors:\n";
                foreach ($block['errors'] as $error) {
                    echo "  - {$error}\n";
                }
            } else {
                echo "✓ Code validated successfully\n";
            }
        }
    } else {
        echo "No code blocks found in response\n";
    }

    echo "\n";
}
```

## Implementing Retry Logic

Sometimes responses fail validation. Smart retry logic can dramatically improve success rates:

```php
<?php

declare(strict_types=1);

use Pagent\Pagent;

class RobustResponseProcessor
{
    private $agent;
    private $maxRetries = 3;
    private $retryDelay = 1; // seconds

    public function __construct()
    {
        $this->agent = agent()->withProvider(anthropic());
    }

    public function processWithRetry(
        string $prompt,
        callable $validator,
        array $options = []
    ): array {
        $attempts = [];
        $lastError = null;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                // Adjust prompt based on previous failures
                $adjustedPrompt = $this->adjustPrompt($prompt, $attempts);

                // Get response
                $response = $this->agent
                    ->withPrompt($adjustedPrompt)
                    ->withOptions($options)
                    ->send();

                // Validate response
                $result = $validator($response);

                // Success!
                return [
                    'success' => true,
                    'result' => $result,
                    'attempts' => $attempt,
                    'history' => $attempts,
                ];

            } catch (Exception $e) {
                $lastError = $e;
                $attempts[] = [
                    'attempt' => $attempt,
                    'response' => $response ?? null,
                    'error' => $e->getMessage(),
                ];

                // Wait before retry (except on last attempt)
                if ($attempt < $this->maxRetries) {
                    sleep($this->retryDelay);
                }
            }
        }

        // All attempts failed
        return [
            'success' => false,
            'error' => $lastError->getMessage(),
            'attempts' => $this->maxRetries,
            'history' => $attempts,
        ];
    }

    private function adjustPrompt(string $originalPrompt, array $attempts): string
    {
        if (empty($attempts)) {
            return $originalPrompt;
        }

        // Add clarification based on previous errors
        $lastError = end($attempts)['error'];

        $clarification = "\n\nIMPORTANT: ";

        if (str_contains($lastError, 'JSON')) {
            $clarification .= "Return ONLY valid JSON, no additional text.";
        } elseif (str_contains($lastError, 'Missing required field')) {
            $clarification .= "Ensure ALL required fields are included.";
        } else {
            $clarification .= "Please follow the format exactly as specified.";
        }

        return $originalPrompt . $clarification;
    }
}

// Example usage with automatic retry
$processor = new RobustResponseProcessor();

$result = $processor->processWithRetry(
    'Generate a JSON object with fields: id (number), name (string), active (boolean)',
    function ($response) {
        // This validator will throw exceptions on invalid data
        $data = json_decode($response, true);

        if (! $data) {
            throw new RuntimeException('Invalid JSON response');
        }

        if (! isset($data['id']) || ! is_numeric($data['id'])) {
            throw new RuntimeException('Missing or invalid id field');
        }

        if (! isset($data['name']) || ! is_string($data['name'])) {
            throw new RuntimeException('Missing or invalid name field');
        }

        if (! isset($data['active']) || ! is_bool($data['active'])) {
            throw new RuntimeException('Missing or invalid active field');
        }

        return $data;
    },
    ['temperature' => 0.1]
);

if ($result['success']) {
    echo "Success after {$result['attempts']} attempt(s)\n";
    echo "Result: " . json_encode($result['result'], JSON_PRETTY_PRINT) . "\n";
} else {
    echo "Failed after {$result['attempts']} attempts\n";
    echo "Error: {$result['error']}\n";
    echo "\nAttempt history:\n";
    foreach ($result['history'] as $attempt) {
        echo "  Attempt {$attempt['attempt']}: {$attempt['error']}\n";
    }
}
```

## Multi-Format Response Handler

Real applications often need to handle multiple response formats. Here's a comprehensive handler:

```php
<?php

declare(strict_types=1);

use Pagent\Pagent;

class MultiFormatResponseHandler
{
    private array $formatHandlers = [];

    public function __construct()
    {
        $this->registerDefaultHandlers();
    }

    private function registerDefaultHandlers(): void
    {
        // JSON handler
        $this->registerFormat('json', function ($response) {
            $data = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return ['type' => 'json', 'data' => $data];
            }
            return null;
        });

        // Markdown list handler
        $this->registerFormat('markdown_list', function ($response) {
            if (preg_match_all('/^[\*\-]\s+(.+)$/m', $response, $matches)) {
                return ['type' => 'list', 'items' => $matches[1]];
            }
            return null;
        });

        // Key-value pairs handler
        $this->registerFormat('key_value', function ($response) {
            if (preg_match_all('/^([^:]+):\s*(.+)$/m', $response, $matches)) {
                $data = array_combine($matches[1], $matches[2]);
                return ['type' => 'key_value', 'data' => $data];
            }
            return null;
        });

        // Plain text fallback
        $this->registerFormat('text', function ($response) {
            return ['type' => 'text', 'content' => trim($response)];
        });
    }

    public function registerFormat(string $name, callable $handler): void
    {
        $this->formatHandlers[$name] = $handler;
    }

    public function process(string $response): array
    {
        foreach ($this->formatHandlers as $name => $handler) {
            $result = $handler($response);
            if ($result !== null) {
                return $result;
            }
        }

        // Should never reach here if text handler is registered
        return ['type' => 'unknown', 'raw' => $response];
    }
}

// Test multi-format handling
$handler = new MultiFormatResponseHandler();

$testResponses = [
    '{"status": "success", "count": 42}',
    "- First item\n- Second item\n- Third item",
    "Name: John Doe\nAge: 30\nCity: New York",
    "This is just plain text without any special formatting.",
];

foreach ($testResponses as $response) {
    $processed = $handler->process($response);
    echo "Response type: {$processed['type']}\n";
    echo "Processed data:\n";
    print_r($processed);
    echo "\n" . str_repeat('-', 40) . "\n";
}
```

## Checkpoint: Testing Your Response Processing

Before moving forward, ensure you can:

1. ✓ Validate basic response properties (length, content)
2. ✓ Extract JSON data from text responses
3. ✓ Handle markdown formatted responses
4. ✓ Implement retry logic with prompt adjustments
5. ✓ Process multiple response formats dynamically

Try this challenge: Create a response processor that can extract email addresses, URLs, and phone numbers from any text response, regardless of format.

## Common Pitfalls and Solutions

### Pitfall 1: Assuming Perfect JSON
**Problem:** Expecting valid JSON without validation
**Solution:** Always use try-catch blocks and validate structure

### Pitfall 2: Ignoring Partial Responses
**Problem:** Response cut off due to token limits
**Solution:** Check for completeness indicators, request continuation if needed

### Pitfall 3: Over-strict Validation
**Problem:** Rejecting slightly imperfect but usable responses
**Solution:** Implement graduated validation with fallbacks

### Pitfall 4: No Retry Strategy
**Problem:** Single failures cause complete process failure
**Solution:** Implement intelligent retry with prompt refinement

## Summary

You've learned how to build robust response processing pipelines that handle the unpredictable nature of LLM outputs. Key takeaways:

- Always validate responses before using them
- Extract structured data using multiple strategies
- Handle different formats with appropriate parsers
- Implement retry logic to improve success rates
- Process responses defensively with proper error handling

## Next Steps

In Chapter 6, we'll explore streaming responses and real-time processing, building on these response processing foundations to handle continuous data flows and create responsive, interactive applications.

**Practice Exercise:** Build a response processor that can handle a customer service conversation, extracting customer sentiment, identifying issues, and suggesting responses, all while handling various response formats and potential failures.

**Additional Resources:**
- [PHP JSON Documentation](https://www.php.net/manual/en/book.json.php)
- [Regular Expressions in PHP](https://www.php.net/manual/en/book.pcre.php)
- [Error Handling Best Practices](https://www.php.net/manual/en/language.exceptions.php)