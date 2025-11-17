# Chapter 18: Handoff Pattern

**Learning Objectives:**
- Understand when and why to use agent handoffs
- Implement seamless context transfer between agents
- Build multi-agent routing and escalation systems
- Handle handoff edge cases and error scenarios
- Design effective handoff strategies for production systems

**Prerequisites:** Chapter 16 (Multi-agent fundamentals)

---

## Introduction

The handoff pattern is one of the most natural and powerful orchestration patterns in multi-agent systems. Just like a support team transfers a customer from general support to a specialist, or a hospital transfers a patient from one department to another, agent handoffs enable seamless transitions between agents while preserving conversation context.

In this chapter, we'll explore Pagent's handoff implementation, learn practical patterns for routing and escalation, and discover how to build sophisticated multi-agent workflows that feel natural to users.

## Understanding the Handoff Pattern

### What Is a Handoff?

A **handoff** is a one-way transfer of control from one agent to another, including:

1. **Context transfer** - The entire conversation history moves to the new agent
2. **Reason annotation** - Why the handoff occurred
3. **Clean continuation** - The new agent is ready to continue the conversation

Think of it as a warm introduction:

```php
$supportAgent->prompt('I need help with your API documentation');
// General support realizes this needs technical expertise

$techAgent = $supportAgent->handoff(
    'technical-expert',
    'Customer needs API documentation help'
);

// Tech expert now has full context and reason for handoff
$techAgent->prompt('Which endpoint are you having trouble with?');
```

The `technical-expert` agent receives:
- All messages from the `supportAgent` conversation
- The reason: "Customer needs API documentation help"
- A clean slate to continue helping the customer

### When to Use Handoffs

Handoffs excel in scenarios requiring:

**1. Specialization**

Different agents have different expertise:

```php
agent('general-support')
    ->system('You are a friendly customer support agent.');

agent('legal-expert')
    ->system('You are a legal expert specializing in contracts and compliance.');

agent('technical-support')
    ->system('You are a senior developer who helps with technical issues.');
```

When a question requires specialized knowledge, hand off to the expert.

**2. Escalation**

Progressive escalation paths:

```php
// Tier 1 → Tier 2 → Manager
$tier1 = agent('tier1-support');
$tier1->prompt('This issue is really frustrating!');

if ($needsEscalation) {
    $tier2 = $tier1->handoff('tier2-support', 'Customer frustrated, needs senior help');
}
```

**3. Language or Context Switching**

Different agents for different contexts:

```php
// English → Spanish
$englishAgent->prompt('Quiero hablar en español');
$spanishAgent = $englishAgent->handoff('spanish-agent', 'Customer prefers Spanish');

// Casual → Formal
$casualAgent->prompt('This is a legal matter');
$formalAgent = $casualAgent->handoff('legal-agent', 'Requires formal legal language');
```

**4. Workflow Stages**

Multi-stage processes:

```php
// Intake → Triage → Treatment
$intakeAgent->prompt('I have a headache and fever');
$triageAgent = $intakeAgent->handoff('triage-agent', 'Symptoms logged');
```

## The Handoff API

### Basic Handoff

The simplest handoff transfers to a named agent:

```php
$sourceAgent = agent('source');
$sourceAgent->prompt('Hello world');

$targetAgent = $sourceAgent->handoff('target');
```

**What happens:**
1. `sourceAgent` packages its entire conversation history
2. Resolves the `target` agent from the registry
3. Adds context message to `target`'s messages array
4. Returns the `target` agent ready for use

### Handoff with Reason

Provide context about why the handoff occurred:

```php
$support = agent('support');
$support->prompt('I need a refund for order #12345');

$billing = $support->handoff(
    'billing-specialist',
    'Customer requesting refund for order #12345'
);

// Billing agent receives:
// "Previous conversation with support:
//
// [user]: I need a refund for order #12345
// [assistant]: [response]
//
// Handoff reason: Customer requesting refund for order #12345"
```

The reason helps the new agent understand:
- **Context** - What triggered the handoff
- **Priority** - How urgent or important the matter is
- **Expectations** - What the user needs

### Agent Resolution

Handoffs support both string names and `Agent` instances:

```php
// By name (uses Registry)
$target = $source->handoff('expert');

// By instance
$expertAgent = agent('expert');
$target = $source->handoff($expertAgent);
```

String-based handoffs are more common because they integrate with the global registry:

```php
// Define agents upfront
agent('tier1')->provider('anthropic')->system('General support');
agent('tier2')->provider('anthropic')->system('Senior support');
agent('manager')->provider('anthropic')->system('Management escalation');

// Later, hand off by name
$tier1 = agent('tier1');
$tier2 = $tier1->handoff('tier2');
$manager = $tier2->handoff('manager');
```

## Context Transfer

### How Context Transfers

The `Handoff` class builds a context message containing:

```php
// From src/Orchestration/Handoff.php:54-64
$contextMessage = "Previous conversation with {$fromAgent->getName()}:\n\n";

foreach ($this->fromAgent->messages as $message) {
    $role = $message['role'];
    $content = is_string($message['content'])
        ? $message['content']
        : json_encode($message['content']);
    $contextMessage .= "[{$role}]: {$content}\n";
}

if ($this->reason) {
    $contextMessage .= "\nHandoff reason: {$this->reason}\n";
}
```

This message is added to the target agent's message history:

```php
$this->toAgent->messages[] = [
    'role' => 'user',
    'content' => $contextMessage,
];
```

### What Gets Transferred

**Included in handoff:**
- All user messages
- All assistant responses
- Tool call results (formatted as JSON)
- Handoff reason (if provided)

**Not included:**
- Source agent's system prompt (target has its own)
- Source agent's configuration (temperature, model, etc.)
- Registered tools (target defines its own)
- Guards and middleware (target has its own)

### Example Context Transfer

```php
$support = agent('support')
    ->provider(mock(['*' => 'I can help with that']))
    ->system('You are general support');

$support->prompt('Hello');
$support->prompt('I need technical help');

$tech = agent('tech')
    ->provider(mock(['*' => 'Technical response']))
    ->system('You are a technical expert');

$techAgent = $support->handoff('tech', 'Technical issue');

// Inspect what tech agent received
var_dump($techAgent->messages);

// Output:
// [
//     [
//         'role' => 'user',
//         'content' => 'Previous conversation with support:
//
//         [user]: Hello
//         [assistant]: I can help with that
//         [user]: I need technical help
//         [assistant]: I can help with that
//
//         Handoff reason: Technical issue'
//     ]
// ]
```

The technical agent sees the full conversation and can respond with context.

## Practical Handoff Patterns

### Pattern 1: Customer Service Escalation

Classic support tier system:

```php
agent('tier1-support')
    ->provider('anthropic')
    ->model('claude-sonnet-4-20250514')
    ->system('You are a friendly tier 1 support agent.
        Handle basic questions. If the issue is complex,
        say you need to escalate to senior support.');

agent('tier2-support')
    ->provider('anthropic')
    ->model('claude-sonnet-4-20250514')
    ->system('You are a senior support specialist.
        Handle complex technical issues and billing problems.');

agent('manager')
    ->provider('anthropic')
    ->model('claude-sonnet-4-20250514')
    ->system('You are a support manager.
        Handle escalations, complaints, and special requests.');

// Start with tier 1
$tier1 = agent('tier1-support');
$response = $tier1->prompt('My account was charged twice for the same order');

// Tier 1 recognizes this needs escalation
if (str_contains(strtolower($response->content), 'escalate') ||
    str_contains(strtolower($response->content), 'senior')) {

    $tier2 = $tier1->handoff('tier2-support', 'Billing issue - duplicate charge');
    $response = $tier2->prompt('Can you investigate this?');
}

// If still not resolved, escalate to manager
if (/* customer still unhappy */) {
    $manager = $tier2->handoff('manager', 'Unresolved billing complaint');
}
```

### Pattern 2: Specialty Routing

Route to specialists based on topic:

```php
agent('router')
    ->provider('anthropic')
    ->system('You are a routing agent. Classify questions as:
        - TECHNICAL: Code, bugs, API issues
        - BILLING: Payments, invoices, refunds
        - LEGAL: Contracts, privacy, compliance
        - GENERAL: Everything else')
    ->tool('route_to_specialist', 'Route to specialist agent',
        function (string $category, string $reason) {
            $specialists = [
                'TECHNICAL' => 'technical-expert',
                'BILLING' => 'billing-specialist',
                'LEGAL' => 'legal-expert',
                'GENERAL' => 'general-support',
            ];

            return $specialists[$category] ?? 'general-support';
        });

// Define specialists
agent('technical-expert')->provider('anthropic')
    ->system('You are a senior developer. Help with technical issues.');

agent('billing-specialist')->provider('anthropic')
    ->system('You are a billing expert. Help with payments and invoices.');

agent('legal-expert')->provider('anthropic')
    ->system('You are a legal advisor. Provide compliance guidance.');

// Route incoming questions
$router = agent('router');
$question = 'Can you help me understand your data retention policy?';

$classification = $router->prompt("Classify this question: {$question}");

// Extract category and route (in production, use structured output)
if (str_contains($classification->content, 'LEGAL')) {
    $specialist = $router->handoff('legal-expert', 'Privacy policy question');
    $answer = $specialist->prompt($question);
}
```

