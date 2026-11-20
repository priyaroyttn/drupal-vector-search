<?php

declare(strict_types=1);

namespace Drupal\hybrid_search\Plugin\tool\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\search_api\Entity\Index;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolBase;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;
use Drupal\tool\TypedData\OutputDefinition;

/**
 * Searches the hybrid keyword and vector content index.
 */
#[Tool(
  id: 'hybrid_content_search',
  label: new TranslatableMarkup('Hybrid content search'),
  description: new TranslatableMarkup('Search Drupal articles and pages with hybrid ranking: pgvector cosine similarity combined with database full-text scores.'),
  operation: ToolOperation::Read,
  input_definitions: [
    'query' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Query'),
      description: new TranslatableMarkup('Natural-language or keyword search text.'),
    ),
    'limit' => new InputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Limit'),
      description: new TranslatableMarkup('Maximum number of results. Defaults to 5.'),
      required: FALSE,
    ),
  ],
  output_definitions: [
    'results' => new OutputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Results'),
      description: new TranslatableMarkup('JSON list of matching titles, URLs, scores, and excerpts.'),
    ),
  ],
)]
final class HybridContentSearchTool extends ToolBase {

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    $index = Index::load('content');
    if ($index === NULL || !$index->status()) {
      return ExecutableResult::failure(new TranslatableMarkup('The hybrid content index is not available.'));
    }

    $limit = (int) ($values['limit'] ?? 5);
    if ($limit < 1) {
      $limit = 1;
    }
    if ($limit > 20) {
      $limit = 20;
    }

    $query = $index->query();
    $query->keys((string) $values['query']);
    $query->range(0, $limit);
    $executed = $query->execute();

    $results = [];
    foreach ($executed->getResultItems() as $item) {
      $entity = $item->getOriginalObject()?->getValue();
      $url = '';
      $title = $item->getId();
      if ($entity !== NULL && method_exists($entity, 'toUrl')) {
        $title = (string) $entity->label();
        $url = $entity->toUrl('canonical', ['absolute' => TRUE])->toString();
      }
      $results[] = [
        'title' => $title,
        'url' => $url,
        'score' => $item->getScore(),
        'excerpt' => $item->getExcerpt(),
      ];
    }

    return ExecutableResult::success(
      new TranslatableMarkup('Found @count result(s).', ['@count' => count($results)]),
      ['results' => json_encode($results, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]'],
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(array $values, AccountInterface $account, bool $return_as_object = FALSE): bool|AccessResultInterface {
    $access = AccessResult::allowedIfHasPermission($account, 'access content');
    return $return_as_object ? $access : $access->isAllowed();
  }

}
