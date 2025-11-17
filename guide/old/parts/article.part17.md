# Chapter 17: Pipeline Pattern

## What You'll Learn

By the end of this chapter, you'll be able to:
- Build sequential agent pipelines that process data through multiple stages
- Implement data transformation between pipeline stages
- Handle errors gracefully with proper propagation strategies
- Optimize pipeline performance through parallelization and caching
- Monitor pipeline execution with comprehensive observability

## Prerequisites

- Completed Chapter 16: Multi-Agent Systems
- Understanding of agent composition and coordination
- Familiarity with PHP generators and iterators
- Basic knowledge of data transformation patterns

## Time Estimate

30-40 minutes of hands-on practice

## Final Result

You'll build production-ready pipelines including a document processor, ETL system, content generator, and quality assurance pipeline that demonstrate real-world applications of the pipeline pattern.

## Understanding Pipeline Architecture

The pipeline pattern chains agents together where each stage transforms data before passing it to the next. Think of it as an assembly line where each worker (agent) performs a specific task before handing off to the next.

### Core Pipeline Concepts

Let's start with the fundamental pipeline interface:

```php
<?php

declare(strict_types=1);

namespace App\Pipelines;

use Pagent\Agent;

interface PipelineStage
{
    public function process(mixed $input): mixed;
    public function getName(): string;
    public function canProcess(mixed $input): bool;
}

interface Pipeline
{
    public function addStage(PipelineStage $stage): self;
    public function execute(mixed $input): mixed;
    public function getMetrics(): array;
}
```

### Basic Pipeline Implementation

Here's a foundation pipeline class that manages stage execution:

```php
<?php

declare(strict_types=1);

namespace App\Pipelines;

use Exception;
use Pagent\Agent;
use Psr\Log\LoggerInterface;

final class AgentPipeline implements Pipeline
{
    /** @var array<PipelineStage> */
    private array $stages = [];

    /** @var array<string, array> */
    private array $metrics = [];

    private ?LoggerInterface $logger = null;

    public function __construct(
        private readonly string $name = 'default',
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger;
    }

    public function addStage(PipelineStage $stage): self
    {
        $this->stages[] = $stage;
        $this->metrics[$stage->getName()] = [
            'executions' => 0,
            'errors' => 0,
            'total_time' => 0,
        ];

        return $this;
    }

    public function execute(mixed $input): mixed
    {
        $result = $input;
        $stageNumber = 0;

        foreach ($this->stages as $stage) {
            $stageNumber++;
            $this->logStageStart($stage, $stageNumber);

            if (! $stage->canProcess($result)) {
                $this->logStageSkip($stage, $stageNumber);
                continue;
            }

            try {
                $startTime = microtime(true);
                $result = $stage->process($result);
                $duration = microtime(true) - $startTime;

                $this->recordMetrics($stage->getName(), $duration);
                $this->logStageComplete($stage, $stageNumber, $duration);
            } catch (Exception $e) {
                $this->handleStageError($stage, $e, $stageNumber);
                throw $e;
            }
        }

        return $result;
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    private function recordMetrics(string $stageName, float $duration): void
    {
        $this->metrics[$stageName]['executions']++;
        $this->metrics[$stageName]['total_time'] += $duration;
    }

    private function handleStageError(
        PipelineStage $stage,
        Exception $e,
        int $stageNumber
    ): void {
        $this->metrics[$stage->getName()]['errors']++;
        $this->logger?->error(
            "Pipeline stage {$stageNumber} ({$stage->getName()}) failed",
            ['error' => $e->getMessage()]
        );
    }

    private function logStageStart(PipelineStage $stage, int $number): void
    {
        $this->logger?->info(
            "Starting pipeline stage {$number}: {$stage->getName()}"
        );
    }

    private function logStageComplete(
        PipelineStage $stage,
        int $number,
        float $duration
    ): void {
        $this->logger?->info(
            "Completed stage {$number}: {$stage->getName()}",
            ['duration_ms' => round($duration * 1000, 2)]
        );
    }

    private function logStageSkip(PipelineStage $stage, int $number): void
    {
        $this->logger?->debug(
            "Skipping stage {$number}: {$stage->getName()} - cannot process input"
        );
    }
}
```

## Document Processing Pipeline

Let's build a complete document processing pipeline that extracts text, summarizes content, extracts entities, and generates metadata:

```php
<?php

declare(strict_types=1);

namespace App\Pipelines\Document;

use App\Pipelines\PipelineStage;
use Pagent\Agent;

final class TextExtractionStage implements PipelineStage
{
    public function __construct(
        private readonly Agent $agent
    ) {}

    public function process(mixed $input): mixed
    {
        if (! is_array($input) || ! isset($input['content'])) {
            throw new \InvalidArgumentException('Invalid input format');
        }

        $response = $this->agent
            ->system('You extract and clean text from documents.')
            ->prompt("Extract all text from this content, removing formatting:\n\n{$input['content']}")
            ->generate();

        return array_merge($input, [
            'extracted_text' => $response->content,
            'extraction_timestamp' => time(),
        ]);
    }

    public function getName(): string
    {
        return 'text_extraction';
    }

    public function canProcess(mixed $input): bool
    {
        return is_array($input) && isset($input['content']);
    }
}

final class SummarizationStage implements PipelineStage
{
    public function __construct(
        private readonly Agent $agent,
        private readonly int $maxWords = 150
    ) {}

    public function process(mixed $input): mixed
    {
        if (! isset($input['extracted_text'])) {
            throw new \InvalidArgumentException('No extracted text found');
        }

        $response = $this->agent
            ->system('You create concise, informative summaries.')
            ->prompt("Summarize this text in {$this->maxWords} words:\n\n{$input['extracted_text']}")
            ->generate();

        return array_merge($input, [
            'summary' => $response->content,
            'summary_word_count' => str_word_count($response->content),
        ]);
    }

    public function getName(): string
    {
        return 'summarization';
    }

    public function canProcess(mixed $input): bool
    {
        return isset($input['extracted_text']) &&
               strlen($input['extracted_text']) > 100;
    }
}

final class EntityExtractionStage implements PipelineStage
{
    public function __construct(
        private readonly Agent $agent
    ) {}

    public function process(mixed $input): mixed
    {
        $prompt = <<<PROMPT
        Extract all entities from this text and return as JSON:
        - people (names of people)
        - organizations (company names, institutions)
        - locations (cities, countries, addresses)
        - dates (specific dates or time periods)
        - products (product names, services)

        Text: {$input['extracted_text']}
        PROMPT;

        $response = $this->agent
            ->system('You extract entities from text and return structured JSON.')
            ->prompt($prompt)
            ->generate();

        $entities = json_decode($response->content, true) ?? [];

        return array_merge($input, [
            'entities' => $entities,
            'entity_count' => array_sum(array_map('count', $entities)),
        ]);
    }

    public function getName(): string
    {
        return 'entity_extraction';
    }

    public function canProcess(mixed $input): bool
    {
        return isset($input['extracted_text']);
    }
}

final class MetadataGenerationStage implements PipelineStage
{
    public function __construct(
        private readonly Agent $agent
    ) {}

    public function process(mixed $input): mixed
    {
        $prompt = <<<PROMPT
        Generate metadata for this document:

        Summary: {$input['summary']}
        Entities: {json_encode($input['entities'])}

        Return as JSON with:
        - category (primary topic category)
        - tags (5-10 relevant tags)
        - sentiment (positive/negative/neutral)
        - readability_level (elementary/intermediate/advanced)
        - key_topics (3-5 main topics)
        PROMPT;

        $response = $this->agent
            ->system('You generate comprehensive document metadata.')
            ->prompt($prompt)
            ->generate();

        $metadata = json_decode($response->content, true) ?? [];

        return array_merge($input, [
            'metadata' => $metadata,
            'processing_complete' => true,
            'processed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function getName(): string
    {
        return 'metadata_generation';
    }

    public function canProcess(mixed $input): bool
    {
        return isset($input['summary']) && isset($input['entities']);
    }
}
```

### Using the Document Pipeline

```php
// Create the document processing pipeline
$pipeline = new AgentPipeline('document_processor', $logger);

$pipeline
    ->addStage(new TextExtractionStage(agent()))
    ->addStage(new SummarizationStage(agent(), 200))
    ->addStage(new EntityExtractionStage(agent()))
    ->addStage(new MetadataGenerationStage(agent()));

// Process a document
$document = [
    'id' => 'doc-123',
    'content' => file_get_contents('article.html'),
    'source' => 'web_scraper',
];

$result = $pipeline->execute($document);

// Access processed data
echo "Summary: " . $result['summary'] . PHP_EOL;
echo "Entities found: " . $result['entity_count'] . PHP_EOL;
echo "Category: " . $result['metadata']['category'] . PHP_EOL;

// Check pipeline metrics
$metrics = $pipeline->getMetrics();
foreach ($metrics as $stage => $stats) {
    echo "{$stage}: {$stats['executions']} executions, ";
    echo round($stats['total_time'], 2) . "s total" . PHP_EOL;
}
```

## ETL System with Agents

Build an Extract-Transform-Load pipeline for data processing:

```php
<?php

declare(strict_types=1);

namespace App\Pipelines\ETL;

final class DataValidationStage implements PipelineStage
{
    public function __construct(
        private readonly Agent $agent,
        private readonly array $schema
    ) {}

    public function process(mixed $input): mixed
    {
        $schemaJson = json_encode($this->schema);
        $dataJson = json_encode($input['data']);

        $prompt = <<<PROMPT
        Validate this data against the schema and fix any issues:

        Schema: {$schemaJson}
        Data: {$dataJson}

        Return the validated and corrected data as JSON.
        Include a 'validation_issues' array listing any problems found.
        PROMPT;

        $response = $this->agent
            ->system('You validate and correct data according to schemas.')
            ->prompt($prompt)
            ->generate();

        $result = json_decode($response->content, true);

        return array_merge($input, [
            'validated_data' => $result['data'] ?? $input['data'],
            'validation_issues' => $result['validation_issues'] ?? [],
            'validation_timestamp' => time(),
        ]);
    }

    public function getName(): string
    {
        return 'data_validation';
    }

    public function canProcess(mixed $input): bool
    {
        return isset($input['data']);
    }
}

final class DataEnrichmentStage implements PipelineStage
{
    public function __construct(
        private readonly Agent $agent,
        private readonly array $enrichmentRules
    ) {}

    public function process(mixed $input): mixed
    {
        $rulesJson = json_encode($this->enrichmentRules);
        $dataJson = json_encode($input['validated_data']);

        $prompt = <<<PROMPT
        Enrich this data according to these rules:

        Rules: {$rulesJson}
        Data: {$dataJson}

        Add missing fields, calculate derived values, and enhance existing data.
        Return the enriched data as JSON.
        PROMPT;

        $response = $this->agent
            ->system('You enrich data by adding calculated fields and external information.')
            ->prompt($prompt)
            ->generate();

        $enrichedData = json_decode($response->content, true);

        return array_merge($input, [
            'enriched_data' => $enrichedData,
            'enrichment_count' => count($enrichedData) - count($input['validated_data']),
        ]);
    }

    public function getName(): string
    {
        return 'data_enrichment';
    }

    public function canProcess(mixed $input): bool
    {
        return isset($input['validated_data']);
    }
}
```

## Content Generation Workflow

Create a multi-stage content generation pipeline:

```php
<?php

declare(strict_types=1);

namespace App\Pipelines\Content;

final class IdeationStage implements PipelineStage
{
    public function __construct(
        private readonly Agent $agent,
        private readonly int $ideaCount = 5
    ) {}

    public function process(mixed $input): mixed
    {
        $prompt = <<<PROMPT
        Generate {$this->ideaCount} content ideas for:
        Topic: {$input['topic']}
        Audience: {$input['audience']}
        Goal: {$input['goal']}

        Return as JSON array with title, angle, and key_points for each idea.
        PROMPT;

        $response = $this->agent
            ->system('You generate creative content ideas.')
            ->prompt($prompt)
            ->generate();

        return array_merge($input, [
            'ideas' => json_decode($response->content, true),
            'selected_idea' => null,
        ]);
    }

    public function getName(): string
    {
        return 'ideation';
    }

    public function canProcess(mixed $input): bool
    {
        return isset($input['topic']) && isset($input['audience']);
    }
}

final class OutlineStage implements PipelineStage
{
    public function __construct(
        private readonly Agent $agent
    ) {}

    public function process(mixed $input): mixed
    {
        // Select best idea if not already selected
        if ($input['selected_idea'] === null) {
            $input['selected_idea'] = $input['ideas'][0];
        }

        $ideaJson = json_encode($input['selected_idea']);

        $prompt = <<<PROMPT
        Create a detailed outline for this content:
        {$ideaJson}

        Include introduction, main sections with subsections, and conclusion.
        Return as structured JSON.
        PROMPT;

        $response = $this->agent
            ->system('You create comprehensive content outlines.')
            ->prompt($prompt)
            ->generate();

        return array_merge($input, [
            'outline' => json_decode($response->content, true),
        ]);
    }

    public function getName(): string
    {
        return 'outline_creation';
    }

    public function canProcess(mixed $input): bool
    {
        return isset($input['ideas']) || isset($input['selected_idea']);
    }
}

final class DraftingStage implements PipelineStage
{
    public function __construct(
        private readonly Agent $agent,
        private readonly int $wordCount = 1000
    ) {}

    public function process(mixed $input): mixed
    {
        $outlineJson = json_encode($input['outline']);

        $prompt = <<<PROMPT
        Write a {$this->wordCount}-word article following this outline:
        {$outlineJson}

        Topic: {$input['topic']}
        Audience: {$input['audience']}
        Tone: {$input['tone'] ?? 'professional'}
        PROMPT;

        $response = $this->agent
            ->system('You write engaging, well-structured content.')
            ->prompt($prompt)
            ->generate();

        return array_merge($input, [
            'draft' => $response->content,
            'draft_word_count' => str_word_count($response->content),
        ]);
    }

    public function getName(): string
    {
        return 'content_drafting';
    }

    public function canProcess(mixed $input): bool
    {
        return isset($input['outline']);
    }
}
```

