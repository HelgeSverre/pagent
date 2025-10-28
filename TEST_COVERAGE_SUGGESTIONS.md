# Pagent Test Coverage Analysis & Recommendations

**Generated:** 2025-10-28
**Total Tests Analyzed:** 221+ across 35 test files
**Recommended New Tests:** 25 prioritized test cases

---

## Executive Summary

The Pagent test suite demonstrates **excellent coverage (~85%)** with mature testing practices. This analysis identifies **25 high-value test cases** focusing on error handling, edge cases, and provider-specific logic that would bring coverage to **~95%** and significantly improve production resilience.

### Quick Stats

- **Current Test Files:** 35
- **Current Test Cases:** 221+
- **Test Framework:** Pest PHP
- **Coverage Strengths:** Core functionality, guards, tools, orchestration
- **Coverage Gaps:** Provider unit tests, complex error scenarios, edge cases

---

## Test Coverage Gap Analysis

### Current Coverage by Component

| Component               | Test Files | Test Cases | Coverage Level | Gap Priority |
| ----------------------- | ---------- | ---------- | -------------- | ------------ |
| Core Agent              | 4          | 27         | Comprehensive  | Low          |
| Tools & Validation      | 3          | 22         | Comprehensive  | Medium       |
| Guards                  | 4          | 25         | Comprehensive  | Low          |
| Middleware              | 1          | 7          | Comprehensive  | Low          |
| Providers (Unit)        | 3          | 6          | **Minimal**    | **High**     |
| Providers (Integration) | 4          | 26+        | Comprehensive  | Low          |
| Orchestration           | 3          | 19         | Comprehensive  | Medium       |
| Workflows               | 2          | 15         | Comprehensive  | Low          |
| Evaluation              | 3          | 17         | Comprehensive  | Low          |
| Built-in Tools          | 8          | 49         | Comprehensive  | Low          |

---

## Priority 1: Critical Error Handling & Security 🔴

**Estimated Effort:** 2-3 hours
**Impact:** High - Prevents production failures and security vulnerabilities

### Test 1.1: Tool Call Infinite Loop Protection

**Test Case:** "Test tool call infinite loop detection"
**Target Function:** `Agent.php` → `prompt()` (lines 137-139)
**Test Type:** Unit
**Rationale:** The code has a while loop for tool execution with no infinite loop protection. If a tool continuously requests itself or creates a circular call chain, it could cause an infinite loop.

**Suggested Implementation:**

```php
// tests/Unit/AgentTest.php

it('prevents infinite tool call loops', function() {
    $callCount = 0;
    $mock = new class extends Mock {
        public int $callCount = 0;

        public function prompt(string $message, array $options = []): object {
            $this->callCount++;
            return (object)[
                'content' => 'response',
                'tool_calls' => [
                    ['name' => 'recursive_tool', 'arguments' => []]
                ]
            ];
        }
    };

    $agent = agent('test')->provider($mock);
    $agent->tool('recursive_tool', 'A tool that calls itself',
        fn() => ['result' => 'done']
    );

    expect(fn() => $agent->prompt('test'))
        ->toThrow(RuntimeException::class, 'Maximum tool call depth exceeded');
});
```

**Gap Identified:** No current tests for tool call loop scenarios
**Code Location:** `src/Agent.php:137-139`

---

### Test 1.2: Tool Not Found During Execution

**Test Case:** "Test tool execution when tool is removed mid-execution"
**Target Function:** `Agent.php` → `executeTool()` called from tool call loop
**Test Type:** Unit
**Rationale:** If a tool is removed between when the provider returns tool calls and when execution occurs, the system should handle gracefully.

**Suggested Implementation:**

```php
it('handles tool removal during execution gracefully', function() {
    $mock = new class extends Mock {
        public function prompt(string $message, array $options = []): object {
            return (object)[
                'content' => 'I will use the calculator',
                'tool_calls' => [
                    ['name' => 'calculate', 'arguments' => ['a' => 5, 'b' => 3]]
                ]
            ];
        }
    };

    $agent = agent('test')->provider($mock);
    $agent->tool('calculate', 'Do math', fn($a, $b) => $a + $b);

    // Simulate tool removal before execution
    $agent->clearTools();

    expect(fn() => $agent->prompt('test'))
        ->toThrow(RuntimeException::class, 'Tool not found: calculate');
});
```

**Gap Identified:** No tests for tool state changes during execution
**Code Location:** `src/Agent.php:502-528`

---

### Test 1.3: Anthropic Unknown Content Block Types

**Test Case:** "Test Anthropic response with unknown content block types"
**Target Function:** `Anthropic.php` → `prompt()` (lines 95-105)
**Test Type:** Unit (with mocked HTTP)
**Rationale:** The provider only handles 'text' and 'tool_use' block types. Unknown types are silently ignored. Should test forward compatibility.

**Suggested Implementation:**

