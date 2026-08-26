<?php

declare(strict_types=1);

namespace Pagent\Tools;

use Pagent\Exceptions\RuntimeException;
use TeamTNT\TNTSearch\TNTSearch;

final class SearchTool extends Tool
{
    private ?TNTSearch $tnt = null;

    private bool $initialized = false;

    /**
     * Whether the storage directory is a managed temp location whose
     * generated files should be cleaned up when the tool is destroyed.
     */
    private bool $ownsStorage = false;

    /**
     * Temp files created by this instance (staging sqlite dbs and, for managed
     * temp storage, generated index files). Removed on destruct.
     *
     * @var array<string>
     */
    private array $tempFiles = [];

    /**
     * Create a new SearchTool instance.
     *
     * @param  string|null  $indexPath  Path to pre-built index file
     * @param  array<int, array<string, mixed>>|null  $documents  Array of documents to index (each document must have 'id' and searchable fields)
     * @param  string|null  $query  SQL query to fetch documents from database (requires connection config)
     * @param  array<string>|null  $paths  File or directory paths to index
     * @param  array<string, mixed>  $connection  Database connection config for query-based indexing
     * @param  bool  $returnContent  Whether to return full document content (true) or just IDs (false)
     * @param  bool  $fuzzy  Enable fuzzy search by default
     * @param  int  $fuzziness  Levenshtein distance for fuzzy search (1-3)
     * @param  int  $maxResults  Maximum number of results to return
     * @param  string  $storage  Directory where index files are stored. Omit (or pass ':memory:')
     *                           for a managed temp directory whose generated files are deleted
     *                           when the tool is destroyed. NOTE: true in-memory SQLite indexes
     *                           are not supported - TNTSearch opens its own connections to the
     *                           index file, so ':memory:' is only an alias for ephemeral,
     *                           auto-cleaned file storage.
     * @param  string|null  $stemmer  Stemmer class (e.g., PorterStemmer::class)
     *
     * @example Pre-indexed search:
     *   SearchTool::fromIndex('docs/api.index')
     * @example Ephemeral document search (temp storage, auto-cleaned):
     *   SearchTool::fromDocuments($docs)
     * @example Database-backed search:
     *   new SearchTool(
     *       query: 'SELECT id, title, content FROM articles',
     *       connection: ['driver' => 'mysql', 'host' => 'localhost', 'database' => 'mydb']
     *   )
     * @example File/directory indexing:
     *   SearchTool::fromPaths(['docs/', 'articles/'])
     */
    public function __construct(
        private ?string $indexPath = null,
        private ?array $documents = null,
        private ?string $query = null,
        private ?array $paths = null,
        private array $connection = [],
        private bool $returnContent = false,
        private bool $fuzzy = true,
        private int $fuzziness = 2,
        private int $maxResults = 10,
        private string $storage = '',
        private ?string $stemmer = null,
    ) {
        // Validate that at least one document source is provided
        if ($this->indexPath === null && $this->documents === null && $this->query === null && $this->paths === null) {
            throw new RuntimeException('At least one document source must be provided (indexPath, documents, query, or paths)');
        }

        // Validate fuzzy search parameters
        if ($this->fuzziness < 1 || $this->fuzziness > 3) {
            throw new RuntimeException('Fuzziness must be between 1 and 3');
        }

        // ':memory:' is an alias for managed temp storage (see docblock): TNTSearch
        // cannot use in-memory SQLite because it opens separate connections to the
        // index file. Generated files are registered for cleanup instead.
        if ($this->storage === ':memory:') {
            $this->storage = '';
        }

        // Set default storage path if not provided and not using pre-built index
        if ($this->storage === '' && $this->indexPath === null) {
            $this->storage = sys_get_temp_dir().'/tntsearch';
            $this->ownsStorage = true;
        }
    }

    public function __destruct()
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * Create a SearchTool from a pre-built index file.
     */
    public static function fromIndex(
        string $path,
        bool $returnContent = false,
        bool $fuzzy = true,
        int $fuzziness = 2,
        int $maxResults = 10,
    ): self {
        return new self(
            indexPath: $path,
            returnContent: $returnContent,
            fuzzy: $fuzzy,
            fuzziness: $fuzziness,
            maxResults: $maxResults,
        );
    }

    /**
     * Create a SearchTool from an array of documents (each must have an 'id' field).
     *
     * @param  array<int, array<string, mixed>>  $docs
     */
    public static function fromDocuments(
        array $docs,
        bool $returnContent = false,
        bool $fuzzy = true,
        int $fuzziness = 2,
        int $maxResults = 10,
        string $storage = '',
        ?string $stemmer = null,
    ): self {
        return new self(
            documents: $docs,
            returnContent: $returnContent,
            fuzzy: $fuzzy,
            fuzziness: $fuzziness,
            maxResults: $maxResults,
            storage: $storage,
            stemmer: $stemmer,
        );
    }

