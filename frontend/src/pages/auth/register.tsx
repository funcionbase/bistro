import GoogleOnlyAuthGate from '@/components/auth/google-only-auth-gate';

/**
 * HU #231 — Registro via Google OAuth únicamente. Ver `login.tsx` para el
 * patrón completo: esta página delega en `GoogleOnlyAuthGate` con copy
 * orientado a creación de cuenta.
 */
export default function Register() {
    return <GoogleOnlyAuthGate variant="register" />;
}
