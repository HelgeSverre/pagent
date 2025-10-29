# Pest Test Helpers & Expectations Specification

**Status:** Planning
**Target Version:** v0.11.0 - Developer Tools & Debugging
**Created:** 2025-10-29
**Estimated Effort:** 3-4 hours

## Overview

Custom Pest expectations and helper functions to improve test readability and reduce boilerplate when testing Pagent agents, tools, guards, middleware, and orchestration patterns.

## Current State Analysis

### Existing Helpers (from `tests/Pest.php`)

**Custom Expectations:**
- `toBeAgent()` - Check instance of Agent
- `toHaveProvider()` - Check agent has configured provider

**Helper Functions:**
- `testAgent(string $name = 'test-agent')` - Create test agent with mock provider
- `skipIfMissingEnv()`, `skipIfHasEnv()` - Environment-based test skipping
- `hasAnthropicKey()`, `hasOpenAiKey()`, `hasOllamaAvailable()` - API availability checks

**Common Test Patterns Found:**
```php
// Response content checking
expect($response->content)->toBe('expected')
expect($response->content)->toContain('keyword')

// Tool checking
expect($agent->getTools())->toHaveCount(1)
expect($agent->getTools()[0]->name)->toBe('tool_name')

// Guard checking
expect($agent->getGuards())->toHaveCount(1)
expect(fn() => $agent->prompt('test'))->toThrow(GuardException::class)

// Middleware checking
expect($agent->getMiddleware())->toHaveCount(1)
expect($metrics->getTotalTokens())->toBe(100)

// Conversation history
expect($agent->messages)->toHaveCount(2)
expect($agent->messages[0]['role'])->toBe('user')
```

## Proposed Custom Expectations

### 1. Response Content Expectations

```php
// Check response contains keywords
expect($response)->toContainKeyword('hello')
expect($response)->toContainKeywords(['hello', 'world'])

// Check response matches regex
expect($response)->toMatchPattern('/^Hello/')

// Check response length
expect($response)->toBeWithinLength(10, 100)
expect($response)->toHaveLengthGreaterThan(50)
expect($response)->toHaveLengthLessThan(200)

// Check response has code block
expect($response)->toHaveCodeBlock()
expect($response)->toHaveCodeBlock('php')

// Check response format
expect($response)->toBeValidJson()
expect($response)->toBeValidMarkdown()
expect($response)->toMatchJsonSchema($schema)
```

### 2. Agent State Expectations

```php
// Check agent configuration
expect($agent)->toHaveSystemPrompt()
expect($agent)->toHaveSystemPrompt('You are helpful')
expect($agent)->toHaveModel('claude-3-5-sonnet-20241022')
expect($agent)->toHaveTemperature(0.7)

// Check conversation history
expect($agent)->toHaveConversationLength(4) // 2 turns
expect($agent)->toHaveMessageCount(4)
expect($agent)->toHaveEmptyConversation()
expect($agent)->toHaveConversationContaining('hello')
```

### 3. Tool Expectations

```php
// Check tool configuration
expect($agent)->toHaveTool('calculate')
expect($agent)->toHaveTools(['add', 'multiply'])
expect($agent)->toHaveToolCount(3)

// Check tool usage in response
expect($response)->toHaveUsedTool('calculate')
expect($response)->toHaveUsedTools(['add', 'multiply'])
expect($response)->toHaveToolCall('calculate', ['a' => 5, 'b' => 3])
expect($response)->toHaveToolCallCount(2)

// Check tool call results
expect($response)->toHaveToolResult('calculate', 8)
```

### 4. Guard Expectations

```php
// Check guard configuration
expect($agent)->toHaveGuard('pii')
expect($agent)->toHaveGuard(PIIGuard::class)
expect($agent)->toHaveGuards(['pii', 'contentFilter'])
expect($agent)->toHaveGuardCount(2)

// Check guard execution
expect($response)->toHaveTriggeredGuard('pii')
expect($response)->toHavePassedGuard('pii')
expect($response)->toHavePassedAllGuards()
expect($response)->toHaveUsedFallback()
```

### 5. Middleware Expectations

