# Chapter 19: Delegation Pattern

## What You'll Learn

By the end of this chapter, you'll be able to:

- Design effective delegation strategies for distributing work across agents
- Implement parallel delegation with proper coordination
- Handle result aggregation from multiple agents
- Balance load across specialized agents
- Optimize delegation decisions based on agent capabilities

**Prerequisites:** Understanding of routing patterns (Chapter 16) and agent systems (Chapter 17)

**Time Estimate:** 45 minutes

**Final Result:** A sophisticated delegation system that coordinates multiple agents to solve complex problems efficiently

## Understanding Delegation

Delegation is about intelligently distributing work to maximize efficiency and quality. Unlike simple routing, delegation involves strategic decisions about task decomposition, parallel execution, and result synthesis.

### The Delegation Challenge

Consider coordinating a research project:

```php
// Without delegation - sequential and slow
$agent = agent()
    ->withProvider(anthropic())
    ->usingModel('claude-3-5-sonnet-20241022');

$research = $agent->ask("Research quantum computing applications in medicine");
// Single agent handles everything sequentially

// With delegation - parallel and efficient
$coordinator = new ResearchCoordinator();
$research = $coordinator->research("quantum computing in medicine");
// Multiple specialists work in parallel
```

Delegation transforms sequential bottlenecks into parallel workflows.

## Building a Research Coordinator

Let's implement a sophisticated research coordination system:

```php
<?php

declare(strict_types=1);

namespace App\Delegation;

use Pagent\Agent;
use Pagent\AgentBuilder;

final class ResearchCoordinator
{
    private array $specialists = [];
    private array $activeResearch = [];

    public function __construct()
    {
        $this->initializeSpecialists();
    }

    private function initializeSpecialists(): void
    {
        // Literature specialist - finds and analyzes papers
        $this->specialists['literature'] = agent()
            ->withProvider(anthropic())
            ->usingModel('claude-3-5-sonnet-20241022')
            ->withInstruction('You are a literature research specialist. Find and analyze academic papers.')
            ->withMaxTokens(2000);

        // Data analyst - processes statistics and findings
        $this->specialists['data'] = agent()
            ->withProvider(openai())
            ->usingModel('gpt-4-turbo-preview')
            ->withInstruction('You are a data analysis specialist. Extract and analyze quantitative findings.')
            ->withTemperature(0.3);

        // Synthesizer - combines findings into insights
        $this->specialists['synthesis'] = agent()
            ->withProvider(anthropic())
            ->usingModel('claude-3-5-sonnet-20241022')
            ->withInstruction('You are a synthesis specialist. Combine research findings into coherent insights.');

        // Critic - evaluates quality and identifies gaps
        $this->specialists['critic'] = agent()
            ->withProvider(openai())
            ->usingModel('gpt-4-turbo-preview')
            ->withInstruction('You are a critical reviewer. Evaluate research quality and identify gaps.')
            ->withTemperature(0.7);
    }

    public function research(string $topic): array
    {
        // Phase 1: Decompose research question
        $subtasks = $this->decomposeResearchTask($topic);

        // Phase 2: Delegate to specialists in parallel
        $findings = $this->delegateParallel($subtasks);

        // Phase 3: Synthesize results
        $synthesis = $this->synthesizeFindings($findings);

        // Phase 4: Critical review
        $review = $this->criticalReview($synthesis);

        return [
            'topic' => $topic,
            'findings' => $findings,
            'synthesis' => $synthesis,
            'review' => $review,
            'quality_score' => $this->calculateQualityScore($review)
        ];
    }

    private function decomposeResearchTask(string $topic): array
    {
        $decomposer = agent()
            ->withProvider(anthropic())
            ->usingModel('claude-3-5-sonnet-20241022')
            ->withInstruction('Decompose research topics into specific subtasks.');

        $prompt = sprintf(
            "Decompose this research topic into 4-6 specific subtasks:\n%s\n\nReturn as JSON array.",
            $topic
        );

        $response = $decomposer->ask($prompt);
        return json_decode($response->content(), true) ?? [];
    }

    private function delegateParallel(array $subtasks): array
    {
        $promises = [];

        foreach ($subtasks as $index => $subtask) {
            // Determine best specialist for this subtask
            $specialist = $this->selectSpecialist($subtask);

            // Track active research
            $researchId = uniqid('research_');
            $this->activeResearch[$researchId] = [
                'subtask' => $subtask,
                'specialist' => $specialist,
                'start_time' => microtime(true)
            ];

            // Delegate asynchronously
            $promises[$researchId] = $this->delegateToSpecialist(
                $specialist,
                $subtask
            );
        }

        // Wait for all research to complete
        $findings = [];
        foreach ($promises as $researchId => $promise) {
            $findings[$researchId] = $this->awaitResult($promise);

            // Record completion
            $this->activeResearch[$researchId]['end_time'] = microtime(true);
            $this->activeResearch[$researchId]['duration'] =
                $this->activeResearch[$researchId]['end_time'] -
                $this->activeResearch[$researchId]['start_time'];
        }

        return $findings;
    }

    private function selectSpecialist(array $subtask): string
    {
        // Analyze subtask characteristics
        $keywords = strtolower($subtask['description'] ?? '');

        if (str_contains($keywords, 'paper') || str_contains($keywords, 'study')) {
            return 'literature';
        }

        if (str_contains($keywords, 'data') || str_contains($keywords, 'statistic')) {
            return 'data';
        }

        if (str_contains($keywords, 'combine') || str_contains($keywords, 'integrate')) {
            return 'synthesis';
        }

        // Default to literature specialist
        return 'literature';
    }

    private function delegateToSpecialist(string $specialist, array $subtask): mixed
    {
        $agent = $this->specialists[$specialist];

        $prompt = sprintf(
            "Research task: %s\n\nProvide detailed findings with sources.",
            $subtask['description']
        );

        // Simulate async with immediate return (in real implementation, use async library)
        return $agent->ask($prompt);
    }

    private function awaitResult(mixed $promise): array
    {
        // In real implementation, this would await async promise
        $response = $promise;

        return [
            'content' => $response->content(),
            'tokens_used' => $response->usage()['total_tokens'] ?? 0,
            'model' => $response->model()
        ];
    }

    private function synthesizeFindings(array $findings): string
    {
        $synthesizer = $this->specialists['synthesis'];

        $combinedFindings = array_map(
            fn($finding) => $finding['content'],
            $findings
        );

        $prompt = sprintf(
            "Synthesize these research findings into a coherent summary:\n\n%s",
            implode("\n\n---\n\n", $combinedFindings)
        );

        $response = $synthesizer->ask($prompt);
        return $response->content();
    }

    private function criticalReview(string $synthesis): array
    {
        $critic = $this->specialists['critic'];

        $prompt = sprintf(
            "Critically review this research synthesis. Identify strengths, weaknesses, and gaps:\n\n%s",
            $synthesis
        );

        $response = $critic->ask($prompt);

        return [
            'review' => $response->content(),
            'strengths' => $this->extractStrengths($response->content()),
            'weaknesses' => $this->extractWeaknesses($response->content()),
            'gaps' => $this->extractGaps($response->content())
        ];
    }

    private function calculateQualityScore(array $review): float
    {
        $strengths = count($review['strengths']);
        $weaknesses = count($review['weaknesses']);
        $gaps = count($review['gaps']);

        // Simple scoring algorithm
        $score = 100;
        $score -= ($weaknesses * 5);
        $score -= ($gaps * 3);
        $score = max(0, min(100, $score));

        return $score / 100;
    }

    private function extractStrengths(string $review): array
    {
        // Simple extraction (enhance with NLP in production)
        preg_match_all('/strength[s]?:(.+?)(?:weakness|gap|\z)/si', $review, $matches);
        return array_filter(array_map('trim', $matches[1] ?? []));
    }

    private function extractWeaknesses(string $review): array
    {
        preg_match_all('/weakness[es]?:(.+?)(?:strength|gap|\z)/si', $review, $matches);
        return array_filter(array_map('trim', $matches[1] ?? []));
    }

    private function extractGaps(string $review): array
    {
        preg_match_all('/gap[s]?:(.+?)(?:strength|weakness|\z)/si', $review, $matches);
        return array_filter(array_map('trim', $matches[1] ?? []));
    }
}
```

## Implementing Parallel Task Execution

Let's create a more sophisticated parallel execution system:

