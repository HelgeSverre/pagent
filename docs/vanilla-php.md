# Vanilla PHP Integration Guide

This guide shows how to use Pagent in vanilla PHP projects without any framework, using simple patterns for organizing and accessing your AI agents.

## Overview

We'll set up:

1. Centralized agent configuration file
2. Simple autoloading with Composer
3. Helper functions for easy access
4. Basic routing and request handling
5. Session-based conversation persistence

---

## Installation

```bash
# Install Pagent
composer require helgesverre/pagent

# Optional: for environment variables
composer require vlucas/phpdotenv
```

---

## Project Structure

```
your-app/
├── config/
│   └── agents.php         # Agent configurations
├── public/
│   ├── index.php          # Application entry point
│   └── api.php            # API endpoints
├── src/
│   ├── helpers.php        # Helper functions
│   └── Services/          # Business logic (optional)
├── .env                   # API keys
└── composer.json
```

---

## Step 1: Configure Environment

**`.env`**:

```env
# API Keys
ANTHROPIC_API_KEY=your-key-here
OPENAI_API_KEY=your-key-here

# App Config
APP_ENV=production
APP_DEBUG=false
```

---

## Step 2: Create Agent Configuration

**`config/agents.php`**:

```php
<?php

declare(strict_types=1);

use function Pagent\agent;

// Load environment variables
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

// =============================================================================
// CUSTOMER SUPPORT
// =============================================================================

agent('support')
    ->provider('anthropic')
    ->model('claude-sonnet-4-6')
    ->system('You are a helpful customer support agent. Be concise and professional.')
    ->temperature(0.3)
    ->tool('search_orders', 'Search customer orders by email', function (string $email) {
        // Connect to your database
        $db = getDatabase();
        $stmt = $db->prepare('SELECT * FROM orders WHERE customer_email = ?');
        $stmt->execute([$email]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    })
    ->tool('get_order_status', 'Get order status by ID', function (string $orderId) {
        $db = getDatabase();
        $stmt = $db->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$orderId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    });

// =============================================================================
// CONTENT GENERATION
// =============================================================================

agent('blog-writer')
    ->provider('openai')
    ->model('gpt-4o-mini')
    ->system('You are a professional blog writer. Create engaging, SEO-optimized content.')
    ->temperature(0.8);

agent('product-descriptions')
    ->provider('anthropic')
    ->model('claude-sonnet-4-6')
    ->system('Write compelling product descriptions that highlight benefits and features.')
    ->temperature(0.7);

agent('social-media')
    ->provider('openai')
    ->model('gpt-4o-mini')
    ->system('Create engaging social media posts. Be concise, use emojis, include hashtags.')
    ->temperature(0.9);

// =============================================================================
// DATA ANALYSIS
// =============================================================================

agent('data-analyst')
    ->provider('openai')
    ->model('gpt-4')
    ->system('Analyze data and provide clear, actionable insights.')
    ->temperature(0.3);

// =============================================================================
// CODE REVIEW
// =============================================================================

agent('code-reviewer')
    ->provider('anthropic')
    ->model('claude-sonnet-4-6')
    ->system('You are a senior code reviewer. Provide constructive feedback on code quality, security, and best practices.')
    ->temperature(0.2);
```

---

## Step 3: Create Helper Functions

**`src/helpers.php`**:

```php
<?php

declare(strict_types=1);

use function Pagent\agent;

/**
 * Get a configured agent by name.
 */
function pagent(string $name): \Pagent\Agent
{
    return agent($name);
}

/**
 * Get database connection.
 */
function getDatabase(): PDO
{
    static $db = null;

    if ($db === null) {
        $host = $_ENV['DB_HOST'] ?? 'localhost';
        $name = $_ENV['DB_NAME'] ?? 'database';
        $user = $_ENV['DB_USER'] ?? 'root';
        $pass = $_ENV['DB_PASS'] ?? '';

        $db = new PDO(
            "mysql:host={$host};dbname={$name};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    return $db;
}

/**
 * Send JSON response.
 */
function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Get JSON input from request.
 */
function getJsonInput(): array
{
    $input = file_get_contents('php://input');
    return json_decode($input, true) ?? [];
}

/**
 * Start session if not started.
 */
function ensureSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Get conversation history from session.
 */
function getConversationHistory(string $agentName): array
{
    ensureSession();
    return $_SESSION["agent_history_{$agentName}"] ?? [];
}

/**
 * Save conversation history to session.
 */
function saveConversationHistory(string $agentName, array $messages): void
{
    ensureSession();
    $_SESSION["agent_history_{$agentName}"] = $messages;
}

/**
 * Clear conversation history.
 */
function clearConversationHistory(string $agentName): void
{
    ensureSession();
    unset($_SESSION["agent_history_{$agentName}"]);
}

/**
 * Load conversation history into agent.
 */
function loadHistory(\Pagent\Agent $agent, string $agentName): void
{
    $history = getConversationHistory($agentName);
    foreach ($history as $message) {
        $agent->messages[] = $message;
    }
}

/**
 * Handle errors and send JSON error response.
 */
function handleError(\Throwable $e, bool $debug = false): void
{
    error_log($e->getMessage());

    $response = ['error' => 'An error occurred'];

    if ($debug) {
        $response['message'] = $e->getMessage();
        $response['trace'] = $e->getTraceAsString();
    }

    jsonResponse($response, 500);
}
```

