<?php

/**
 * @file
 * Configures pgvector + Search API hybrid search, facets, core search, MCP.
 *
 * Prerequisites: enable required modules, e.g.
 *   drush pm:enable -y search_node ai_provider_ollama ai_provider_openai \
 *     ai_search ai_vdb_provider_postgres search_api_db facets key hybrid_search
 *     mcp_server mcp_server_tool_bridge tool ai_automators token
 *
 * Environment (optional):
 *   OPENAI_API_KEY — OpenAI API key (stored via Key module; used for AI Search
 *     moderation default and optional AI Automators).
 *   OLLAMA_HOST — e.g. http://host.docker.internal (Docker on Mac) or
 *     http://127.0.0.1 for native PHP.
 *   OLLAMA_PORT — default 11434.
 *   OLLAMA_EMBEDDING_MODEL — default nomic-embed-text:latest (pull: ollama pull nomic-embed-text).
 *   OLLAMA_EMBEDDING_DIMENSIONS — vector size for that model (default 768).
 *   OLLAMA_CHAT_MODEL — tokenizer/chunking chat model id (default llama3.2:latest).
 *
 * Usage (recommended, inside the Drupal container where DB host "postgres" works):
 *   docker compose exec drupal vendor/bin/drush php:script scripts/setup-vector-search.php
 *
 * Or from the host after: export DRUPAL_DB_HOST=127.0.0.1 DRUPAL_DB_PORT=5433
 */

declare(strict_types=1);

use Drupal\ai\Plugin\search_api\processor\AiReranker;
use Drupal\block\Entity\Block;
use Drupal\facets\Entity\Facet;
use Drupal\key\Entity\Key;
use Drupal\mcp_server_tool_bridge\Entity\McpToolConfig;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\node\Entity\Node;
use Drupal\search\Entity\SearchPage;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\Item\Field;
use Drupal\taxonomy\Entity\Term;
use Drupal\views\Entity\View;

ensure_required_modules();

$openai_key = getenv('OPENAI_API_KEY') ?: '';
$ollama_host = getenv('OLLAMA_HOST') ?: 'http://host.docker.internal';
$ollama_port = getenv('OLLAMA_PORT') ?: '11434';
$embedding_model = getenv('OLLAMA_EMBEDDING_MODEL') ?: 'nomic-embed-text:latest';
$embedding_dimensions = (int) (getenv('OLLAMA_EMBEDDING_DIMENSIONS') ?: '768');
$chat_model = getenv('OLLAMA_CHAT_MODEL') ?: 'llama3.2:latest';

ensure_key('postgres_password', 'Postgres password', 'drupal_pass');
if ($openai_key !== '') {
  ensure_key('openai_api_key', 'OpenAI API key', $openai_key);
}

$config_factory = \Drupal::configFactory();

$config_factory->getEditable('ai_provider_ollama.settings')
  ->set('host_name', rtrim($ollama_host, '/'))
  ->set('port', (int) $ollama_port)
  ->save();

if ($openai_key !== '') {
  $config_factory->getEditable('ai_provider_openai.settings')
    ->set('api_key', 'openai_api_key')
    ->save();
}

$config_factory->getEditable('ai_vdb_provider_postgres.settings')
  ->set('host', 'postgres')
  ->set('port', 5432)
  ->set('username', 'drupal')
  ->set('password', 'postgres_password')
  ->set('default_database', 'drupal')
  ->save();

warm_ollama_provider_model_cache();
$embedding_machine_name = ollama_machine_name($embedding_model);
$chat_machine_name = ollama_machine_name($chat_model);
$ai = $config_factory->getEditable('ai.settings');
$models = $ai->get('models') ?? [];
if (isset($models['ollama']['embeddings']) && is_array($models['ollama']['embeddings'])) {
  foreach (array_keys($models['ollama']['embeddings']) as $legacy_id) {
    if (str_contains($legacy_id, 'nomic_embed')) {
      unset($models['ollama']['embeddings'][$legacy_id]);
    }
  }
}
$models['ollama']['embeddings'][$embedding_machine_name] = [
  'provider' => 'ollama',
  'operation_type' => 'embeddings',
  'model_id' => $embedding_model,
  'label' => $embedding_model,
  'dimensions' => $embedding_dimensions,
];
$models['ollama']['chat'][$chat_machine_name] = [
  'provider' => 'ollama',
  'operation_type' => 'chat',
  'model_id' => $chat_model,
  'label' => $chat_model,
];
$providers = $ai->get('default_providers') ?? [];
$providers['embeddings'] = [
  'provider_id' => 'ollama',
  'model_id' => $embedding_model,
];
// Moderation default for AI Search chunk pipeline (OpenAI when a key exists).
if ($openai_key !== '') {
  $providers['moderation'] = [
    'provider_id' => 'openai',
    'model_id' => 'text-moderation-latest',
  ];
}
$ai->set('models', $models)
  ->set('default_providers', $providers)
  ->set('default_vdb_provider', 'postgres')
  ->save();