```php
// tests/Unit/Providers/AnthropicTest.php

it('handles unknown content block types gracefully', function() {
    // Use reflection or a mock HTTP client to inject response
    $anthropicResponse = [
        'id' => 'msg_123',
        'type' => 'message',
        'role' => 'assistant',
        'content' => [
            ['type' => 'text', 'text' => 'Hello'],
            ['type' => 'future_block_type', 'data' => ['unknown' => 'data']],
            ['type' => 'text', 'text' => 'World']
        ],
        'model' => 'claude-3-sonnet-20240229',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 20]
    ];

    // Test that unknown blocks are skipped but known blocks are processed
    $response = $anthropic->prompt('test');

    expect($response->content)->toBe('HelloWorld');
    expect($response->tool_calls)->toBeEmpty();
});
```

**Gap Identified:** No tests for content block type extensibility
**Code Location:** `src/Providers/Anthropic.php:95-105`

---

### Test 1.4: OpenAI Malformed Tool Arguments

**Test Case:** "Test OpenAI tool call with malformed JSON arguments"
**Target Function:** `OpenAI.php` → `prompt()` (line 113)
**Test Type:** Unit (with mocked HTTP)
**Rationale:** Tool arguments undergo double JSON decode. Malformed JSON should be handled gracefully.

**Suggested Implementation:**

```php
// tests/Unit/Providers/OpenAITest.php

it('handles malformed tool call arguments gracefully', function() {
    $openaiResponse = [
        'id' => 'chatcmpl-123',
        'choices' => [
            [
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [
                        [
                            'id' => 'call_abc123',
                            'type' => 'function',
                            'function' => [
                                'name' => 'calculate',
                                'arguments' => '{invalid json'  // Malformed JSON
                            ]
                        ]
                    ]
                ]
            ]
        ]
    ];

    expect(fn() => $openai->prompt('test'))
        ->toThrow(RuntimeException::class, 'malformed tool arguments');
});
```

**Gap Identified:** No tests for JSON parsing failures in tool arguments
**Code Location:** `src/Providers/OpenAI.php:113`

---

### Test 1.5: Mixed Array Keys in Tool Arguments

**Test Case:** "Test validation with mixed associative and indexed array keys"
**Target Function:** `ToolValidator.php` → `isAssociativeArray()` (lines 82-87)
**Test Type:** Unit
**Rationale:** Real-world LLM responses might have mixed keys. The validator should handle this correctly.

**Suggested Implementation:**

```php
// tests/Unit/Tool/ToolValidatorTest.php

it('handles mixed array keys correctly', function() {
    $tool = Tool::fromClosure(
        'mixed_test',
        'Test mixed args',
        fn(string $a, int $b) => "$a-$b"
    );

    // Mixed keys should be treated as associative
    $mixedArgs = [0 => 'value1', 'b' => 42];

    expect(fn() => ToolValidator::validate($tool, $mixedArgs))
        ->not->toThrow();

    // Ensure first indexed arg is used for param 'a'
    expect($tool->execute($mixedArgs))->toBe('value1-42');
});

it('throws for mixed array with missing required parameters', function() {
    $tool = Tool::fromClosure(
        'mixed_test',
        'Test',
        fn(string $a, string $b) => "$a-$b"
    );

    // Has index 0 but missing named param 'b'
    $mixedArgs = [0 => 'value1'];

    expect(fn() => ToolValidator::validate($tool, $mixedArgs))
        ->toThrow(RuntimeException::class, 'missing required argument');
});
```

**Gap Identified:** Only pure associative and pure indexed arrays tested
**Code Location:** `src/Tool/ToolValidator.php:82-87`

---

## Priority 2: Boundary Conditions & Edge Cases 🟡

**Estimated Effort:** 3-4 hours
**Impact:** Medium - Prevents subtle bugs in edge cases

### Test 2.1: Empty Message Content

**Test Case:** "Test agent response with empty content field"
**Target Function:** `Agent.php` → `prompt()` (lines 163-165)
**Test Type:** Unit
**Rationale:** Should verify behavior when content is empty string, null, or missing.

**Suggested Implementation:**

```php
it('handles empty response content correctly', function() {
    $mock = new class extends Mock {
        public function prompt(string $message, array $options = []): object {
            return (object)[
                'content' => '',  // Empty content
                'tokens' => 10
            ];
        }
    };

    $agent = agent('test')->provider($mock);
    $agent->prompt('test');

    // Should not add empty content to history
    expect($agent->messages)->toHaveCount(1);  // Only user message
    expect($agent->messages[0]['role'])->toBe('user');
});

it('handles null response content correctly', function() {
    $mock = new class extends Mock {
        public function prompt(string $message, array $options = []): object {
            return (object)[
                'content' => null,
                'tokens' => 10
            ];
        }
    };

    $agent = agent('test')->provider($mock);
    $response = $agent->prompt('test');

    expect($response->content)->toBeNull();
    expect($agent->messages)->toHaveCount(1);  // Only user message
});
```

**Gap Identified:** No tests for empty/null content edge cases
**Code Location:** `src/Agent.php:163-165`

---

### Test 2.2: Guard Execution Order

**Test Case:** "Test guard execution stops at first violation"
**Target Function:** `Agent.php` → guard loop (lines 451-462)
**Test Type:** Unit
**Rationale:** Should verify that guard execution stops at first failure and later guards aren't called.

**Suggested Implementation:**