---

## Step 4: Configure Composer Autoload

**`composer.json`**:

```json
{
  "name": "yourname/vanilla-pagent-app",
  "require": {
    "php": "^8.4",
    "helgesverre/pagent": "^1.0",
    "vlucas/phpdotenv": "^5.6"
  },
  "autoload": {
    "psr-4": {
      "App\\": "src/"
    },
    "files": ["config/agents.php", "src/helpers.php"]
  }
}
```

Run:

```bash
composer dump-autoload
```

---

## Step 5: Create API Endpoints

**`public/api.php`**:

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Enable error reporting for development
if ($_ENV['APP_DEBUG'] ?? false) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// Set CORS headers (adjust for production)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Simple router
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

try {
    match (true) {
        // Support chat endpoint
        $method === 'POST' && $uri === '/api/support/chat' => handleSupportChat(),

        // Support history
        $method === 'GET' && $uri === '/api/support/history' => handleSupportHistory(),

        // Support reset
        $method === 'POST' && $uri === '/api/support/reset' => handleSupportReset(),

        // Generate blog post
        $method === 'POST' && $uri === '/api/content/blog' => handleGenerateBlog(),

        // Generate social media post
        $method === 'POST' && $uri === '/api/content/social' => handleGenerateSocial(),

        // Quick chat with any agent
        $method === 'POST' && $uri === '/api/chat' => handleQuickChat(),

        // Health check
        $method === 'GET' && $uri === '/api/health' => handleHealthCheck(),

        // Not found
        default => jsonResponse(['error' => 'Endpoint not found'], 404),
    };
} catch (\Throwable $e) {
    handleError($e, $_ENV['APP_DEBUG'] ?? false);
}

// =============================================================================
// ROUTE HANDLERS
// =============================================================================

/**
 * Handle support chat.
 */
function handleSupportChat(): void
{
    $input = getJsonInput();
    $message = $input['message'] ?? '';

    if (empty($message)) {
        jsonResponse(['error' => 'Message is required'], 400);
    }

    $agent = pagent('support');

    // Load conversation history
    loadHistory($agent, 'support');

    // Get response
    $response = $agent->prompt($message);

    // Save conversation history
    saveConversationHistory('support', $agent->messages);

    jsonResponse([
        'reply' => $response->content,
        'model' => $response->model,
        'tokens' => $response->tokens,
    ]);
}

/**
 * Get support conversation history.
 */
function handleSupportHistory(): void
{
    $history = getConversationHistory('support');

    jsonResponse([
        'messages' => $history,
        'count' => count($history),
    ]);
}

/**
 * Reset support conversation.
 */
function handleSupportReset(): void
{
    clearConversationHistory('support');

    jsonResponse([
        'message' => 'Conversation reset successfully',
    ]);
}

/**
 * Generate blog post.
 */
function handleGenerateBlog(): void
{
    $input = getJsonInput();
    $topic = $input['topic'] ?? '';
    $wordCount = $input['word_count'] ?? 500;

    if (empty($topic)) {
        jsonResponse(['error' => 'Topic is required'], 400);
    }

    $prompt = "Write a {$wordCount}-word blog post about: {$topic}";
    $response = pagent('blog-writer')->prompt($prompt);

    jsonResponse([
        'article' => $response->content,
        'tokens_used' => $response->tokens,
    ]);
}

/**
 * Generate social media post.
 */
function handleGenerateSocial(): void
{
    $input = getJsonInput();
    $topic = $input['topic'] ?? '';
    $platform = $input['platform'] ?? 'twitter';

    if (empty($topic)) {
        jsonResponse(['error' => 'Topic is required'], 400);
    }

    $prompt = "Create a {$platform} post about: {$topic}";
    $response = pagent('social-media')->prompt($prompt);

    jsonResponse([
        'post' => $response->content,
        'tokens_used' => $response->tokens,
    ]);
}

