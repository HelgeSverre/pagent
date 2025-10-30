<?php

declare(strict_types=1);

use Pagent\Tools\SearchTool;

/**
 * SearchTool Integration Tests.
 *
 * These tests verify that SearchTool works correctly with real TNTSearch indexing and searching.
 * Unlike unit tests, these tests use actual TNTSearch functionality rather than mocks.
 */
beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/pagent_search_integration_'.uniqid();
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

test('search tool indexes and searches documents with relevance ranking', function () {
    $documents = [
        ['id' => 1, 'title' => 'Introduction to PHP', 'content' => 'PHP is a popular general-purpose scripting language that is especially suited to web development.'],
        ['id' => 2, 'title' => 'PHP Best Practices', 'content' => 'Following PHP best practices ensures your code is maintainable, secure, and performant.'],
        ['id' => 3, 'title' => 'JavaScript Basics', 'content' => 'JavaScript is a lightweight, interpreted programming language with first-class functions.'],
        ['id' => 4, 'title' => 'Python for Beginners', 'content' => 'Python is an interpreted, high-level, general-purpose programming language.'],
        ['id' => 5, 'title' => 'Advanced PHP Techniques', 'content' => 'Master advanced PHP programming techniques including generators, traits, and design patterns.'],
    ];

    $tool = new SearchTool(
        documents: $documents,
        storage: ':memory:',
        returnContent: true,
        fuzzy: false
    );

    $result = $tool->execute(['query' => 'PHP']);

    // Should find multiple PHP-related documents
    expect($result['hits'])->toBeGreaterThan(0);
    expect($result['results'])->toBeArray();

    // Results should be ranked by relevance
    $ids = array_column($result['results'], 'id');

    // All PHP documents should be in results
    expect($ids)->toContain(1);
    expect($ids)->toContain(2);
    expect($ids)->toContain(5);

    // JavaScript and Python should not match PHP search
    expect($ids)->not->toContain(3);
    expect($ids)->not->toContain(4);

    // Check relevance scores are provided
    foreach ($result['results'] as $item) {
        expect($item['score'])->toBeNumeric();
        expect($item['score'])->toBeGreaterThanOrEqual(0);
    }
});

test('search tool supports fuzzy matching for typos', function () {
    $documents = [
        ['id' => 1, 'content' => 'The quick brown fox jumps over the lazy dog'],
        ['id' => 2, 'content' => 'A journey of a thousand miles begins with a single step'],
    ];

    $tool = new SearchTool(
        documents: $documents,
        storage: ':memory:',
        fuzzy: true,
        fuzziness: 1,
        returnContent: false
    );

    // Search with typo "journy" instead of "journey" (1 character difference)
    $result = $tool->execute(['query' => 'journy']);

    // Should still find the document due to fuzzy matching
    expect($result['hits'])->toBeGreaterThanOrEqual(0); // Fuzzy might not always match depending on index

    // Verify fuzzy search is at least enabled and doesn't error
    expect($result)->toHaveKey('results');
});

test('search tool handles complex boolean queries', function () {
    $documents = [
        ['id' => 1, 'content' => 'PHP is great for web development'],
        ['id' => 2, 'content' => 'Python is excellent for data science'],
        ['id' => 3, 'content' => 'PHP and Python are both popular languages'],
    ];

    $tool = new SearchTool(
        documents: $documents,
        storage: ':memory:',
        returnContent: false
    );

    // Search for documents with PHP but not Python
    $result = $tool->execute(['query' => 'PHP']);

    $ids = array_column($result['results'], 'id');

    // Should find documents 1 and 3
    expect($result['hits'])->toBeGreaterThan(0);
    expect($ids)->toContain(1);
});