    /**
     * Create a SearchTool that indexes files from the given file/directory paths.
     *
     * @param  array<string>  $paths
     */
    public static function fromPaths(
        array $paths,
        bool $returnContent = false,
        bool $fuzzy = true,
        int $fuzziness = 2,
        int $maxResults = 10,
        string $storage = '',
        ?string $stemmer = null,
    ): self {
        return new self(
            paths: $paths,
            returnContent: $returnContent,
            fuzzy: $fuzzy,
            fuzziness: $fuzziness,
            maxResults: $maxResults,
            storage: $storage,
            stemmer: $stemmer,
        );
    }

    public function name(): string
    {
        return 'search';
    }

    public function description(): string
    {
        return 'Search through indexed documents using full-text search with fuzzy matching and BM25 ranking. Returns relevant documents ranked by relevance score.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search query string. Supports boolean operators (AND, OR, NOT) and fuzzy matching.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of results to return (default: '.$this->maxResults.')',
                    'minimum' => 1,
                    'maximum' => 100,
                ],
                'fuzzy' => [
                    'type' => 'boolean',
                    'description' => 'Enable fuzzy search to find approximate matches (default: '.($this->fuzzy ? 'true' : 'false').')',
                ],
                'with_content' => [
                    'type' => 'boolean',
                    'description' => 'Return full document content instead of just IDs (default: '.($this->returnContent ? 'true' : 'false').')',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $params): mixed
    {
        $query = $this->requiredString($params, 'query');
        $limit = $this->optionalInt($params, 'limit', $this->maxResults);
        $fuzzy = $this->optionalBool($params, 'fuzzy', $this->fuzzy);
        $withContent = $this->optionalBool($params, 'with_content', $this->returnContent);

        // Validate limit
        if ($limit < 1 || $limit > 100) {
            throw new RuntimeException('Limit must be between 1 and 100');
        }

        // Initialize TNTSearch on first use (lazy initialization)
        if (! $this->initialized) {
            $this->initialize();
        }

        // Configure fuzzy search
        $tnt = $this->getTnt();
        if ($fuzzy) {
            $tnt->fuzziness(true);
            $tnt->setFuzzyDistance($this->fuzziness);
            $tnt->setFuzzyPrefixLength(2);
            $tnt->setFuzzyMaxExpansions(50);
        } else {
            $tnt->fuzziness(false);
        }

        // Perform search
        $results = $tnt->search($query, $limit);

        // Format results
        return $this->formatResults($results, $withContent);
    }

    private function getTnt(): TNTSearch
    {
        if ($this->tnt === null) {
            throw new RuntimeException('TNTSearch not initialized');
        }

        return $this->tnt;
    }

    private function initialize(): void
    {
        $this->tnt = new TNTSearch;

        // Initialize based on document source
        if ($this->indexPath !== null) {
            $this->initializeFromIndex();
        } elseif ($this->documents !== null) {
            $this->initializeFromDocuments();
        } elseif ($this->query !== null) {
            $this->initializeFromQuery();
        } elseif ($this->paths !== null) {
            $this->initializeFromPaths();
        }

        $this->initialized = true;
    }

    private function initializeFromIndex(): void
    {
        if ($this->indexPath === null || ! file_exists($this->indexPath)) {
            throw new RuntimeException('Index file not found: '.($this->indexPath ?? 'null'));
        }

        $tnt = $this->getTnt();
        $tnt->loadConfig([
            'storage' => dirname($this->indexPath),
        ]);

        $tnt->selectIndex(basename($this->indexPath));
    }

