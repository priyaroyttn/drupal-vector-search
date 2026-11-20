<?php

declare(strict_types=1);

namespace Drupal\hybrid_search\Utility;

use Drupal\search_api\Query\QueryInterface;

/**
 * Detects queries that must not widen results via vector keyword OR-boost.
 */
final class StrictKeywordQuery {

  /**
   * Whether the user entered explicit boolean operators (complex parse mode).
   */
  public static function usesExplicitBoolean(QueryInterface $query): bool {
    $original = $query->getOriginalKeys();
    if (!is_string($original) || $original === '') {
      return FALSE;
    }

    return preg_match('/\b(AND|OR|NOT)\b/', $original) === 1;
  }

  /**
   * Strips vector boost item ID tags added by database_boost_by_ai_search.
   */
  public static function stripVectorBoostTags(QueryInterface $query): void {
    $tags = &$query->getTags();
    foreach (array_keys($tags) as $tag) {
      if (str_starts_with($tag, 'ai_search_ids:')) {
        unset($tags[$tag]);
      }
    }
  }

}
