<?php

namespace App\Support;

/**
 * Keep legacy rich-HTML plan templates readable by the existing Markdown theme.
 * This is whitespace compatibility only, not HTML sanitization or a DB rewrite.
 */
final class LegacyPlanContent
{
    public static function forMarkdown(string $content): string
    {
        // Ordinary Markdown, JSON feature lists and intentional code examples
        // keep their original semantics. Only legacy style-first HTML fragments opt in.
        if (!preg_match('/\A\s*<style\b/i', $content) ||
            preg_match('/^[\t ]*(?:`{3,}|~{3,})/m', $content)) {
            return $content;
        }

        // Markdown treats 4-space-indented tags after blank lines as code.
        // Protect raw-text/whitespace-sensitive elements byte for byte; only
        // de-indent actual tag/comment lines elsewhere, never text or CSS.
        $pattern = '~<(style|script|pre|textarea|code)\b[^>]*>.*?</\1\s*>~is';
        $matched = preg_match_all($pattern, $content, $protected, PREG_OFFSET_CAPTURE);
        if ($matched === false) {
            return $content;
        }

        $normalize = static fn (string $part): string => preg_replace(
            '/^[\t ]+(?=<(?:\/?[a-zA-Z][a-zA-Z0-9:_-]*(?:[\s>\/])|!--))/',
            '',
            $part
        ) ?? $part;
        // Apply per line without changing LF/CRLF or any content between tags.
        $normalizeLines = static fn (string $part): string => preg_replace_callback(
            '/[^\r\n]+/',
            static fn (array $match): string => $normalize($match[0]),
            $part
        ) ?? $part;

        $result = '';
        $offset = 0;
        foreach ($protected[0] as [$block, $start]) {
            $prefix = $normalizeLines(substr($content, $offset, $start - $offset));
            // Remove indentation before the opening raw-text tag as well,
            // while preserving every byte inside the protected element.
            $result .= preg_replace('/^[\t ]+\z/m', '', $prefix) ?? $prefix;
            $result .= $block;
            $offset = $start + strlen($block);
        }
        return $result . $normalizeLines(substr($content, $offset));
    }
}