```php
<?php

declare(strict_types=1);

namespace App\Delegation;

use Pagent\Agent;
use React\Promise\PromiseInterface;
use React\Promise\Promise;

final class ParallelExecutor
{
    private array $workers = [];
    private array $taskQueue = [];
    private array $results = [];
    private int $maxWorkers;

    public function __construct(int $maxWorkers = 5)
    {
        $this->maxWorkers = $maxWorkers;
        $this->initializeWorkers();
    }

    private function initializeWorkers(): void
    {
        for ($i = 0; $i < $this->maxWorkers; $i++) {
            $this->workers[$i] = [
                'id' => sprintf('worker_%d', $i),
                'agent' => $this->createWorkerAgent(),
                'status' => 'idle',
                'current_task' => null,
                'tasks_completed' => 0
            ];
        }
    }

    private function createWorkerAgent(): Agent
    {
        return agent()
            ->withProvider(openai())
            ->usingModel('gpt-4-turbo-preview')
            ->withMaxTokens(1000);
    }

    public function executeTasks(array $tasks): array
    {
        // Add tasks to queue
        foreach ($tasks as $task) {
            $this->queueTask($task);
        }

        // Execute in parallel batches
        while (!empty($this->taskQueue)) {
            $this->processBatch();
        }

        // Wait for all workers to complete
        $this->waitForCompletion();

        return $this->results;
    }

    private function queueTask(array $task): void
    {
        $taskId = uniqid('task_');
        $this->taskQueue[$taskId] = [
            'id' => $taskId,
            'description' => $task['description'],
            'priority' => $task['priority'] ?? 5,
            'complexity' => $this->estimateComplexity($task),
            'status' => 'queued'
        ];
    }

    private function estimateComplexity(array $task): int
    {
        // Estimate based on task characteristics
        $description = $task['description'] ?? '';
        $wordCount = str_word_count($description);

        if ($wordCount > 100) return 9;
        if ($wordCount > 50) return 7;
        if ($wordCount > 20) return 5;
        return 3;
    }

    private function processBatch(): void
    {
        // Sort queue by priority
        uasort($this->taskQueue, function($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });

        // Assign tasks to idle workers
        foreach ($this->workers as &$worker) {
            if ($worker['status'] === 'idle' && !empty($this->taskQueue)) {
                $task = $this->selectTaskForWorker($worker);
                if ($task) {
                    $this->assignTask($worker, $task);
                }
            }
        }

        // Process assigned tasks
        $this->processAssignedTasks();
    }

    private function selectTaskForWorker(array $worker): ?array
    {
        // Load balancing: assign complex tasks to workers with fewer completions
        foreach ($this->taskQueue as $taskId => $task) {
            if ($task['status'] === 'queued') {
                // Simple load balancing
                if ($worker['tasks_completed'] < 3 || $task['complexity'] < 7) {
                    unset($this->taskQueue[$taskId]);
                    return $task;
                }
            }
        }

        // Take any available task
        foreach ($this->taskQueue as $taskId => $task) {
            if ($task['status'] === 'queued') {
                unset($this->taskQueue[$taskId]);
                return $task;
            }
        }

        return null;
    }

    private function assignTask(array &$worker, array $task): void
    {
        $worker['status'] = 'working';
        $worker['current_task'] = $task;
        $task['status'] = 'processing';
        $task['worker_id'] = $worker['id'];
        $task['start_time'] = microtime(true);
    }

    private function processAssignedTasks(): void
    {
        foreach ($this->workers as &$worker) {
            if ($worker['status'] === 'working' && $worker['current_task']) {
                $result = $this->executeTask($worker['agent'], $worker['current_task']);

                // Store result
                $this->results[$worker['current_task']['id']] = [
                    'task' => $worker['current_task'],
                    'result' => $result,
                    'worker_id' => $worker['id'],
                    'duration' => microtime(true) - $worker['current_task']['start_time']
                ];

                // Update worker status
                $worker['tasks_completed']++;
                $worker['status'] = 'idle';
                $worker['current_task'] = null;
            }
        }
    }

    private function executeTask(Agent $agent, array $task): string
    {
        $response = $agent->ask($task['description']);
        return $response->content();
    }

    private function waitForCompletion(): void
    {
        while ($this->hasActiveWorkers()) {
            usleep(100000); // 100ms
            $this->processAssignedTasks();
        }
    }

    private function hasActiveWorkers(): bool
    {
        foreach ($this->workers as $worker) {
            if ($worker['status'] === 'working') {
                return true;
            }
        }
        return false;
    }

    public function getWorkerStats(): array
    {
        return array_map(function($worker) {
            return [
                'id' => $worker['id'],
                'status' => $worker['status'],
                'tasks_completed' => $worker['tasks_completed']
            ];
        }, $this->workers);
    }
}
```