### Pattern 3: Progressive Refinement

Multiple agents refine output progressively:

```php
agent('drafter')
    ->provider('anthropic')
    ->system('You are a content drafter. Create rough drafts quickly.');

agent('editor')
    ->provider('anthropic')
    ->system('You are an editor. Improve clarity, grammar, and structure.');

agent('reviewer')
    ->provider('anthropic')
    ->system('You are a senior reviewer. Ensure accuracy and polish.');

$drafter = agent('drafter');
$draft = $drafter->prompt('Write a product announcement for our new API');

// Hand off to editor
$editor = $drafter->handoff('editor', 'Draft complete, needs editing');
$edited = $editor->prompt('Please improve this draft');

// Final review
$reviewer = $editor->handoff('reviewer', 'Edited version ready for review');
$final = $reviewer->prompt('Final review and approval');

echo "Final version:\n{$final->content}";
```

Each agent sees the previous work and can build on it.

### Pattern 4: Multi-Language Support

Language-specific agents:

```php
agent('language-detector')
    ->provider('anthropic')
    ->system('Detect the language of user messages.
        Respond with just the language code: en, es, fr, de, etc.');

agent('english-agent')->provider('anthropic')
    ->system('You are a helpful assistant. Respond in English.');

agent('spanish-agent')->provider('anthropic')
    ->system('Eres un asistente útil. Responde en español.');

agent('french-agent')->provider('anthropic')
    ->system('Vous êtes un assistant utile. Répondez en français.');

// Detect language and hand off
$detector = agent('language-detector');
$message = 'Bonjour, comment puis-je vous aider?';
$language = $detector->prompt($message);

$languageMap = [
    'en' => 'english-agent',
    'es' => 'spanish-agent',
    'fr' => 'french-agent',
];

$langCode = trim(strtolower($language->content));
$agentName = $languageMap[$langCode] ?? 'english-agent';

$specialist = $detector->handoff($agentName, "User speaks {$langCode}");
$response = $specialist->prompt($message);
```

## Error Handling and Edge Cases

### Agent Not Found

Handoff throws a `RuntimeException` if the target agent doesn't exist:

```php
try {
    $target = $source->handoff('nonexistent-agent');
} catch (RuntimeException $e) {
    echo $e->getMessage();
    // "Target agent 'nonexistent-agent' not found for handoff"
}
```

**Best practice:** Always ensure target agents are registered before handoff:

```php
use function Pagent\agents;

$availableAgents = array_keys(agents());

if (in_array('specialist', $availableAgents)) {
    $target = $source->handoff('specialist');
} else {
    // Fallback: continue with current agent
    echo "Specialist unavailable, continuing with general support\n";
}
```

### Empty Conversation History

Handoff works even if the source agent has no messages:

```php
$empty = agent('empty')->provider(mock());
$target = $empty->handoff('target', 'Empty handoff test');

// Target receives:
// "Previous conversation with empty:
//
// Handoff reason: Empty handoff test"
```

This is useful for creating agent workflows where the first agent is just a router.

### Circular Handoffs

Pagent doesn't prevent circular handoffs. You must handle this in your application logic:

```php
// ❌ Infinite loop danger
$agent1->handoff('agent2');
$agent2->handoff('agent1');  // Creates circular reference

// ✅ Track handoff chain
function handoffWithTracking(Agent $from, string $to, array &$chain = []): Agent
{
    if (in_array($to, $chain)) {
        throw new RuntimeException("Circular handoff detected: " . implode(' -> ', $chain) . " -> {$to}");
    }

    $chain[] = $from->getName();
    return $from->handoff($to);
}
```

### Memory and Session Handling

If the source agent has memory enabled, remember that handoff doesn't affect memory:

```php
$source = agent('source')
    ->provider('anthropic')
    ->memory('sqlite', ['database' => 'support.db'])
    ->sessionId('session-123');

$source->prompt('Hello');  // Saved to memory

$target = $source->handoff('target');
// Target does NOT have source's memory
// Target conversation is independent
```

**If you need shared memory:**

