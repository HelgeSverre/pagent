# Chapter 7: Building Custom Tools

## What You'll Learn

In this chapter, you'll master the art of creating custom tools for your AI agents. By the end, you'll be able to:

- Create reusable PHP callable tools that agents can invoke
- Design clean tool interfaces with proper validation
- Handle both synchronous and asynchronous operations
- Compose complex tools from simpler building blocks
- Document tools effectively for AI understanding

**Prerequisites**: Completed Chapter 6 (Managing Agent State)
**Time Estimate**: 45 minutes
**Final Result**: A suite of custom tools including email sender, data transformer, and API wrapper

## Understanding Tools in Pagent

Tools are the bridge between AI reasoning and real-world actions. When an agent needs to perform tasks beyond text generation—sending emails, querying databases, or calling APIs—it uses tools.

Think of tools as specialized functions that agents can call. Just like a carpenter reaches for different tools depending on the task, your agent selects the appropriate tool based on what it needs to accomplish.

## Creating Your First Tool

Let's start with a simple weather lookup tool to understand the basic structure:

```php
<?php

declare(strict_types=1);

namespace App\Tools;

use Pagent\Tool;
use Pagent\ToolMetadata;

final class WeatherTool extends Tool
{
    public function __construct(
        private readonly string $apiKey
    ) {}

    public function metadata(): ToolMetadata
    {
        return ToolMetadata::create('get_weather')
            ->description('Get current weather for a location')
            ->parameter('location', 'string', 'City name or coordinates', required: true)
            ->parameter('units', 'string', 'Temperature units (celsius/fahrenheit)', required: false);
    }

    public function execute(array $arguments): mixed
    {
        $location = $arguments['location'];
        $units = $arguments['units'] ?? 'celsius';

        // Simulate API call (replace with actual implementation)
        return [
            'location' => $location,
            'temperature' => 22,
            'units' => $units,
            'conditions' => 'Partly cloudy',
            'humidity' => 65,
        ];
    }
}
```

This tool demonstrates the three essential components:

1. **Constructor**: Handles dependencies like API keys
2. **Metadata**: Describes the tool for AI understanding
3. **Execute**: Performs the actual work

Now let's use this tool with an agent:

```php
use App\Tools\WeatherTool;

$weatherTool = new WeatherTool($_ENV['WEATHER_API_KEY']);

$agent = agent()
    ->using('anthropic')
    ->withTool($weatherTool)
    ->create();

$response = $agent->send('What\'s the weather like in Paris?');
echo $response->content;
// "The current weather in Paris is 22°C with partly cloudy conditions..."
```

## Building an Email Sending Tool

Let's create a more sophisticated tool that sends emails with proper validation:

```php
<?php

declare(strict_types=1);

namespace App\Tools;

use InvalidArgumentException;
use Pagent\Tool;
use Pagent\ToolMetadata;
use PHPMailer\PHPMailer\PHPMailer;

final class EmailTool extends Tool
{
    private PHPMailer $mailer;

    public function __construct(
        private readonly array $smtpConfig
    ) {
        $this->mailer = new PHPMailer(true);
        $this->configureMailer();
    }

    private function configureMailer(): void
    {
        $this->mailer->isSMTP();
        $this->mailer->Host = $this->smtpConfig['host'];
        $this->mailer->Port = $this->smtpConfig['port'];
        $this->mailer->SMTPAuth = true;
        $this->mailer->Username = $this->smtpConfig['username'];
        $this->mailer->Password = $this->smtpConfig['password'];
        $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    }

    public function metadata(): ToolMetadata
    {
        return ToolMetadata::create('send_email')
            ->description('Send an email message')
            ->parameter('to', 'string', 'Recipient email address', required: true)
            ->parameter('subject', 'string', 'Email subject line', required: true)
            ->parameter('body', 'string', 'Email body content', required: true)
            ->parameter('cc', 'array', 'CC recipients', required: false)
            ->parameter('attachments', 'array', 'File paths to attach', required: false)
            ->parameter('html', 'boolean', 'Send as HTML email', required: false);
    }

    public function execute(array $arguments): mixed
    {
        $this->validate($arguments);

        try {
            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();

            // Set recipients
            $this->mailer->addAddress($arguments['to']);

            if (isset($arguments['cc'])) {
                foreach ($arguments['cc'] as $cc) {
                    $this->mailer->addCC($cc);
                }
            }

            // Set content
            $this->mailer->Subject = $arguments['subject'];

            if ($arguments['html'] ?? false) {
                $this->mailer->isHTML(true);
                $this->mailer->Body = $arguments['body'];
                $this->mailer->AltBody = strip_tags($arguments['body']);
            } else {
                $this->mailer->Body = $arguments['body'];
            }

            // Add attachments
            if (isset($arguments['attachments'])) {
                foreach ($arguments['attachments'] as $attachment) {
                    if (! file_exists($attachment)) {
                        throw new InvalidArgumentException("Attachment not found: {$attachment}");
                    }
                    $this->mailer->addAttachment($attachment);
                }
            }

            // Send the email
            $this->mailer->send();

            return [
                'success' => true,
                'message' => 'Email sent successfully',
                'recipient' => $arguments['to'],
                'timestamp' => date('Y-m-d H:i:s'),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function validate(array $arguments): void
    {
        // Validate email format
        if (! filter_var($arguments['to'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid recipient email address');
        }

        // Validate CC emails if present
        if (isset($arguments['cc'])) {
            foreach ($arguments['cc'] as $cc) {
                if (! filter_var($cc, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException("Invalid CC email address: {$cc}");
                }
            }
        }

        // Validate subject and body not empty
        if (empty($arguments['subject'])) {
            throw new InvalidArgumentException('Subject cannot be empty');
        }

        if (empty($arguments['body'])) {
            throw new InvalidArgumentException('Body cannot be empty');
        }
    }
}
```

