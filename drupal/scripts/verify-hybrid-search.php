<?php

/**
 * @file
 * Smoke-test hybrid search (keyword index + vector query embed).
 *
 * Usage (inside Drupal container):
 *   vendor/bin/drush php:script scripts/verify-hybrid-search.php [keywords]
 *
 * Watch Ollama while this runs (host Mac):
 *   curl -s http://127.0.0.1:11434/api/ps
 */

declare(strict_types=1);

use Drupal\search_api\Entity\Index;

$keywords = $extra[0] ?? 'ollama embeddings pgvector';

print "=== Hybrid search verification ===\n";
print "Keywords: {$keywords}\n\n";

$content = Index::load('content');
$vectors = Index::load('content_vectors');
if (!$content || !$vectors) {
  throw new \RuntimeException('Missing content or content_vectors index.');
}

$content_query = $content->query(['limit' => 5]);
$content_query->keys($keywords);
$content_results = $content_query->execute();
$keyword_count = count($content_results->getResultItems());
print "Keyword index (content): {$keyword_count} results (limit 5).\n";

$has_boost = FALSE;
foreach ($content->getProcessors() as $processor) {
  if ($processor->getPluginId() === 'database_boost_by_ai_search') {
    $has_boost = TRUE;
    $cfg = $processor->getConfiguration();
    print "Boost processor: ai_index={$cfg['search_api_ai_index']}, min_score={$cfg['minimum_relevance_score']}.\n";
    break;
  }
}
if (!$has_boost) {
  print "WARNING: database_boost_by_ai_search is not enabled on content index.\n";
}

print "\nVector index query (embeds keywords via Ollama, then pgvector)…\n";
$vector_query = $vectors->query(['limit' => 5]);
$vector_query->keys($keywords);
$vector_query->setOption('search_api_bypass_access', TRUE);
$started = microtime(TRUE);
try {
  $vector_results = $vector_query->execute();
  $elapsed = round(microtime(TRUE) - $started, 2);
  $vector_count = count($vector_results->getResultItems());
  print "Vector index: {$vector_count} results in {$elapsed}s.\n";
  foreach ($vector_results->getResultItems() as $item) {
    $score = round((float) $item->getScore(), 4);
    print "  - {$item->getId()} score={$score}\n";
  }
}
catch (\Throwable $e) {
  print "Vector query failed: {$e->getMessage()}\n";
}

/** @var \PDO|null $pdo */
$pdo = NULL;
try {
  $vdb = \Drupal::service('ai.vdb_provider')->createInstance('postgres');
  $pdo = $vdb->getConnection('drupal');
  if ($pdo instanceof \PDO) {
    $rows = (int) $pdo->query('SELECT COUNT(*) FROM content_vectors WHERE embedding IS NOT NULL')->fetchColumn();
    print "\npgvector rows with embeddings: {$rows}\n";
  }
}
catch (\Throwable) {
  // Optional.
}

print "\nIf vector query succeeded and took >0s, Ollama should have logged an embed request.\n";
print "UI test: /hybrid-search?keys=" . rawurlencode($keywords) . "\n";