```php
// Check middleware configuration
expect($agent)->toHaveMiddleware('logging')
expect($agent)->toHaveMiddleware(LoggingMiddleware::class)
expect($agent)->toHaveMiddlewareCount(3)

// Check middleware execution
expect($metrics)->toHaveTotalTokens(100)
expect($metrics)->toHaveAverageDuration(50)
expect($metrics)->toHaveRequestCount(5)
expect($rateLimit)->toHaveRemainingRequests(3)
```

### 6. Orchestration Expectations

```php
// Check handoff
expect($target)->toHaveReceivedHandoffFrom('source')
expect($target)->toHaveHandoffReason('needs specialist')

// Check pipeline execution
expect($result)->toHavePipelineLength(3)
expect($result)->toHaveUsedAgent('agent1')

// Check delegation
expect($response)->toHaveDelegatedTo('specialist')
```

### 7. Evaluation Expectations

```php
// Check evaluation results
expect($result)->toHavePassedMetric('keyword')
expect($result)->toHavePassedAllMetrics()
expect($result)->toHaveAverageScore('keyword', 0.8)
expect($result)->toHaveMinimumScore('length', 0.5)
expect($result)->toHaveDatasetSize(10)
```

## Helper Function Proposals

### 1. Agent Creation Helpers

```php
// Already exists
testAgent(string $name = 'test-agent'): Agent

// New helpers
testAgentWithTools(array $tools): Agent
testAgentWithGuards(array $guards): Agent
testAgentWithMiddleware(array $middleware): Agent

// Create agent with mock responses
testAgentWithResponses(array $responses): Agent
```

### 2. Mock Tool Helpers

```php
// Create simple mock tools
mockTool(string $name, mixed $returnValue): Tool
mockToolThatFails(string $name, string $error): Tool
mockToolWithDelay(string $name, int $ms): Tool

// Example usage:
$agent->tool(...mockTool('calculate', 42));
```

### 3. Dataset Helpers

```php
// Create test datasets
testDataset(int $size = 5): Dataset
testDatasetFromArray(array $data): Dataset
testDatasetWithExpected(array $pairs): Dataset

// Example:
$dataset = testDatasetWithExpected([
    ['input' => 'hello', 'expected' => 'hi'],
    ['input' => 'bye', 'expected' => 'goodbye'],
]);
```

### 4. Response Assertion Helpers

```php
// Quick response assertions
assertAgentResponded(Agent $agent, string $expected): void
assertAgentRespondedWith(Agent $agent, callable $assertion): void
assertToolCalled(Agent $agent, string $tool): void
assertGuardBlocked(Agent $agent, string|object $guard): void

// Example:
$agent->prompt('hello');
assertAgentResponded($agent, 'hi');
assertToolCalled($agent, 'get_weather');
```

### 5. Conversation Helpers

```php
// Build test conversations
conversation(Agent $agent): ConversationBuilder

// Example:
conversation($agent)
    ->user('Hello')
    ->assistant('Hi')
    ->user('How are you?')
    ->assistant('I am fine')
    ->assert();
```

## Implementation Plan

### Phase 1: Core Response Expectations (1 hour)
1. Implement `toContainKeyword()`, `toContainKeywords()`
2. Implement `toMatchPattern()`
3. Implement `toBeWithinLength()`, `toHaveLengthGreaterThan()`, `toHaveLengthLessThan()`
4. Implement `toHaveCodeBlock()`

### Phase 2: Agent & Tool Expectations (1 hour)
1. Implement `toHaveTool()`, `toHaveTools()`, `toHaveToolCount()`
2. Implement `toHaveUsedTool()`, `toHaveUsedTools()`
3. Implement `toHaveToolCall()`, `toHaveToolCallCount()`
4. Implement `toHaveSystemPrompt()`, `toHaveModel()`, `toHaveTemperature()`
5. Implement `toHaveConversationLength()`, `toHaveMessageCount()`

### Phase 3: Guard & Middleware Expectations (0.5 hour)
1. Implement `toHaveGuard()`, `toHaveGuards()`, `toHaveGuardCount()`
2. Implement `toHaveTriggeredGuard()`, `toHavePassedGuard()`, `toHavePassedAllGuards()`
3. Implement `toHaveMiddleware()`, `toHaveMiddlewareCount()`

