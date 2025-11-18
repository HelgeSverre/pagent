<?php

declare(strict_types=1);

use Pagent\Events\Events\Agent\AfterPromptEvent;
use Pagent\Events\Events\Agent\BeforePromptEvent;
use Pagent\Events\Events\Agent\ContextPrunedEvent;
use Pagent\Events\Events\Guard\GuardCheckingEvent;
use Pagent\Events\Events\Guard\GuardFallbackEvent;
use Pagent\Events\Events\Guard\GuardPassedEvent;
use Pagent\Events\Events\Guard\GuardViolatedEvent;
use Pagent\Events\Events\LLM\AfterLLMResponseEvent;
use Pagent\Events\Events\LLM\BeforeLLMRequestEvent;
use Pagent\Events\Events\Memory\MemoryLoadedEvent;
use Pagent\Events\Events\Memory\MemoryLoadingEvent;
use Pagent\Events\Events\Memory\MemorySavedEvent;
use Pagent\Events\Events\Memory\MemorySavingEvent;
use Pagent\Events\Events\Stream\StreamChunkEvent;
use Pagent\Events\Events\Stream\StreamCompletedEvent;
use Pagent\Events\Events\Stream\StreamStartedEvent;
use Pagent\Events\Events\Tool\ToolErrorEvent;
use Pagent\Events\Events\Tool\ToolExecutedEvent;
use Pagent\Events\Events\Tool\ToolExecutingEvent;

test('BeforePromptEvent generates correct event name', function () {
    $agent = agent('test_'.uniqid())->build();
    $event = new BeforePromptEvent($agent, 'hello', []);

    expect($event->getEventName())->toBe('before_prompt')
        ->and($event->agent)->toBe($agent)
        ->and($event->message)->toBe('hello')
        ->and($event->options)->toBe([])
        ->and($event->timestamp)->toBeFloat();
});

test('AfterPromptEvent has correct properties', function () {
    $agent = agent('test_'.uniqid())->build();
    $event = new AfterPromptEvent($agent, 'hello', 'response', ['key' => 'value']);

    expect($event->getEventName())->toBe('after_prompt')
        ->and($event->agent)->toBe($agent)
        ->and($event->message)->toBe('hello')
        ->and($event->response)->toBe('response')
        ->and($event->options)->toBe(['key' => 'value']);
});

test('ContextPrunedEvent has correct properties', function () {
    $agent = agent('test_'.uniqid())->build();
    $event = new ContextPrunedEvent($agent, 100, 50, 5000);

    expect($event->getEventName())->toBe('context_pruned')
        ->and($event->agent)->toBe($agent)
        ->and($event->messagesBefore)->toBe(100)
        ->and($event->messagesAfter)->toBe(50)
        ->and($event->tokensSaved)->toBe(5000);
});

test('BeforeLLMRequestEvent has correct properties', function () {
    $agent = agent('test_'.uniqid())->build();
    $event = new BeforeLLMRequestEvent($agent, 'anthropic', 'claude-3-5-sonnet-20241022', ['max_tokens' => 1000]);

    expect($event->getEventName())->toBe('before_llm_request')
        ->and($event->provider)->toBe('anthropic')
        ->and($event->model)->toBe('claude-3-5-sonnet-20241022')
        ->and($event->requestPayload)->toBe(['max_tokens' => 1000]);
});

test('AfterLLMResponseEvent has correct properties', function () {
    $agent = agent('test_'.uniqid())->build();
    $event = new AfterLLMResponseEvent($agent, 'openai', 'gpt-4', ['content' => 'test'], 250.5);

    expect($event->getEventName())->toBe('after_llm_response')
        ->and($event->provider)->toBe('openai')
        ->and($event->model)->toBe('gpt-4')
        ->and($event->durationMs)->toBe(250.5);
});

test('ToolExecutingEvent has correct properties', function () {
    $agent = agent('test_'.uniqid())->build();
    $event = new ToolExecutingEvent($agent, 'calculator', ['a' => 5, 'b' => 3]);

    expect($event->getEventName())->toBe('tool_executing')
        ->and($event->toolName)->toBe('calculator')
        ->and($event->parameters)->toBe(['a' => 5, 'b' => 3]);
});

test('ToolExecutedEvent has correct properties', function () {
    $agent = agent('test_'.uniqid())->build();
    $event = new ToolExecutedEvent($agent, 'calculator', ['a' => 5], 42, 12.5);

    expect($event->getEventName())->toBe('tool_executed')
        ->and($event->toolName)->toBe('calculator')
        ->and($event->result)->toBe(42)
        ->and($event->durationMs)->toBe(12.5);
});

test('ToolErrorEvent has correct properties', function () {
    $agent = agent('test_'.uniqid())->build();
    $error = new RuntimeException('Tool failed');
    $event = new ToolErrorEvent($agent, 'calculator', ['a' => 5], $error);

    expect($event->getEventName())->toBe('tool_error')
        ->and($event->toolName)->toBe('calculator')
        ->and($event->error)->toBe($error);
});

test('GuardCheckingEvent has correct properties', function () {
    $agent = agent('test_'.uniqid())->build();
    $event = new GuardCheckingEvent($agent, 'profanity', 'hello world');

    expect($event->getEventName())->toBe('guard_checking')
        ->and($event->guardName)->toBe('profanity')
        ->and($event->content)->toBe('hello world');
});