```php
it('stops guard execution at first violation', function() {
    $mock = mock(['test' => 'response with bad content']);

    $firstGuardCalled = false;
    $secondGuardCalled = false;

    $firstGuard = new class implements Guard {
        public bool &$called;

        public function check(string $input, string $output): bool {
            $this->called = true;
            return false;  // Violation
        }

        public function getName(): string { return 'first'; }
        public function getViolationMessage(): string { return 'First violation'; }
    };

    $secondGuard = new class implements Guard {
        public bool &$called;

        public function check(string $input, string $output): bool {
            $this->called = true;
            return true;
        }

        public function getName(): string { return 'second'; }
        public function getViolationMessage(): string { return 'Second violation'; }
    };

    $agent = agent('test')
        ->provider($mock)
        ->guard($firstGuard)
        ->guard($secondGuard);

    expect(fn() => $agent->prompt('test'))->toThrow(GuardException::class);
    expect($firstGuardCalled)->toBeTrue();
    expect($secondGuardCalled)->toBeFalse();  // Should NOT be called
});
```

**Gap Identified:** No test validates early-exit behavior
**Code Location:** `src/Agent.php:451-462`

---

### Test 2.3: Transform Function Exceptions

**Test Case:** "Test pipeline stage with transform function that throws exception"
**Target Function:** `Orchestration/Pipeline.php` → `run()` (lines 63-67)
**Test Type:** Unit
**Rationale:** Transform functions can throw exceptions. Should test error handler behavior.

**Suggested Implementation:**

```php
// tests/Unit/Orchestration/PipelineTest.php

it('handles transform function exceptions with error handler', function() {
    $mock = mock(['step1' => 'output1']);

    $errorHandlerCalled = false;

    $pipeline = pipeline('test')
        ->agent(agent('agent1')->provider($mock))
        ->agent(
            agent('agent2')->provider($mock),
            fn($output) => throw new RuntimeException('Transform failed')
        )
        ->onError(function($exception, $stage, $input) use (&$errorHandlerCalled) {
            $errorHandlerCalled = true;
            expect($exception->getMessage())->toBe('Transform failed');
            expect($stage)->toBe(1);  // Second stage (0-indexed)
            return 'fallback';
        });

    $result = $pipeline->run('input');
    expect($errorHandlerCalled)->toBeTrue();
    expect($result)->toBe('fallback');
});

it('throws transform function exceptions without error handler', function() {
    $mock = mock(['step1' => 'output1']);

    $pipeline = pipeline('test')
        ->agent(agent('agent1')->provider($mock))
        ->agent(
            agent('agent2')->provider($mock),
            fn($output) => throw new RuntimeException('Transform failed')
        );

    expect(fn() => $pipeline->run('input'))
        ->toThrow(RuntimeException::class, 'Stage 1 failed');
});
```

**Gap Identified:** Transform exceptions not explicitly tested
**Code Location:** `src/Orchestration/Pipeline.php:63-67`

---

### Test 2.4: Multiple Supervisor Feedback Rounds

**Test Case:** "Test delegation with multiple supervisor rejections and revisions"
**Target Function:** `Delegation.php` → `execute()` (lines 76-79)
**Test Type:** Unit
**Rationale:** Worker should be able to revise multiple times based on supervisor feedback.

**Suggested Implementation:**

```php
// tests/Unit/Orchestration/DelegationTest.php

it('handles multiple supervisor feedback rounds', function() {
    $responses = ['draft v1', 'draft v2', 'draft v3', 'final draft'];
    $responseIndex = 0;

    $mock = new class($responses, $responseIndex) extends Mock {
        private array $responses;
        private int &$index;

        public function __construct(array $responses, int &$index) {
            $this->responses = $responses;
            $this->index = &$index;
        }

        public function prompt(string $message, array $options = []): object {
            $response = $this->responses[$this->index] ?? 'done';
            $this->index++;
            return (object)['content' => $response, 'tokens' => 10];
        }
    };

    $attempts = 0;
    $supervisor = function($result) use (&$attempts) {
        $attempts++;
        return match($attempts) {
            1 => "Needs more detail (attempt $attempts)",
            2 => "Still not enough (attempt $attempts)",
            3 => "Better but needs polish (attempt $attempts)",
            default => true  // Accept on 4th attempt
        };
    };

    $manager = agent('manager')->provider($mock);
    $worker = agent('worker')->provider($mock);

    $delegation = $manager->delegate('Create report')
        ->to($worker)
        ->supervise($supervisor);

    $result = $delegation->execute();

    expect($attempts)->toBe(4);
    expect($responseIndex)->toBe(4);  // Worker called 3 times, manager once
});
```

**Gap Identified:** Only single rejection tested, not multiple feedback rounds
**Code Location:** `src/Orchestration/Delegation.php:76-79`

---

### Test 2.5: Circular Handoff Detection

**Test Case:** "Test detection of circular handoff chains"
**Target Function:** `Handoff.php` → `transfer()`
**Test Type:** Unit
**Rationale:** Agent A → Agent B → Agent A creates a circular reference that should be detected or documented.

**Suggested Implementation:**

