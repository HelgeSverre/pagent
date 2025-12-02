# Chapter 18: The Handoff Pattern

## What You'll Learn

In this chapter, you'll master the art of agent handoffs—transferring conversations between specialized agents based on context, expertise, or escalation needs. By the end, you'll be able to:

- Implement sophisticated handoff logic between agents
- Define clear conditions for when handoffs should occur
- Transfer context seamlessly between agents
- Handle handoff failures gracefully
- Track and analyze handoff metrics

## Prerequisites

- Completed Chapter 16 (Observation Patterns)
- Understanding of agent chaining and composition
- Familiarity with context management
- Basic knowledge of state machines

## Time Estimate

45-60 minutes of hands-on practice

## The Handoff Pattern Explained

Agent handoffs represent a critical pattern in building sophisticated AI systems. Just as human support teams escalate issues to specialists or transfer customers to appropriate departments, AI agents need mechanisms to recognize their limitations and transfer control to more suitable agents.

Think of handoffs like a medical triage system: a general practitioner evaluates symptoms and, when specialized knowledge is needed, refers the patient to a cardiologist, neurologist, or other specialist. Each specialist receives the patient's history and context, ensuring continuity of care.

## Building Your First Handoff System

Let's start with a customer service system that escalates complex issues to specialized agents:

```php
use Pagent\Pagent;
use Pagent\Contracts\Provider;

class HandoffManager
{
    private array $agents = [];
    private array $handoffHistory = [];
    private ?string $currentAgentId = null;

    public function registerAgent(string $id, Provider $agent, array $capabilities): void
    {
        $this->agents[$id] = [
            'provider' => $agent,
            'capabilities' => $capabilities,
            'active' => false,
        ];
    }

    public function shouldHandoff(string $content, array $context): ?string
    {
        // Analyze content for handoff triggers
        $triggers = $this->analyzeHandoffTriggers($content, $context);

        if ($triggers === null) {
            return null;
        }

        // Find the best agent for the triggers
        return $this->findBestAgent($triggers, $context);
    }

    private function analyzeHandoffTriggers(string $content, array $context): ?array
    {
        $triggers = [];

        // Check for explicit escalation requests
        if (preg_match('/escalate|speak to specialist|need expert/i', $content)) {
            $triggers['escalation'] = true;
        }

        // Check for technical complexity
        if ($this->detectTechnicalComplexity($content) > 0.7) {
            $triggers['technical'] = true;
        }

        // Check for sentiment issues
        if (($context['sentiment'] ?? 0) < -0.5) {
            $triggers['sentiment'] = true;
        }

        // Check for language requirements
        $detectedLanguage = $this->detectLanguage($content);
        if ($detectedLanguage !== 'en') {
            $triggers['language'] = $detectedLanguage;
        }

        return empty($triggers) ? null : $triggers;
    }

    private function findBestAgent(array $triggers, array $context): ?string
    {
        $scores = [];

        foreach ($this->agents as $id => $agent) {
            if ($id === $this->currentAgentId) {
                continue; // Don't handoff to self
            }

            $score = $this->calculateAgentScore($agent['capabilities'], $triggers, $context);
            if ($score > 0) {
                $scores[$id] = $score;
            }
        }

        if (empty($scores)) {
            return null;
        }

        // Return agent with highest score
        arsort($scores);
        return array_key_first($scores);
    }

    private function calculateAgentScore(array $capabilities, array $triggers, array $context): float
    {
        $score = 0.0;

        // Match capabilities to triggers
        foreach ($triggers as $trigger => $value) {
            if (isset($capabilities[$trigger])) {
                $score += $capabilities[$trigger] * 1.5;
            }
        }

        // Consider agent load (if tracked)
        if (isset($capabilities['current_load'])) {
            $score *= (1 - $capabilities['current_load']);
        }

        // Consider past performance
        if (isset($context['required_expertise'])) {
            foreach ($context['required_expertise'] as $expertise) {
                if (in_array($expertise, $capabilities['expertise'] ?? [])) {
                    $score += 0.5;
                }
            }
        }

        return $score;
    }
}
```

## Implementing Context Transfer

Context transfer ensures continuity when handing off between agents. Here's a robust context serialization system:

```php
class ContextTransfer
{
    private array $transferableFields = [
        'conversation_history',
        'user_preferences',
        'current_task',
        'metadata',
        'sentiment_analysis',
    ];

    public function prepareHandoffContext(array $currentContext, string $targetAgentId): array
    {
        $handoffContext = [
            'handoff_timestamp' => time(),
            'source_agent' => $currentContext['agent_id'] ?? 'unknown',
            'target_agent' => $targetAgentId,
            'handoff_reason' => $this->determineHandoffReason($currentContext),
        ];

        // Transfer core conversation data
        foreach ($this->transferableFields as $field) {
            if (isset($currentContext[$field])) {
                $handoffContext[$field] = $this->sanitizeField($field, $currentContext[$field]);
            }
        }

        // Add summary of conversation so far
        $handoffContext['conversation_summary'] = $this->summarizeConversation($currentContext);

        // Include any pending actions
        $handoffContext['pending_actions'] = $this->extractPendingActions($currentContext);

        return $handoffContext;
    }

    private function summarizeConversation(array $context): string
    {
        if (empty($context['conversation_history'])) {
            return 'New conversation';
        }

        // Create a concise summary of key points
        $messages = $context['conversation_history'];
        $summary = "Previous discussion points:\n";

        // Extract key topics
        $topics = $this->extractKeyTopics($messages);
        foreach ($topics as $topic) {
            $summary .= "- {$topic}\n";
        }

        // Note any unresolved issues
        if (isset($context['unresolved_issues'])) {
            $summary .= "\nUnresolved issues:\n";
            foreach ($context['unresolved_issues'] as $issue) {
                $summary .= "- {$issue}\n";
            }
        }

        return $summary;
    }

    private function sanitizeField(string $field, mixed $value): mixed
    {
        return match($field) {
            'conversation_history' => $this->sanitizeConversationHistory($value),
            'user_preferences' => $this->sanitizeUserPreferences($value),
            'metadata' => $this->sanitizeMetadata($value),
            default => $value,
        };
    }

    private function sanitizeConversationHistory(array $history): array
    {
        // Keep only recent relevant messages
        $relevantHistory = [];
        $messageCount = count($history);
        $keepCount = min(10, $messageCount); // Keep last 10 messages max

        for ($i = $messageCount - $keepCount; $i < $messageCount; $i++) {
            if (isset($history[$i])) {
                // Remove any sensitive data
                $message = $history[$i];
                if (isset($message['sensitive_data'])) {
                    unset($message['sensitive_data']);
                }
                $relevantHistory[] = $message;
            }
        }

        return $relevantHistory;
    }
}
```

## Customer Service Escalation Example

Let's build a complete customer service system with escalation capabilities:

```php
use Pagent\Pagent;

class CustomerServiceSystem
{
    private HandoffManager $handoffManager;
    private ContextTransfer $contextTransfer;
    private array $activeConversations = [];

    public function __construct()
    {
        $this->handoffManager = new HandoffManager();
        $this->contextTransfer = new ContextTransfer();

        $this->initializeAgents();
    }

    private function initializeAgents(): void
    {
        // Level 1: General support agent
        $this->handoffManager->registerAgent('general_support',
            anthropic()->claude3Sonnet(),
            [
                'level' => 1,
                'expertise' => ['general', 'faq', 'basic_troubleshooting'],
                'escalation' => 0.3,
                'languages' => ['en'],
            ]
        );

        // Level 2: Technical specialist
        $this->handoffManager->registerAgent('technical_specialist',
            openai()->gpt4(),
            [
                'level' => 2,
                'expertise' => ['technical', 'debugging', 'integration'],
                'escalation' => 0.7,
                'technical' => 0.9,
                'languages' => ['en'],
            ]
        );

        // Level 3: Senior expert
        $this->handoffManager->registerAgent('senior_expert',
            anthropic()->claude3Opus(),
            [
                'level' => 3,
                'expertise' => ['complex_issues', 'architecture', 'custom_solutions'],
                'escalation' => 1.0,
                'technical' => 1.0,
                'sentiment' => 0.8, // Good at handling frustrated customers
                'languages' => ['en'],
            ]
        );
    }

    public function handleCustomerQuery(string $customerId, string $query): string
    {
        $conversation = $this->getOrCreateConversation($customerId);

        // Check if handoff is needed
        $targetAgent = $this->handoffManager->shouldHandoff($query, $conversation['context']);

        if ($targetAgent !== null) {
            return $this->executeHandoff($conversation, $targetAgent, $query);
        }

        // Process with current agent
        return $this->processWithCurrentAgent($conversation, $query);
    }

    private function executeHandoff(array &$conversation, string $targetAgent, string $query): string
    {
        $sourceAgent = $conversation['current_agent'];

        // Prepare context for transfer
        $handoffContext = $this->contextTransfer->prepareHandoffContext(
            $conversation['context'],
            $targetAgent
        );

        // Log the handoff
        $this->logHandoff($sourceAgent, $targetAgent, $handoffContext['handoff_reason']);

        // Create handoff message for the new agent
        $handoffPrompt = $this->createHandoffPrompt($handoffContext, $query);

        // Get response from new agent
        $agent = $this->handoffManager->getAgent($targetAgent);
        $response = $agent->ask($handoffPrompt)
            ->withContext($handoffContext)
            ->get();

        // Update conversation state
        $conversation['current_agent'] = $targetAgent;
        $conversation['handoff_count'] = ($conversation['handoff_count'] ?? 0) + 1;
        $conversation['context'] = array_merge($conversation['context'], $handoffContext);

        // Wrap response with handoff notification
        return $this->wrapHandoffResponse($response, $targetAgent);
    }

    private function createHandoffPrompt(array $context, string $query): string
    {
        $prompt = "You are taking over this conversation from another agent.\n\n";

        $prompt .= "**Conversation Summary:**\n";
        $prompt .= $context['conversation_summary'] . "\n\n";

        $prompt .= "**Handoff Reason:** {$context['handoff_reason']}\n\n";

        if (!empty($context['pending_actions'])) {
            $prompt .= "**Pending Actions:**\n";
            foreach ($context['pending_actions'] as $action) {
                $prompt .= "- {$action}\n";
            }
            $prompt .= "\n";
        }

        $prompt .= "**Current Customer Query:**\n{$query}\n\n";
        $prompt .= "Please provide expert assistance while acknowledging the handoff naturally.";

        return $prompt;
    }

    private function wrapHandoffResponse(string $response, string $agentType): string
    {
        $prefix = match($agentType) {
            'technical_specialist' => "I'm a technical specialist who can help with your issue. ",
            'senior_expert' => "I'm a senior expert, and I'll personally assist you with this. ",
            default => "",
        };

        return $prefix . $response;
    }
}
```

## Multi-Language Support Bot

Here's how to implement language-based handoffs:

```php
class MultiLanguageBot
{
    private array $languageAgents = [];
    private LanguageDetector $detector;

    public function __construct()
    {
        $this->detector = new LanguageDetector();
        $this->initializeLanguageAgents();
    }

    private function initializeLanguageAgents(): void
    {
        $languages = [
            'en' => ['model' => 'claude-3-sonnet', 'prompt_style' => 'direct'],
            'es' => ['model' => 'claude-3-sonnet', 'prompt_style' => 'formal'],
            'fr' => ['model' => 'claude-3-sonnet', 'prompt_style' => 'polite'],
            'de' => ['model' => 'claude-3-sonnet', 'prompt_style' => 'precise'],
            'zh' => ['model' => 'claude-3-opus', 'prompt_style' => 'respectful'],
        ];

        foreach ($languages as $lang => $config) {
            $this->languageAgents[$lang] = $this->createLanguageAgent($lang, $config);
        }
    }

    public function respond(string $message, array $context = []): string
    {
        $detectedLanguage = $this->detector->detect($message);
        $currentLanguage = $context['language'] ?? 'en';

        // Check if language switch is needed
        if ($detectedLanguage !== $currentLanguage) {
            return $this->handleLanguageHandoff($message, $currentLanguage, $detectedLanguage, $context);
        }

        return $this->languageAgents[$currentLanguage]->respond($message, $context);
    }

    private function handleLanguageHandoff(
        string $message,
        string $fromLang,
        string $toLang,
        array $context
    ): string {
        // Translate context if needed
        if (!empty($context['conversation_history'])) {
            $context['original_language_history'] = $context['conversation_history'];
            $context['conversation_history'] = $this->translateHistory(
                $context['conversation_history'],
                $fromLang,
                $toLang
            );
        }

        // Add language switch notification
        $context['language_switched'] = true;
        $context['previous_language'] = $fromLang;
        $context['language'] = $toLang;

        // Get response in new language
        $response = $this->languageAgents[$toLang]->respond($message, $context);

        // Log language handoff
        $this->logLanguageHandoff($fromLang, $toLang, $message);

        return $response;
    }
}
```

## Handling Handoff Failures

Robust handoff systems must handle failures gracefully:

```php
class ResilientHandoffManager extends HandoffManager
{
    private array $fallbackChain = [];
    private int $maxRetries = 3;

    public function executeHandoffWithFallback(
        string $targetAgent,
        array $context,
        string $query
    ): array {
        $attempts = 0;
        $errors = [];

        while ($attempts < $this->maxRetries) {
            try {
                $result = $this->attemptHandoff($targetAgent, $context, $query);

                if ($result['success']) {
                    return $result;
                }

                // Try fallback agent
                $targetAgent = $this->getFallbackAgent($targetAgent, $errors);
                if ($targetAgent === null) {
                    break;
                }

            } catch (\Exception $e) {
                $errors[] = [
                    'agent' => $targetAgent,
                    'error' => $e->getMessage(),
                    'attempt' => $attempts + 1,
                ];

                // Log the failure
                $this->logHandoffFailure($targetAgent, $e, $context);
            }

            $attempts++;
        }

        // All handoffs failed, return to original agent with context
        return $this->handleCompleteHandoffFailure($context, $query, $errors);
    }

    private function attemptHandoff(string $agent, array $context, string $query): array
    {
        $agentInstance = $this->agents[$agent]['provider'] ?? null;

        if ($agentInstance === null) {
            throw new \RuntimeException("Agent {$agent} not found");
        }

        // Check agent availability
        if (!$this->isAgentAvailable($agent)) {
            return ['success' => false, 'reason' => 'agent_unavailable'];
        }

        // Attempt the handoff
        $response = $agentInstance->ask($query)
            ->withContext($context)
            ->withTimeout(30)
            ->get();

        return [
            'success' => true,
            'response' => $response,
            'agent' => $agent,
        ];
    }

    private function handleCompleteHandoffFailure(
        array $context,
        string $query,
        array $errors
    ): array {
        // Return to original agent with error context
        $fallbackPrompt = $this->createFallbackPrompt($query, $errors);

        $originalAgent = $context['original_agent'] ?? 'general_support';
        $response = $this->agents[$originalAgent]['provider']
            ->ask($fallbackPrompt)
            ->get();

        return [
            'success' => false,
            'response' => $response,
            'agent' => $originalAgent,
            'errors' => $errors,
            'fallback_used' => true,
        ];
    }
}
```

## Tracking Handoff Metrics

Monitor your handoff system's performance:

```php
class HandoffMetrics
{
    private array $metrics = [];

    public function recordHandoff(string $from, string $to, string $reason, bool $success): void
    {
        $this->metrics[] = [
            'timestamp' => microtime(true),
            'from_agent' => $from,
            'to_agent' => $to,
            'reason' => $reason,
            'success' => $success,
        ];
    }

    public function getHandoffSuccessRate(): float
    {
        if (empty($this->metrics)) {
            return 0.0;
        }

        $successful = array_filter($this->metrics, fn($m) => $m['success']);
        return count($successful) / count($this->metrics);
    }

    public function getAverageHandoffsPerConversation(): float
    {
        // Group by conversation and calculate average
        $conversations = $this->groupByConversation($this->metrics);
        $totalHandoffs = array_sum(array_map('count', $conversations));

        return $totalHandoffs / count($conversations);
    }

    public function getMostCommonHandoffPaths(): array
    {
        $paths = [];

        foreach ($this->metrics as $metric) {
            $path = "{$metric['from_agent']}->{$metric['to_agent']}";
            $paths[$path] = ($paths[$path] ?? 0) + 1;
        }

        arsort($paths);
        return array_slice($paths, 0, 5); // Top 5 paths
    }
}
```

## Summary

You've learned to implement sophisticated handoff patterns that enable seamless transitions between specialized agents. Key takeaways:

- **Condition Evaluation**: Define clear triggers for when handoffs should occur
- **Context Preservation**: Transfer conversation state without losing continuity
- **Failure Handling**: Build resilient systems that gracefully handle handoff failures
- **Metric Tracking**: Monitor handoff performance to optimize your system

These patterns form the foundation for building complex, multi-agent systems that can handle diverse scenarios with appropriate expertise.

## Next Steps

In Chapter 19, we'll explore distributed agent architectures, learning how to coordinate agents across multiple services and handle complex orchestration scenarios. You'll discover patterns for building truly scalable AI systems.

## Additional Resources

- Agent handoff best practices documentation
- Context serialization strategies
- Multi-agent coordination patterns
- Performance optimization for handoff systems
