import GoogleOnlyAuthGate from '@/components/auth/google-only-auth-gate';

/**
 * HU #231 — En flexyflow no hay contraseñas que recuperar. Si el usuario
 * llega aquí desde un link viejo, el gate explica que el acceso es por
 * Google y dispara el redirect automático al flujo OAuth.
 */
export default function ForgotPassword() {
    return <GoogleOnlyAuthGate variant="forgot-password" />;
}