### Phase 4: Helper Functions (0.5 hour)
1. Implement `testAgentWithTools()`, `testAgentWithGuards()`, `testAgentWithMiddleware()`
2. Implement `mockTool()`, `mockToolThatFails()`, `mockToolWithDelay()`
3. Implement `assertAgentResponded()`, `assertToolCalled()`, `assertGuardBlocked()`

### Phase 5: Documentation & Tests (1 hour)
1. Update test files to use new expectations
2. Document all helpers in `tests/Pest.php`
3. Create examples in README or docs
4. Test all helpers work correctly

## Usage Examples

### Before (Current)

```php
test('agent uses weather tool', function () {
    $agent = testAgent();
    $agent->tool('weather', 'Get weather', fn($loc) => "Sunny in {$loc}");

    $mockProvider = new class implements Provider {
        public function prompt(string $msg, array $opts = []): object {
            return (object)[
                'content' => 'Using weather tool',
                'tool_calls' => [
                    ['id' => '1', 'name' => 'weather', 'arguments' => ['location' => 'NYC']],
                ],
                'model' => 'mock',
                'tokens' => 10,
                'provider' => 'mock',
            ];
        }
    };

    $agent->provider($mockProvider);
    $response = $agent->prompt('What is the weather in NYC?');

    expect($response)->toBeObject()
        ->and(isset($response->tool_calls))->toBeTrue()
        ->and($response->tool_calls[0]['name'])->toBe('weather')
        ->and($response->tool_calls[0]['arguments']['location'])->toBe('NYC');
});
```

### After (With Helpers)

```php
test('agent uses weather tool', function () {
    $agent = testAgent();
    $agent->tool('weather', 'Get weather', fn($loc) => "Sunny in {$loc}");

    $mockProvider = new class implements Provider {
        public function prompt(string $msg, array $opts = []): object {
            return (object)[
                'content' => 'Using weather tool',
                'tool_calls' => [
                    ['id' => '1', 'name' => 'weather', 'arguments' => ['location' => 'NYC']],
                ],
                'model' => 'mock',
                'tokens' => 10,
                'provider' => 'mock',
            ];
        }
    };

    $agent->provider($mockProvider);
    $response = $agent->prompt('What is the weather in NYC?');

    expect($response)
        ->toHaveUsedTool('weather')
        ->toHaveToolCall('weather', ['location' => 'NYC']);
});
```

### Guard Testing Example

```php
test('PII guard blocks sensitive data', function () {
    $agent = testAgent();
    $agent->guard('pii');

    $mockProvider = mock([
        'What is your email?' => 'My email is test@example.com'
    ]);

    $agent->provider($mockProvider);

    expect(fn() => $agent->prompt('What is your email?'))
        ->toThrow(GuardException::class);
});

// With fallback
test('PII guard uses fallback', function () {
    $agent = testAgent();
    $agent->guard('pii')->fallback(fn() => 'I cannot share that');

    $mockProvider = mock([
        'What is your email?' => 'My email is test@example.com'
    ]);

    $agent->provider($mockProvider);
    $response = $agent->prompt('What is your email?');

    expect($response)
        ->toHaveTriggeredGuard('pii')
        ->toHaveUsedFallback()
        ->toContainKeyword('cannot share');
});
```

## Files to Create/Modify

1. **tests/Pest.php** - Add all custom expectations and helpers
2. **tests/Unit/TestHelpersTest.php** - New file to test the helpers themselves
3. **README.md** - Update testing section with examples
4. **ai-docs/FEATURES.md** - Document new testing features

## Benefits

1. **Improved Readability** - Tests read like natural language
2. **Less Boilerplate** - Common patterns abstracted into helpers
3. **Consistency** - Standard way to test agent behavior
4. **Better DX** - Faster test writing, easier onboarding
5. **Maintainability** - Centralized assertion logic

## Related Features

- Evaluation framework (already uses metrics)
- Middleware system (metrics tracking)
- Guard system (exception handling)
- Tool calling (complex assertions)

## Success Metrics

- 50% reduction in test boilerplate
- All new tests use custom expectations
- Positive developer feedback
- Reduced test maintenance time