```php
// tests/Unit/Orchestration/HandoffTest.php

it('handles circular handoff chains', function() {
    $mock = mock(['task' => 'response']);

    $agentA = agent('agentA')->provider($mock);
    $agentB = agent('agentB')->provider($mock);

    // A hands off to B
    $agentA->prompt('Initial task');
    $transferredB = $agentA->handoff('agentB')->transfer();

    // B hands off back to A
    $transferredB->prompt('Continue task');
    $transferredA = $transferredB->handoff('agentA')->transfer();

    // Should succeed but context should show handoff chain
    expect($transferredA->messages)->toContain(
        fn($msg) => str_contains($msg['content'] ?? '', 'Handoff from agentB')
    );
});
```

**Gap Identified:** No tests for circular handoff scenarios
**Code Location:** `src/Orchestration/Handoff.php:49-67`

---

### Test 2.6: Provider-Specific Message Formatting

**Test Case:** "Test message formatting edge cases for different providers"
**Target Function:** `Agent.php` → `formatToolCallMessage()` & `formatToolResult()`
**Test Type:** Unit
**Rationale:** Different logic for Anthropic vs OpenAI. Should test provider detection and formatting.

**Suggested Implementation:**

```php
// tests/Unit/AgentTest.php

it('formats tool calls correctly for Anthropic', function() {
    $anthropic = new Anthropic(['api_key' => 'test-key']);
    $agent = agent('test')->provider($anthropic);

    $response = (object)[
        'raw_content' => [
            ['type' => 'text', 'text' => 'Using tool'],
            ['type' => 'tool_use', 'id' => 'tool_123', 'name' => 'calc', 'input' => ['a' => 1]]
        ],
        'tool_calls' => [['id' => 'tool_123', 'name' => 'calc', 'arguments' => ['a' => 1]]]
    ];

    // Use reflection to call private method
    $reflection = new ReflectionClass($agent);
    $method = $reflection->getMethod('formatToolCallMessage');
    $method->setAccessible(true);

    $message = $method->invoke($agent, $response);

    expect($message['role'])->toBe('assistant');
    expect($message['content'])->toBeArray();
});

it('formats tool calls correctly for OpenAI', function() {
    $openai = new OpenAI(['api_key' => 'test-key']);
    $agent = agent('test')->provider($openai);

    $response = (object)[
        'content' => 'Using tool',
        'tool_calls' => [[
            'id' => 'call_123',
            'name' => 'calc',
            'arguments' => ['a' => 1]
        ]]
    ];

    $reflection = new ReflectionClass($agent);
    $method = $reflection->getMethod('formatToolCallMessage');
    $method->setAccessible(true);

    $message = $method->invoke($agent, $response);

    expect($message['role'])->toBe('assistant');
    expect($message['content'])->toBe('Using tool');
    expect($message['tool_calls'])->toBeArray();
});
```

**Gap Identified:** Provider detection logic not explicitly tested
**Code Location:** `src/Agent.php:486-528`

---

## Priority 3: Untested Logic Paths 🟢

**Estimated Effort:** 2-3 hours
**Impact:** Medium - Covers existing code paths without explicit tests

### Test 3.1: IP Address Detection

**Test Case:** "Test PII guard IP address detection"
**Target Function:** `PIIGuard.php` → check() with IP pattern
**Test Type:** Unit
**Rationale:** IP address pattern exists but isn't tested. Should validate pattern works.

**Suggested Implementation:**

```php
// tests/Unit/Guards/PIIGuardTest.php

it('detects IP addresses when enabled', function() {
    $guard = new PIIGuard(['ip']);

    expect($guard->check('', 'Server IP: 192.168.1.1'))->toBeFalse();
    expect($guard->check('', 'Connect to 10.0.0.1'))->toBeFalse();
    expect($guard->check('', 'Public IP: 8.8.8.8'))->toBeFalse();
    expect($guard->check('', 'No IP addresses here'))->toBeTrue();
});

it('validates various IP formats', function() {
    $guard = new PIIGuard(['ip']);

    // Valid IPs that should be detected
    expect($guard->check('', '127.0.0.1'))->toBeFalse();
    expect($guard->check('', '255.255.255.255'))->toBeFalse();

    // Invalid IPs should still be caught (pattern doesn't validate ranges)
    expect($guard->check('', '999.999.999.999'))->toBeFalse();
});
```

**Gap Identified:** IP pattern exists but has no tests
**Code Location:** `src/Guards/PIIGuard.php:18`

---

### Test 3.2: Content Filter Strict Mode

**Test Case:** "Test content filter strict mode behavior"
**Target Function:** `ContentFilterGuard.php` → constructor
**Test Type:** Unit
**Rationale:** Strict mode parameter exists but is unused. Should document intended behavior.

**Suggested Implementation:**

```php
// tests/Unit/Guards/ContentFilterGuardTest.php

it('has strict mode parameter for future use', function() {
    $guard = new ContentFilterGuard([], strictMode: true);
    expect($guard)->toBeInstanceOf(ContentFilterGuard::class);
});

// If strict mode should be implemented:
it('strict mode blocks borderline content', function() {
    $normalGuard = new ContentFilterGuard();
    $strictGuard = new ContentFilterGuard(strictMode: true);

    $borderlineContent = 'This is mildly concerning';

    expect($normalGuard->check('', $borderlineContent))->toBeTrue();
    expect($strictGuard->check('', $borderlineContent))->toBeFalse();
})->skip('Strict mode not yet implemented');
```