/**
 * Quick chat with any agent.
 */
function handleQuickChat(): void
{
    $input = getJsonInput();
    $agentName = $input['agent'] ?? 'support';
    $message = $input['message'] ?? '';

    if (empty($message)) {
        jsonResponse(['error' => 'Message is required'], 400);
    }

    $response = pagent($agentName)->prompt($message);

    jsonResponse([
        'reply' => $response->content,
        'agent' => $agentName,
        'model' => $response->model,
        'tokens' => $response->tokens,
    ]);
}

/**
 * Health check.
 */
function handleHealthCheck(): void
{
    jsonResponse([
        'status' => 'ok',
        'agents' => ['support', 'blog-writer', 'social-media', 'data-analyst'],
        'php_version' => PHP_VERSION,
    ]);
}
```

---

## Step 6: Create Web Interface (Optional)

**`public/index.php`**:

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

ensureSession();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Agent Demo</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, sans-serif; padding: 20px; max-width: 800px; margin: 0 auto; }
        h1 { margin-bottom: 20px; }
        .chat-box { border: 1px solid #ddd; border-radius: 8px; padding: 20px; margin-bottom: 20px; height: 400px; overflow-y: auto; }
        .message { margin-bottom: 15px; padding: 10px; border-radius: 6px; }
        .user { background: #e3f2fd; text-align: right; }
        .agent { background: #f5f5f5; }
        .input-area { display: flex; gap: 10px; margin-bottom: 10px; }
        input[type="text"] { flex: 1; padding: 10px; border: 1px solid #ddd; border-radius: 6px; }
        button { padding: 10px 20px; background: #1976d2; color: white; border: none; border-radius: 6px; cursor: pointer; }
        button:hover { background: #1565c0; }
        .actions { display: flex; gap: 10px; }
        .actions button { background: #666; }
        .actions button:hover { background: #444; }
    </style>
</head>
<body>
    <h1> AI Agent Demo</h1>

    <div class="chat-box" id="chatBox"></div>

    <div class="input-area">
        <input type="text" id="messageInput" placeholder="Type your message...">
        <button onclick="sendMessage()">Send</button>
    </div>

    <div class="actions">
        <button onclick="clearHistory()">Clear History</button>
        <button onclick="showHistory()">Show History</button>
    </div>

    <script>
        const chatBox = document.getElementById('chatBox');
        const messageInput = document.getElementById('messageInput');

        // Load history on page load
        loadHistory();

        messageInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') sendMessage();
        });

        async function sendMessage() {
            const message = messageInput.value.trim();
            if (!message) return;

            // Add user message to UI
            addMessage(message, 'user');
            messageInput.value = '';

            try {
                const response = await fetch('/api.php/api/support/chat', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ message })
                });

                const data = await response.json();

                if (data.error) {
                    addMessage('Error: ' + data.error, 'agent');
                } else {
                    addMessage(data.reply, 'agent');
                }
            } catch (error) {
                addMessage('Error: Could not connect to server', 'agent');
            }
        }

        function addMessage(content, role) {
            const div = document.createElement('div');
            div.className = `message ${role}`;
            div.textContent = content;
            chatBox.appendChild(div);
            chatBox.scrollTop = chatBox.scrollHeight;
        }

        async function loadHistory() {
            try {
                const response = await fetch('/api.php/api/support/history');
                const data = await response.json();

                chatBox.innerHTML = '';
                data.messages.forEach(msg => {
                    addMessage(msg.content, msg.role);
                });
            } catch (error) {
                console.error('Could not load history:', error);
            }
        }

        async function clearHistory() {
            try {
                await fetch('/api.php/api/support/reset', { method: 'POST' });
                chatBox.innerHTML = '';
            } catch (error) {
                alert('Could not clear history');
            }
        }

        function showHistory() {
            loadHistory();
        }
    </script>
</body>
</html>
```

---

## Usage Examples

### Start PHP Server

```bash
# Start built-in PHP server
php -S localhost:8000 -t public

# Or use the API directly
php -S localhost:8000 public/api.php
```

### Test Support Chat

```bash
curl -X POST http://localhost:8000/api.php/api/support/chat \
  -H "Content-Type: application/json" \
  -d '{"message": "I need help with my order"}'
```

Response:

```json
{
  "reply": "I'd be happy to help you with your order. Could you please provide your order number or email address?",
  "model": "claude-sonnet-4-6",
  "tokens": 38
}
```

### Generate Blog Post

