import Markdown from '@/components/ui/markdown';
import ManualLayout from '@/layouts/manual-layout';
import { MANUAL_BASE } from '@/lib/manual-nav';
import { useEffect, useState } from 'react';
import { Navigate, useParams } from 'react-router-dom';

/**
 * Contenido del manual en markdown (`src/data/manual/<slug>.md`), cargado
 * lazy por Vite. Cada archivo lleva frontmatter con la meta que antes vivía
 * como props JSX (title, description, section, readingTime, lastUpdated).
 */
const pages = import.meta.glob('../../data/manual/*.md', { query: '?raw', import: 'default' }) as Record<
    string,
    () => Promise<string>
>;

interface PageData {
    title: string;
    description: string;
    metaTitle: string;
    metaDescription: string;
    section?: string;
    readingTime?: string;
    lastUpdated?: string;
    content: string;
}

function parsePage(raw: string): PageData {
    const match = raw.match(/^---\n([\s\S]*?)\n---\n([\s\S]*)$/);
    const fm = match?.[1] ?? '';
    const get = (key: string) => fm.match(new RegExp(`^${key}: "([^"]*)"`, 'm'))?.[1];
    return {
        title: get('title') ?? '',
        description: get('description') ?? '',
        metaTitle: get('metaTitle') ?? '',
        metaDescription: get('metaDescription') ?? '',
        section: get('section'),
        readingTime: get('readingTime'),
        lastUpdated: get('lastUpdated'),
        content: match?.[2] ?? raw,
    };
}

/** Página genérica del manual: resuelve `:slug` → markdown con frontmatter. */
export default function ManualMarkdownPage() {
    const { slug = '' } = useParams();
    const loader = pages[`../../data/manual/${slug}.md`];
    const [page, setPage] = useState<PageData | null>(null);

    useEffect(() => {
        setPage(null);
        if (!loader) return;
        let cancelled = false;
        loader().then((raw) => {
            if (!cancelled) setPage(parsePage(raw));
        });
        return () => {
            cancelled = true;
        };
    }, [slug, loader]);

    if (!loader) return <Navigate to={MANUAL_BASE} replace />;
    if (!page) return null;

    return (
        <ManualLayout
            key={slug}
            currentSlug={slug}
            pageTitle={page.title}
            pageDescription={page.description}
            metaTitle={page.metaTitle}
            metaDescription={page.metaDescription}
            sectionLabel={page.section}
            readingTime={page.readingTime}
            lastUpdated={page.lastUpdated}
        >
            <Markdown content={page.content} />
        </ManualLayout>
    );
}