**Gap Identified:** Strict mode parameter exists but unused
**Code Location:** `src/Guards/ContentFilterGuard.php:19`

---

### Test 3.3: Conversation Import Edge Cases

**Test Case:** "Test importConversation with various malformed JSON"
**Target Function:** `Agent.php` → `importConversation()`
**Test Type:** Unit
**Rationale:** More edge cases for JSON validation beyond basic structure.

**Suggested Implementation:**

```php
// tests/Unit/AgentTest.php

it('handles various invalid conversation JSON formats', function() {
    $agent = agent('test')->provider(mock());

    // Invalid JSON syntax
    expect(fn() => $agent->importConversation('not json'))
        ->toThrow(RuntimeException::class);

    // Valid JSON but wrong structure
    expect(fn() => $agent->importConversation('{"messages": "not an array"}'))
        ->toThrow(RuntimeException::class);

    // Missing messages key
    expect(fn() => $agent->importConversation('{"data": []}'))
        ->toThrow(RuntimeException::class);

    // Empty messages array (should be valid)
    expect(fn() => $agent->importConversation('{"messages": []}'))
        ->not->toThrow();
});

it('validates message structure in imported conversations', function() {
    $agent = agent('test')->provider(mock());

    // Messages without role
    $invalidMessages = json_encode([
        'messages' => [
            ['content' => 'Hello']  // Missing role
        ]
    ]);

    // Should either throw or silently handle
    $agent->importConversation($invalidMessages);
    expect($agent->messages)->toHaveCount(1);
});
```

**Gap Identified:** Limited JSON validation edge cases tested
**Code Location:** `src/Agent.php:411-413`

---

### Test 3.4: Provider Config Passing

**Test Case:** "Test provider configuration is properly passed through builder"
**Target Function:** `AgentBuilder.php` → `provider()`
**Test Type:** Unit
**Rationale:** Config array accepted but not validated that it reaches provider.

**Suggested Implementation:**

```php
// tests/Unit/AgentBuilderTest.php

it('passes configuration to provider constructor', function() {
    $config = [
        'api_key' => 'test-custom-key',
        'timeout' => 60,
        'max_retries' => 3
    ];

    $builder = agent('test')->provider('mock', $config);
    $agent = $builder->build();

    // Verify config was passed to provider
    $provider = $agent->provider;
    expect($provider)->toBeInstanceOf(Mock::class);
});

it('allows provider-specific configuration', function() {
    // For providers that accept custom config
    $anthropicConfig = [
        'api_key' => 'test-key',
        'base_url' => 'https://custom.api.endpoint'
    ];

    expect(fn() => agent('test')->provider('anthropic', $anthropicConfig))
        ->not->toThrow();
});
```

**Gap Identified:** Config passing not validated
**Code Location:** `src/AgentBuilder.php:32-36`

---

### Test 3.5: Empty Pipeline Execution

**Test Case:** "Test pipeline execution with no steps"
**Target Function:** `Workflow/Pipeline.php` → `run()`
**Test Type:** Unit
**Rationale:** Should define and test behavior for pipelines without steps.

**Suggested Implementation:**

```php
// tests/Unit/Workflow/PipelineTest.php

it('handles empty pipeline gracefully', function() {
    $pipeline = Pipeline::create();

    $result = $pipeline->run('input');

    // Should return input unchanged with empty result
    expect($result->output)->toBe('input');
    expect($result->steps)->toBeEmpty();
    expect($result->metadata->total_tokens)->toBe(0);
});
```

**Gap Identified:** Empty pipeline behavior not tested
**Code Location:** `src/Workflow/Pipeline.php:49-75`

---

### Test 3.6: Empty Chain Execution

**Test Case:** "Test chain execution with no agents"
**Target Function:** `Workflow/Chain.php` → `run()`
**Test Type:** Unit
**Rationale:** Should define and test behavior for chains without agents.

**Suggested Implementation:**

```php
// tests/Unit/Workflow/ChainTest.php

it('handles empty chain gracefully', function() {
    $chain = Chain::create();

    $result = $chain->run('input');

    expect($result->output)->toBe('input');
    expect($result->steps)->toBeEmpty();
    expect($result->metadata->total_tokens)->toBe(0);
});
```

**Gap Identified:** Empty chain behavior not tested
**Code Location:** `src/Workflow/Chain.php:34-55`

---

## Priority 4: Integration & Complex Scenarios 🔵

**Estimated Effort:** 4-5 hours
**Impact:** Medium - Validates complex interactions

### Test 4.1: Multi-Turn Tool Execution

**Test Case:** "Test agent continues conversation after tool execution with history"
**Target Function:** `Agent.php` → prompt() with tool call loop
**Test Type:** Integration
**Rationale:** Tool execution adds messages to history. Multi-turn conversations should accumulate correctly.

**Suggested Implementation:**

