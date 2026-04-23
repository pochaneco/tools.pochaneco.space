<?php

use App\Services\Knowledge\MarkdownChunker;
use Yethee\Tiktoken\EncoderProvider;

beforeEach(function () {
    $this->chunker = new MarkdownChunker(new EncoderProvider);
});

it('returns a single chunk for documents without headings', function () {
    $out = $this->chunker->chunk("A short paragraph.\n\nAnother one.");

    expect($out)->toHaveCount(1);
    expect($out[0]['heading_path'])->toBe('');
    expect($out[0]['content'])->toContain('A short paragraph');
});

it('splits by top-level headings', function () {
    $md = <<<'MD'
# Intro

Hello.

# Setup

Install X.

# Usage

Run Y.
MD;

    $out = $this->chunker->chunk($md);

    expect($out)->toHaveCount(3);
    expect($out[0]['heading_path'])->toBe('# Intro');
    expect($out[1]['heading_path'])->toBe('# Setup');
    expect($out[2]['heading_path'])->toBe('# Usage');
});

it('tracks nested heading paths', function () {
    $md = <<<'MD'
# Guide

intro text

## Setup

setup text

### Mac

mac text

## Usage

usage text
MD;

    $out = $this->chunker->chunk($md);

    $paths = array_map(fn ($c) => $c['heading_path'], $out);

    expect($paths)->toContain('# Guide');
    expect($paths)->toContain('# Guide > ## Setup');
    expect($paths)->toContain('# Guide > ## Setup > ### Mac');
    expect($paths)->toContain('# Guide > ## Usage');
});

it('embeds the heading line inside the chunk content', function () {
    $out = $this->chunker->chunk("# Title\n\nBody here.");

    expect($out[0]['content'])->toContain('# Title');
    expect($out[0]['content'])->toContain('Body here.');
});

it('reports a non-zero token count for non-empty chunks', function () {
    $out = $this->chunker->chunk("# Hello\n\nSome text.");

    expect($out[0]['token_count'])->toBeGreaterThan(0);
});

it('falls back to sliding window for oversized sections', function () {
    // Build a section that blows past MAX_TOKENS_PER_CHUNK by repeating
    // a paragraph many times under a single heading.
    $paragraph = str_repeat('word ', 200);
    $body = "# Huge\n\n".str_repeat($paragraph."\n\n", 30);

    $out = $this->chunker->chunk($body);

    expect(count($out))->toBeGreaterThan(1);
    foreach ($out as $chunk) {
        expect($chunk['heading_path'])->toBe('# Huge');
    }
});

it('skips empty content between headings', function () {
    $out = $this->chunker->chunk("# A\n\n\n\n# B\n\nBody.");

    // `# A` still emits because the heading itself is content; we only
    // guarantee that no chunk is pure whitespace.
    foreach ($out as $chunk) {
        expect(trim($chunk['content']))->not->toBe('');
    }
});
