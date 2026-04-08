<?php
/**
 * Clean Room CMS - Vector Search Integration
 *
 * Bridges the AI Client (embeddings) with php-vector-store (storage + search).
 * Provides:
 *   1. Automatic post indexing on create/update
 *   2. Semantic search (find content by meaning, not just keywords)
 *   3. Hybrid search (vector + BM25 full-text fusion)
 *   4. RAG context builder (retrieve relevant content for AI prompts)
 *   5. Abilities for AI agents (semantic_search, find_similar)
 *
 * Usage:
 *   cr_vectors()->search('posts', 'How do I deploy PHP apps?', limit: 5);
 *   cr_vectors()->index_post($post_id);
 *   cr_vectors()->rag_context('Explain caching strategies', max_tokens: 2000);
 */

use PHPVectorStore\VectorStore;
use PHPVectorStore\BM25\Index as BM25Index;
use PHPVectorStore\HybridSearch;
use PHPVectorStore\HybridMode;
use PHPVectorStore\Distance;

class CR_Vectors {
    private ?VectorStore $store = null;
    private ?BM25Index $bm25 = null;
    private ?HybridSearch $hybrid = null;
    private int $dimensions;
    private string $storage_path;
    private static ?CR_Vectors $instance = null;

    // Embedding model dimensions by provider
    private const MODEL_DIMENSIONS = [
        'text-embedding-3-small' => 1536,
        'text-embedding-3-large' => 3072,
        'text-embedding-ada-002' => 1536,
        'voyage-3'               => 1024,
        'voyage-3-lite'          => 512,
        'nomic-embed-text'       => 768,
        'all-minilm'             => 384,
        'mxbai-embed-large'      => 1024,
    ];

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->storage_path = (defined('CR_CONTENT_PATH') ? CR_CONTENT_PATH : __DIR__ . '/../../content') . '/vectors';
        $this->dimensions = (int) (get_option('cr_vector_dimensions', 1536) ?: 1536);
    }

    /**
     * Get the vector store instance (lazy-loaded).
     */
    public function store(): VectorStore {
        if ($this->store === null) {
            if (!is_dir($this->storage_path)) {
                mkdir($this->storage_path, 0755, true);
            }
            $this->store = new VectorStore($this->storage_path, $this->dimensions);
        }
        return $this->store;
    }

    /**
     * Get the BM25 index instance (lazy-loaded).
     */
    public function bm25(): BM25Index {
        if ($this->bm25 === null) {
            $this->bm25 = new BM25Index();
            // Load persisted BM25 index if exists
            $bm25_path = $this->storage_path . '/bm25';
            if (is_dir($bm25_path)) {
                foreach (['posts', 'pages'] as $collection) {
                    $file = $bm25_path . '/' . $collection . '.bm25';
                    if (file_exists($file)) {
                        try { $this->bm25->load($bm25_path, $collection); } catch (\Throwable $e) {}
                    }
                }
            }
        }
        return $this->bm25;
    }

    /**
     * Get the hybrid search instance.
     */
    public function hybrid(): HybridSearch {
        if ($this->hybrid === null) {
            $mode = get_option('cr_vector_hybrid_mode', 'rrf') === 'weighted'
                ? HybridMode::Weighted
                : HybridMode::RRF;
            $this->hybrid = new HybridSearch($this->store(), $this->bm25(), $mode);
        }
        return $this->hybrid;
    }

    // ===========================
    // Embedding Generation
    // ===========================

    /**
     * Generate an embedding vector for text using the configured AI provider.
     */
    public function embed(string $text): ?array {
        $text = trim($text);
        if (empty($text)) return null;

        // Truncate to ~8000 tokens (~32000 chars) to stay within model limits
        if (mb_strlen($text) > 32000) {
            $text = mb_substr($text, 0, 32000);
        }

        $provider = get_option('cr_vector_embed_provider', 'openai');
        $model = get_option('cr_vector_embed_model', 'text-embedding-3-small');

        $connector = cr_ai_client()->get_connector($provider);
        if (!$connector) return null;

        if ($provider === 'openai') {
            return $this->embed_openai($connector, $text, $model);
        } elseif ($provider === 'ollama') {
            return $this->embed_ollama($text, $model);
        }

        return null;
    }

    private function embed_openai(CR_AI_Connector $connector, string $text, string $model): ?array {
        // OpenAI embedding API is separate from chat completions
        $config = get_option('cr_ai_connectors', []);
        $api_key = $config['openai']['api_key'] ?? '';
        $base_url = $config['openai']['base_url'] ?? 'https://api.openai.com/v1';

        $body = json_encode([
            'model' => $model,
            'input' => $text,
        ]);

        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\nAuthorization: Bearer {$api_key}\r\n",
                'content' => $body,
                'timeout' => 30,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $response = @file_get_contents(rtrim($base_url, '/') . '/embeddings', false, $context);
        if ($response === false) return null;

        $data = json_decode($response, true);
        return $data['data'][0]['embedding'] ?? null;
    }

    private function embed_ollama(string $text, string $model): ?array {
        $config = get_option('cr_ai_connectors', []);
        $base_url = $config['ollama']['base_url'] ?? 'http://localhost:11434';

        $body = json_encode(['model' => $model, 'input' => $text]);

        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n",
                'content' => $body,
                'timeout' => 60,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents(rtrim($base_url, '/') . '/api/embed', false, $context);
        if ($response === false) return null;

        $data = json_decode($response, true);
        return $data['embeddings'][0] ?? null;
    }

    // ===========================
    // Post Indexing
    // ===========================

    /**
     * Index a post: generate embedding and store in vector DB + BM25.
     */
    public function index_post(int $post_id): bool {
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') return false;

        // Build text for embedding
        $text = $this->post_to_text($post);
        if (empty($text)) return false;

        // Generate embedding
        $vector = $this->embed($text);
        if (!$vector) return false;

        $collection = $this->post_type_collection($post->post_type);

        // Store in vector DB
        $this->store()->set($collection, (string) $post_id, $vector, [
            'post_id'    => (int) $post->ID,
            'title'      => $post->post_title,
            'post_type'  => $post->post_type,
            'author'     => (int) $post->post_author,
            'date'       => $post->post_date,
            'excerpt'    => mb_substr(strip_tags($post->post_content), 0, 300),
        ]);
        $this->store()->flush();

        // Index in BM25 for hybrid search
        $this->bm25()->addDocument($collection, (string) $post_id, $text);
        $this->save_bm25($collection);

        // Store embedding generation timestamp
        update_post_meta($post_id, '_vector_indexed_at', gmdate('Y-m-d H:i:s'));

        do_action('cr_post_indexed', $post_id, $collection);

        return true;
    }

    /**
     * Remove a post from the vector index.
     */
    public function deindex_post(int $post_id, string $post_type = 'post'): bool {
        $collection = $this->post_type_collection($post_type);

        $this->store()->remove($collection, (string) $post_id);
        $this->store()->flush();

        $this->bm25()->removeDocument($collection, (string) $post_id);
        $this->save_bm25($collection);

        delete_post_meta($post_id, '_vector_indexed_at');

        return true;
    }

    /**
     * Reindex all published posts.
     */
    public function reindex_all(string $post_type = 'post', ?callable $progress = null): int {
        $db = cr_db();
        $ids = $db->get_col($db->prepare(
            "SELECT ID FROM `{$db->prefix}posts` WHERE post_type = %s AND post_status = 'publish' ORDER BY ID ASC",
            $post_type
        ));

        $indexed = 0;
        $total = count($ids);

        foreach ($ids as $id) {
            if ($this->index_post((int) $id)) {
                $indexed++;
            }
            if ($progress) {
                $progress($indexed, $total, (int) $id);
            }
        }

        return $indexed;
    }

    // ===========================
    // Search
    // ===========================

    /**
     * Semantic search: find content by meaning.
     */
    public function search(string $collection, string $query, int $limit = 5, bool $hybrid = true): array {
        $vector = $this->embed($query);
        if (!$vector) return [];

        if ($hybrid && $this->bm25()->count($collection) > 0) {
            $results = $this->hybrid()->search($collection, $vector, $query, $limit, [
                'vectorWeight' => 0.7,
                'textWeight'   => 0.3,
            ]);
        } else {
            $results = $this->store()->search($collection, $vector, $limit);
        }

        return $this->enrich_results($results);
    }

    /**
     * Find posts similar to a given post.
     */
    public function find_similar(int $post_id, int $limit = 5): array {
        $post = get_post($post_id);
        if (!$post) return [];

        $collection = $this->post_type_collection($post->post_type);
        $existing = $this->store()->get($collection, (string) $post_id);

        if (!$existing) {
            // Post not indexed yet - index it first
            $this->index_post($post_id);
            $existing = $this->store()->get($collection, (string) $post_id);
        }

        if (!$existing || empty($existing['vector'])) return [];

        $results = $this->store()->search($collection, $existing['vector'], $limit + 1);

        // Remove the source post from results
        $results = array_filter($results, fn($r) => $r['id'] !== (string) $post_id);
        $results = array_slice($results, 0, $limit);

        return $this->enrich_results($results);
    }

    /**
     * Search across all content types.
     */
    public function search_all(string $query, int $limit = 10): array {
        $vector = $this->embed($query);
        if (!$vector) return [];

        $collections = $this->store()->collections();
        if (empty($collections)) return [];

        $results = $this->store()->searchAcross($collections, $vector, $limit);

        return $this->enrich_results($results);
    }

    // ===========================
    // RAG (Retrieval-Augmented Generation)
    // ===========================

    /**
     * Build context for RAG: retrieve relevant content and format for AI prompts.
     */
    public function rag_context(string $query, int $max_chunks = 5, int $max_chars = 4000): string {
        $results = $this->search('posts', $query, $max_chunks);

        if (empty($results)) return '';

        $context_parts = [];
        $total_chars = 0;

        foreach ($results as $result) {
            $post = get_post((int) $result['post_id']);
            if (!$post) continue;

            $content = strip_tags($post->post_content);
            $remaining = $max_chars - $total_chars;
            if ($remaining <= 0) break;

            if (mb_strlen($content) > $remaining) {
                $content = mb_substr($content, 0, $remaining) . '...';
            }

            $context_parts[] = "### {$post->post_title}\n{$content}";
            $total_chars += mb_strlen($content);
        }

        if (empty($context_parts)) return '';

        return "Relevant content from the site:\n\n" . implode("\n\n---\n\n", $context_parts);
    }

    /**
     * AI prompt with RAG: auto-retrieve context and send to AI.
     */
    public function ask(string $question, array $options = []): CR_AI_Response {
        $context = $this->rag_context($question,
            max_chunks: $options['max_chunks'] ?? 5,
            max_chars: $options['max_chars'] ?? 4000
        );

        $builder = cr_ai()->with_guidelines();

        if (!empty($context)) {
            $builder->system("Use the following site content to answer questions. If the content doesn't cover the topic, say so.\n\n{$context}");
        }

        return $builder
            ->user($question)
            ->temperature($options['temperature'] ?? 0.3)
            ->max_tokens($options['max_tokens'] ?? 1024)
            ->send();
    }

    // ===========================
    // Stats & Management
    // ===========================

    /**
     * Get vector index statistics.
     */
    public function stats(): array {
        $store_stats = $this->store()->stats();
        $collections = $this->store()->collections();

        $collection_stats = [];
        foreach ($collections as $name) {
            $collection_stats[$name] = [
                'vectors' => $this->store()->count($name),
                'bm25_docs' => $this->bm25()->count($name),
            ];
        }

        return [
            'dimensions'    => $this->dimensions,
            'storage_path'  => $this->storage_path,
            'total_vectors' => $store_stats['total_vectors'] ?? 0,
            'storage_bytes' => $store_stats['total_bytes'] ?? 0,
            'collections'   => $collection_stats,
        ];
    }

    /**
     * Drop all vectors for a collection.
     */
    public function drop(string $collection): void {
        $this->store()->drop($collection);

        // Also clear BM25
        $bm25_path = $this->storage_path . '/bm25';
        $file = $bm25_path . '/' . $collection . '.bm25';
        if (file_exists($file)) unlink($file);
    }

    /**
     * Reset for testing.
     */
    public function reset(): void {
        $this->store = null;
        $this->bm25 = null;
        $this->hybrid = null;
        self::$instance = null;
    }

    // ===========================
    // Internals
    // ===========================

    private function post_to_text(object $post): string {
        $parts = [];

        if (!empty($post->post_title)) {
            $parts[] = $post->post_title;
        }

        $content = strip_tags($post->post_content);
        if (!empty($content)) {
            $parts[] = $content;
        }

        if (!empty($post->post_excerpt)) {
            $parts[] = $post->post_excerpt;
        }

        // Add taxonomy terms
        foreach (['category', 'post_tag'] as $tax) {
            $terms = get_the_terms((int) $post->ID, $tax);
            if (!empty($terms)) {
                $names = array_map(fn($t) => $t->name, $terms);
                $parts[] = implode(', ', $names);
            }
        }

        return implode("\n\n", $parts);
    }

    private function post_type_collection(string $post_type): string {
        return match ($post_type) {
            'page' => 'pages',
            default => 'posts',
        };
    }

    private function enrich_results(array $results): array {
        return array_map(function ($r) {
            $data = is_array($r) ? $r : (array) $r;

            // Flatten SearchResult objects
            $meta = $data['metadata'] ?? [];
            return array_merge($meta, [
                'vector_id'   => $data['id'] ?? '',
                'score'       => round((float) ($data['score'] ?? 0), 4),
                'collection'  => $data['collection'] ?? null,
            ]);
        }, $results);
    }

    private function save_bm25(string $collection): void {
        $bm25_path = $this->storage_path . '/bm25';
        if (!is_dir($bm25_path)) mkdir($bm25_path, 0755, true);
        try { $this->bm25()->save($bm25_path, $collection); } catch (\Throwable $e) {}
    }
}

