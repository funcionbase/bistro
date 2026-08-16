import GoogleOnlyAuthGate from '@/components/auth/google-only-auth-gate';

/**
 * La confirmación de contraseña ya no aplica: si necesitamos
 * reverificar identidad para acciones sensibles, hacemos un re-auth contra
 * Google OAuth. El gate se encarga del rebote.
 */
export default function ConfirmPassword() {
    return <GoogleOnlyAuthGate variant="confirm-password" />;
}