This email tool showcases:
- **Dependency injection** for configuration
- **Comprehensive validation** to prevent errors
- **Error handling** with try-catch blocks
- **Rich return values** for agent feedback

## Creating a Data Transformation Pipeline Tool

Tools can also handle complex data processing. Here's a pipeline tool that transforms data through multiple stages:

```php
<?php

declare(strict_types=1);

namespace App\Tools;

use Pagent\Tool;
use Pagent\ToolMetadata;

final class DataPipelineTool extends Tool
{
    private array $transformers = [];

    public function __construct()
    {
        $this->registerTransformers();
    }

    private function registerTransformers(): void
    {
        $this->transformers = [
            'uppercase' => fn($data) => array_map('strtoupper', $data),
            'lowercase' => fn($data) => array_map('strtolower', $data),
            'trim' => fn($data) => array_map('trim', $data),
            'filter_empty' => fn($data) => array_filter($data),
            'unique' => fn($data) => array_unique($data),
            'sort' => fn($data) => sort($data) ? $data : $data,
            'reverse' => fn($data) => array_reverse($data),
            'json_decode' => fn($data) => array_map(
                fn($item) => is_string($item) ? json_decode($item, true) ?? $item : $item,
                $data
            ),
        ];
    }

    public function metadata(): ToolMetadata
    {
        return ToolMetadata::create('transform_data')
            ->description('Transform data through a pipeline of operations')
            ->parameter('data', 'array', 'Input data to transform', required: true)
            ->parameter('pipeline', 'array', 'List of transformation operations', required: true)
            ->parameter('debug', 'boolean', 'Return intermediate results', required: false);
    }

    public function execute(array $arguments): mixed
    {
        $data = $arguments['data'];
        $pipeline = $arguments['pipeline'];
        $debug = $arguments['debug'] ?? false;

        $results = [];
        $intermediates = [];

        foreach ($pipeline as $operation) {
            if (is_string($operation)) {
                $operation = ['type' => $operation];
            }

            $type = $operation['type'] ?? null;

            if (! isset($this->transformers[$type])) {
                throw new InvalidArgumentException("Unknown transformation: {$type}");
            }

            $transformer = $this->transformers[$type];
            $data = $transformer($data);

            if ($debug) {
                $intermediates[] = [
                    'operation' => $type,
                    'result' => $data,
                ];
            }
        }

        $results['final'] = $data;
        $results['operations'] = count($pipeline);

        if ($debug) {
            $results['intermediates'] = $intermediates;
        }

        return $results;
    }
}
```

Usage example:

```php
$pipelineTool = new DataPipelineTool();

$agent = agent()
    ->using('anthropic')
    ->withTool($pipelineTool)
    ->create();

$response = $agent->send(
    'Clean this data: ["  hello  ", "WORLD", "", "hello", "test "]
    Remove empty values, trim whitespace, convert to lowercase, and remove duplicates.'
);

// Agent will use the tool with appropriate pipeline:
// ['trim', 'lowercase', 'filter_empty', 'unique']
// Result: ["hello", "world", "test"]
```

## Building API Wrapper Tools

Wrapping external APIs as tools lets agents interact with external services:

```php
<?php

declare(strict_types=1);

namespace App\Tools;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Pagent\Tool;
use Pagent\ToolMetadata;

final class GitHubTool extends Tool
{
    private Client $client;

    public function __construct(
        private readonly string $token
    ) {
        $this->client = new Client([
            'base_uri' => 'https://api.github.com/',
            'headers' => [
                'Authorization' => "token {$this->token}",
                'Accept' => 'application/vnd.github.v3+json',
            ],
        ]);
    }

    public function metadata(): ToolMetadata
    {
        return ToolMetadata::create('github_api')
            ->description('Interact with GitHub repositories')
            ->parameter('action', 'string', 'Action to perform (search_repos, get_issues, create_issue)', required: true)
            ->parameter('repo', 'string', 'Repository in format owner/name', required: false)
            ->parameter('query', 'string', 'Search query or issue title', required: false)
            ->parameter('body', 'string', 'Issue body content', required: false)
            ->parameter('labels', 'array', 'Issue labels', required: false);
    }

    public function execute(array $arguments): mixed
    {
        $action = $arguments['action'];

        try {
            return match ($action) {
                'search_repos' => $this->searchRepositories($arguments),
                'get_issues' => $this->getIssues($arguments),
                'create_issue' => $this->createIssue($arguments),
                default => throw new InvalidArgumentException("Unknown action: {$action}"),
            };
        } catch (GuzzleException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function searchRepositories(array $arguments): array
    {
        $query = $arguments['query'] ?? 'stars:>1000';

        $response = $this->client->get('search/repositories', [
            'query' => ['q' => $query, 'sort' => 'stars', 'per_page' => 5],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        return [
            'total_count' => $data['total_count'],
            'repositories' => array_map(
                fn($repo) => [
                    'name' => $repo['full_name'],
                    'stars' => $repo['stargazers_count'],
                    'description' => $repo['description'],
                    'url' => $repo['html_url'],
                ],
                $data['items']
            ),
        ];
    }

    private function getIssues(array $arguments): array
    {
        $repo = $arguments['repo'] ?? throw new InvalidArgumentException('Repository required');

        $response = $this->client->get("repos/{$repo}/issues", [
            'query' => ['state' => 'open', 'per_page' => 10],
        ]);

        $issues = json_decode($response->getBody()->getContents(), true);

        return [
            'repository' => $repo,
            'issues' => array_map(
                fn($issue) => [
                    'number' => $issue['number'],
                    'title' => $issue['title'],
                    'state' => $issue['state'],
                    'created_at' => $issue['created_at'],
                    'labels' => array_column($issue['labels'], 'name'),
                ],
                $issues
            ),
        ];
    }

    private function createIssue(array $arguments): array
    {
        $repo = $arguments['repo'] ?? throw new InvalidArgumentException('Repository required');
        $title = $arguments['query'] ?? throw new InvalidArgumentException('Issue title required');

        $response = $this->client->post("repos/{$repo}/issues", [
            'json' => [
                'title' => $title,
                'body' => $arguments['body'] ?? '',
                'labels' => $arguments['labels'] ?? [],
            ],
        ]);

        $issue = json_decode($response->getBody()->getContents(), true);

        return [
            'success' => true,
            'issue' => [
                'number' => $issue['number'],
                'url' => $issue['html_url'],
                'title' => $issue['title'],
            ],
        ];
    }
}
```

## Handling Asynchronous Operations

For long-running operations, create tools that return immediately and provide status updates:

```php
<?php

declare(strict_types=1);

namespace App\Tools;

use Pagent\Tool;
use Pagent\ToolMetadata;
use React\Promise\Promise;

final class AsyncProcessingTool extends Tool
{
    private array $jobs = [];

    public function metadata(): ToolMetadata
    {
        return ToolMetadata::create('async_process')
            ->description('Handle long-running asynchronous operations')
            ->parameter('action', 'string', 'start, check, or cancel', required: true)
            ->parameter('job_id', 'string', 'Job identifier', required: false)
            ->parameter('data', 'mixed', 'Data to process', required: false);
    }

    public function execute(array $arguments): mixed
    {
        $action = $arguments['action'];

        return match ($action) {
            'start' => $this->startJob($arguments),
            'check' => $this->checkJob($arguments),
            'cancel' => $this->cancelJob($arguments),
            default => throw new InvalidArgumentException("Unknown action: {$action}"),
        };
    }

    private function startJob(array $arguments): array
    {
        $jobId = uniqid('job_');
        $data = $arguments['data'] ?? [];

        // Simulate async processing
        $this->jobs[$jobId] = [
            'status' => 'processing',
            'progress' => 0,
            'started_at' => time(),
            'data' => $data,
        ];

        // In real implementation, dispatch to queue or background process
        $this->processInBackground($jobId, $data);

        return [
            'job_id' => $jobId,
            'status' => 'started',
            'message' => 'Job started successfully',
        ];
    }

    private function checkJob(array $arguments): array
    {
        $jobId = $arguments['job_id'] ?? throw new InvalidArgumentException('Job ID required');

        if (! isset($this->jobs[$jobId])) {
            return ['status' => 'not_found', 'error' => 'Job not found'];
        }

        $job = $this->jobs[$jobId];

        // Simulate progress
        $elapsed = time() - $job['started_at'];
        $job['progress'] = min(100, $elapsed * 20);

        if ($job['progress'] >= 100) {
            $job['status'] = 'completed';
            $job['result'] = $this->generateResult($job['data']);
        }

        $this->jobs[$jobId] = $job;

        return [
            'job_id' => $jobId,
            'status' => $job['status'],
            'progress' => $job['progress'],
            'result' => $job['result'] ?? null,
        ];
    }

    private function cancelJob(array $arguments): array
    {
        $jobId = $arguments['job_id'] ?? throw new InvalidArgumentException('Job ID required');

        if (isset($this->jobs[$jobId])) {
            $this->jobs[$jobId]['status'] = 'cancelled';
            return ['status' => 'cancelled', 'message' => 'Job cancelled successfully'];
        }

        return ['status' => 'not_found', 'error' => 'Job not found'];
    }

    private function processInBackground(string $jobId, mixed $data): void
    {
        // In production, use queue workers, ReactPHP, or similar
        // This is a simplified simulation
    }

    private function generateResult(mixed $data): array
    {
        return [
            'processed_at' => date('Y-m-d H:i:s'),
            'input_count' => is_array($data) ? count($data) : 1,
            'output' => 'Processing complete',
        ];
    }
}
```

## Tool Composition

Combine simple tools into more complex operations:

```php
final class ComposedTool extends Tool
{
    public function __construct(
        private readonly WeatherTool $weather,
        private readonly EmailTool $email
    ) {}

    public function metadata(): ToolMetadata
    {
        return ToolMetadata::create('weather_alert')
            ->description('Check weather and send email alerts')
            ->parameter('location', 'string', 'Location to check', required: true)
            ->parameter('email', 'string', 'Alert recipient', required: true)
            ->parameter('threshold', 'number', 'Temperature threshold', required: false);
    }

    public function execute(array $arguments): mixed
    {
        // Get weather data
        $weather = $this->weather->execute([
            'location' => $arguments['location'],
        ]);

        $threshold = $arguments['threshold'] ?? 30;

        // Check if alert needed
        if ($weather['temperature'] > $threshold) {
            $emailResult = $this->email->execute([
                'to' => $arguments['email'],
                'subject' => 'Weather Alert: High Temperature',
                'body' => sprintf(
                    'Temperature in %s has reached %d°C, exceeding the threshold of %d°C.',
                    $weather['location'],
                    $weather['temperature'],
                    $threshold
                ),
            ]);

            return [
                'alert_sent' => $emailResult['success'],
                'weather' => $weather,
                'email_result' => $emailResult,
            ];
        }

        return [
            'alert_sent' => false,
            'weather' => $weather,
            'message' => 'Temperature within normal range',
        ];
    }
}
```

## Best Practices

1. **Always validate inputs** - Never trust agent-provided arguments
2. **Return structured data** - Use arrays with consistent keys
3. **Handle errors gracefully** - Return error details for agent learning
4. **Keep tools focused** - One tool, one responsibility
5. **Document thoroughly** - Clear descriptions help agents use tools correctly

## Summary

You've learned to create powerful custom tools that extend your agents' capabilities beyond text generation. Your toolkit now includes email senders, data transformers, API wrappers, and asynchronous processors. These tools form the foundation for building agents that can interact with the real world.

## Next Steps

In Chapter 8, we'll explore testing strategies for your AI agents, ensuring your tools and conversations work reliably in production.

## Practice Exercises

1. **Database Tool**: Create a tool that performs safe database queries with parameter binding
2. **File Manager**: Build a tool for reading, writing, and manipulating files with proper permissions
3. **Calculator**: Implement a mathematical expression evaluator with function support
4. **Web Scraper**: Design a tool that extracts structured data from web pages
5. **Notification Hub**: Create a multi-channel notification tool (email, SMS, Slack)

Remember: Tools are what transform your agents from conversationalists into problem solvers. The more sophisticated your tools, the more capable your agents become.