## Quality Assurance Pipeline

Implement a comprehensive QA pipeline for code or content:

```php
<?php

declare(strict_types=1);

namespace App\Pipelines\QA;

final class QualityCheckStage implements PipelineStage
{
    public function __construct(
        private readonly Agent $agent,
        private readonly array $criteria
    ) {}

    public function process(mixed $input): mixed
    {
        $criteriaList = implode("\n", array_map(
            fn($c) => "- {$c}",
            $this->criteria
        ));

        $prompt = <<<PROMPT
        Evaluate this content against these quality criteria:
        {$criteriaList}

        Content: {$input['content']}

        Return JSON with:
        - score (0-100)
        - passed_criteria (array)
        - failed_criteria (array)
        - recommendations (array)
        PROMPT;

        $response = $this->agent
            ->system('You perform thorough quality assessments.')
            ->prompt($prompt)
            ->generate();

        $assessment = json_decode($response->content, true);

        return array_merge($input, [
            'qa_assessment' => $assessment,
            'qa_passed' => $assessment['score'] >= 80,
        ]);
    }

    public function getName(): string
    {
        return 'quality_check';
    }

    public function canProcess(mixed $input): bool
    {
        return isset($input['content']);
    }
}
```

## Pipeline Error Handling

Implement robust error handling with retry logic:

```php
final class ResilientPipeline extends AgentPipeline
{
    private array $retryConfig = [
        'max_attempts' => 3,
        'delay_ms' => 1000,
        'backoff_multiplier' => 2,
    ];

    public function execute(mixed $input): mixed
    {
        $result = $input;

        foreach ($this->stages as $stage) {
            $attempt = 0;
            $lastError = null;

            while ($attempt < $this->retryConfig['max_attempts']) {
                try {
                    $attempt++;
                    $result = $stage->process($result);
                    break;
                } catch (Exception $e) {
                    $lastError = $e;

                    if ($attempt < $this->retryConfig['max_attempts']) {
                        $delay = $this->retryConfig['delay_ms'] *
                                pow($this->retryConfig['backoff_multiplier'], $attempt - 1);
                        usleep($delay * 1000);

                        $this->logger?->warning(
                            "Retrying stage {$stage->getName()} (attempt {$attempt})",
                            ['error' => $e->getMessage()]
                        );
                    }
                }
            }

            if ($lastError !== null && $attempt >= $this->retryConfig['max_attempts']) {
                throw new PipelineException(
                    "Stage {$stage->getName()} failed after {$attempt} attempts",
                    0,
                    $lastError
                );
            }
        }

        return $result;
    }
}
```

## Performance Optimization

Implement parallel execution for independent stages:

```php
final class ParallelPipeline implements Pipeline
{
    private array $parallelGroups = [];

    public function addParallelStages(array $stages): self
    {
        $this->parallelGroups[] = $stages;
        return $this;
    }

    public function execute(mixed $input): mixed
    {
        $result = $input;

        foreach ($this->parallelGroups as $group) {
            $results = [];

            // Execute stages in parallel
            foreach ($group as $stage) {
                $results[$stage->getName()] = $stage->process($result);
            }

            // Merge results
            foreach ($results as $stageResult) {
                $result = array_merge($result, $stageResult);
            }
        }

        return $result;
    }
}
```

## Summary

You've learned to build sophisticated pipeline patterns with Pagent:

- **Pipeline Architecture**: Sequential processing with stage interfaces
- **Document Processing**: Multi-stage text analysis and extraction
- **ETL Systems**: Data validation, enrichment, and transformation
- **Content Generation**: Ideation through publishing workflows
- **Quality Assurance**: Automated quality checks and assessments
- **Error Handling**: Resilient pipelines with retry logic
- **Performance**: Parallel execution strategies

## Next Steps

- Implement caching between pipeline stages
- Add pipeline branching for conditional flows
- Create pipeline templates for common workflows
- Build pipeline monitoring dashboards
- Explore stream processing for real-time pipelines

## Additional Resources

- [Pipeline Design Patterns](https://martinfowler.com/articles/collection-pipeline.html)
- [Data Pipeline Best Practices](https://www.oreilly.com/library/view/data-pipelines-pocket/9781492087823/)
- [Stream Processing Concepts](https://www.confluent.io/learn/stream-processing/)