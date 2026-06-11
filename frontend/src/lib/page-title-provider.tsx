import { PageTitleContext } from '@/lib/page-title-context';
import { type ReactNode, useMemo, useState } from 'react';

export function PageTitleProvider({ children }: { children: ReactNode }) {
    const [title, setTitle] = useState('');
    const value = useMemo(() => ({ title, setPageTitle: setTitle }), [title]);
    return <PageTitleContext.Provider value={value}>{children}</PageTitleContext.Provider>;
}