$embeddings_engine = 'ollama__' . $embedding_machine_name;
$chat_model_option = 'ollama__' . $chat_machine_name;

$pgvector_config = [
  'chat_model' => $chat_model_option,
  'database' => 'postgres',
  'database_settings' => [
    'database_name' => 'drupal',
    'collection' => 'content_vectors',
    'metric' => 'cosine_similarity',
    'vector_index_strategy' => 'hnsw',
  ],
  'embeddings_engine' => $embeddings_engine,
  'embeddings_engine_configuration' => [
    'set_dimensions' => TRUE,
    'dimensions' => $embedding_dimensions,
  ],
  'embedding_strategy' => 'contextual_chunks',
  'embedding_strategy_configuration' => [
    'chunk_size' => 256,
    'chunk_min_overlap' => 32,
    'embedding_collection_size' => 1,
    'skip_moderation' => $openai_key === '',
  ],
  'include_raw_embedding_vector' => FALSE,
];

$server = Server::load('pgvector');
if ($server === NULL) {
  Server::create([
    'id' => 'pgvector',
    'name' => 'AI / pgvector',
    'description' => 'PostgreSQL pgvector backend using Ollama embeddings.',
    'backend' => 'search_api_ai_search',
    'status' => TRUE,
    'backend_config' => $pgvector_config,
  ])->save();
  print "Created Search API server pgvector.\n";
}
else {
  $server->set('description', 'PostgreSQL pgvector backend using Ollama embeddings.');
  $server->set('backend_config', array_replace_recursive($server->getBackendConfig(), $pgvector_config));
  $server->save();
  print "Updated Search API server pgvector.\n";
}

if (Server::load('database') === NULL) {
  Server::create([
    'id' => 'database',
    'name' => 'Database',
    'description' => 'PostgreSQL full-text backend. Hybrid ranking boosts these results with the pgvector index.',
    'backend' => 'search_api_db',
    'status' => TRUE,
    'backend_config' => [
      'database' => 'default:default',
      'min_chars' => 1,
      'matching' => 'words',
      'phrase' => 'bigram',
      'autocomplete' => [
        'suggest_suffix' => TRUE,
        'suggest_words' => TRUE,
      ],
    ],
  ])->save();
  print "Created Search API server database.\n";
}

$vector_index = ensure_node_index(
  'content_vectors',
  'Content vectors',
  'pgvector',
  [
    'title' => ['path' => 'title', 'type' => 'text', 'label' => 'Title'],
    'body' => ['path' => 'body', 'type' => 'text', 'label' => 'Body'],
    'type' => ['path' => 'type', 'type' => 'string', 'label' => 'Content type'],
  ],
);
sync_vector_index_metadata_fields($vector_index, [
  'type' => ['path' => 'type', 'type' => 'string', 'label' => 'Content type'],
]);
$config_factory->getEditable('ai_search.index.content_vectors')
  ->set('index_id', 'content_vectors')
  ->set('control_field_max_length', FALSE)
  ->set('exclude_chunk_from_metadata', FALSE)
  ->set('exclude_title', FALSE)
  ->set('results_cache_mode', 'none')
  ->set('indexing_options', [
    'body' => ['indexing_option' => 'main_content', 'max' => -1],
    'title' => ['indexing_option' => 'contextual_content', 'max' => -1],
    'type' => ['indexing_option' => 'attributes', 'max' => -1],
  ])
  ->save();

ensure_pgvector_collection_schema($vector_index, $embedding_dimensions);

$content_index = ensure_node_index(
  'content',
  'Content',
  'database',
  [
    'title' => ['path' => 'title', 'type' => 'text', 'label' => 'Title'],
    'body' => ['path' => 'body', 'type' => 'text', 'label' => 'Body'],
    'field_tags' => ['path' => 'field_tags:entity:name', 'type' => 'string', 'label' => 'Tags'],
    'type' => ['path' => 'type', 'type' => 'string', 'label' => 'Content type'],
  ],
);

$processor_manager = \Drupal::service('plugin.manager.search_api.processor');