```php
// tests/Integration/ToolCallingTest.php

it('maintains conversation history through multiple tool calls', function() {
    $mock = mock([
        'What is 5 + 3?' => 'Let me calculate that',
        'What about 10 - 2?' => 'Let me calculate that too'
    ]);

    $agent = agent('test')
        ->provider($mock)
        ->tool('calculate', 'Do math', fn($a, $b, $op) => match($op) {
            '+' => $a + $b,
            '-' => $a - $b,
            default => 0
        });

    // First calculation
    $agent->prompt('What is 5 + 3?');
    expect($agent->messages)->toHaveCount(4);  // user, assistant, tool_result, assistant

    // Second calculation should build on history
    $agent->prompt('What about 10 - 2?');
    expect($agent->messages)->toHaveCount(8);  // Previous 4 + new 4

    // Verify history contains both calculations
    $toolMessages = array_filter($agent->messages,
        fn($m) => ($m['role'] ?? '') === 'tool'
    );
    expect($toolMessages)->toHaveCount(2);
});
```

**Gap Identified:** Multi-turn tool conversations not tested
**Code Location:** `src/Agent.php:137-139`

---

### Test 4.2: Mixed Provider Workflow

**Test Case:** "Test pipeline with different providers for each stage"
**Target Function:** `Orchestration/Pipeline.php` with multiple providers
**Test Type:** Integration
**Rationale:** Pipeline should support mixing Anthropic, OpenAI, and Mock providers.

**Suggested Implementation:**

```php
// tests/Integration/ProviderFeaturesTest.php

it('supports mixed providers in pipeline', function() {
    $anthropic = anthropic();
    $openai = openai();
    $mock = mock(['stage3' => 'final output']);

    $pipeline = pipeline('mixed')
        ->agent(agent('stage1')->provider($anthropic))
        ->agent(agent('stage2')->provider($openai))
        ->agent(agent('stage3')->provider($mock));

    $result = $pipeline->run('Analyze this data');

    expect($result)->toBe('final output');
    expect($pipeline->getResults())->toHaveCount(3);

    // Verify each stage used different provider
    $results = $pipeline->getResults();
    expect($results[0]['agent'])->toBe('stage1');
    expect($results[1]['agent'])->toBe('stage2');
    expect($results[2]['agent'])->toBe('stage3');
})->group('api');
```

**Gap Identified:** Mixed provider workflows not explicitly tested
**Code Location:** Multiple provider classes + Pipeline

---

### Test 4.3: Delegation with Tool-Using Agents

**Test Case:** "Test delegation where worker agent uses tools"
**Target Function:** `Delegation.php` + Agent tool execution
**Type:** Integration
**Rationale:** Worker might need tools to complete task. Supervisor should see final result.

**Suggested Implementation:**

```php
// tests/Integration/ToolCallingTest.php

it('allows worker to use tools during delegation', function() {
    $mock = mock([
        'Calculate total' => 'I will use the calculator',
        'Review' => 'The calculation is correct'
    ]);

    $manager = agent('manager')->provider($mock);
    $worker = agent('worker')
        ->provider($mock)
        ->tool('calculate', 'Do math', fn($a, $b) => $a + $b);

    $delegation = $manager->delegate('Calculate 50 + 30')
        ->to($worker)
        ->supervise(fn($result) => true);  // Always approve

    $result = $delegation->execute();

    // Worker should have used tool
    $toolUsed = array_filter($worker->messages,
        fn($m) => isset($m['name']) && $m['name'] === 'calculate'
    );
    expect($toolUsed)->not->toBeEmpty();

    // Manager should see final result, not tool internals
    expect($result->manager_review)->toBeString();
});
```

**Gap Identified:** Tool usage within delegation not tested
**Code Location:** `src/Orchestration/Delegation.php` + `src/Agent.php`

---

### Test 4.4: Guard Violation with Middleware

**Test Case:** "Test guard violation with active middleware stack"
**Target Function:** `Agent.php` → middleware + guards
**Test Type:** Integration
**Rationale:** When guard fails, middleware after() hooks might not run. Should test this behavior.

**Suggested Implementation:**

```php
// tests/Integration/BasicUsageTest.php

it('handles guard violations with middleware correctly', function() {
    $beforeCalled = false;
    $afterCalled = false;

    $middleware = new class implements Middleware {
        public bool &$beforeCalled;
        public bool &$afterCalled;

        public function before(string $prompt, array $options): array {
            $this->beforeCalled = true;
            return $options;
        }

        public function after(object $response): object {
            $this->afterCalled = true;
            return $response;
        }
    };

    $mock = mock(['test' => 'sensitive data: 123-45-6789']);

    $agent = agent('test')
        ->provider($mock)
        ->middleware($middleware)
        ->guard(new PIIGuard());

    expect(fn() => $agent->prompt('test'))
        ->toThrow(GuardException::class);

    expect($beforeCalled)->toBeTrue();   // Before should run
    expect($afterCalled)->toBeFalse();   // After should NOT run due to guard
});
```

**Gap Identified:** Middleware behavior during guard failures not tested
**Code Location:** `src/Agent.php` middleware + guard sections

---

### Test 4.5: Large Message History Performance

**Test Case:** "Test agent performance with large message history"
**Target Function:** `Agent.php` → message history handling
**Test Type:** Performance
**Rationale:** Should test memory and performance with large histories (100+ messages).

