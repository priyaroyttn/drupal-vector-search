<?php

declare(strict_types=1);

namespace Drupal\hybrid_search\Utility;

use Drupal\Component\Utility\Html;
use Drupal\node\NodeInterface;
use Drupal\search_api\Item\ItemInterface;

/**
 * Builds excerpts from the start of node body text instead of mid-content snippets.
 */
final class ExcerptLead {

  /**
   * Whether Search API wrapped the excerpt with a leading ellipsis separator.
   */
  public static function startsWithEllipsis(string $text): bool {
    $text = trim($text);
    if ($text === '') {
      return FALSE;
    }

    return str_starts_with($text, '…');
  }

  /**
   * Plain-text excerpt from the beginning of the node body field.
   */
  public static function fromNodeBody(ItemInterface $item, int $maxLength): ?string {
    if ($maxLength < 1) {
      return NULL;
    }

    $entity = $item->getOriginalObject()?->getValue();
    if (!$entity instanceof NodeInterface || !$entity->hasField('body') || $entity->get('body')->isEmpty()) {
      return NULL;
    }

    $text = self::plainText((string) $entity->get('body')->value);
    if ($text === '') {
      return NULL;
    }

    $snippet = mb_substr($text, 0, $maxLength);
    $pos = mb_strrpos($snippet, ' ');
    if ($pos !== FALSE && $pos > (int) ($maxLength / 2)) {
      $snippet = mb_substr($snippet, 0, $pos);
    }
    $snippet = trim($snippet);
    if (mb_strlen($text) > mb_strlen($snippet)) {
      $snippet .= ' …';
    }

    return $snippet;
  }

  /**
   * Strips HTML and normalizes whitespace like Search API excerpt preparation.
   */
  private static function plainText(string $value): string {
    $text = preg_replace('#<(style|script).*?>.*?</\1>#is', ' ', $value) ?? $value;
    $text = strip_tags(str_replace(['<', '>'], [' <', '> '], $text));
    $text = Html::decodeEntities($text);
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;

    return trim($text);
  }

}