$boost_config = [
  'search_api_ai_index' => 'content_vectors',
  'minimum_relevance_score' => 0.2,
  'number_to_return' => 10,
  'exact_phrase_action' => 'continue',
  'exact_phrase_action_reduce_number' => 2,
  'pass_conditions_fields' => [
    'type' => 'type',
  ],
  'max_pager_iterations' => 1,
];
if (!$content_index->isValidProcessor('database_boost_by_ai_search')) {
  $content_index->addProcessor($processor_manager->createInstance('database_boost_by_ai_search', $boost_config));
  print "Enabled Boost Database by AI Search on the content index.\n";
}
else {
  sync_boost_processor_fields($content_index, $boost_config);
}

$highlight_config = [
  'prefix' => '<strong class="hybrid-search-highlight">',
  'suffix' => '</strong>',
  'excerpt' => TRUE,
  'excerpt_length' => 256,
  'excerpt_always' => TRUE,
  'highlight' => 'always',
  'highlight_partial' => FALSE,
  'exclude_fields' => [],
];
if (!$content_index->isValidProcessor('highlight')) {
  $content_index->addProcessor($processor_manager->createInstance('highlight', $highlight_config));
  print "Enabled Search API Highlight processor.\n";
}
else {
  foreach ($content_index->getProcessors() as $processor) {
    if ($processor->getPluginId() !== 'highlight') {
      continue;
    }
    $processor->setConfiguration($highlight_config + $processor->getConfiguration());
    print "Updated Search API Highlight processor.\n";
    break;
  }
}

// AI Reranker is available only when a provider implements the rerank operation
// (OpenAI provider in this stack does not). Skip when unsupported.
if ($openai_key !== '' && AiReranker::supportsIndex($content_index) && !$content_index->isValidProcessor('ai_reranker')) {
  $content_index->addProcessor($processor_manager->createInstance('ai_reranker', [
    'provider_id' => '',
    'model_id' => '',
    'top_n' => 10,
    'source_fields' => ['title', 'body'],
  ]));
  print "Added AI Reranker processor (configure provider/model at /admin/config/search/search-api/index/content/processors).\n";
}

$content_index->save();

ensure_view();
ensure_core_search_route();
\Drupal::service('plugin.manager.facets.facet_source')->clearCachedDefinitions();

ensure_facet('tags', 'Tags', 'field_tags');
ensure_facet('content_type', 'Content type', 'type');
$theme = \Drupal::config('system.theme')->get('default');
ensure_facet_block('content_type', 'Content type', $theme, 0);
ensure_facet_block('tags', 'Tags', $theme, 1);

if (McpToolConfig::load('hybrid_content_search') === NULL) {
  McpToolConfig::create([
    'id' => 'hybrid_content_search',
    'tool_id' => 'hybrid_content_search',
    'description' => 'Search published articles and pages. Combines vector cosine similarity with database full-text scores.',
    'status' => TRUE,
  ])->save();
  print "Exposed hybrid_content_search on the MCP server.\n";
}

$mcp = $config_factory->getEditable('mcp_server.settings');
$mcp->set('server_name', 'Drupal Hybrid Search')
  ->set('server_instructions', 'Use the hybrid_content_search tool for semantic+keyword Search API results. Core Drupal search lives at /search (redirects to /search/content). Faceted hybrid search: /hybrid-search.')
  ->save();

user_role_grant_permissions('authenticated', ['access mcp server']);

$tag_ids = [];
foreach (['Drupal', 'Search', 'Vectors'] as $tag_name) {
  $existing = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties([
    'vid' => 'tags',
    'name' => $tag_name,
  ]);
  if ($existing) {
    $term = reset($existing);
  }
  else {
    $term = Term::create([
      'vid' => 'tags',
      'name' => $tag_name,
    ]);
    $term->save();
  }
  $tag_ids[$tag_name] = (int) $term->id();
}