// ===========================
// Global accessor
// ===========================

function cr_vectors(): CR_Vectors {
    return CR_Vectors::instance();
}

// ===========================
// Auto-indexing hooks
// ===========================

/**
 * Auto-index posts when created/updated (if vector indexing is enabled).
 */
function cr_vector_auto_index(int $post_id, object $post, bool $update): void {
    if (!get_option('cr_vector_auto_index', false)) return;
    if ($post->post_status !== 'publish') return;
    if (in_array($post->post_type, ['revision', 'nav_menu_item'])) return;

    // Queue async indexing instead of blocking the request
    if (class_exists('CR_Queue') && get_option('cr_vector_async_index', true)) {
        cr_queue_push('cr_vector_index_job', [$post_id], [
            'group' => 'vectors',
            'priority' => 5,
        ]);
    } else {
        cr_vectors()->index_post($post_id);
    }
}

add_action('save_post', 'cr_vector_auto_index', 20, 3);

/**
 * Deindex when post is deleted.
 */
add_action('before_delete_post', function (int $post_id, object $post) {
    if (!get_option('cr_vector_auto_index', false)) return;
    cr_vectors()->deindex_post($post_id, $post->post_type);
}, 10, 2);

/**
 * Queue job handler for async indexing.
 */
add_action('cr_vector_index_job', function (int $post_id) {
    cr_vectors()->index_post($post_id);
});

