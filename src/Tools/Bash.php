<?php

declare(strict_types=1);

namespace Pagent\Tools;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class Bash extends Tool
{
    public function __construct(
        private ?string $workingDir = null,
        private int $timeout = 60,
        private array $allowedCommands = [],
    ) {}

    public function name(): string
    {
        return 'bash';
    }

    public function description(): string
    {
        return 'Execute a shell command and return its output. Use with caution.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => [
                    'type' => 'string',
                    'description' => 'Shell command to execute',
                ],
            ],
            'required' => ['command'],
        ];
    }

    public function execute(array $params): mixed
    {
        $command = $this->requiredString($params, 'command');

        // Check if command is allowed
        if (! empty($this->allowedCommands)) {
            $commandBase = explode(' ', trim($command))[0];
            if (! in_array($commandBase, $this->allowedCommands, true)) {
                throw new RuntimeException("Command not allowed: {$commandBase}");
            }
        }

        $workingDir = $this->workingDir ?? getcwd();

        // Create and configure process
        $process = Process::fromShellCommandline($command);
        $process->setWorkingDirectory($workingDir);
        $process->setTimeout($this->timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new RuntimeException('Command execution timed out');
        }

        return [
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
            'exit_code' => $process->getExitCode() ?? 1,
            'success' => $process->isSuccessful(),
        ];
    }
}