$samples = [
  [
    'type' => 'article',
    'title' => 'Hybrid vector search in Drupal',
    'body' => 'Hybrid search combines keyword matching with semantic vector similarity. PostgreSQL pgvector stores embeddings from Ollama and ranks nearby meanings, while the database full-text score keeps exact terminology such as pgvector, Search API, and facets at the top.',
    'tags' => ['Drupal', 'Search', 'Vectors'],
  ],
  [
    'type' => 'article',
    'title' => 'Embeddings with Ollama',
    'body' => 'Ollama serves local embedding models (for example nomic-embed-text). Cosine similarity compares a question to stored article chunks even when the words do not overlap exactly.',
    'tags' => ['Vectors'],
  ],
  [
    'type' => 'article',
    'title' => 'Filtering search results with facets',
    'body' => 'Facets narrow the keyword Search API index on this page (tags and content type). The hybrid boost processor passes content type into the pgvector query; tag facets filter full-text results and which nodes can receive vector boost.',
    'tags' => ['Search'],
  ],
  [
    'type' => 'page',
    'title' => 'About this search demo',
    'body' => 'This site demonstrates PostgreSQL, the pgvector extension, Ollama embeddings, a hybrid Search API view with checkbox facets, core search at /search, and an MCP tool for external agents. Use AI Automators (/admin/config/ai/automators) for OpenAI-assisted field workflows.',
    'tags' => [],
  ],
];
foreach ($samples as $sample) {
  $found = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties([
    'title' => $sample['title'],
  ]);
  if ($found) {
    continue;
  }
  $values = [
    'type' => $sample['type'],
    'title' => $sample['title'],
    'body' => [
      'value' => $sample['body'],
      'format' => 'basic_html',
    ],
    'status' => 1,
    'uid' => 1,
  ];
  if ($sample['type'] === 'article' && $sample['tags']) {
    $values['field_tags'] = array_map(
      static fn (string $name): array => ['target_id' => $tag_ids[$name]],
      $sample['tags'],
    );
  }
  Node::create($values)->save();
  print "Created {$sample['type']}: {$sample['title']}\n";
}

ensure_menu_link('main', 'Core search', 'internal:/search', 10);
ensure_menu_link('main', 'Hybrid search', 'internal:/hybrid-search', 15);

reindex_search_api_indexes(['content_vectors', 'content']);

print $openai_key === '' ? "OPENAI_API_KEY_MISSING (AI Search moderation and OpenAI features need a key in env or Key module)\n" : "OPENAI_API_KEY_PRESENT\n";

// Rebuild the container so removed hybrid_search services (e.g. route subscriber) are gone
// before Drush kernel shutdown triggers a route rebuild.
drupal_flush_all_caches();
print "Setup complete.\n";

/**
 * Clears and rebuilds Search API indexes (vector + keyword).
 */
function reindex_search_api_indexes(array $index_ids): void {
  foreach ($index_ids as $index_id) {
    $index = Index::load($index_id);
    if ($index === NULL) {
      continue;
    }
    try {
      $index->clear();
      $index->indexItems();
      print "Reindexed Search API index {$index_id}.\n";
    }
    catch (\Throwable $e) {
      print "Reindex {$index_id} failed: {$e->getMessage()}\n";
    }
  }
}

/**
 * Recreates the pgvector collection table and attribute columns (facets metadata).
 */
function ensure_pgvector_collection_schema(Index $index, int $dimensions): void {
  if (!\Drupal::moduleHandler()->moduleExists('ai_vdb_provider_postgres')) {
    return;
  }
  $server = $index->getServerInstance();
  if ($server === NULL || $server->getBackendId() !== 'search_api_ai_search') {
    return;
  }
  $backend_config = $server->getBackendConfig();
  $collection = $backend_config['database_settings']['collection'] ?? '';
  $database_name = $backend_config['database_settings']['database_name'] ?? NULL;
  if ($collection === '') {
    return;
  }
  /** @var \Drupal\ai_vdb_provider_postgres\Plugin\VdbProvider\PostgresProvider $vdb */
  $vdb = \Drupal::service('ai.vdb_provider')->createInstance('postgres');
  $connection = $vdb->getConnection($database_name);
  if (!$connection instanceof \PDO) {
    print "Could not open Postgres for pgvector schema repair.\n";
    return;
  }
  /** @var \Drupal\ai_vdb_provider_postgres\PostgresPgvectorClient $client */
  $client = \Drupal::service('ai_vdb_provider_postgres.client');
  try {
    $client->dropCollection($collection, $connection);
  }
  catch (\Throwable) {
    // Collection may not exist yet.
  }
  $client->createCollection($collection, $dimensions, $connection);
  $client->updateFields($index->getFields(), $collection, $connection);
  ai_vdb_provider_postgres_search_api_index_update($index);
  print "Rebuilt pgvector collection {$collection} with metadata columns.\n";
}

/**
 * Warms the Ollama model map so getModel() resolves API names during indexing.
 */
/**
 * Machine name for Ollama model ids (matches ai_provider_ollama).
 */
function ollama_machine_name(string $model): string {
  return preg_replace('@[^a-z0-9_]+@', '_', mb_strtolower($model)) ?? '';
}

function warm_ollama_provider_model_cache(): void {
  if (!\Drupal::moduleHandler()->moduleExists('ai_provider_ollama')) {
    return;
  }
  $provider = \Drupal::service('ai.provider')->createInstance('ollama');
  if (method_exists($provider, 'getConfiguredModels')) {
    $provider->getConfiguredModels();
  }
}