test('GuardPassedEvent has correct properties', function () {
    $agent = agent('test_'.uniqid())->build();
    $event = new GuardPassedEvent($agent, 'profanity', 'hello world');

    expect($event->getEventName())->toBe('guard_passed')
        ->and($event->guardName)->toBe('profanity')
        ->and($event->content)->toBe('hello world');
});

test('GuardViolatedEvent has correct properties', function () {
    $agent = agent('test_'.uniqid())->build();
    $event = new GuardViolatedEvent($agent, 'profanity', 'bad words', 'Contains profanity');

    expect($event->getEventName())->toBe('guard_violated')
        ->and($event->guardName)->toBe('profanity')
        ->and($event->content)->toBe('bad words')
        ->and($event->reason)->toBe('Contains profanity');
});

test('GuardFallbackEvent has correct properties', function () {
    $agent = agent('test_'.uniqid())->build();
    $event = new GuardFallbackEvent($agent, 'profanity', 2, 3);

    expect($event->getEventName())->toBe('guard_fallback')
        ->and($event->guardName)->toBe('profanity')
        ->and($event->attemptNumber)->toBe(2)
        ->and($event->maxAttempts)->toBe(3);
});

test('MemoryLoadingEvent has correct properties', function () {
    $agent = agent('test_'.uniqid())->build();
    $event = new MemoryLoadingEvent($agent, 'conversation', 'user_123');

    expect($event->getEventName())->toBe('memory_loading')
        ->and($event->key)->toBe('conversation')
        ->and($event->namespace)->toBe('user_123');
});

test('MemoryLoadedEvent has correct properties', function () {
    $agent = agent('test_'.uniqid())->build();
    $event = new MemoryLoadedEvent($agent, 'conversation', ['data' => 'value'], 'user_123');

    expect($event->getEventName())->toBe('memory_loaded')
        ->and($event->key)->toBe('conversation')
        ->and($event->value)->toBe(['data' => 'value'])
        ->and($event->namespace)->toBe('user_123');
});

test('MemorySavingEvent has correct properties', function () {
    $agent = agent('test_'.uniqid())->build();
    $event = new MemorySavingEvent($agent, 'conversation', ['data' => 'value'], 'user_123');

    expect($event->getEventName())->toBe('memory_saving')
        ->and($event->key)->toBe('conversation')
        ->and($event->value)->toBe(['data' => 'value'])
        ->and($event->namespace)->toBe('user_123');
});

test('MemorySavedEvent has correct properties', function () {
    $agent = agent('test_'.uniqid())->build();
    $event = new MemorySavedEvent($agent, 'conversation', ['data' => 'value'], null);

    expect($event->getEventName())->toBe('memory_saved')
        ->and($event->key)->toBe('conversation')
        ->and($event->value)->toBe(['data' => 'value'])
        ->and($event->namespace)->toBeNull();
});

test('StreamStartedEvent has correct properties', function () {
    $agent = agent('test_'.uniqid())->build();
    $event = new StreamStartedEvent($agent, 'anthropic', 'claude-3-5-sonnet-20241022');

    expect($event->getEventName())->toBe('stream_started')
        ->and($event->provider)->toBe('anthropic')
        ->and($event->model)->toBe('claude-3-5-sonnet-20241022');
});

test('StreamChunkEvent has correct properties', function () {
    $agent = agent('test_'.uniqid())->build();
    $event = new StreamChunkEvent($agent, 'Hello', 5);

    expect($event->getEventName())->toBe('stream_chunk')
        ->and($event->chunk)->toBe('Hello')
        ->and($event->chunkNumber)->toBe(5);
});

test('StreamCompletedEvent has correct properties', function () {
    $agent = agent('test_'.uniqid())->build();
    $event = new StreamCompletedEvent($agent, 'Hello world', 42, 1250.75);

    expect($event->getEventName())->toBe('stream_completed')
        ->and($event->fullContent)->toBe('Hello world')
        ->and($event->totalChunks)->toBe(42)
        ->and($event->durationMs)->toBe(1250.75);
});

test('all event classes extend Event base class', function () {
    $agent = agent('test_'.uniqid())->build();

    $events = [
        new BeforePromptEvent($agent, 'test', []),
        new AfterPromptEvent($agent, 'test', 'response', []),
        new ContextPrunedEvent($agent, 10, 5, 100),
        new BeforeLLMRequestEvent($agent, 'anthropic', 'claude', []),
        new AfterLLMResponseEvent($agent, 'anthropic', 'claude', [], 100.0),
        new ToolExecutingEvent($agent, 'tool', []),
        new ToolExecutedEvent($agent, 'tool', [], 'result', 50.0),
        new ToolErrorEvent($agent, 'tool', [], new RuntimeException),
        new GuardCheckingEvent($agent, 'guard', 'content'),
        new GuardPassedEvent($agent, 'guard', 'content'),
        new GuardViolatedEvent($agent, 'guard', 'content', 'reason'),
        new GuardFallbackEvent($agent, 'guard', 1, 3),
        new MemoryLoadingEvent($agent, 'key'),
        new MemoryLoadedEvent($agent, 'key', 'value'),
        new MemorySavingEvent($agent, 'key', 'value'),
        new MemorySavedEvent($agent, 'key', 'value'),
        new StreamStartedEvent($agent, 'provider', 'model'),
        new StreamChunkEvent($agent, 'chunk', 1),
        new StreamCompletedEvent($agent, 'content', 10, 500.0),
    ];

    foreach ($events as $event) {
        expect($event)->toBeInstanceOf(\Pagent\Events\Event::class);
    }
});
