import GoogleOnlyAuthGate from '@/components/auth/google-only-auth-gate';

/**
 * HU #231 — Los enlaces de reset-password ya no aplican: las cuentas usan
 * Google OAuth y no tienen contraseña gestionable. El gate redirige al
 * flujo Google con un mensaje contextual.
 */
export default function ResetPassword() {
    return <GoogleOnlyAuthGate variant="reset-password" />;
}