/**
 * Tags stay on the keyword index; vector index keeps type for content-type facets.
 */
function sync_vector_index_metadata_fields(Index $index, array $required_fields = []): void {
  $changed = FALSE;
  if ($index->getField('field_tags') !== NULL) {
    $index->removeField('field_tags');
    $changed = TRUE;
    print "Removed field_tags from content_vectors (Tags facets use the content index).\n";
  }
  foreach ($required_fields as $field_id => $definition) {
    if ($index->getField($field_id) !== NULL) {
      continue;
    }
    $field = new Field($index, $field_id);
    $field->setLabel($definition['label']);
    $field->setDatasourceId('entity:node');
    $field->setPropertyPath($definition['path']);
    $field->setType($definition['type']);
    $index->addField($field);
    $changed = TRUE;
    print "Added {$field_id} to {$index->id()} for vector metadata.\n";
  }
  if ($changed) {
    $index->save();
  }
}

/**
 * Updates Boost Database by AI Search field pass-through for hybrid queries.
 */
function sync_boost_processor_fields(Index $index, array $boost_config): void {
  foreach ($index->getProcessors() as $processor) {
    if ($processor->getPluginId() !== 'database_boost_by_ai_search') {
      continue;
    }
    // Processor values must not win over script defaults (e.g. minimum_relevance_score).
    $processor->setConfiguration(array_replace_recursive($processor->getConfiguration(), $boost_config));
    $index->save();
    print "Updated Boost Database by AI Search pass-through fields.\n";
    return;
  }
}

/**
 * Enables modules required by this setup script (Key, Search API, AI, etc.).
 */
function ensure_required_modules(): void {
  $required = [
    'pgsql',
    'key',
    'token',
    'search',
    'search_node',
    'search_api',
    'search_api_db',
    'facets',
    'ai',
    'ai_search',
    'ai_vdb_provider_postgres',
    'ai_provider_ollama',
    'ai_provider_openai',
    'ai_automators',
    'tool',
    'hybrid_search',
    'mcp_server',
    'mcp_server_tool_bridge',
  ];
  $module_handler = \Drupal::moduleHandler();
  $to_install = [];
  foreach ($required as $module) {
    if (!$module_handler->moduleExists($module)) {
      $to_install[] = $module;
    }
  }
  if ($to_install === []) {
    return;
  }
  /** @var \Drupal\Core\Extension\ModuleInstallerInterface $installer */
  $installer = \Drupal::service('module_installer');
  $installer->install($to_install, TRUE);
  print 'Enabled modules: ' . implode(', ', $to_install) . "\n";
  drupal_flush_all_caches();
}

/**
 * Creates a configuration key when it does not exist.
 */
function ensure_key(string $id, string $label, string $value): void {
  if (!\Drupal::moduleHandler()->moduleExists('key')) {
    throw new \RuntimeException('The Key module is not enabled. Run: drush pm:enable key');
  }
  if (!class_exists(Key::class)) {
    throw new \RuntimeException('Key entity class is unavailable. Clear caches and try again: drush cr');
  }
  if (Key::load($id) !== NULL) {
    return;
  }
  $key = Key::create([
    'id' => $id,
    'label' => $label,
    'key_type' => 'authentication',
    'key_type_settings' => [],
    'key_provider' => 'config',
    'key_provider_settings' => [
      'key_value' => $value,
    ],
    'key_input' => 'text_field',
    'key_input_settings' => [],
  ]);
  $key->save();
  print "Created key {$id}.\n";
}

/**
 * Creates a node index and its fields when missing.
 */
function ensure_node_index(string $id, string $label, string $server_id, array $fields): Index {
  $index = Index::load($id);
  if ($index instanceof Index) {
    return $index;
  }
  $index = Index::create([
    'id' => $id,
    'name' => $label,
    'server' => $server_id,
    'status' => TRUE,
    'datasource_settings' => [
      'entity:node' => [
        'bundles' => [
          'default' => FALSE,
          'selected' => ['article', 'page'],
        ],
        'languages' => [
          'default' => TRUE,
          'selected' => [],
        ],
      ],
    ],
    'tracker_settings' => [
      'default' => [
        'indexing_order' => 'fifo',
      ],
    ],
    'options' => [
      'cron_limit' => 50,
      'index_directly' => FALSE,
      'track_changes_in_references' => TRUE,
    ],
  ]);
  foreach ($fields as $field_id => $definition) {
    $field = new Field($index, $field_id);
    $field->setLabel($definition['label']);
    $field->setDatasourceId('entity:node');
    $field->setPropertyPath($definition['path']);
    $field->setType($definition['type']);
    $index->addField($field);
  }
  $index->save();
  print "Created Search API index {$id}.\n";
  return $index;
}