// ===========================
// Register AI Abilities
// ===========================

add_action('cr_register_abilities', function () {
    register_ability('semantic_search', [
        'description'  => 'Search site content by meaning using vector similarity. Better than keyword search for natural language queries.',
        'category'     => 'search',
        'permission'   => 'read',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'query'   => ['type' => 'string', 'description' => 'Natural language search query'],
                'limit'   => ['type' => 'integer', 'description' => 'Max results (default 5)'],
            ],
            'required' => ['query'],
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'results' => ['type' => 'array'],
                'count'   => ['type' => 'integer'],
            ],
        ],
        'callback' => function (array $input): array {
            $results = cr_vectors()->search('posts', $input['query'], (int) ($input['limit'] ?? 5));
            return ['results' => $results, 'count' => count($results)];
        },
    ]);

    register_ability('find_similar_posts', [
        'description'  => 'Find posts similar to a given post based on content meaning.',
        'category'     => 'search',
        'permission'   => 'read',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'post_id' => ['type' => 'integer', 'description' => 'Source post ID'],
                'limit'   => ['type' => 'integer', 'description' => 'Max results (default 5)'],
            ],
            'required' => ['post_id'],
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => ['similar' => ['type' => 'array']],
        ],
        'callback' => function (array $input): array {
            $results = cr_vectors()->find_similar((int) $input['post_id'], (int) ($input['limit'] ?? 5));
            return ['similar' => $results];
        },
    ]);

    register_ability('vector_stats', [
        'description'  => 'Get statistics about the vector search index.',
        'category'     => 'site',
        'permission'   => 'manage_options',
        'input_schema' => ['type' => 'object', 'properties' => new \stdClass()],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'dimensions'    => ['type' => 'integer'],
                'total_vectors' => ['type' => 'integer'],
                'collections'   => ['type' => 'object'],
            ],
        ],
        'callback' => function (): array {
            return cr_vectors()->stats();
        },
    ]);
});
