<?php

declare(strict_types=1);

namespace Pagent\Tools;

use Pagent\Exceptions\ConfigurationException;
use Pagent\Exceptions\RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Execute shell commands.
 *
 * SECURITY: The allowedCommands allowlist is a guardrail against accidental
 * misuse, NOT a security boundary. Commands run through a real shell, so a
 * sufficiently creative input may still find a way around it. When an
 * allowlist is set, commands containing shell metacharacters (;, |, &,
 * backticks, $(, >, <, newlines) are rejected outright and the first token
 * must be allowlisted. For untrusted input, run the process inside an
 * OS-level sandbox (container, jail, seccomp) instead of relying on this.
 *
 * Constructing Bash without an allowlist requires an explicit
 * `unrestricted: true` opt-in.
 */
final class Bash extends Tool
{
    /**
     * @param  array<string>  $allowedCommands  Allowlist of permitted first tokens (e.g. ['ls', 'git'])
     * @param  bool  $unrestricted  Explicit opt-in to run ANY command when no allowlist is set
     */
    public function __construct(
        private ?string $workingDir = null,
        private int $timeout = 60,
        private array $allowedCommands = [],
        bool $unrestricted = false,
    ) {
        if ($this->allowedCommands === [] && ! $unrestricted) {
            throw new ConfigurationException(
                'Bash tool constructed without an allowlist. Either pass allowedCommands: [...] '
                .'to restrict which commands may run, or pass unrestricted: true to explicitly '
                .'allow any command (dangerous with untrusted input).'
            );
        }
    }

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
            // Reject shell metacharacters that could chain a second, non-allowlisted
            // command (e.g. `ls; rm -rf /`, pipes, command substitution, redirects).
            if (preg_match('/[;|&`><\n]|\$\(/', $command) === 1) {
                throw new RuntimeException(
                    'Command contains shell metacharacters (;, |, &, `, $(, >, <, newline) '
                    .'which are not permitted when an allowlist is configured'
                );
            }

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