/**
 * Creates or updates the hybrid search view at /hybrid-search.
 */
function ensure_view(): void {
  $view = View::load('hybrid_content_search');
  if ($view === NULL) {
    View::create([
      'id' => 'hybrid_content_search',
      'label' => 'Hybrid content search',
      'description' => 'Full-text search (Search API database) boosted by pgvector + Ollama embeddings.',
      'base_table' => 'search_api_index_content',
      'base_field' => 'search_api_id',
      'status' => TRUE,
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_title' => 'Default',
          'position' => 0,
          'display_options' => [
            'title' => 'Hybrid search',
            'access' => [
              'type' => 'perm',
              'options' => ['perm' => 'access content'],
            ],
            'cache' => [
              'type' => 'search_api_none',
              'options' => [],
            ],
            'query' => [
              'type' => 'search_api_query',
              'options' => [],
            ],
            'exposed_form' => [
              'type' => 'basic',
              'options' => [
                'submit_button' => 'Search',
                'reset_button' => TRUE,
                'reset_button_label' => 'Reset',
                'exposed_sorts_label' => 'Sort by',
                'expose_sort_order' => TRUE,
                'sort_asc_label' => 'Asc',
                'sort_desc_label' => 'Desc',
              ],
            ],
            'pager' => [
              'type' => 'mini',
              'options' => [
                'items_per_page' => 10,
                'offset' => 0,
                'id' => 0,
                'tags' => [
                  'previous' => '‹ previous',
                  'next' => 'next ›',
                ],
                'expose' => [
                  'items_per_page' => FALSE,
                  'items_per_page_label' => 'Items per page',
                  'items_per_page_options' => '5, 10, 25, 50',
                  'items_per_page_options_all' => FALSE,
                  'items_per_page_options_all_label' => '- All -',
                  'offset' => FALSE,
                  'offset_label' => 'Offset',
                ],
              ],
            ],
            'style' => hybrid_view_style_config(),
            'row' => hybrid_view_row_config(),
            'fields' => hybrid_view_fields_config(),
            'filters' => [
              'search_api_fulltext' => [
                'id' => 'search_api_fulltext',
                'table' => 'search_api_index_content',
                'field' => 'search_api_fulltext',
                'relationship' => 'none',
                'group_type' => 'group',
                'admin_label' => '',
                'operator' => 'and',
                'value' => '',
                'group' => 1,
                'exposed' => TRUE,
                'expose' => [
                  'operator_id' => 'search_op',
                  'label' => 'Search',
                  'description' => 'Keywords (Search API). Use quotes for phrases. AND / OR / NOT for boolean queries (complex parse mode). Results use highlighting and hybrid vector boost.',
                  'use_operator' => FALSE,
                  'operator' => 'search_op',
                  'identifier' => 'keys',
                  'required' => FALSE,
                  'remember' => FALSE,
                  'multiple' => FALSE,
                  'remember_roles' => [
                    'authenticated' => 'authenticated',
                  ],
                ],
                'is_grouped' => FALSE,
                'group_info' => [
                  'label' => '',
                  'description' => '',
                  'identifier' => '',
                  'optional' => TRUE,
                  'widget' => 'select',
                  'multiple' => FALSE,
                  'remember' => FALSE,
                  'default_group' => 'All',
                  'default_group_multiple' => [],
                  'group_items' => [],
                ],
                'parse_mode' => 'complex',
                'min_length' => 1,
                'fields' => ['title', 'body'],
                'plugin_id' => 'search_api_fulltext',
              ],
            ],
            'header' => hybrid_view_header_config(),
            'sorts' => [
              'search_api_relevance' => [
                'id' => 'search_api_relevance',
                'table' => 'search_api_index_content',
                'field' => 'search_api_relevance',
                'relationship' => 'none',
                'group_type' => 'group',
                'admin_label' => '',
                'order' => 'DESC',
                'exposed' => FALSE,
                'plugin_id' => 'search_api',
              ],
            ],
            'empty' => [
              'area' => [
                'id' => 'area',
                'table' => 'views',
                'field' => 'area',
                'relationship' => 'none',
                'group_type' => 'group',
                'admin_label' => '',
                'empty' => TRUE,
                'content' => [
                  'value' => '<p>No matching content. Try the <a href="/search">core content search</a> or widen your filters.</p>',
                  'format' => 'basic_html',
                ],
                'plugin_id' => 'text',
              ],
            ],
            'display_extenders' => [],
          ],
          'cache_metadata' => [
            'contexts' => ['url', 'url.query_args', 'user.permissions'],
            'cacheable' => FALSE,
            'max-age' => 0,
            'tags' => [],
          ],
        ],
        'page_1' => [
          'display_plugin' => 'page',
          'id' => 'page_1',
          'display_title' => 'Page',
          'position' => 1,
          'display_options' => [
            'display_extenders' => [],
            'path' => 'hybrid-search',
          ],
          'cache_metadata' => [
            'contexts' => ['url', 'url.query_args', 'user.permissions'],
            'cacheable' => FALSE,
            'max-age' => 0,
            'tags' => [],
          ],
        ],
      ],
    ])->save();
    print "Created hybrid search view at /hybrid-search.\n";
    return;
  }

  // Update existing view (path, parse mode, excerpt row, empty text).
  $displays = $view->get('display');
  $displays['default']['display_options']['title'] = 'Hybrid search';
  $displays['default']['display_options']['style'] = hybrid_view_style_config();
  $displays['default']['display_options']['row'] = hybrid_view_row_config();
  $displays['default']['display_options']['fields'] = hybrid_view_fields_config();
  if (isset($displays['default']['display_options']['filters']['search_api_fulltext'])) {
    $displays['default']['display_options']['filters']['search_api_fulltext']['parse_mode'] = 'complex';
    $displays['default']['display_options']['filters']['search_api_fulltext']['fields'] = ['title', 'body'];
    $displays['default']['display_options']['filters']['search_api_fulltext']['expose']['description'] = 'Keywords (Search API). Use quotes for phrases. AND / OR / NOT for boolean queries (complex parse mode). Results use highlighting and hybrid vector boost.';
  }
  if (isset($displays['default']['display_options']['empty']['area']['content'])) {
    $displays['default']['display_options']['empty']['area']['content']['value'] = '<p>No matching content. Try the <a href="/search">core content search</a> or widen your filters.</p>';
  }
  $displays['page_1']['display_options']['path'] = 'hybrid-search';
  $displays['default']['display_options']['header'] = hybrid_view_header_config();
  $view->set('display', $displays);
  $view->save();
  print "Updated hybrid search view to /hybrid-search.\n";
}

