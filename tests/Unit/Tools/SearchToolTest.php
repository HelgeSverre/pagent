<?php

declare(strict_types=1);

use Pagent\Tools\SearchTool;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/pagent_search_test_'.uniqid();
    mkdir($this->tempDir);
});

afterEach(function () {
    if (is_dir($this->tempDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($this->tempDir);
    }
});

test('search tool requires at least one document source', function () {
    expect(fn () => new SearchTool)
        ->toThrow(RuntimeException::class, 'At least one document source must be provided');
});

test('search tool validates fuzziness parameter', function () {
    expect(fn () => new SearchTool(
        documents: [['id' => 1, 'content' => 'test']],
        fuzziness: 5
    ))->toThrow(RuntimeException::class, 'Fuzziness must be between 1 and 3');
});

test('search tool with documents performs basic search', function () {
    $documents = [
        ['id' => 1, 'title' => 'PHP Tutorial', 'content' => 'Learn PHP programming basics'],
        ['id' => 2, 'title' => 'JavaScript Guide', 'content' => 'Master JavaScript fundamentals'],
        ['id' => 3, 'title' => 'PHP Advanced', 'content' => 'Advanced PHP concepts and patterns'],
    ];

    $tool = new SearchTool(
        documents: $documents,
        storage: ':memory:',
        returnContent: false
    );

    $result = $tool->execute(['query' => 'PHP']);

    expect($result)->toBeArray()
        ->toHaveKey('hits')
        ->toHaveKey('results');

    expect($result['hits'])->toBeGreaterThan(0);
    expect($result['results'])->toBeArray();

    // Should find documents containing PHP
    $ids = array_column($result['results'], 'id');
    expect($ids)->toContain(1);
    expect($ids)->toContain(3);
});

test('search tool returns full content when configured', function () {
    $documents = [
        ['id' => 1, 'title' => 'Test Doc', 'content' => 'This is a test document'],
    ];

    $tool = new SearchTool(
        documents: $documents,
        storage: ':memory:',
        returnContent: true
    );

    $result = $tool->execute(['query' => 'test']);

    expect($result['results'][0])->toHaveKey('document');
    expect($result['results'][0]['document'])->toBe($documents[0]);
});

test('search tool supports fuzzy search', function () {
    $documents = [
        ['id' => 1, 'content' => 'programming'],
    ];

    $tool = new SearchTool(
        documents: $documents,
        storage: ':memory:',
        fuzzy: true,
        fuzziness: 2
    );

    // Should match "programing" (typo) with fuzzy search
    $result = $tool->execute(['query' => 'programing']);

    expect($result['hits'])->toBeGreaterThan(0);
});

test('search tool respects limit parameter', function () {
    $documents = array_map(
        fn ($i) => ['id' => $i, 'content' => "Document $i with searchable content"],
        range(1, 20)
    );

    $tool = new SearchTool(
        documents: $documents,
        storage: ':memory:',
        maxResults: 10
    );

    $result = $tool->execute(['query' => 'searchable', 'limit' => 5]);

    expect($result['results'])->toHaveCount(5);
});

test('search tool validates limit range', function () {
    $documents = [['id' => 1, 'content' => 'test']];

    $tool = new SearchTool(documents: $documents, storage: ':memory:');

    expect(fn () => $tool->execute(['query' => 'test', 'limit' => 0]))
        ->toThrow(RuntimeException::class, 'Limit must be between 1 and 100');

    expect(fn () => $tool->execute(['query' => 'test', 'limit' => 101]))
        ->toThrow(RuntimeException::class, 'Limit must be between 1 and 100');
});

test('search tool throws when query parameter is missing', function () {
    $documents = [['id' => 1, 'content' => 'test']];
    $tool = new SearchTool(documents: $documents, storage: ':memory:');

    expect(fn () => $tool->execute([]))
        ->toThrow(RuntimeException::class, 'Query parameter is required');
});

test('search tool indexes files from paths', function () {
    // Create test files
    file_put_contents($this->tempDir.'/doc1.txt', 'This is document one about PHP');
    file_put_contents($this->tempDir.'/doc2.md', 'This is document two about JavaScript');

    $tool = new SearchTool(
        paths: [$this->tempDir],
        storage: ':memory:',
        returnContent: false
    );

    $result = $tool->execute(['query' => 'PHP']);

    expect($result['hits'])->toBeGreaterThan(0);
    expect($result['results'])->not->toBeEmpty();
});

test('search tool throws when paths do not exist', function () {
    $tool = new SearchTool(
        paths: ['/nonexistent/path'],
        storage: ':memory:'
    );

    expect(fn () => $tool->execute(['query' => 'test']))
        ->toThrow(RuntimeException::class, 'Path not found');
});

test('search tool throws when no files found in paths', function () {
    // Empty directory
    $tool = new SearchTool(
        paths: [$this->tempDir],
        storage: ':memory:'
    );

    expect(fn () => $tool->execute(['query' => 'test']))
        ->toThrow(RuntimeException::class, 'No files found in provided paths');
});

test('search tool only indexes text files from paths', function () {
    // Create both text and binary files
    file_put_contents($this->tempDir.'/doc.txt', 'Text content');
    file_put_contents($this->tempDir.'/image.jpg', 'binary data');
    file_put_contents($this->tempDir.'/data.json', '{"key": "value"}');

    $tool = new SearchTool(
        paths: [$this->tempDir],
        storage: ':memory:',
        returnContent: false
    );

    $result = $tool->execute(['query' => 'content']);

    // Should find the text file but not the jpg
    expect($result['results'])->not->toBeEmpty();
});

test('search tool validates document structure', function () {
    $documents = [
        ['id' => 1, 'content' => 'valid'],
        ['content' => 'missing id'], // Invalid
    ];

    $tool = new SearchTool(
        documents: $documents,
        storage: ':memory:'
    );

    expect(fn () => $tool->execute(['query' => 'test']))
        ->toThrow(RuntimeException::class, "missing required 'id' field");
});

test('search tool safely indexes document fields that require SQL identifier quoting', function () {
    $tool = SearchTool::fromDocuments([[
        'id' => 1,
        'select' => 'reserved keyword',
        'display name' => 'quoted field searchable',
    ]], returnContent: true);

    $result = $tool->execute(['query' => 'searchable']);

    expect($result['hits'])->toBe(1)
        ->and($result['results'][0]['document']['display name'])->toBe('quoted field searchable');
});

test('search tool has correct metadata', function () {
    $tool = new SearchTool(
        documents: [['id' => 1, 'content' => 'test']],
        storage: ':memory:'
    );

    expect($tool->name())->toBe('search');
    expect($tool->description())->toContain('Search through indexed documents');
    expect($tool->parameters())->toHaveKey('properties');
    expect($tool->parameters()['required'])->toBe(['query']);
});

test('search tool parameters include all expected fields', function () {
    $tool = new SearchTool(
        documents: [['id' => 1, 'content' => 'test']],
        storage: ':memory:'
    );

    $params = $tool->parameters();

    expect($params['properties'])->toHaveKey('query');
    expect($params['properties'])->toHaveKey('limit');
    expect($params['properties'])->toHaveKey('fuzzy');
    expect($params['properties'])->toHaveKey('with_content');
});

test('search tool returns results with scores', function () {
    $documents = [
        ['id' => 1, 'content' => 'PHP programming'],
        ['id' => 2, 'content' => 'JavaScript programming'],
    ];

    $tool = new SearchTool(
        documents: $documents,
        storage: ':memory:',
        returnContent: false
    );

    $result = $tool->execute(['query' => 'programming']);

    foreach ($result['results'] as $item) {
        expect($item)->toHaveKey('id');
        expect($item)->toHaveKey('score');
        expect($item['score'])->toBeNumeric();
    }
});

test('search tool includes execution time in results', function () {
    $documents = [['id' => 1, 'content' => 'test content']];

    $tool = new SearchTool(
        documents: $documents,
        storage: ':memory:'
    );

    $result = $tool->execute(['query' => 'test']);

    expect($result)->toHaveKey('execution_time');
});

test('search tool can override fuzzy setting per query', function () {
    $documents = [['id' => 1, 'content' => 'exact match required']];

    $tool = new SearchTool(
        documents: $documents,
        storage: ':memory:',
        fuzzy: true // Default is fuzzy
    );

    // Should be able to disable fuzzy for specific query
    $result = $tool->execute(['query' => 'exact', 'fuzzy' => false]);

    expect($result)->toBeArray();
});

test('search tool can override return content per query', function () {
    $documents = [['id' => 1, 'content' => 'test']];

    $tool = new SearchTool(
        documents: $documents,
        storage: ':memory:',
        returnContent: false // Default is IDs only
    );

    // Should be able to request content for specific query
    $result = $tool->execute(['query' => 'test', 'with_content' => true]);

    expect($result['results'][0])->toHaveKey('document');
});

// Helper function tests
test('search helper function creates search tool', function () {
    $documents = [['id' => 1, 'content' => 'test']];

    $tool = search(documents: $documents);

    expect($tool)->toBeInstanceOf(SearchTool::class);
    expect($tool->name())->toBe('search');
});

test('searchIndex helper function creates tool with index path', function () {
    // Create a dummy index file
    $indexPath = $this->tempDir.'/test.index';
    touch($indexPath);

    $tool = searchIndex($indexPath);

    expect($tool)->toBeInstanceOf(SearchTool::class);
});

test('searchDocuments helper function creates in-memory tool', function () {
    $documents = [['id' => 1, 'content' => 'test']];

    $tool = searchDocuments($documents);

    expect($tool)->toBeInstanceOf(SearchTool::class);

    // Should work immediately
    $result = $tool->execute(['query' => 'test']);
    expect($result['results'])->not->toBeEmpty();
});

test('searchDocuments helper returns content by default', function () {
    $documents = [['id' => 1, 'content' => 'test document']];

    $tool = searchDocuments($documents);
    $result = $tool->execute(['query' => 'test']);

    // returnContent should be true by default
    expect($result['results'][0])->toHaveKey('document');
});

test('search tool cleans up its temp files on destruct', function () {
    $tool = new SearchTool(documents: [['id' => 1, 'content' => 'cleanup test']]);
    $tool->execute(['query' => 'cleanup']);

    $prop = new ReflectionProperty($tool, 'tempFiles');
    $files = $prop->getValue($tool);

    expect($files)->not->toBeEmpty();
    foreach ($files as $file) {
        expect(file_exists($file))->toBeTrue();
    }

    unset($tool);
    gc_collect_cycles();

    foreach ($files as $file) {
        expect(file_exists($file))->toBeFalse();
    }
});

test('fromDocuments factory creates a working tool', function () {
    $tool = SearchTool::fromDocuments([['id' => 1, 'content' => 'factory test']], returnContent: true);

    $result = $tool->execute(['query' => 'factory']);

    expect($result['hits'])->toBeGreaterThan(0);
    expect($result['results'][0])->toHaveKey('document');
});

test('fromPaths factory creates a working tool', function () {
    file_put_contents($this->tempDir.'/doc.txt', 'searchable path factory content');

    $tool = SearchTool::fromPaths([$this->tempDir]);

    $result = $tool->execute(['query' => 'factory']);

    expect($result['hits'])->toBeGreaterThan(0);
});

test('fromIndex factory creates a working tool', function () {
    // Build an index in a persistent storage dir first
    $builder = new SearchTool(
        documents: [['id' => 1, 'content' => 'prebuilt index content']],
        storage: $this->tempDir
    );
    $builder->execute(['query' => 'prebuilt']);

    $indexFile = null;
    foreach (scandir($this->tempDir) as $file) {
        if (str_ends_with($file, '.index')) {
            $indexFile = $this->tempDir.'/'.$file;
        }
    }

    expect($indexFile)->not->toBeNull();

    $tool = SearchTool::fromIndex($indexFile);
    $result = $tool->execute(['query' => 'prebuilt']);

    expect($result['hits'])->toBeGreaterThan(0);
});

test('user-provided storage keeps its index files after destruct', function () {
    $tool = new SearchTool(
        documents: [['id' => 1, 'content' => 'persistent storage']],
        storage: $this->tempDir
    );
    $tool->execute(['query' => 'persistent']);
    unset($tool);
    gc_collect_cycles();

    $indexFiles = array_filter(scandir($this->tempDir), fn ($f) => str_ends_with($f, '.index'));

    expect($indexFiles)->not->toBeEmpty();
});

test('indexing does not write progress output to the caller', function () {
    $tool = SearchTool::fromDocuments([
        ['id' => 1, 'content' => 'quiet indexing'],
    ]);

    ob_start();
    try {
        $result = $tool->execute(['query' => 'quiet']);
    } finally {
        $output = ob_get_clean();
    }

    expect($result['hits'])->toBe(1)
        ->and($output)->toBe('');
});