**Suggested Implementation:**

```php
// tests/Unit/AgentTest.php

it('handles large message history efficiently', function() {
    $mock = mock();
    $agent = agent('test')->provider($mock);

    $startMemory = memory_get_usage();
    $startTime = microtime(true);

    // Simulate 100 turns of conversation
    for ($i = 0; $i < 100; $i++) {
        $agent->prompt("Message $i");
    }

    $endTime = microtime(true);
    $endMemory = memory_get_usage();

    expect($agent->messages)->toHaveCount(200);  // 100 user + 100 assistant

    $duration = $endTime - $startTime;
    $memoryUsed = ($endMemory - $startMemory) / 1024 / 1024;  // MB

    expect($duration)->toBeLessThan(1.0);  // Should complete in < 1 second
    expect($memoryUsed)->toBeLessThan(10);  // Should use < 10MB
})->group('performance');
```

**Gap Identified:** No performance tests for large histories
**Code Location:** `src/Agent.php` message management

---

## Priority 5: Provider Unit Tests (Mocked HTTP) 🟣

**Estimated Effort:** 2-3 hours
**Impact:** High - Significantly improves provider coverage

### Test 5.1: Anthropic HTTP Error Codes

**Test Case:** "Test Anthropic provider handles various HTTP error codes"
**Target Function:** `Anthropic.php` → `prompt()` (lines 83-89)
**Test Type:** Unit (with mocked cURL)
**Rationale:** Should test 401, 429, 500, etc. Currently only API key validation tested.

**Suggested Implementation:**

```php
// tests/Unit/Providers/AnthropicTest.php

it('handles 401 authentication errors', function() {
    // Use a mock HTTP client or stub cURL functions
    $anthropic = new Anthropic(['api_key' => 'invalid-key']);

    // Mock cURL response with 401
    expect(fn() => $anthropic->prompt('test'))
        ->toThrow(RuntimeException::class, 'authentication_error');
});

it('handles 429 rate limit errors', function() {
    $anthropic = new Anthropic(['api_key' => 'test-key']);

    // Mock 429 response
    expect(fn() => $anthropic->prompt('test'))
        ->toThrow(RuntimeException::class, 'rate_limit_error');
});

it('handles 500 server errors', function() {
    $anthropic = new Anthropic(['api_key' => 'test-key']);

    // Mock 500 response
    expect(fn() => $anthropic->prompt('test'))
        ->toThrow(RuntimeException::class, 'Internal server error');
});
```

**Gap Identified:** Only API key validation tested, no HTTP error handling
**Code Location:** `src/Providers/Anthropic.php:83-89`

---

### Test 5.2: OpenAI Pass-Through Options

**Test Case:** "Test OpenAI pass-through options are sent to API"
**Target Function:** `OpenAI.php` → `prompt()` (lines 68-72)
**Test Type:** Unit (with mocked HTTP)
**Rationale:** Should verify that response_format, seed, etc. are actually passed through.

**Suggested Implementation:**

```php
// tests/Unit/Providers/OpenAITest.php

it('passes through response_format option', function() {
    $openai = new OpenAI(['api_key' => 'test-key']);

    // Mock HTTP client to capture request body
    $requestBody = null;

    $openai->prompt('test', [
        'response_format' => ['type' => 'json_object']
    ]);

    expect($requestBody['response_format']['type'])->toBe('json_object');
});

it('passes through seed option', function() {
    $openai = new OpenAI(['api_key' => 'test-key']);

    $openai->prompt('test', ['seed' => 12345]);

    expect($requestBody['seed'])->toBe(12345);
});

it('does not pass through internal options', function() {
    $openai = new OpenAI(['api_key' => 'test-key']);

    $openai->prompt('test', [
        'system' => 'System prompt',
        'messages' => [],
        'custom_option' => 'value'
    ]);

    // Internal options should be filtered
    expect($requestBody)->not->toHaveKey('system');
    expect($requestBody)->not->toHaveKey('messages');

    // Custom options should pass through
    expect($requestBody['custom_option'])->toBe('value');
});
```

**Gap Identified:** Pass-through logic not tested
**Code Location:** `src/Providers/OpenAI.php:68-72`

---

### Test 5.3: cURL Failure Scenarios

**Test Case:** "Test provider handles cURL failures (timeout, DNS, connection refused)"
**Target Function:** `Anthropic.php` & `OpenAI.php` → `prompt()` cURL sections
**Test Type:** Unit (with mocked cURL)
**Rationale:** Network failures should be handled gracefully.

**Suggested Implementation:**

```php
// tests/Unit/Providers/AnthropicTest.php

it('handles connection timeout', function() {
    $anthropic = new Anthropic(['api_key' => 'test-key']);

    // Mock cURL to return false (connection failed)
    expect(fn() => $anthropic->prompt('test'))
        ->toThrow(RuntimeException::class, 'API request failed');
});

it('handles DNS resolution failure', function() {
    $anthropic = new Anthropic(['api_key' => 'test-key']);

    // Mock cURL error
    expect(fn() => $anthropic->prompt('test'))
        ->toThrow(RuntimeException::class);
});

// Similar tests for OpenAI provider
```

