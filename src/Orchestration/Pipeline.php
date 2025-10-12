<?php

declare(strict_types=1);

namespace Pagent\Orchestration;

use Closure;
use Exception;
use Pagent\Agent;
use RuntimeException;

final class Pipeline
{
    /** @var array<int, array{agent: string|Agent, transform: ?Closure}> */
    private array $stages = [];

    private ?Closure $errorHandler = null;

    private array $results = [];

    public function __construct(
        private readonly string $name,
    ) {}

    public function agent(string|Agent $agent, ?Closure $transform = null): self
    {
        $this->stages[] = [
            'agent' => $agent,
            'transform' => $transform,
        ];

        return $this;
    }

    public function onError(Closure $handler): self
    {
        $this->errorHandler = $handler;

        return $this;
    }

    public function run(mixed $input): mixed
    {
        $output = $input;
        $this->results = [];

        foreach ($this->stages as $index => $stage) {
            // TODO: why not get from resolved agent?
            $agentName = is_string($stage['agent']) ? $stage['agent'] : $stage['agent']->getName();

            try {
                $agent = resolveAgent($stage['agent']);

                if ( ! $agent instanceof Agent) {
                    throw new RuntimeException("Agent '{$agentName}' not found at stage {$index}");
                }

                // Apply transform if provided
                if ($stage['transform']) {
                    $input = ($stage['transform'])($output);
                } else {
                    $input = is_string($output) ? $output : json_encode($output);
                }

                // Run the agent
                $response = $agent->prompt($input);
                $output = $response->content;

                // Store result
                $this->results[] = [
                    'stage' => $index,
                    'agent' => $agentName,
                    'input' => $input,
                    'output' => $output,
                    'response' => $response,
                ];
            } catch (Exception $e) {
                if ($this->errorHandler) {
                    return ($this->errorHandler)($e, $index, $agentName);
                }

                throw new RuntimeException(
                    "Pipeline '{$this->name}' failed at stage {$index} (agent: {$agentName}): {$e->getMessage()}",
                    previous: $e,
                );
            }
        }

        return $output;
    }

    public function getResults(): array
    {
        return $this->results;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