## Building a Voting System

Implement consensus through democratic delegation:

```php
<?php

declare(strict_types=1);

namespace App\Delegation;

final class VotingSystem
{
    private array $voters = [];
    private array $ballots = [];

    public function __construct(int $voterCount = 5)
    {
        $this->initializeVoters($voterCount);
    }

    private function initializeVoters(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->voters[] = [
                'id' => sprintf('voter_%d', $i),
                'agent' => $this->createVoterAgent($i),
                'weight' => $this->calculateVoterWeight($i),
                'bias' => $this->assignVoterBias($i)
            ];
        }
    }

    private function createVoterAgent(int $index): Agent
    {
        // Vary models for diversity
        $models = [
            'claude-3-5-sonnet-20241022',
            'gpt-4-turbo-preview',
            'claude-3-opus-20240229'
        ];

        $model = $models[$index % count($models)];
        $provider = str_contains($model, 'claude') ? anthropic() : openai();

        return agent()
            ->withProvider($provider)
            ->usingModel($model)
            ->withTemperature(0.3 + ($index * 0.1)); // Vary temperature
    }

    private function calculateVoterWeight(int $index): float
    {
        // Could be based on past accuracy, expertise, etc.
        return 1.0; // Equal weight for now
    }

    private function assignVoterBias(int $index): string
    {
        $biases = ['conservative', 'progressive', 'neutral', 'analytical', 'creative'];
        return $biases[$index % count($biases)];
    }

    public function vote(string $question, array $options): array
    {
        // Collect votes from all voters
        $this->collectVotes($question, $options);

        // Tally results
        $results = $this->tallyVotes();

        // Determine winner
        $winner = $this->determineWinner($results);

        // Generate consensus report
        $consensus = $this->generateConsensus($results, $winner);

        return [
            'question' => $question,
            'options' => $options,
            'votes' => $this->ballots,
            'results' => $results,
            'winner' => $winner,
            'consensus' => $consensus
        ];
    }

    private function collectVotes(string $question, array $options): void
    {
        $this->ballots = [];

        foreach ($this->voters as $voter) {
            $ballot = $this->castVote($voter, $question, $options);
            $this->ballots[] = $ballot;
        }
    }

    private function castVote(array $voter, string $question, array $options): array
    {
        $prompt = $this->createVotingPrompt($question, $options, $voter['bias']);

        $response = $voter['agent']->ask($prompt);

        // Parse vote from response
        $vote = $this->parseVote($response->content(), $options);

        return [
            'voter_id' => $voter['id'],
            'vote' => $vote,
            'reasoning' => $response->content(),
            'weight' => $voter['weight'],
            'bias' => $voter['bias']
        ];
    }

    private function createVotingPrompt(string $question, array $options, string $bias): string
    {
        $optionsList = implode("\n", array_map(
            fn($i, $opt) => sprintf("%d. %s", $i + 1, $opt),
            array_keys($options),
            $options
        ));

        return sprintf(
            "Question: %s\n\nOptions:\n%s\n\nYour perspective: %s\n\nChoose the best option and explain why. Start your response with 'VOTE: [number]'",
            $question,
            $optionsList,
            $bias
        );
    }

    private function parseVote(string $response, array $options): int
    {
        if (preg_match('/VOTE:\s*(\d+)/', $response, $matches)) {
            $vote = (int)$matches[1] - 1;
            if ($vote >= 0 && $vote < count($options)) {
                return $vote;
            }
        }

        // Default to first option if parsing fails
        return 0;
    }

    private function tallyVotes(): array
    {
        $tally = [];

        foreach ($this->ballots as $ballot) {
            $vote = $ballot['vote'];
            $weight = $ballot['weight'];

            if (!isset($tally[$vote])) {
                $tally[$vote] = 0;
            }

            $tally[$vote] += $weight;
        }

        arsort($tally);
        return $tally;
    }

    private function determineWinner(array $results): int
    {
        return array_key_first($results);
    }

    private function generateConsensus(array $results, int $winner): string
    {
        $totalVotes = array_sum($results);
        $winnerVotes = $results[$winner] ?? 0;
        $percentage = ($winnerVotes / $totalVotes) * 100;

        if ($percentage > 80) {
            return "Strong consensus";
        } elseif ($percentage > 60) {
            return "Moderate consensus";
        } elseif ($percentage > 40) {
            return "Weak consensus";
        } else {
            return "No clear consensus";
        }
    }
}
```