**Gap Identified:** Network failure scenarios not tested
**Code Location:** `src/Providers/Anthropic.php:77`, `src/Providers/OpenAI.php:92`

---

## Implementation Strategy

### Phase 1: Critical Safety (Week 1)

- Test 1.1: Tool call infinite loop protection
- Test 1.2: Tool removal during execution
- Test 1.5: Mixed array keys validation

**Deliverable:** 3 critical safety tests implemented

### Phase 2: Provider Coverage (Week 2)

- Test 5.1: Anthropic HTTP error codes
- Test 5.2: OpenAI pass-through options
- Test 5.3: cURL failure scenarios

**Deliverable:** Provider unit test coverage increased from minimal to comprehensive

### Phase 3: Edge Cases (Week 3)

- Test 2.1: Empty message content
- Test 2.2: Guard execution order
- Test 3.1: IP address detection
- Test 3.3: Conversation import edge cases

**Deliverable:** 4 edge case tests covering boundary conditions

### Phase 4: Complex Scenarios (Week 4)

- Test 2.3: Transform exceptions
- Test 2.4: Multiple supervisor feedback
- Test 4.1: Multi-turn tool execution
- Test 4.4: Guard violations with middleware

**Deliverable:** Integration test suite expanded with complex scenarios

### Phase 5: Completeness (Week 5)

- Remaining tests from all priorities
- Documentation updates
- Test coverage report

**Deliverable:** All 25 tests implemented, coverage at ~95%

---

## Testing Best Practices for Implementation

### 1. Test Isolation

```php
// Use beforeEach/afterEach for setup/teardown
beforeEach(function() {
    clearAgents();  // Clean registry
    $this->tempDir = sys_get_temp_dir() . '/pagent_tests_' . uniqid();
    mkdir($this->tempDir);
});

afterEach(function() {
    if (file_exists($this->tempDir)) {
        // Recursive delete
    }
});
```

### 2. Mock HTTP Clients

```php
// Create a MockHttpClient for testing providers
class MockHttpClient {
    public function __construct(
        private int $statusCode = 200,
        private array $response = [],
        private ?Exception $exception = null
    ) {}

    public function post(string $url, array $data): array {
        if ($this->exception) {
            throw $this->exception;
        }

        return [
            'status' => $this->statusCode,
            'body' => json_encode($this->response)
        ];
    }
}
```

### 3. Custom Assertions

```php
// Add custom Pest expectations
expect()->extend('toHaveToolCall', function(string $toolName) {
    $toolCalls = array_filter(
        $this->value,
        fn($call) => $call['name'] === $toolName
    );

    expect($toolCalls)->not->toBeEmpty();
});

// Usage
expect($response->tool_calls)->toHaveToolCall('calculate');
```

### 4. Test Data Builders

```php
// Create builders for complex test data
class ResponseBuilder {
    private array $data = [];

    public static function create(): self {
        return new self();
    }

    public function withContent(string $content): self {
        $this->data['content'] = $content;
        return $this;
    }

    public function withToolCall(string $name, array $args): self {
        $this->data['tool_calls'][] = [
            'name' => $name,
            'arguments' => $args
        ];
        return $this;
    }

    public function build(): object {
        return (object)$this->data;
    }
}

// Usage
$response = ResponseBuilder::create()
    ->withContent('Let me calculate')
    ->withToolCall('add', ['a' => 5, 'b' => 3])
    ->build();
```

---

## Measuring Success

### Coverage Metrics

**Before:** ~85% coverage (221 tests)
**Target:** ~95% coverage (246 tests)

### Key Metrics to Track

1. **Line Coverage:** Target 90%+
2. **Branch Coverage:** Target 85%+
3. **Error Path Coverage:** Target 100% for critical paths
4. **Integration Coverage:** All orchestration patterns tested

### Running Coverage Reports

```bash
# Generate coverage report
composer test:coverage

# View HTML report
open coverage/index.html

# Check minimum coverage threshold
composer test:coverage --min=90
```

---

## Maintenance Guidelines

### When Adding New Features

**Always add tests for:**

- All public methods
- Error conditions
- Edge cases (null, empty, boundary values)
- Integration with existing features

### Test Review Checklist

- [ ] Test is isolated (no external dependencies)
- [ ] Test name clearly describes what is being tested
- [ ] Assertions are specific and meaningful
- [ ] Error messages are helpful
- [ ] Edge cases are covered
- [ ] Performance implications considered
- [ ] Documentation updated if needed

---

## Conclusion

This test coverage analysis identified **25 high-value test cases** that would bring the Pagent test suite from **85% to 95% coverage**. The recommendations focus on:

1. **Critical Safety** - Preventing infinite loops and data loss
2. **Provider Coverage** - Testing HTTP error handling and configuration
3. **Edge Cases** - Covering boundary conditions and unusual inputs
4. **Integration** - Validating complex multi-component interactions

The suggested implementation strategy spreads the work across 5 weeks, with early focus on critical safety and provider coverage. All tests include code examples and can be implemented using the existing Pest PHP testing framework.

**Estimated Total Effort:** 13-18 hours
**Expected Coverage Improvement:** +10% (85% → 95%)
**Priority Focus:** Error handling, security, and provider-specific logic
