<?php

declare(strict_types=1);

namespace Drupal\hybrid_search\Utility;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Render\Element;

/**
 * Builds keyword lists for excerpt highlighting on boolean Search API queries.
 */
final class HighlightKeys {

  private const BOOLEAN_TERMS = ['and', 'or', 'not'];

  /**
   * Flattens parsed Search API keys into a list of positive search terms.
   *
   * @param mixed $keys
   *   Parsed keys from the query, or a plain string.
   *
   * @return list<string>
   *   Unique terms in query order.
   */
  public static function flattenKeys(mixed $keys): array {
    if (is_string($keys)) {
      if ($keys === '') {
        return [];
      }
      $split = preg_split('/\s+/u', $keys);
      return $split ? array_values(array_filter($split, 'strlen')) : [];
    }
    if (!is_array($keys) || !empty($keys['#negation'])) {
      return [];
    }

    $keywords = [];
    foreach ($keys as $i => $key) {
      if (!Element::child($i)) {
        continue;
      }
      if (is_array($key)) {
        foreach (self::flattenKeys($key) as $nested) {
          $keywords[$nested] = $nested;
        }
      }
      elseif (is_string($key) && $key !== '') {
        $keywords[$key] = $key;
      }
    }

    return array_values($keywords);
  }

  /**
   * Terms to mark in excerpts (drops boolean operators; adds simple plurals).
   *
   * @param mixed $keys
   *   Parsed keys from the query.
   *
   * @return list<string>
   *   Highlight terms.
   */
  public static function forHighlighting(mixed $keys): array {
    $terms = self::flattenKeys($keys);
    $highlight = [];
    foreach ($terms as $term) {
      if (in_array(mb_strtolower($term), self::BOOLEAN_TERMS, TRUE)) {
        continue;
      }
      $highlight[$term] = $term;
      if (preg_match('/^(.+?)s$/iu', $term, $matches) !== 0 && mb_strlen($matches[1]) >= 2) {
        $highlight[$matches[1]] = $matches[1];
      }
    }

    return array_values($highlight);
  }

  /**
   * Wraps whole-word occurrences of highlight terms in the configured markup.
   *
   * Always uses word boundaries so terms like "API" are not matched inside
   * unrelated words (e.g. "rapidly").
   *
   * @param string $text
   *   Plain text excerpt body.
   * @param list<string> $keys
   *   Terms to highlight.
   * @param string $prefix
   *   Markup before a match.
   * @param string $suffix
   *   Markup after a match.
   */
  public static function highlightPlainText(
    string $text,
    array $keys,
    string $prefix,
    string $suffix,
  ): string {
    if ($keys === [] || $text === '') {
      return $text;
    }

    // cspell:disable-next-line
    $cjk = '\x{1100}-\x{11FF}\x{3040}-\x{309F}\x{30A1}-\x{318E}' .
      '\x{31A0}-\x{31B7}\x{31F0}-\x{31FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FCF}' .
      '\x{A000}-\x{A48F}\x{A4D0}-\x{A4FD}\x{A960}-\x{A97F}\x{AC00}-\x{D7FF}' .
      '\x{F900}-\x{FAFF}\x{FF21}-\x{FF3A}\x{FF41}-\x{FF5A}\x{FF66}-\x{FFDC}' .
      '\x{20000}-\x{2FFFD}\x{30000}-\x{3FFFD}';
    // cspell:enable
    $boundary = '(?:(?<=[' . Unicode::PREG_CLASS_WORD_BOUNDARY . $cjk . '])|(?=[' . Unicode::PREG_CLASS_WORD_BOUNDARY . $cjk . ']))';

    usort($keys, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
    $pattern = implode('|', array_map(static fn (string $key): string => preg_quote($key, '/'), $keys));
    $regex = '/' . $boundary . '(?:' . $pattern . ')' . $boundary . '/iu';
    $replace = $prefix . '\0' . $suffix;
    $highlighted = preg_replace($regex, $replace, ' ' . $text . ' ');

    return $highlighted !== NULL ? trim($highlighted) : $text;
  }

}