## Distributed Analysis System

Create a sophisticated distributed analysis system:

```php
<?php

declare(strict_types=1);

namespace App\Delegation;

final class DistributedAnalyzer
{
    private array $analyzers;
    private array $analysisCache = [];

    public function analyze(mixed $data, array $dimensions = []): array
    {
        // Default dimensions if not specified
        if (empty($dimensions)) {
            $dimensions = ['quality', 'completeness', 'accuracy', 'relevance'];
        }

        // Distribute analysis across dimensions
        $analyses = $this->distributeAnalysis($data, $dimensions);

        // Aggregate results
        $aggregated = $this->aggregateAnalyses($analyses);

        // Generate meta-analysis
        $meta = $this->metaAnalysis($aggregated);

        return [
            'data_hash' => $this->hashData($data),
            'dimensions' => $dimensions,
            'analyses' => $analyses,
            'aggregated' => $aggregated,
            'meta_analysis' => $meta,
            'confidence' => $this->calculateConfidence($analyses)
        ];
    }

    private function distributeAnalysis(mixed $data, array $dimensions): array
    {
        $analyses = [];

        foreach ($dimensions as $dimension) {
            // Check cache
            $cacheKey = $this->getCacheKey($data, $dimension);
            if (isset($this->analysisCache[$cacheKey])) {
                $analyses[$dimension] = $this->analysisCache[$cacheKey];
                continue;
            }

            // Perform analysis
            $analyzer = $this->getAnalyzer($dimension);
            $analysis = $this->performAnalysis($analyzer, $data, $dimension);

            // Cache result
            $this->analysisCache[$cacheKey] = $analysis;
            $analyses[$dimension] = $analysis;
        }

        return $analyses;
    }

    private function getAnalyzer(string $dimension): Agent
    {
        if (!isset($this->analyzers[$dimension])) {
            $this->analyzers[$dimension] = agent()
                ->withProvider(anthropic())
                ->usingModel('claude-3-5-sonnet-20241022')
                ->withInstruction(sprintf(
                    'You are a specialist in analyzing %s. Provide detailed, objective analysis.',
                    $dimension
                ))
                ->withTemperature(0.3);
        }

        return $this->analyzers[$dimension];
    }

    private function performAnalysis(Agent $analyzer, mixed $data, string $dimension): array
    {
        $prompt = sprintf(
            "Analyze the following data for %s:\n\n%s\n\nProvide a score (0-100) and detailed analysis.",
            $dimension,
            $this->serializeData($data)
        );

        $response = $analyzer->ask($prompt);

        return [
            'dimension' => $dimension,
            'score' => $this->extractScore($response->content()),
            'analysis' => $response->content(),
            'timestamp' => time()
        ];
    }

    private function aggregateAnalyses(array $analyses): array
    {
        $scores = [];
        $insights = [];

        foreach ($analyses as $dimension => $analysis) {
            $scores[$dimension] = $analysis['score'];
            $insights[$dimension] = $this->extractKeyInsights($analysis['analysis']);
        }

        return [
            'average_score' => array_sum($scores) / count($scores),
            'scores' => $scores,
            'insights' => $insights,
            'standard_deviation' => $this->calculateStandardDeviation($scores)
        ];
    }

    private function metaAnalysis(array $aggregated): array
    {
        $metaAnalyzer = agent()
            ->withProvider(anthropic())
            ->usingModel('claude-3-5-sonnet-20241022')
            ->withInstruction('Synthesize multiple analytical perspectives into coherent insights.');

        $prompt = sprintf(
            "Synthesize these analyses:\n\n%s\n\nIdentify patterns, contradictions, and overall assessment.",
            json_encode($aggregated, JSON_PRETTY_PRINT)
        );

        $response = $metaAnalyzer->ask($prompt);

        return [
            'synthesis' => $response->content(),
            'overall_score' => $aggregated['average_score'],
            'confidence_level' => $this->determineConfidenceLevel($aggregated['standard_deviation']),
            'key_findings' => $this->extractKeyFindings($response->content())
        ];
    }

    private function calculateConfidence(array $analyses): float
    {
        if (empty($analyses)) return 0.0;

        $scores = array_column($analyses, 'score');
        $stdDev = $this->calculateStandardDeviation($scores);

        // Lower standard deviation = higher confidence
        $confidence = max(0, 100 - ($stdDev * 2));
        return round($confidence, 2);
    }

    private function calculateStandardDeviation(array $values): float
    {
        $count = count($values);
        if ($count <= 1) return 0.0;

        $mean = array_sum($values) / $count;
        $variance = array_sum(array_map(
            fn($x) => pow($x - $mean, 2),
            $values
        )) / $count;

        return sqrt($variance);
    }

    private function extractScore(string $analysis): int
    {
        if (preg_match('/score[:\s]+(\d+)/i', $analysis, $matches)) {
            return min(100, max(0, (int)$matches[1]));
        }
        return 50; // Default middle score
    }

    private function extractKeyInsights(string $analysis): array
    {
        // Simple extraction - enhance with NLP
        $lines = explode("\n", $analysis);
        $insights = [];

        foreach ($lines as $line) {
            if (preg_match('/^[-*]\s+(.+)/', $line, $matches)) {
                $insights[] = trim($matches[1]);
            }
        }

        return array_slice($insights, 0, 3); // Top 3 insights
    }

    private function extractKeyFindings(string $synthesis): array
    {
        // Extract key findings from synthesis
        $findings = [];

        if (preg_match_all('/key finding[s]?:(.+?)(?:\n\n|$)/si', $synthesis, $matches)) {
            foreach ($matches[1] as $finding) {
                $findings[] = trim($finding);
            }
        }

        return $findings;
    }

    private function determineConfidenceLevel(float $stdDev): string
    {
        if ($stdDev < 5) return 'Very High';
        if ($stdDev < 10) return 'High';
        if ($stdDev < 20) return 'Moderate';
        if ($stdDev < 30) return 'Low';
        return 'Very Low';
    }

    private function hashData(mixed $data): string
    {
        return md5($this->serializeData($data));
    }

    private function serializeData(mixed $data): string
    {
        if (is_string($data)) return $data;
        return json_encode($data);
    }

    private function getCacheKey(mixed $data, string $dimension): string
    {
        return sprintf('%s_%s', $this->hashData($data), $dimension);
    }
}
```

