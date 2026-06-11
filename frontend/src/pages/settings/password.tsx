import { ShieldCheck } from 'lucide-react';

import HeadingSmall from '@/components/heading-small';
import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import SettingsLayout from '@/layouts/settings/layout';

/**
 * HU #231 — Settings · Contraseña: deshabilitado por diseño.
 *
 * flexyflow autentica únicamente con Google OAuth, así que las cuentas no
 * tienen contraseña gestionable por la app. La página antes hospedaba un
 * form de cambio de contraseña (POST a `/api/v1/account/password`); ese
 * endpoint hoy responde 410 Gone. La página se mantiene navegable para no
 * romper enlaces internos del sidebar de settings, pero solo informa.
 */
export default function Password() {
    return (
        <PageShell title="Contraseña">
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Cambio de contraseña deshabilitado"
                        description="Tu cuenta usa Google para iniciar sesión, no hay contraseña que cambiar acá."
                    />

                    <Alert variant="accent">
                        <ShieldCheck className="h-4 w-4" />
                        <AlertTitle>Tu acceso lo gestiona Google</AlertTitle>
                        <AlertDescription>
                            En flexyflow entras con OAuth de Google. Para cambiar la contraseña, hacelo desde la
                            configuración de tu cuenta de Google. La próxima vez que abras la app, flexyflow
                            reconocerá la nueva contraseña automáticamente.
                        </AlertDescription>
                    </Alert>

                    <p className="text-muted-foreground text-sm leading-relaxed">
                        ¿Querés cerrar tu sesión activa en este equipo? Usa el botón <span className="font-medium">Cerrar sesión</span> del menú de usuario. Si necesitás revocar el acceso a flexyflow desde tu cuenta de Google,
                        hacelo desde <span className="font-medium">Mi cuenta › Seguridad › Aplicaciones conectadas</span> en Google.
                    </p>
                </div>
            </SettingsLayout>
        </PageShell>
    );
}
