import GoogleOnlyAuthGate from '@/components/auth/google-only-auth-gate';

/**
 * HU #231 — La verificación de correo viene incluida en el callback Google
 * (el provider entrega `email_verified`). Esta página informa y rebota al
 * flujo OAuth.
 */
export default function VerifyEmail() {
    return <GoogleOnlyAuthGate variant="verify-email" />;
}
