<?php

declare(strict_types=1);

use Pagent\Evaluation\EvaluationResult;
use Pagent\Evaluation\Report;

describe('Report', function () {
    it('generates HTML with UTF-8 charset meta tag', function () {
        $result = new EvaluationResult(
            agentName: 'Test Agent',
            results: [
                [
                    'input' => 'test input',
                    'output' => 'test output',
                    'metrics' => ['accuracy' => 1.0],
                ],
            ],
            metrics: ['accuracy' => 'Test accuracy metric'],
            datasetSize: 1
        );

        $report = new Report($result);
        $html = $report->toHtml();

        expect($html)->toContain('<meta charset="UTF-8">');
    });

    it('handles emojis and special characters in agent name', function () {
        $result = new EvaluationResult(
            agentName: 'Test Agent 🤖 with émojis & symbols ★',
            results: [
                [
                    'input' => 'test input',
                    'output' => 'test output',
                    'metrics' => ['accuracy' => 1.0],
                ],
            ],
            metrics: ['accuracy' => 'Test accuracy metric'],
            datasetSize: 1
        );

        $report = new Report($result);
        $html = $report->toHtml();

        expect($html)->toContain('<meta charset="UTF-8">')
            ->and($html)->toContain('Test Agent 🤖 with émojis &amp; symbols ★')
            ->and($html)->toContain('Test Agent 🤖 with émojis &amp; symbols ★ - Evaluation Report');
    });

    it('preserves emojis in body content', function () {
        $result = new EvaluationResult(
            agentName: 'Agent',
            results: [
                [
                    'input' => 'What is the weather? 🌤️',
                    'output' => 'It is sunny ☀️',
                    'expected' => 'Sunny weather 🌞',
                    'metrics' => ['accuracy' => 0.9],
                ],
            ],
            metrics: ['accuracy' => 'Test accuracy metric'],
            datasetSize: 1
        );

        $report = new Report($result);
        $html = $report->toHtml();

        expect($html)->toContain('What is the weather? 🌤️')
            ->and($html)->toContain('It is sunny ☀️')
            ->and($html)->toContain('Sunny weather 🌞')
            ->and($html)->toContain('Evaluation Report')
            ->and($html)->toContain('Agent - Evaluation Report');
    });

    it('supports a custom report title', function () {
        $result = new EvaluationResult(
            agentName: 'Agent',
            results: [],
            metrics: [],
            datasetSize: 0
        );

        $html = (new Report($result, 'My Custom Title'))->toHtml();

        expect($html)->toContain('My Custom Title')
            ->and($html)->not->toContain('Agent - Evaluation Report');
    });

    it('generates valid HTML structure', function () {
        $result = new EvaluationResult(
            agentName: 'Test Agent',
            results: [
                [
                    'input' => 'test input',
                    'output' => 'test output',
                    'metrics' => ['accuracy' => 1.0, 'precision' => 0.95],
                ],
            ],
            metrics: [
                'accuracy' => 'Accuracy description',
                'precision' => 'Precision description',
            ],
            datasetSize: 1
        );

        $report = new Report($result);
        $html = $report->toHtml();

        expect($html)->toContain('<!DOCTYPE html>')
            ->and($html)->toContain('<html>')
            ->and($html)->toContain('<head>')
            ->and($html)->toContain('<meta charset="UTF-8">')
            ->and($html)->toContain('<title>')
            ->and($html)->toContain('</title>')
            ->and($html)->toContain('<style>')
            ->and($html)->toContain('</style>')
            ->and($html)->toContain('</head>')
            ->and($html)->toContain('<body>')
            ->and($html)->toContain('</body>')
            ->and($html)->toContain('</html>');
    });

    it('generates markdown format', function () {
        $result = new EvaluationResult(
            agentName: 'Test Agent 🤖',
            results: [
                [
                    'input' => 'test input',
                    'output' => 'test output',
                    'metrics' => ['accuracy' => 1.0],
                ],
            ],
            metrics: ['accuracy' => 'Test accuracy metric'],
            datasetSize: 1
        );

        $report = new Report($result);
        $markdown = $report->toMarkdown();

        expect($markdown)->toContain('# Evaluation Report: Test Agent 🤖')
            ->and($markdown)->toContain('## Metrics Summary')
            ->and($markdown)->toContain('## Detailed Results');
    });

    it('saves HTML report to file', function () {
        $result = new EvaluationResult(
            agentName: 'Test Agent 🎯',
            results: [
                [
                    'input' => 'test input',
                    'output' => 'test output',
                    'metrics' => ['accuracy' => 1.0],
                ],
            ],
            metrics: ['accuracy' => 'Test accuracy metric'],
            datasetSize: 1
        );

        $report = new Report($result);
        $tempFile = sys_get_temp_dir().'/test_report_'.uniqid().'.html';

        try {
            $report->save($tempFile);

            expect(file_exists($tempFile))->toBeTrue();

            $content = file_get_contents($tempFile);
            expect($content)->toContain('<meta charset="UTF-8">')
                ->and($content)->toContain('Test Agent 🎯');
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    });

    it('saves markdown report to file', function () {
        $result = new EvaluationResult(
            agentName: 'Test Agent',
            results: [
                [
                    'input' => 'test input',
                    'output' => 'test output',
                    'metrics' => ['accuracy' => 1.0],
                ],
            ],
            metrics: ['accuracy' => 'Test accuracy metric'],
            datasetSize: 1
        );

        $report = new Report($result);
        $tempFile = sys_get_temp_dir().'/test_report_'.uniqid().'.md';

        try {
            $report->save($tempFile);

            expect(file_exists($tempFile))->toBeTrue();
            expect(file_get_contents($tempFile))->toContain('# Evaluation Report');
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    });

    it('saves JSON report to file', function () {
        $result = new EvaluationResult(
            agentName: 'Test Agent',
            results: [
                [
                    'input' => 'test input',
                    'output' => 'test output',
                    'metrics' => ['accuracy' => 1.0],
                ],
            ],
            metrics: ['accuracy' => 'Test accuracy metric'],
            datasetSize: 1
        );

        $report = new Report($result);
        $tempFile = sys_get_temp_dir().'/test_report_'.uniqid().'.json';

        try {
            $report->save($tempFile);

            expect(file_exists($tempFile))->toBeTrue();

            $content = file_get_contents($tempFile);
            $data = json_decode($content, true);

            expect($data)->toBeArray()
                ->and($data)->toHaveKey('agent')
                ->and($data['agent'])->toBe('Test Agent');
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    });

    it('throws exception for unsupported format', function () {
        $result = new EvaluationResult(
            agentName: 'Test Agent',
            results: [],
            metrics: [],
            datasetSize: 0
        );

        $report = new Report($result);

        expect(fn () => $report->save('/tmp/test.txt'))
            ->toThrow(RuntimeException::class, 'Unsupported export format: txt');
    });
});