```php
$sessionId = 'shared-session-123';

$source = agent('source')
    ->provider('anthropic')
    ->memory('sqlite', ['database' => 'shared.db'])
    ->sessionId($sessionId);

$target = agent('target')
    ->provider('anthropic')
    ->memory('sqlite', ['database' => 'shared.db'])
    ->sessionId($sessionId);  // Same session ID

// Both agents can access the same conversation history
```

## Advanced Handoff Strategies

### LLM-Driven Routing

Let the LLM decide when and where to hand off:

```php
agent('intelligent-router')
    ->provider('anthropic')
    ->system('You are a routing assistant. Based on user questions,
        determine which specialist to route to.')
    ->tool('handoff_to_specialist', 'Hand off to specialist agent',
        function (string $specialistType, string $reason) {
            $router = agent('intelligent-router');

            $specialists = [
                'technical' => 'tech-support',
                'billing' => 'billing-specialist',
                'legal' => 'legal-expert',
            ];

            $agentName = $specialists[$specialistType] ?? 'general-support';
            return $router->handoff($agentName, $reason);
        });

$router = agent('intelligent-router');
$question = 'I need help understanding your API rate limits';

// LLM decides to call handoff_to_specialist('technical', 'API question')
$response = $router->prompt($question);
```

The tool returns the new agent, allowing the LLM to self-route.

### Conditional Handoff with Guards

Only hand off if certain conditions are met:

```php
function conditionalHandoff(
    Agent $source,
    string $target,
    callable $condition,
    string $reason
): Agent {
    if ($condition($source)) {
        return $source->handoff($target, $reason);
    }

    return $source;  // No handoff, continue with source
}

// Usage
$support = agent('support');
$support->prompt('I am very frustrated with your service!');

$escalated = conditionalHandoff(
    $support,
    'manager',
    function (Agent $agent) {
        $lastMessage = end($agent->messages)['content'] ?? '';
        return str_contains(strtolower($lastMessage), 'frustrated') ||
               str_contains(strtolower($lastMessage), 'angry');
    },
    'Customer expressing frustration'
);
```

### Handoff Tracking

Track handoff chains for analytics:

```php
class HandoffTracker
{
    private array $handoffs = [];

    public function track(Agent $from, string $to, string $reason): Agent
    {
        $this->handoffs[] = [
            'from' => $from->getName(),
            'to' => $to,
            'reason' => $reason,
            'timestamp' => time(),
        ];

        return $from->handoff($to, $reason);
    }

    public function getChain(): array
    {
        return $this->handoffs;
    }

    public function summary(): string
    {
        $chain = array_map(fn($h) => $h['from'], $this->handoffs);
        $chain[] = end($this->handoffs)['to'];

        return implode(' → ', $chain);
    }
}

// Usage
$tracker = new HandoffTracker();

$tier1 = agent('tier1');
$tier1->prompt('Help!');

$tier2 = $tracker->track($tier1, 'tier2', 'Needs senior help');
$tier2->prompt('Still need help');

$manager = $tracker->track($tier2, 'manager', 'Escalation required');

echo $tracker->summary();
// Output: tier1 → tier2 → manager
```

## Real-World Example: Support System

Let's build a complete support system with intelligent routing and escalation:

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use function Pagent\agent;

