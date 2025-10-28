<?php

// Manager delegates to worker agents with tools - automated research workflow
agent('manager')->provider('anthropic')->system('Coordinate research tasks');

agent('researcher')->provider('openai')->system('Research topics thoroughly')
    ->tool('search_papers', 'Search academic papers', fn (string $query) => ['papers' => rand(10, 50), 'top_result' => "Study on {$query}", 'citations' => rand(100, 1000)])
    ->tool('analyze_trends', 'Analyze research trends', fn (string $topic) => ['trend' => 'increasing', 'growth' => rand(10, 50).'%', 'hot_keywords' => ['AI', 'ML', 'NLP']]);

agent('writer')->provider('anthropic')->system('Write clear summaries')
    ->tool('format_citations', 'Format citations', fn (array $papers) => ['formatted' => count($papers).' sources in APA format', 'bibliography' => '...']);

// Delegation with supervision - manager validates worker output
$result = agent('manager')
    ->delegate('Research and summarize AI trends in healthcare')
    ->to('researcher')
    ->to('writer')
    ->supervise(fn ($output) => str_contains($output, 'citation') || strlen($output) > 100)
    ->onComplete(fn ($r) => "✓ Task completed by {$r->worker}")
    ->execute();

echo $result->manager_review;
