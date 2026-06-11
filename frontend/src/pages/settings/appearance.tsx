import AppearanceTabs from '@/components/appearance-tabs';
import HeadingSmall from '@/components/heading-small';
import { PageShell } from '@/components/page-shell';

import SettingsLayout from '@/layouts/settings/layout';


export default function Appearance() {
    return (
        <PageShell title="Apariencia">
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Apariencia" description="Elige el tema visual de la app" />
                    <AppearanceTabs />
                </div>
            </SettingsLayout>
        </PageShell>
    );
}
