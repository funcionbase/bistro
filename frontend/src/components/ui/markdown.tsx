import React from 'react';
import ReactMarkdown from 'react-markdown';
import rehypeExternalLinks from 'rehype-external-links';
import rehypeSanitize, { defaultSchema } from 'rehype-sanitize';

/**
 * Schema sanitizado para markdown trusted producido por staff.
 * Permite el conjunto mínimo necesario para textos extensos:
 * - Tipografía: h1-h4, p, strong, em, br, hr, blockquote.
 * - Listas: ul, ol, li.
 * - Código: code, pre.
 * - Tablas: table/thead/tbody/tr/th/td.
 * - Enlaces: a[href|title] con protocolos http/https/mailto.
 *
 * Bloquea: <script>, <iframe>, <object>, <embed>, eventos on*,
 * style inline, javascript:, data:, vbscript:.
 *
 * Ver `docs/wiki/SECURITY_INPUT_HANDLING.md`.
 */
const sanitizeSchema = {
    ...defaultSchema,
    tagNames: [
        'h1',
        'h2',
        'h3',
        'h4',
        'p',
        'ul',
        'ol',
        'li',
        'strong',
        'em',
        'code',
        'pre',
        'blockquote',
        'a',
        'br',
        'hr',
        'table',
        'thead',
        'tbody',
        'tr',
        'th',
        'td',
    ],
    attributes: {
        ...defaultSchema.attributes,
        a: ['href', 'title'],
    },
    protocols: {
        href: ['http', 'https', 'mailto'],
    },
};

interface MarkdownProps {
    content: string;
    className?: string;
}

export default function Markdown({ content, className }: MarkdownProps) {
    return (
        <div className={className}>
            <ReactMarkdown
                rehypePlugins={[
                    [rehypeSanitize, sanitizeSchema],
                    [rehypeExternalLinks, { rel: ['noopener', 'noreferrer', 'nofollow'], target: '_blank' }],
                ]}
            >
                {content}
            </ReactMarkdown>
        </div>
    );
}