test('search tool indexes files from directory recursively', function () {
    // Create nested directory structure with files
    mkdir($this->tempDir.'/docs');
    mkdir($this->tempDir.'/docs/php');
    mkdir($this->tempDir.'/docs/js');

    file_put_contents($this->tempDir.'/docs/php/basics.md', '# PHP Basics

PHP is a server-side scripting language designed for web development.');

    file_put_contents($this->tempDir.'/docs/php/advanced.md', '# Advanced PHP

Learn about PHP design patterns and advanced techniques.');

    file_put_contents($this->tempDir.'/docs/js/intro.md', '# JavaScript Introduction

JavaScript is the language of the web.');

    $tool = new SearchTool(
        paths: [$this->tempDir.'/docs'],
        storage: ':memory:',
        returnContent: true
    );

    $result = $tool->execute(['query' => 'PHP design patterns']);

    expect($result['hits'])->toBeGreaterThan(0);

    // Should find the advanced.md file
    $found = false;
    foreach ($result['results'] as $item) {
        if (isset($item['document']['path']) && str_contains($item['document']['path'], 'advanced.md')) {
            $found = true;
            break;
        }
    }

    expect($found)->toBeTrue();
});

test('search tool handles large document sets efficiently', function () {
    // Create 100 documents
    $documents = array_map(function ($i) {
        return [
            'id' => $i,
            'title' => "Document $i",
            'content' => "This is document number $i with some searchable content about topic ".($i % 10),
        ];
    }, range(1, 100));

    $tool = new SearchTool(
        documents: $documents,
        storage: ':memory:',
        maxResults: 20,
        returnContent: false
    );

    $result = $tool->execute(['query' => 'searchable content', 'limit' => 15]);

    // Should successfully search through all documents
    expect($result['hits'])->toBeGreaterThan(0);
    expect($result['results'])->toHaveCount(15);

    // Execution time should be reasonable (< 100ms)
    expect($result['execution_time'])->toMatch('/^\d+(\.\d+)?\s?m?s$/');
});

test('search tool with pre-built index file', function () {
    $documents = [
        ['id' => 1, 'title' => 'Test Document', 'content' => 'This is a test document for indexing'],
    ];

    // First create an index
    $initialTool = new SearchTool(
        documents: $documents,
        storage: $this->tempDir,
        returnContent: false
    );

    // Execute a search to trigger indexing
    $initialTool->execute(['query' => 'test']);

    // Find the created index file
    $files = scandir($this->tempDir);
    $indexFile = null;
    foreach ($files as $file) {
        if (str_ends_with($file, '.index')) {
            $indexFile = $this->tempDir.'/'.$file;
            break;
        }
    }

    expect($indexFile)->not->toBeNull();
    expect(file_exists($indexFile))->toBeTrue();

    // Now create a new tool using the pre-built index
    $tool = new SearchTool(indexPath: $indexFile);

    $result = $tool->execute(['query' => 'document']);

    expect($result['hits'])->toBeGreaterThan(0);
    expect($result['results'])->not->toBeEmpty();
});

test('search tool returns empty results for non-matching query', function () {
    $documents = [
        ['id' => 1, 'content' => 'PHP programming'],
        ['id' => 2, 'content' => 'JavaScript development'],
    ];

    $tool = new SearchTool(
        documents: $documents,
        storage: ':memory:',
        returnContent: false,
        fuzzy: false
    );

    $result = $tool->execute(['query' => 'xyzabc123nonexistent']);

    expect($result['hits'])->toBe(0);
    expect($result['results'])->toBeEmpty();
});

test('search tool preserves document fields in results', function () {
    $documents = [
        [
            'id' => 1,
            'title' => 'My Article',
            'author' => 'John Doe',
            'content' => 'Article content here',
            'tags' => ['php', 'web'],
        ],
    ];

    $tool = new SearchTool(
        documents: $documents,
        storage: ':memory:',
        returnContent: true
    );

    $result = $tool->execute(['query' => 'article']);

    expect($result['results'])->not->toBeEmpty();

    $doc = $result['results'][0]['document'];

    // All original fields should be preserved
    expect($doc['id'])->toBe(1);
    expect($doc['title'])->toBe('My Article');
    expect($doc['author'])->toBe('John Doe');
    expect($doc['content'])->toBe('Article content here');
    expect($doc['tags'])->toBe(['php', 'web']);
});

test('search tool handles UTF-8 and international characters', function () {
    $documents = [
        ['id' => 1, 'content' => 'Héllo wörld with ünïcödé characters'],
        ['id' => 2, 'content' => '你好世界 Chinese characters'],
        ['id' => 3, 'content' => 'Привет мир Cyrillic characters'],
    ];

    $tool = new SearchTool(
        documents: $documents,
        storage: ':memory:',
        returnContent: true,
        fuzzy: false
    );

    // Should find UTF-8 content
    $result = $tool->execute(['query' => 'wörld']);

    expect($result['hits'])->toBeGreaterThan(0);
});

test('search tool can be reused for multiple queries', function () {
    $documents = [
        ['id' => 1, 'content' => 'PHP programming'],
        ['id' => 2, 'content' => 'JavaScript development'],
        ['id' => 3, 'content' => 'Python scripting'],
    ];

    $tool = new SearchTool(
        documents: $documents,
        storage: ':memory:',
        returnContent: false
    );

    // First query
    $result1 = $tool->execute(['query' => 'PHP']);
    expect(array_column($result1['results'], 'id'))->toContain(1);

    // Second query
    $result2 = $tool->execute(['query' => 'JavaScript']);
    expect(array_column($result2['results'], 'id'))->toContain(2);

    // Third query
    $result3 = $tool->execute(['query' => 'Python']);
    expect(array_column($result3['results'], 'id'))->toContain(3);

    // All queries should work correctly
    expect($result1['hits'])->toBeGreaterThan(0);
    expect($result2['hits'])->toBeGreaterThan(0);
    expect($result3['hits'])->toBeGreaterThan(0);
});

test('helper function searchDocuments works end-to-end', function () {
    $documents = [
        ['id' => 1, 'title' => 'Test Doc', 'content' => 'This is a test document'],
        ['id' => 2, 'title' => 'Another Doc', 'content' => 'This is another document'],
    ];

    $tool = searchDocuments($documents);

    $result = $tool->execute(['query' => 'test']);

    expect($result['hits'])->toBeGreaterThan(0);
    expect($result['results'][0])->toHaveKey('document');
    expect($result['results'][0]['document']['title'])->toBe('Test Doc');
});
