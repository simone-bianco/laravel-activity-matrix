<?php

declare(strict_types=1);

namespace SimoneBianco\ActivityMatrix;

final class ActivitySanitizer
{
    private const string SENSITIVE_KEY = '/password|authorization|cookie|token|secret|api.?key|private.?key|credential|payload|prompt|body|stdout|stderr|script|argv|input|text|html|content|headers|environment|^env$/i';

    private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

    public function details(array $value, int $maxBytes = 16384, int $maxStringLength = 512, int $maxDepth = 5, array $visiblePaths = []): array
    {
        // Reserve the root truncation marker; account for escaped JSON bytes, including keys and containers.
        $budget = $maxBytes - 32;
        $truncated = false;
        $result = $this->walk($value, $budget, $truncated, 0, $maxStringLength, $maxDepth, $visiblePaths, []);
        if ($truncated) {
            $result['_truncated'] = true;
        }

        return $result;
    }

    public function text(string $value, int $limit = 240): string
    {
        return mb_substr(mb_scrub($this->redactText($value), 'UTF-8'), 0, $limit, 'UTF-8');
    }

    private function redactText(string $value, int $depth = 0): string
    {
        if ($depth > 5) {
            return '[redacted]';
        }
        $value = preg_replace('/\b(?:Bearer|Basic)\s+[^\s]+|\bsk-(?:proj-|svcacct-)?[A-Za-z0-9_-]{16,}/i', '[redacted]', $value) ?? '[redacted]';
        $value = preg_replace('~\b([a-z][a-z0-9+.-]*://)[^/\s?#@]+@~i', '$1[redacted]@', $value) ?? '[redacted]';
        $assignment = <<<'REGEX'
~(?<key>[a-z_%][a-z0-9_.%-]*)(?<separator>["']?\s*[:=]\s*)(?!//)(?<value>"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|[^\s&,;]+)~i
REGEX;
        $value = preg_replace_callback($assignment, function (array $match) use ($depth): string {
            if (! preg_match(self::SENSITIVE_KEY, rawurldecode($match['key']))) {
                return $match['key'].$match['separator'].$this->redactText($match['value'], $depth + 1);
            }
            $quote = in_array($match['value'][0], ['"', "'"], true) ? $match['value'][0] : '';

            return $match['key'].$match['separator'].$quote.'[redacted]'.$quote;
        }, $value) ?? '[redacted]';

        return $value;
    }

    private function walk(array $values, int &$budget, bool &$truncated, int $depth, int $maxStringLength, int $maxDepth, array $visiblePaths, array $parentPath): array
    {
        $result = [];
        $budget -= 2;
        if ($depth > $maxDepth) {
            $truncated = true;

            return $result;
        }
        $count = 0;
        foreach ($values as $originalKey => $value) {
            if ($originalKey === '_truncated') {
                $truncated = $truncated || $value === true;

                continue;
            }
            if (++$count > 100) {
                $truncated = true;
                break;
            }
            $key = is_int($originalKey) ? $originalKey : $this->text((string) $originalKey, 80);
            $truncated = $truncated || $key !== $originalKey;
            if (array_key_exists($key, $result)) {
                $truncated = true;

                continue;
            }
            $keyCost = strlen(json_encode((string) $key, self::JSON_FLAGS)) + 2;
            $path = [...$parentPath, is_int($originalKey) ? '*' : $originalKey];
            // Explicit domain paths bypass this key's mask; child keys and embedded credentials still redact.
            if (! in_array($path, $visiblePaths, true) && preg_match(self::SENSITIVE_KEY, rawurldecode((string) $originalKey))) {
                $safe = '[redacted]';
            } elseif (is_array($value)) {
                if ($budget < $keyCost + 2) {
                    $truncated = true;
                    break;
                }
                $budget -= $keyCost;
                $result[$key] = $this->walk($value, $budget, $truncated, $depth + 1, $maxStringLength, $maxDepth, $visiblePaths, $path);

                continue;
            } elseif (is_string($value)) {
                $truncated = $truncated || mb_strlen($value, 'UTF-8') > $maxStringLength;
                $safe = $this->text($value, $maxStringLength);
            } elseif (is_scalar($value) || $value === null) {
                $safe = $value;
                if (is_float($safe) && ! is_finite($safe)) {
                    $truncated = true;

                    continue;
                }
            } else {
                $truncated = true;

                continue;
            }
            $cost = $keyCost + strlen(json_encode($safe, self::JSON_FLAGS));
            if ($cost > $budget) {
                $truncated = true;
                break;
            }
            $budget -= $cost;
            $result[$key] = $safe;
        }

        return $result;
    }
}