    private function initializeFromDocuments(): void
    {
        if (empty($this->documents)) {
            throw new RuntimeException('No documents provided for indexing');
        }

        // Validate document structure
        foreach ($this->documents as $i => $doc) {
            if (! isset($doc['id'])) {
                throw new RuntimeException("Document at index {$i} is missing required 'id' field");
            }
        }

        // Create a temporary SQLite database to hold documents
        // Note: We must use a file-based database, not :memory:, because TNTSearch
        // creates its own PDO connection and can't access an in-memory database
        $tempDb = $this->tempDir().'/docs_'.uniqid().'.sqlite';
        $this->tempFiles[] = $tempDb;

        // Create database and insert documents
        $pdo = new \PDO('sqlite:'.$tempDb);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Determine all fields from documents
        $fields = [];
        foreach ($this->documents as $doc) {
            $fields = array_merge($fields, array_keys($doc));
        }
        $fields = array_unique($fields);

        foreach ($fields as $field) {
            /** @phpstan-ignore-next-line Runtime input is not constrained by the property PHPDoc. */
            if (! is_string($field) || $field === '' || str_contains($field, "\0")) {
                throw new RuntimeException('Document field names must be non-empty strings without null bytes');
            }
        }

        // Create table
        $quotedFields = array_map($this->quoteIdentifier(...), $fields);
        $fieldDefs = array_map(static fn (string $field): string => "{$field} TEXT", $quotedFields);
        $pdo->exec('CREATE TABLE documents ('.implode(', ', $fieldDefs).')');

        // Insert documents
        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        $stmt = $pdo->prepare('INSERT INTO documents ('.implode(', ', $quotedFields).') VALUES ('.$placeholders.')');

        foreach ($this->documents as $doc) {
            $values = array_map(function ($field) use ($doc) {
                $value = $doc[$field] ?? null;
                if (is_array($value)) {
                    return json_encode($value);
                }
                if ($value === null) {
                    return '';
                }
                if (is_scalar($value)) {
                    return (string) $value;
                }

                return json_encode($value);
            }, $fields);
            $stmt->execute($values);
        }

        // Configure TNTSearch
        $config = [
            'driver' => 'sqlite',
            'database' => $tempDb,
            'storage' => $this->storage,
        ];

        if ($this->stemmer !== null) {
            $config['stemmer'] = $this->stemmer;
        }

        $tnt = $this->getTnt();
        $tnt->loadConfig($config);

        // Create index from database
        $indexName = 'documents_'.uniqid().'.index';
        if ($this->ownsStorage) {
            $this->tempFiles[] = rtrim($this->storage, '/').'/'.$indexName;
        }
        $indexer = $tnt->createIndex($indexName, true);
        $indexer->query('SELECT * FROM documents');
        $indexer->run();

        $tnt->selectIndex($indexName);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function tempDir(): string
    {
        return sys_get_temp_dir();
    }

    private function initializeFromQuery(): void
    {
        if (empty($this->connection)) {
            throw new RuntimeException('Database connection config is required for query-based indexing');
        }

        if (! isset($this->connection['driver'], $this->connection['host'], $this->connection['database'])) {
            throw new RuntimeException('Database connection must include driver, host, and database');
        }

        if ($this->query === null) {
            throw new RuntimeException('Query is required for query-based indexing');
        }

        $config = $this->connection;
        $config['storage'] = $this->storage;

        if ($this->stemmer !== null) {
            $config['stemmer'] = $this->stemmer;
        }

        $tnt = $this->getTnt();
        $tnt->loadConfig($config);

        // Create index from query
        $indexName = 'query_'.md5($this->query).'.index';
        if ($this->ownsStorage) {
            $this->tempFiles[] = rtrim($this->storage, '/').'/'.$indexName;
        }
        $indexer = $tnt->createIndex($indexName, true);
        $indexer->query($this->query);
        $indexer->run();

        $tnt->selectIndex($indexName);
    }

    private function initializeFromPaths(): void
    {
        if (empty($this->paths)) {
            throw new RuntimeException('No paths provided for indexing');
        }

        // Collect all files from paths
        $files = [];
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                $files[] = $path;
            } elseif (is_dir($path)) {
                $files = array_merge($files, $this->collectFiles($path));
            } else {
                throw new RuntimeException("Path not found: {$path}");
            }
        }

        if (empty($files)) {
            throw new RuntimeException('No files found in provided paths');
        }

        // Convert files to documents
        $documents = [];
        foreach ($files as $i => $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                throw new RuntimeException("Failed to read file: {$file}");
            }

            $documents[] = [
                'id' => $i + 1,
                'path' => $file,
                'filename' => basename($file),
                'content' => $content,
            ];
        }

        // Use document indexing with the collected files
        $this->documents = $documents;
        $this->initializeFromDocuments();
    }

    /**
     * Recursively collect files from a directory.
     *
     * @return array<string>
     */
    private function collectFiles(string $dir): array
    {
        $files = [];
        $items = scandir($dir);

        if ($items === false) {
            return [];
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.'/'.$item;

            if (is_file($path)) {
                // Only index text files
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (in_array($ext, ['txt', 'md', 'php', 'json', 'xml', 'html', 'css', 'js', 'yaml', 'yml'])) {
                    $files[] = $path;
                }
            } elseif (is_dir($path)) {
                $files = array_merge($files, $this->collectFiles($path));
            }
        }

        return $files;
    }

    /**
     * Format search results based on configuration.
     */
    private function formatResults(array $results, bool $withContent): array
    {
        $formatted = [
            'hits' => $results['hits'] ?? 0,
            'execution_time' => $results['execution_time'] ?? '0ms',
            'results' => [],
        ];

        $ids = isset($results['ids']) && is_array($results['ids']) ? $results['ids'] : [];
        $scores = isset($results['docScores']) && is_array($results['docScores']) ? $results['docScores'] : [];

        if (! $withContent) {
            // Return just IDs and scores
            foreach ($ids as $i => $id) {
                $formatted['results'][] = [
                    'id' => $id,
                    'score' => $scores[$i] ?? 0,
                ];
            }
        } else {
            // Return full document content
            foreach ($ids as $i => $id) {
                $doc = $this->getDocument($id);
                if ($doc !== null) {
                    $formatted['results'][] = [
                        'id' => $id,
                        'score' => $scores[$i] ?? 0,
                        'document' => $doc,
                    ];
                }
            }
        }

        return $formatted;
    }

    private function getDocument(int $id): ?array
    {
        // For document-based indexing, retrieve from stored documents
        if ($this->documents !== null) {
            foreach ($this->documents as $doc) {
                if ($doc['id'] === $id) {
                    return $doc;
                }
            }
        }

        // For index-based search, we can't retrieve full documents without additional storage
        // Return null to indicate document content is not available
        return null;
    }
}
