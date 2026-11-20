<?php

/**
 * @file
 * CLI demo of hybrid_content_search (same logic as MCP), no MCP client required.
 *
 * Usage (inside Drupal container):
 *   vendor/bin/drush php:script scripts/demo-hybrid-content-search-tool.php -- "pgvector"
 *   vendor/bin/drush php:script scripts/demo-hybrid-content-search-tool.php -- "how local embeddings work" 10
 */

declare(strict_types=1);

$args = array_slice($_SERVER['argv'] ?? [], 1);
// Drush passes script args after "--" on some versions; strip script path noise.
$args = array_values(array_filter($args, static fn (string $a): bool => !str_contains($a, 'demo-hybrid-content-search-tool.php')));

$query = $args[0] ?? 'how local embeddings work';
$limit = isset($args[1]) ? (int) $args[1] : 5;

/** @var \Drupal\tool\Plugin\ToolManager $tool_manager */
$tool_manager = \Drupal::service('plugin.manager.tool');
$tool = $tool_manager->createInstance('hybrid_content_search');
$tool->setInputValue('query', $query);
$tool->setInputValue('limit', $limit);
$tool->execute();
$result = $tool->getResult();

if (!$result->isSuccess()) {
  fwrite(STDERR, (string) $result->getMessage() . "\n");
  exit(1);
}

$outputs = $result->getContextValues();
$json = $outputs['results'] ?? '[]';
print "Query: {$query}\n";
print "Limit: {$limit}\n\n";
print is_string($json) ? $json : json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
print "\n";
