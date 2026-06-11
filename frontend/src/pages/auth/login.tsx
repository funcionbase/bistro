import GoogleOnlyAuthGate from '@/components/auth/google-only-auth-gate';

/**
 * HU #231 — Acceso únicamente vía Google OAuth.
 *
 * Esta página antes hospedaba el formulario email/password de Breeze. Hoy
 * delega 100% en `GoogleOnlyAuthGate`. El archivo se mantiene (no se borra)
 * porque `route('login')` desde el frontend y `routes/auth.php` apuntan
 * acá; el componente lee `?reason=` y muestra mensaje contextual si el
 * backend rebotó al usuario.
 */
export default function Login() {
    return <GoogleOnlyAuthGate variant="login" />;
}