/**
 * Views style for hybrid results (title + highlighted excerpt).
 */
function hybrid_view_style_config(): array {
  return [
    'type' => 'default',
    'options' => [
      'row_class' => '',
      'default_row_class' => TRUE,
      'uses_fields' => TRUE,
    ],
  ];
}

/**
 * Fields row: linked title and Search API excerpt with highlight markup.
 */
function hybrid_view_row_config(): array {
  return [
    'type' => 'fields',
    'options' => [
      'default_field_elements' => TRUE,
      'inline' => [],
      'separator' => '',
      'hide_empty' => FALSE,
    ],
  ];
}

/**
 * Field handlers for hybrid_content_search (content index).
 */
function hybrid_view_fields_config(): array {
  return [
    'title' => [
      'id' => 'title',
      'table' => 'search_api_index_content',
      'field' => 'title',
      'relationship' => 'none',
      'group_type' => 'group',
      'admin_label' => '',
      'plugin_id' => 'search_api_field',
      'label' => '',
      'exclude' => FALSE,
      'element_type' => 'h3',
      'element_class' => 'hybrid-search-title',
      'element_label_type' => '',
      'element_label_colon' => FALSE,
      'element_default_classes' => TRUE,
      'field_rendering' => TRUE,
      'fallback_handler' => 'search_api',
      'fallback_options' => [
        'link_to_item' => TRUE,
        'use_highlighting' => FALSE,
        'multi_type' => 'separator',
        'multi_separator' => ', ',
        'filter_type' => 'plain',
      ],
    ],
    'search_api_excerpt' => [
      'id' => 'search_api_excerpt',
      'table' => 'search_api_index_content',
      'field' => 'search_api_excerpt',
      'relationship' => 'none',
      'group_type' => 'group',
      'admin_label' => '',
      'plugin_id' => 'search_api_text',
      'label' => '',
      'exclude' => FALSE,
      'element_type' => 'p',
      'element_class' => 'hybrid-search-excerpt',
      'element_label_type' => '',
      'element_label_colon' => FALSE,
      'element_default_classes' => TRUE,
      'link_to_item' => FALSE,
      'use_highlighting' => FALSE,
      'filter_type' => 'xss',
      'multi_type' => 'separator',
      'multi_separator' => ', ',
    ],
  ];
}