// Define support tiers
agent('tier1-support')
    ->provider('anthropic')
    ->model('claude-sonnet-4-20250514')
    ->system('You are a friendly tier 1 support agent.
        Handle basic questions about password resets, login issues,
        and general product questions. If you encounter billing,
        technical, or legal questions, say you need to transfer.');

agent('tier2-support')
    ->provider('anthropic')
    ->model('claude-sonnet-4-20250514')
    ->system('You are a senior support agent.
        Handle complex issues including billing problems,
        account issues, and technical troubleshooting.');

agent('technical-support')
    ->provider('anthropic')
    ->model('claude-sonnet-4-20250514')
    ->system('You are a senior developer providing technical support.
        Help with API issues, integration problems, and bugs.');

agent('billing-specialist')
    ->provider('anthropic')
    ->model('claude-sonnet-4-20250514')
    ->system('You are a billing specialist.
        Handle payment issues, refunds, and invoice questions.');

// Support session
class SupportSession
{
    private Agent $currentAgent;
    private array $handoffChain = [];

    public function __construct()
    {
        $this->currentAgent = agent('tier1-support');
        $this->handoffChain[] = 'tier1-support';
    }

    public function chat(string $message): string
    {
        $response = $this->currentAgent->prompt($message);

        // Check if agent wants to transfer
        $needsEscalation = $this->detectEscalation($response->content);

        if ($needsEscalation) {
            $this->currentAgent = $this->escalate($needsEscalation);
            $response = $this->currentAgent->prompt('How can I help?');
        }

        return $response->content;
    }

    private function detectEscalation(string $response): ?string
    {
        $patterns = [
            'technical-support' => ['technical', 'api', 'code', 'bug'],
            'billing-specialist' => ['billing', 'payment', 'invoice', 'refund'],
            'tier2-support' => ['senior', 'escalate', 'manager'],
        ];

        foreach ($patterns as $agent => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains(strtolower($response), $keyword)) {
                    return $agent;
                }
            }
        }

        return null;
    }

    private function escalate(string $targetAgent): Agent
    {
        $reason = "Escalating from {$this->currentAgent->getName()}";
        $this->handoffChain[] = $targetAgent;

        echo "\n[System] Transferring to {$targetAgent}...\n\n";

        return $this->currentAgent->handoff($targetAgent, $reason);
    }

    public function getJourney(): string
    {
        return implode(' → ', $this->handoffChain);
    }
}

// Simulate support conversation
$session = new SupportSession();

echo "Customer: I need help resetting my password\n";
echo "Support: " . $session->chat('I need help resetting my password') . "\n\n";

echo "Customer: Actually, I was charged twice for my subscription\n";
echo "Support: " . $session->chat('Actually, I was charged twice for my subscription') . "\n\n";

echo "Customer: Can you process a refund?\n";
echo "Support: " . $session->chat('Can you process a refund?') . "\n\n";

echo "[Journey] " . $session->getJourney() . "\n";
```

This example demonstrates:
- **Automatic escalation** based on conversation content
- **Handoff tracking** for analytics
- **Seamless context transfer** between agents
- **Production-ready patterns** for support systems

## Best Practices

### 1. Provide Clear Handoff Reasons

Always explain why the handoff occurred:

```php
// ❌ No context
$target = $source->handoff('expert');

// ✅ Clear context
$target = $source->handoff('expert', 'Customer needs API integration help');
```

### 2. Design Single-Responsibility Agents

Each agent should have a clear, focused role:

```php
// ❌ One agent does everything
agent('support')->system('Handle all support, billing, legal, and technical questions');

// ✅ Specialized agents
agent('general-support')->system('Handle basic questions, route to specialists');
agent('billing')->system('Handle billing and payment questions');
agent('technical')->system('Handle technical and API questions');
```

### 3. Keep System Prompts Consistent

Agents receiving handoffs should understand the format:

```php
$systemPrompt = 'You are a {role}.
    When you receive a handoff, you will see the previous conversation
    and the reason for the handoff. Use this context to help the customer.';

agent('tier2')->system(str_replace('{role}', 'senior support agent', $systemPrompt));
agent('manager')->system(str_replace('{role}', 'support manager', $systemPrompt));
```

### 4. Validate Target Agents

Ensure target agents exist before handoff:

```php
use function Pagent\agents;

function safeHandoff(Agent $source, string $target, string $reason): Agent
{
    if (!isset(agents()[$target])) {
        throw new RuntimeException("Cannot hand off to '{$target}': agent not registered");
    }

    return $source->handoff($target, $reason);
}
```

### 5. Document Handoff Flows

Maintain clear documentation of your handoff routing:

```php
/**
 * Support Agent Handoff Flow:
 *
 * tier1-support → tier2-support (complex issues)
 *              → technical-support (API/code issues)
 *              → billing-specialist (payment issues)
 *
 * tier2-support → manager (escalations)
 *              → legal-expert (legal questions)
 */
```

## What We Learned

In this chapter, you learned:

- **Handoffs transfer control** from one agent to another with full context
- **Context messages** include conversation history and handoff reason
- **Agent resolution** works with both names and instances
- **Practical patterns** include escalation, routing, and refinement
- **Error handling** prevents common pitfalls like missing agents
- **Advanced strategies** enable LLM-driven routing and tracking

The handoff pattern is essential for building natural multi-agent systems. By specializing agents and using handoffs to route conversations, you create systems that feel intelligent and responsive to user needs.

## Next Steps

Now that you understand handoffs, you're ready for:

- **Chapter 19:** Delegation Pattern - Distributing work across multiple agents
- **Chapter 20:** Pipeline Orchestration - Sequential agent processing
- **Chapter 21:** Advanced Multi-Agent Patterns - Complex workflows and coordination

Handoffs are just the beginning. Combined with delegation and pipelines, you can build sophisticated multi-agent applications that handle complex workflows with ease.