## Practical Exercise: Building a Complex Delegation System

Try implementing this comprehensive delegation orchestrator:

```php
// Challenge: Create an intelligent task orchestrator
// Requirements:
// 1. Dynamic worker allocation
// 2. Priority-based scheduling
// 3. Failure recovery
// 4. Performance monitoring

final class TaskOrchestrator
{
    // Your implementation here
    // Should handle:
    // - Worker pool management
    // - Task prioritization
    // - Load balancing
    // - Result validation
    // - Retry logic
}
```

## Summary

You've mastered sophisticated delegation patterns:

✅ **Delegation Strategy Design** - Strategic work distribution
✅ **Parallel Execution** - Concurrent task processing
✅ **Result Aggregation** - Synthesizing multiple outputs
✅ **Load Balancing** - Optimal resource utilization
✅ **Consensus Systems** - Democratic decision making
✅ **Distributed Analysis** - Multi-dimensional evaluation

The delegation pattern transforms single-threaded AI interactions into powerful distributed systems that leverage multiple agents' strengths.

## Next Steps

In Chapter 20, we'll explore **Autonomous Agent Systems**, where agents operate independently with minimal supervision:

- Self-directed goal pursuit
- Autonomous decision making
- Environmental awareness
- Long-term planning
- Self-improvement mechanisms

Your delegation systems now coordinate multiple agents efficiently. Next, we'll make them truly autonomous!