/**
 * Header: result count and when vector / Ollama runs.
 */
function hybrid_view_header_config(): array {
  return [
    'result' => [
      'id' => 'result',
      'table' => 'views',
      'field' => 'result',
      'relationship' => 'none',
      'group_type' => 'group',
      'admin_label' => '',
      'empty' => TRUE,
      'content' => 'Displaying @start–@end of @total',
      'plugin_id' => 'result',
    ],
    'hybrid_hint' => [
      'id' => 'hybrid_hint',
      'table' => 'views',
      'field' => 'area_text_custom',
      'relationship' => 'none',
      'group_type' => 'group',
      'admin_label' => '',
      'empty' => TRUE,
      'content' => 'Enter keywords and click Search to run hybrid ranking (one Ollama embedding call per search, then pgvector similarity). Browsing with an empty search box uses the database index only.',
      'plugin_id' => 'text_custom',
    ],
  ];
}

/**
 * Ensures core search uses /search → /search/content (node) without clashing.
 */
function ensure_core_search_route(): void {
  if (!\Drupal::moduleHandler()->moduleExists('search_node')) {
    print "search_node not enabled; skip core search page setup.\n";
    return;
  }
  $page = SearchPage::load('node_search');
  if ($page === NULL) {
    $page = SearchPage::create([
      'id' => 'node_search',
      'label' => 'Content',
      'path' => 'content',
      'plugin' => 'node_search',
      'configuration' => [
        'rankings' => [],
      ],
    ]);
    $page->enable();
    $page->save();
    print "Created core search page at /search/content.\n";
  }
  \Drupal::configFactory()->getEditable('search.settings')->clear('default_page')->save();
}

/**
 * Creates a facet for the hybrid search view (checkbox widget).
 */
function ensure_facet(string $id, string $label, string $field): void {
  /** @var \Drupal\facets\Entity\Facet|null $facet */
  $facet = Facet::load($id);
  if ($facet === NULL) {
    $facet = Facet::create([
      'id' => $id,
      'name' => $label,
      'weight' => 0,
      'use_hierarchy' => FALSE,
      'hierarchy' => [
        'type' => 'taxonomy',
        'config' => [],
      ],
    ]);
    $facet->setFacetSourceId('search_api:views_page__hybrid_content_search__page_1');
    $facet->setFieldIdentifier($field);
    $facet->setUrlAlias($id);
    $facet->setWidget('checkbox', ['show_numbers' => TRUE]);
    $facet->addProcessor([
      'processor_id' => 'url_processor_handler',
      'weights' => ['pre_query' => -10, 'build' => -10],
      'settings' => [],
    ]);
    $facet->setEmptyBehavior(['behavior' => 'none']);
    $facet->setOnlyVisibleWhenFacetSourceIsVisible(TRUE);
    $facet->save();
    print "Created facet {$id}.\n";
    return;
  }

  if ($facet->getWidget()['type'] !== 'checkbox') {
    $facet->setWidget('checkbox', ['show_numbers' => TRUE]);
    $facet->save();
    print "Updated facet {$id} widget to checkbox.\n";
  }
}

/**
 * Places a facet block in the default theme sidebar.
 */
function ensure_facet_block(string $id, string $label, string $theme, int $weight): void {
  $block_id = $theme . '_facet_' . $id;
  if (Block::load($block_id) !== NULL) {
    return;
  }
  Block::create([
    'id' => $block_id,
    'plugin' => 'facet_block:' . $id,
    'theme' => $theme,
    'region' => 'sidebar',
    'weight' => $weight,
    'settings' => [
      'id' => 'facet_block:' . $id,
      'label' => $label,
      'label_display' => 'visible',
      'provider' => 'facets',
    ],
    'visibility' => [
      'request_path' => [
        'id' => 'request_path',
        'negate' => FALSE,
        'pages' => "/hybrid-search\r\n/hybrid-search/*\r\n/search\r\n/search/*",
      ],
    ],
  ])->save();
  print "Placed {$label} facet block.\n";
}

/**
 * Ensures a menu link exists.
 */
function ensure_menu_link(string $menu, string $title, string $uri, int $weight): void {
  $links = \Drupal::entityTypeManager()->getStorage('menu_link_content')->loadByProperties([
    'menu_name' => $menu,
    'link.uri' => $uri,
  ]);
  if ($links) {
    return;
  }
  MenuLinkContent::create([
    'title' => $title,
    'link' => ['uri' => $uri],
    'menu_name' => $menu,
    'weight' => $weight,
  ])->save();
  print "Added menu link: {$title}.\n";
}
