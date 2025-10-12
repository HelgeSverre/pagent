<?php

declare(strict_types=1);

namespace Pagent\Tools;

use RuntimeException;

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
        $command = $params['command'] ?? throw new RuntimeException('Command parameter is required');

        // Check if command is allowed
        if (! empty($this->allowedCommands)) {
            $commandBase = explode(' ', trim($command))[0];
            if (! in_array($commandBase, $this->allowedCommands, true)) {
                throw new RuntimeException("Command not allowed: {$commandBase}");
            }
        }

        $workingDir = $this->workingDir ?? getcwd();

        // Execute command with timeout
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            $workingDir
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Failed to execute command');
        }

        // Close stdin
        fclose($pipes[0]);

        // Set timeout for reading
        $startTime = time();
        $stdout = '';
        $stderr = '';

        // Read output with timeout
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        while (true) {
            $stdoutChunk = fread($pipes[1], 8192);
            $stderrChunk = fread($pipes[2], 8192);

            if ($stdoutChunk !== false && $stdoutChunk !== '') {
                $stdout .= $stdoutChunk;
            }

            if ($stderrChunk !== false && $stderrChunk !== '') {
                $stderr .= $stderrChunk;
            }

            // Check timeout
            if ((time() - $startTime) > $this->timeout) {
                proc_terminate($process);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw new RuntimeException('Command execution timed out');
            }

            // Check if process finished
            $status = proc_get_status($process);
            if (! $status['running']) {
                // Read any remaining output
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                break;
            }

            usleep(100000); // 100ms
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exit_code' => $exitCode,
            'success' => $exitCode === 0,
        ];
    }
}
