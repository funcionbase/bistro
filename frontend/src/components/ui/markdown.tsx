import React from 'react';
import ReactMarkdown from 'react-markdown';
import { Link } from 'react-router-dom';
import rehypeExternalLinks from 'rehype-external-links';
import rehypeRaw from 'rehype-raw';
import rehypeSanitize, { defaultSchema } from 'rehype-sanitize';
import remarkGfm from 'remark-gfm';

/**
 * Schema sanitizado para markdown trusted producido por staff.
 * Permite el conjunto mínimo necesario para textos extensos:
 * - Tipografía: h1-h4, p, strong, em, br, hr, blockquote.
 * - Listas: ul, ol, li.
 * - Código: code, pre, kbd.
 * - Tablas: table/thead/tbody/tr/th/td.
 * - Enlaces: a[href|title] con protocolos http/https/mailto.
 * - Manual: div.callout-* (avisos) y figure/figcaption/img (screenshots webp
 *   locales) — el HTML raw pasa por rehype-raw y LUEGO por este sanitize,
 *   así que todo lo no listado (script, iframe, on*, style) se elimina.
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
        'kbd',
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
        'div',
        'figure',
        'figcaption',
        'img',
    ],
    attributes: {
        ...defaultSchema.attributes,
        a: ['href', 'title'],
        div: [['className', 'callout', 'callout-info', 'callout-warn', 'callout-success']],
        img: ['src', 'alt', 'title', 'width', 'height', 'loading'],
    },
    protocols: {
        href: ['http', 'https', 'mailto'],
        src: ['http', 'https'],
    },
};

interface MarkdownProps {
    content: string;
    className?: string;
}

/** Links internos (`/manual/...`) navegan por SPA; el resto queda como <a>. */
function MdLink({ href, children, ...rest }: React.AnchorHTMLAttributes<HTMLAnchorElement>) {
    if (href?.startsWith('/')) {
        return <Link to={href}>{children}</Link>;
    }
    return (
        <a href={href} {...rest}>
            {children}
        </a>
    );
}

/**
 * `![alt](src "caption")` → figure con screenshot lazy + figcaption.
 * width/height por defecto = viewport de captura (1440x900) para evitar CLS.
 */
function MdImage({ src, alt, title, width, height }: React.ImgHTMLAttributes<HTMLImageElement>) {
    return (
        <figure>
            <img src={src} alt={alt} width={width ?? 1440} height={height ?? 900} loading="lazy" />
            {title && <figcaption>{title}</figcaption>}
        </figure>
    );
}

export default function Markdown({ content, className }: MarkdownProps) {
    return (
        <div className={className}>
            <ReactMarkdown
                remarkPlugins={[remarkGfm]}
                rehypePlugins={[
                    rehypeRaw,
                    [rehypeSanitize, sanitizeSchema],
                    [rehypeExternalLinks, { rel: ['noopener', 'noreferrer', 'nofollow'], target: '_blank' }],
                ]}
                components={{ a: MdLink, img: MdImage }}
            >
                {content}
            </ReactMarkdown>
        </div>
    );
}
