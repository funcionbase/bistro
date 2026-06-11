import { useSetPageTitle } from '@/lib/page-title-context';
import { useDocumentTitle } from '@/lib/use-document-title';
import { type ReactNode, useEffect } from 'react';

interface PageShellProps {
    title: string;
    children: ReactNode;
}

export function PageShell({ title, children }: PageShellProps) {
    useDocumentTitle(title);
    const setPageTitle = useSetPageTitle();
    useEffect(() => {
        setPageTitle(title);
        return () => setPageTitle('');
    }, [title, setPageTitle]);
    return <>{children}</>;
}
