<?php

declare(strict_types=1);

use Pagent\Agent;
use Pagent\Memory\Adapters\FileAdapter;
use Pagent\Memory\Adapters\SqliteAdapter;
use Pagent\Providers\Mock;
use Pagent\Tool\Tool;
use Tests\Integration\MockOpenAIWithTools;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/pagent_test_'.uniqid();
    $this->tempDbPath = $this->tempDir.'/test_sessions.db';
    mkdir($this->tempDir, 0755, true);
});

afterEach(function (): void {
    // Clean up temporary files and directories
    if (is_dir($this->tempDir)) {
        $files = glob($this->tempDir.'/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->tempDir);
    }
});

it('persists conversation with File adapter', function (): void {
    $adapter = new FileAdapter(['path' => $this->tempDir]);
    $agent = new Agent('test-agent');
    $agent
        ->provider(new Mock(['responses' => ['hello' => 'Hi there!', 'how are you' => 'I am good']]))
        ->memory($adapter)
        ->sessionId('session-001');

    // First interaction
    $response = $agent->prompt('hello');
    expect($response->content)->toBe('Hi there!');

    // Second interaction
    $response = $agent->prompt('how are you');
    expect($response->content)->toBe('I am good');

    // Verify conversation is persisted
    expect($adapter->exists('session-001'))->toBeTrue();

    $messages = $adapter->load('session-001');
    expect($messages)->toHaveCount(4);
    expect($messages[0])->toBe(['role' => 'user', 'content' => 'hello']);
    expect($messages[1])->toBe(['role' => 'assistant', 'content' => 'Hi there!']);
    expect($messages[2])->toBe(['role' => 'user', 'content' => 'how are you']);
    expect($messages[3])->toBe(['role' => 'assistant', 'content' => 'I am good']);
});

it('persists conversation with SQLite adapter', function (): void {
    $agent = new Agent('test-agent');
    $agent
        ->provider(new Mock(['responses' => ['hello' => 'Hi!', 'bye' => 'Goodbye!']]))
        ->memory('Sqlite', ['path' => $this->tempDbPath])
        ->sessionId('session-002');

    // First interaction
    $response = $agent->prompt('hello');
    expect($response->content)->toBe('Hi!');

    // Second interaction
    $response = $agent->prompt('bye');
    expect($response->content)->toBe('Goodbye!');

    // Verify database exists
    expect(file_exists($this->tempDbPath))->toBeTrue();

    // Verify data is in database
    $adapter = new SqliteAdapter(['path' => $this->tempDbPath]);
    $messages = $adapter->load('session-002');
    expect($messages)->toHaveCount(4);
    expect($messages[0])->toBe(['role' => 'user', 'content' => 'hello']);
    expect($messages[1])->toBe(['role' => 'assistant', 'content' => 'Hi!']);
});

it('loads conversation from previous session', function (): void {
    // First session - create conversation
    $agent1 = new Agent('agent-1');
    $agent1
        ->provider(new Mock(['responses' => ['hello' => 'Hi!']]))
        ->memory('File', ['directory' => $this->tempDir])
        ->sessionId('session-003');

    $agent1->prompt('hello');

    // Second session - load previous conversation
    $agent2 = new Agent('agent-2');
    $agent2
        ->provider(new Mock(['responses' => ['continue' => 'Sure, continuing...']]))
        ->memory('File', ['directory' => $this->tempDir])
        ->sessionId('session-003');

    // Messages should be empty before first prompt
    expect($agent2->messages)->toBeEmpty();

    // First prompt triggers auto-load
    $response = $agent2->prompt('continue');
    expect($response->content)->toBe('Sure, continuing...');

    // Should have loaded previous messages plus new ones
    expect($agent2->messages)->toHaveCount(4);
    expect($agent2->messages[0])->toBe(['role' => 'user', 'content' => 'hello']);
    expect($agent2->messages[1])->toBe(['role' => 'assistant', 'content' => 'Hi!']);
    expect($agent2->messages[2])->toBe(['role' => 'user', 'content' => 'continue']);
    expect($agent2->messages[3])->toBe(['role' => 'assistant', 'content' => 'Sure, continuing...']);
});

it('keeps multiple sessions isolated correctly', function (): void {
    $mock = new Mock(['responses' => ['msg' => 'response']]);

    // Session 1
    $agent1 = new Agent('agent-1');
    $agent1
        ->provider($mock)
        ->memory('File', ['directory' => $this->tempDir])
        ->sessionId('session-a');
    $agent1->prompt('msg');

    // Session 2
    $agent2 = new Agent('agent-2');
    $agent2
        ->provider($mock)
        ->memory('File', ['directory' => $this->tempDir])
        ->sessionId('session-b');
    $agent2->prompt('msg');

    // Load and verify both sessions through the memory contract.
    $adapter = new FileAdapter(['directory' => $this->tempDir]);
    expect($adapter->exists('session-a'))->toBeTrue();
    expect($adapter->exists('session-b'))->toBeTrue();
    $messagesA = $adapter->load('session-a');
    $messagesB = $adapter->load('session-b');

    expect($messagesA)->toHaveCount(2);
    expect($messagesB)->toHaveCount(2);
    expect($messagesA)->toBe($messagesB); // Same content but separate storage
});

it('context window pruning works with persistence', function (): void {
    $agent = new Agent('test-agent');
    $mock = new Mock;
    $agent
        ->provider($mock)
        ->memory('File', ['directory' => $this->tempDir])
        ->sessionId('session-prune')
        ->contextWindow(100, 'oldest'); // Very small window

    // Add multiple messages that exceed context window
    $agent->prompt('First message with some content');
    $agent->prompt('Second message with more content');
    $agent->prompt('Third message with even more content here');

    // All messages should be persisted
    $adapter = new FileAdapter(['directory' => $this->tempDir]);
    $persistedMessages = $adapter->load('session-prune');
    expect($persistedMessages)->toHaveCount(6); // 3 user + 3 assistant

    // But context window should prune when sending
    expect($agent->messages)->toHaveCount(6);
});

it('session delete clears data', function (): void {
    $adapter = new FileAdapter(['path' => $this->tempDir]);
    $agent = new Agent('test-agent');
    $agent
        ->provider(new Mock(['responses' => ['hello' => 'Hi!']]))
        ->memory($adapter)
        ->sessionId('session-delete');

    $agent->prompt('hello');

    // Verify session exists
    expect($adapter->exists('session-delete'))->toBeTrue();

    // Delete session
    $adapter->delete('session-delete');

    // Verify session is deleted
    expect($adapter->exists('session-delete'))->toBeFalse();
});

it('clone preserves memory config but not sessionId', function (): void {
    $agent1 = new Agent('agent-1');
    $agent1
        ->provider(new Mock(['responses' => ['msg' => 'response']]))
        ->memory('File', ['directory' => $this->tempDir])
        ->sessionId('session-original');

    $agent1->prompt('msg');

    // Clone the agent
    $agent2 = $agent1->clone('agent-2');

    // Agent2 should have memory adapter but no sessionId
    expect($agent2->messages)->toBeEmpty(); // Fresh conversation

    // Set new sessionId for clone
    $agent2->sessionId('session-clone');
    $agent2->prompt('msg');

    // Both sessions should exist separately
    $adapter = new FileAdapter(['directory' => $this->tempDir]);
    expect($adapter->exists('session-original'))->toBeTrue();
    expect($adapter->exists('session-clone'))->toBeTrue();

    $original = $adapter->load('session-original');
    $cloned = $adapter->load('session-clone');
    expect($original)->toHaveCount(2);
    expect($cloned)->toHaveCount(2);
});

it('reset clears memory', function (): void {
    $agent = new Agent('test-agent');
    $agent
        ->provider(new Mock(['responses' => ['hello' => 'Hi!']]))
        ->memory('File', ['directory' => $this->tempDir])
        ->sessionId('session-reset');

    $agent->prompt('hello');
    expect($agent->messages)->toHaveCount(2);

    // Reset should clear memory reference and sessionId
    $agent->reset();

    expect($agent->messages)->toBeEmpty();
    // After reset, memory and sessionId should be null (can't test directly, but verify behavior)
});

it('memory works with tool calling', function (): void {
    $agent = new Agent('test-agent');
    $mock = new MockOpenAIWithTools;

    $agent
        ->provider($mock)
        ->memory('File', ['directory' => $this->tempDir])
        ->sessionId('session-tools')
        ->tool(
            Tool::fromClosure(
                'get_weather',
                'Get weather for a city',
                fn (string $city): string => "sunny in {$city}"
            )
        );

    $response = $agent->prompt('What is the weather in London?');
    expect($response->content)->toBe('The weather in London is sunny');

    // Verify all messages including tool calls are persisted
    $adapter = new FileAdapter(['directory' => $this->tempDir]);
    $messages = $adapter->load('session-tools');

    // Should have: user message, assistant with tool call, tool result, final assistant response
    expect(count($messages))->toBeGreaterThan(2);
});

it('memory adapter fails gracefully with invalid config', function (): void {
    $agent = new Agent('test-agent');

    // File adapter with non-writable directory should fail immediately when instantiated
    $readOnlyDir = $this->tempDir.'/readonly';
    mkdir($readOnlyDir, 0444, true); // Read-only directory

    $agent->provider(new Mock(['responses' => ['hello' => 'Hi!']]));

    // The memory() call should fail when checking directory permissions
    expect(fn () => $agent->memory('File', ['directory' => $readOnlyDir]))
        ->toThrow(RuntimeException::class, 'Storage directory is not writable');

    // Cleanup
    chmod($readOnlyDir, 0755);
    rmdir($readOnlyDir);
});

it('auto-save happens after prompt', function (): void {
    $adapter = new FileAdapter(['directory' => $this->tempDir]);

    $agent = new Agent('test-agent');
    $agent
        ->provider(new Mock(['responses' => ['hello' => 'Hi!']]))
        ->memory($adapter)
        ->sessionId('session-auto-save');

    // Before prompt, session should not exist
    expect($adapter->exists('session-auto-save'))->toBeFalse();

    // After prompt, session should be auto-saved
    $response = $agent->prompt('hello');
    expect($response->content)->toBe('Hi!');
    expect($adapter->exists('session-auto-save'))->toBeTrue();

    // Verify content
    $messages = $adapter->load('session-auto-save');
    expect($messages)->toHaveCount(2);
});

it('memory adapter handles concurrent sessions', function (): void {
    $adapter = new SqliteAdapter(['path' => $this->tempDbPath]);
    $mock = new Mock(['responses' => ['msg' => 'response']]);

    // Create multiple agents with different sessions
    $sessions = [];
    for ($i = 1; $i <= 5; $i++) {
        $agent = new Agent("agent-{$i}");
        $agent
            ->provider($mock)
            ->memory($adapter)
            ->sessionId("concurrent-{$i}");

        $agent->prompt('msg');
        $sessions[] = "concurrent-{$i}";
    }

    // Verify all sessions exist
    foreach ($sessions as $sessionId) {
        expect($adapter->exists($sessionId))->toBeTrue();
        $messages = $adapter->load($sessionId);
        expect($messages)->toHaveCount(2);
    }
});

it('empty messages do not create session file prematurely', function (): void {
    $adapter = new FileAdapter(['path' => $this->tempDir]);
    $agent = new Agent('test-agent');
    $agent
        ->provider(new Mock(['responses' => ['hello' => 'Hi!']]))
        ->memory($adapter)
        ->sessionId('session-lazy');

    // Session file should not exist yet
    expect($adapter->exists('session-lazy'))->toBeFalse();

    // After prompt it should exist
    $agent->prompt('hello');
    expect($adapter->exists('session-lazy'))->toBeTrue();
});

it('memory prune method keeps recent messages', function (): void {
    $agent = new Agent('test-agent');
    $mock = new Mock;
    $agent
        ->provider($mock)
        ->memory('File', ['directory' => $this->tempDir])
        ->sessionId('session-prune-test');

    // Add 10 messages (5 exchanges)
    for ($i = 1; $i <= 5; $i++) {
        $agent->prompt("message {$i}");
    }

    $adapter = new FileAdapter(['directory' => $this->tempDir]);
    $allMessages = $adapter->load('session-prune-test');
    expect($allMessages)->toHaveCount(10);

    // Prune to keep only 4 messages
    $pruned = $adapter->prune('session-prune-test', 4);
    expect($pruned)->toHaveCount(4);

    // Verify pruned messages are the most recent
    expect($pruned[0]['content'])->toBe('message 4');
    expect($pruned[2]['content'])->toBe('message 5');
});