```bash
curl -X POST http://localhost:8000/api.php/api/content/blog \
  -H "Content-Type: application/json" \
  -d '{"topic": "The Future of AI", "word_count": 300}'
```

### Quick Chat

```bash
curl -X POST http://localhost:8000/api.php/api/chat \
  -H "Content-Type: application/json" \
  -d '{"agent": "data-analyst", "message": "Analyze: Q1: 10k, Q2: 15k, Q3: 12k"}'
```

### Health Check

```bash
curl http://localhost:8000/api.php/api/health
```

---

## Advanced: Multi-Agent Workflow

Add to `public/api.php`:

```php
use function Pagent\Orchestration\pipeline;

function handleContentPipeline(): void
{
    $input = getJsonInput();
    $topic = $input['topic'] ?? '';

    if (empty($topic)) {
        jsonResponse(['error' => 'Topic is required'], 400);
    }

    // Pipeline: Write → Review → Summarize
    $result = pipeline('content-creation')
        ->agent('blog-writer', fn($topic) => "Write a blog post about: {$topic}")
        ->agent('code-reviewer', fn($article) => "Review this article: {$article}")
        ->agent('social-media', fn($review) => "Create a tweet summarizing: {$review}")
        ->run($topic);

    jsonResponse([
        'final_output' => $result,
    ]);
}

// Add to router
$method === 'POST' && $uri === '/api/workflow/content' => handleContentPipeline(),
```

---

## Best Practices

### 1. Error Handling

```php
try {
    $response = pagent('support')->prompt($message);
} catch (\RuntimeException $e) {
    error_log("Agent error: " . $e->getMessage());
    jsonResponse(['error' => 'Service unavailable'], 503);
}
```

### 2. Rate Limiting

```php
function checkRateLimit(string $identifier, int $maxRequests = 60, int $perMinutes = 1): bool
{
    ensureSession();
    $key = "rate_limit_{$identifier}";
    $requests = $_SESSION[$key] ?? [];
    $now = time();
    $cutoff = $now - ($perMinutes * 60);

    // Remove old requests
    $requests = array_filter($requests, fn($t) => $t > $cutoff);

    if (count($requests) >= $maxRequests) {
        return false;
    }

    $requests[] = $now;
    $_SESSION[$key] = $requests;
    return true;
}

// Usage
if (!checkRateLimit($_SERVER['REMOTE_ADDR'])) {
    jsonResponse(['error' => 'Rate limit exceeded'], 429);
}
```

### 3. Input Validation

```php
function validateInput(array $input, array $rules): array
{
    $errors = [];

    foreach ($rules as $field => $rule) {
        if ($rule === 'required' && empty($input[$field])) {
            $errors[] = "{$field} is required";
        }
    }

    if (!empty($errors)) {
        jsonResponse(['errors' => $errors], 400);
    }

    return $input;
}

// Usage
$input = validateInput(getJsonInput(), [
    'message' => 'required',
    'agent' => 'required',
]);
```

### 4. Logging

```php
function logAgentInteraction(string $agent, string $prompt, string $response): void
{
    $logFile = __DIR__ . '/../logs/agents.log';
    $entry = sprintf(
        "[%s] Agent: %s | Prompt Length: %d | Response Length: %d\n",
        date('Y-m-d H:i:s'),
        $agent,
        strlen($prompt),
        strlen($response)
    );
    file_put_contents($logFile, $entry, FILE_APPEND);
}
```

---

## Environment-Specific Configuration

```php
// config/agents.php

$isProd = ($_ENV['APP_ENV'] ?? 'development') === 'production';

agent('support')
    ->provider('anthropic')
    ->model($isProd ? 'claude-sonnet-4-6' : 'claude-sonnet-4-6')
    ->temperature($isProd ? 0.3 : 0.5);
```

---

## Production Deployment

### 1. Use a Web Server

**Nginx configuration**:

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/your-app/public;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location /api.php {
        try_files $uri =404;
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_index api.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

### 2. Enable OPcache

In `php.ini`:

```ini
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=10000
```

### 3. Secure Your API Keys

```bash
# Set proper permissions
chmod 600 .env

# Never commit .env to git
echo ".env" >> .gitignore
```

---

## Summary

This vanilla PHP integration provides:

- **Simple Setup** - No framework required
- **Session Persistence** - Conversation history
- **RESTful API** - JSON endpoints
- **Helper Functions** - Clean, reusable code
- **Error Handling** - Production-ready
- **Extensible** - Easy to add features

This structure is suitable for small applications, prototypes, and projects that
need direct control without a framework integration layer.
