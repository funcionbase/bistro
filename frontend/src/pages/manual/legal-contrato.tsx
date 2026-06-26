import Markdown from '@/components/ui/markdown';
import ManualLayout from '@/layouts/manual-layout';
import contratoRaw from '@/data/legal/contrato.md?raw';

function parseFrontmatter(raw: string): { content: string; version: string; publishedAt: string } {
    const match = raw.match(/^---\n([\s\S]*?)\n---\n([\s\S]*)$/);
    if (!match) return { content: raw, version: '', publishedAt: '' };
    const fm = match[1];
    const version = fm.match(/version:\s*"([^"]+)"/)?.[1] ?? '';
    const publishedAt = fm.match(/published_at:\s*"([^"]+)"/)?.[1] ?? '';
    return { content: match[2], version, publishedAt };
}

const { content, version, publishedAt } = parseFrontmatter(contratoRaw);

export default function ManualLegalContrato() {
    return (
        <ManualLayout
            currentSlug="legal/contrato"
            pageTitle="Contrato de servicio"
            pageDescription={`Versión ${version} — Vigente desde el ${publishedAt}. El acuerdo entre tu empresa y flexyflow.`}
            metaTitle="Contrato de servicio — bistro.flexyflow.co"
            metaDescription="Contrato de Servicio de bistro flexyflow: alcance, periodo freemium, facturación, propiedad de los datos y resolución de conflictos."
            sectionLabel="legal"
        >
            <Markdown content={content} className="wiki-prose" />
        </ManualLayout>
    );
}
