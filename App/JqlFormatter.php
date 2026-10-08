<?php

namespace MAJIRA\App;

/** Lay out top-level JQL clauses without changing quoted text or grouped expressions. */
final class JqlFormatter {

  public static function format(string $jql): string {
    $jql = trim($jql);
    $result = '';
    $quote = '';
    $depth = 0;
    $length = strlen($jql);
    for ($index = 0; $index < $length; $index++) {
      $character = $jql[$index];
      if ($quote !== '') {
        $result .= $character;
        if ($character === '\\' && $index + 1 < $length) {
          $result .= $jql[++$index];
        } else if ($character === $quote) {
          $quote = '';
        }
        continue;
      }
      if ($character === '"' || $character === "'") {
        $quote = $character;
        $result .= $character;
        continue;
      }
      if ($character === '(') {
        $depth++;
      } else if ($character === ')') {
        $depth = max(0, $depth - 1);
      }
      if (ctype_space($character)) {
        while ($index + 1 < $length && ctype_space($jql[$index + 1])) {
          $index++;
        }
        $next = substr($jql, $index + 1);
        if ($depth === 0 && trim($result) !== '' && preg_match('/\A(ORDER\s+BY|AND|OR)(?=\s|$)/i', $next, $match) === 1) {
          $operator = strtoupper(preg_replace('/\s+/', ' ', $match[1]));
          $result = rtrim($result) . "\n" . $operator;
          $index += strlen($match[1]);
          continue;
        }
        if ($result !== '' && !str_ends_with($result, ' ') && !str_ends_with($result, "\n")) {
          $result .= ' ';
        }
        continue;
      }
      $result .= $character;
    }
    return trim($result);
  }

}
