<script setup lang="ts">
import DOMPurify, { type Config as DOMPurifyConfig } from 'dompurify';
import hljs from 'highlight.js/lib/common';
import { Marked, type Tokens } from 'marked';
import { onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps<{
    content: string;
}>();

// Lazily create a single Marked instance per component so custom renderer
// closures do not leak between instances while still allowing simple usage.
const marked = new Marked({
    gfm: true,
    breaks: true,
    async: false,
});

// Language-aware code block highlighting.
marked.use({
    renderer: {
        code(token: Tokens.Code): string {
            const lang = (token.lang ?? '').trim().split(/\s+/)[0] ?? '';
            const langClass = lang ? ` language-${escapeAttr(lang)}` : '';
            let highlighted: string;
            try {
                if (lang && hljs.getLanguage(lang)) {
                    highlighted = hljs.highlight(token.text, { language: lang, ignoreIllegals: true }).value;
                } else {
                    highlighted = hljs.highlightAuto(token.text).value;
                }
            } catch {
                // Fallback: escape the text ourselves so we never emit raw HTML.
                highlighted = escapeHtml(token.text);
            }
            return `<pre><code class="hljs${langClass}">${highlighted}</code></pre>`;
        },
        link(token: Tokens.Link): string {
            const href = escapeAttr(token.href ?? '');
            const title = token.title ? ` title="${escapeAttr(token.title)}"` : '';
            // Render inner markdown (emphasis, code, etc.) for the link text.
            // `this.parser` is provided by marked at runtime.
            const text = (this as unknown as { parser: { parseInline: (t: unknown[]) => string } }).parser.parseInline(token.tokens ?? []);
            const isExternal = /^https?:\/\//i.test(token.href ?? '');
            const targetRel = isExternal ? ' target="_blank" rel="noopener noreferrer"' : '';
            return `<a href="${href}"${title}${targetRel}>${text}</a>`;
        },
    },
});

function escapeHtml(value: string): string {
    return value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function escapeAttr(value: string): string {
    return escapeHtml(value);
}

const SANITIZE_CONFIG: DOMPurifyConfig = {
    ALLOWED_TAGS: [
        'p',
        'br',
        'strong',
        'em',
        'del',
        'code',
        'pre',
        'h1',
        'h2',
        'h3',
        'h4',
        'h5',
        'h6',
        'ul',
        'ol',
        'li',
        'a',
        'blockquote',
        'table',
        'thead',
        'tbody',
        'tr',
        'th',
        'td',
        'hr',
        'span',
        'div',
    ],
    ALLOWED_ATTR: ['href', 'title', 'class', 'target', 'rel'],
    ALLOW_DATA_ATTR: false,
};

function render(content: string): string {
    try {
        const raw = marked.parse(content) as string;
        return DOMPurify.sanitize(raw, SANITIZE_CONFIG) as unknown as string;
    } catch {
        // Never surface a parse error to the user — fall back to escaped text.
        return escapeHtml(content);
    }
}

const renderedHtml = ref<string>('');

// Streaming-friendly throttle. When content updates faster than the browser
// paints (common during SSE delta bursts), we coalesce the work into the next
// animation frame so we cap effective re-renders at ~60fps.
let pendingFrame: number | null = null;
let lastTickMs = 0;

function scheduleRender() {
    const now = performance.now();
    const since = now - lastTickMs;
    if (since >= 16 && pendingFrame === null) {
        lastTickMs = now;
        renderedHtml.value = render(props.content);
        return;
    }
    if (pendingFrame !== null) {
        return;
    }
    pendingFrame = requestAnimationFrame(() => {
        pendingFrame = null;
        lastTickMs = performance.now();
        renderedHtml.value = render(props.content);
    });
}

watch(
    () => props.content,
    () => scheduleRender(),
    { immediate: true },
);

onBeforeUnmount(() => {
    if (pendingFrame !== null) {
        cancelAnimationFrame(pendingFrame);
        pendingFrame = null;
    }
});
</script>

<template>
    <div class="markdown-message" v-html="renderedHtml" />
</template>

<style scoped>
.markdown-message {
    font-size: 0.875rem;
    line-height: 1.6;
    word-break: break-word;
}

.markdown-message :deep(> *:first-child) {
    margin-top: 0;
}

.markdown-message :deep(> *:last-child) {
    margin-bottom: 0;
}

.markdown-message :deep(p) {
    margin: 0 0 0.75em;
}

.markdown-message :deep(h1),
.markdown-message :deep(h2),
.markdown-message :deep(h3),
.markdown-message :deep(h4),
.markdown-message :deep(h5),
.markdown-message :deep(h6) {
    font-weight: 600;
    line-height: 1.25;
    margin: 1em 0 0.5em;
}

.markdown-message :deep(h1) {
    font-size: 1.25rem;
}
.markdown-message :deep(h2) {
    font-size: 1.15rem;
}
.markdown-message :deep(h3) {
    font-size: 1.05rem;
}

.markdown-message :deep(ul),
.markdown-message :deep(ol) {
    margin: 0 0 0.75em;
    padding-left: 1.5em;
}

.markdown-message :deep(ul) {
    list-style: disc;
}

.markdown-message :deep(ol) {
    list-style: decimal;
}

.markdown-message :deep(li) {
    margin: 0.2em 0;
}

.markdown-message :deep(li > p) {
    margin: 0 0 0.3em;
}

.markdown-message :deep(a) {
    color: hsl(221 83% 53%);
    text-decoration: underline;
    text-underline-offset: 2px;
}

.markdown-message :deep(a:hover) {
    color: hsl(221 83% 42%);
}

:global(.dark) .markdown-message :deep(a) {
    color: hsl(213 94% 68%);
}

:global(.dark) .markdown-message :deep(a:hover) {
    color: hsl(213 94% 78%);
}

.markdown-message :deep(blockquote) {
    border-left: 3px solid var(--border);
    color: var(--muted-foreground);
    padding: 0.25em 0.75em;
    margin: 0 0 0.75em;
}

.markdown-message :deep(code) {
    font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, 'Liberation Mono', monospace;
    font-size: 0.85em;
    background: var(--muted);
    padding: 0.1em 0.35em;
    border-radius: 4px;
}

.markdown-message :deep(pre) {
    margin: 0 0 0.75em;
    padding: 0.75em 1em;
    background: hsl(0 0% 97%);
    border: 1px solid var(--border);
    border-radius: 8px;
    overflow-x: auto;
    font-size: 0.8125rem;
    line-height: 1.5;
}

:global(.dark) .markdown-message :deep(pre) {
    background: hsl(0 0% 10%);
}

.markdown-message :deep(pre code) {
    background: transparent;
    padding: 0;
    border-radius: 0;
    font-size: inherit;
}

.markdown-message :deep(table) {
    display: block;
    width: 100%;
    overflow-x: auto;
    border-collapse: collapse;
    margin: 0 0 0.75em;
}

.markdown-message :deep(th),
.markdown-message :deep(td) {
    border: 1px solid var(--border);
    padding: 0.35em 0.6em;
    text-align: left;
}

.markdown-message :deep(thead) {
    background: var(--muted);
}

.markdown-message :deep(tbody tr:hover) {
    background: var(--accent);
}

.markdown-message :deep(hr) {
    border: 0;
    border-top: 1px solid var(--border);
    margin: 1em 0;
}

.markdown-message :deep(strong) {
    font-weight: 600;
}
</style>
