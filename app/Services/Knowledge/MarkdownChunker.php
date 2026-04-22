<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use Throwable;
use Yethee\Tiktoken\EncoderProvider;

/**
 * Splits a Markdown document into retrieval-friendly chunks.
 *
 * The strategy is deliberately dumb: split by ATX headings first so each
 * chunk has a meaningful title, then — only when a single section is too
 * large to fit in one embedding request — fall back to a sliding window
 * over paragraphs. This keeps semantic coherence for normal-sized docs
 * and only pays the window-slicing cost when necessary.
 */
class MarkdownChunker
{
    /** Target maximum tokens per chunk before we fall back to sliding. */
    public const MAX_TOKENS_PER_CHUNK = 800;

    /** Overlap between sliding-window chunks to avoid cutting ideas in half. */
    public const OVERLAP_TOKENS = 100;

    private const ENCODING = 'cl100k_base';

    public function __construct(
        private readonly EncoderProvider $encoders,
    ) {}

    /**
     * @return array<int, array{content: string, heading_path: string, token_count: int}>
     */
    public function chunk(string $markdown): array
    {
        $sections = $this->splitByHeadings($markdown);
        $out = [];

        foreach ($sections as $section) {
            $content = trim($section['content']);
            if ($content === '') {
                continue;
            }

            $tokens = $this->count($content);

            if ($tokens <= self::MAX_TOKENS_PER_CHUNK) {
                $out[] = [
                    'content' => $content,
                    'heading_path' => $section['heading_path'],
                    'token_count' => $tokens,
                ];

                continue;
            }

            foreach ($this->slide($content, $section['heading_path']) as $piece) {
                $out[] = $piece;
            }
        }

        return $out;
    }

    /**
     * Walk the doc line-by-line, accumulating text under the most recent
     * heading stack. We track the full path (H1 > H2 > H3) so chunk
     * metadata includes useful context for the AI and for UI display.
     *
     * @return array<int, array{content: string, heading_path: string}>
     */
    private function splitByHeadings(string $markdown): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $markdown);
        if ($lines === false) {
            return [['content' => $markdown, 'heading_path' => '']];
        }

        $stack = []; // [level => heading_text]
        $buffer = '';
        $currentPath = '';
        $sections = [];

        $flush = function () use (&$buffer, &$currentPath, &$sections) {
            if (trim($buffer) !== '') {
                $sections[] = [
                    'content' => $buffer,
                    'heading_path' => $currentPath,
                ];
            }
            $buffer = '';
        };

        foreach ($lines as $line) {
            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $m)) {
                $flush();
                $level = strlen($m[1]);
                // Replace deeper/equal heading levels, keeping shallower ones.
                foreach (array_keys($stack) as $k) {
                    if ($k >= $level) {
                        unset($stack[$k]);
                    }
                }
                $stack[$level] = $m[2];
                ksort($stack);
                $currentPath = $this->renderPath($stack);
                // Preserve the heading inside the chunk too — the LLM benefits
                // from seeing it as part of the body, not only as metadata.
                $buffer = $line."\n";

                continue;
            }

            $buffer .= $line."\n";
        }

        $flush();

        if ($sections === []) {
            $sections[] = [
                'content' => $markdown,
                'heading_path' => '',
            ];
        }

        return $sections;
    }

    /**
     * @param  array<int, string>  $stack
     */
    private function renderPath(array $stack): string
    {
        $parts = [];
        ksort($stack);
        foreach ($stack as $level => $text) {
            $parts[] = str_repeat('#', $level).' '.$text;
        }

        return implode(' > ', $parts);
    }

    /**
     * Paragraph-aware sliding window: cut the oversized section into
     * chunks of ~MAX_TOKENS_PER_CHUNK with OVERLAP_TOKENS of repeated
     * paragraphs between neighbours.
     *
     * @return array<int, array{content: string, heading_path: string, token_count: int}>
     */
    private function slide(string $content, string $headingPath): array
    {
        $paragraphs = preg_split("/\n\s*\n/", $content) ?: [$content];
        $result = [];

        $current = '';
        $currentTokens = 0;
        $tail = []; // recent paragraphs to carry into the next window

        $emit = function () use (&$current, &$currentTokens, &$result, $headingPath, &$tail) {
            $trimmed = trim($current);
            if ($trimmed === '') {
                return;
            }
            $result[] = [
                'content' => $trimmed,
                'heading_path' => $headingPath,
                'token_count' => $currentTokens,
            ];

            // Seed the next window with the overlap tail so ideas that
            // straddle the cut aren't lost.
            $overlap = '';
            $overlapTokens = 0;
            foreach (array_reverse($tail) as $p) {
                $pt = $this->count($p);
                if ($overlapTokens + $pt > self::OVERLAP_TOKENS) {
                    break;
                }
                $overlap = $p."\n\n".$overlap;
                $overlapTokens += $pt;
            }
            $current = rtrim($overlap);
            $currentTokens = $overlapTokens;
            $tail = [];
        };

        foreach ($paragraphs as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }

            $pTokens = $this->count($p);

            if ($currentTokens + $pTokens > self::MAX_TOKENS_PER_CHUNK && $currentTokens > 0) {
                $emit();
            }

            $current = $current === '' ? $p : $current."\n\n".$p;
            $currentTokens += $pTokens;
            $tail[] = $p;

            // If even a single paragraph blows past the cap we still emit
            // it as-is; splitting inside a paragraph usually makes things
            // worse for retrieval quality.
            if ($currentTokens >= self::MAX_TOKENS_PER_CHUNK) {
                $emit();
            }
        }

        if (trim($current) !== '') {
            $result[] = [
                'content' => trim($current),
                'heading_path' => $headingPath,
                'token_count' => $currentTokens,
            ];
        }

        return $result;
    }

    /**
     * Approximate token count via tiktoken with a length-based fallback
     * when the vocab file isn't available (CI first-boot, offline runs).
     */
    private function count(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        try {
            $encoder = $this->encoders->get(self::ENCODING);

            return count($encoder->encode($text));
        } catch (Throwable) {
            return (int) ceil(mb_strlen($text) / 4);
        }
    }
}
