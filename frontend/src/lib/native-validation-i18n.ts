/**
 * Traduce los mensajes de validación nativa HTML5 (required, type="email",
 * min, max, etc.) al español, interceptando el evento `invalid` en fase
 * de captura antes de que el browser muestre su tooltip nativo.
 *
 * Se llama una sola vez desde `spa/main.tsx`. Los formularios con `noValidate`
 * no emiten `invalid`, así que este listener es el fallback para cualquier
 * formulario sin ese atributo.
 */
export function activateSpanishValidation(): void {
    document.addEventListener(
        'invalid',
        (e) => {
            const input = e.target as HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement;
            if (typeof input.setCustomValidity !== 'function') return;

            const v = input.validity;
            let message = '';

            if (v.valueMissing) {
                message = 'Este campo es obligatorio.';
            } else if (v.typeMismatch && (input as HTMLInputElement).type === 'email') {
                message = 'Ingresa un correo electrónico válido.';
            } else if (v.typeMismatch && (input as HTMLInputElement).type === 'url') {
                message = 'Ingresa una URL válida.';
            } else if (v.typeMismatch) {
                message = 'El valor no tiene el formato esperado.';
            } else if (v.tooShort) {
                message = `Mínimo ${(input as HTMLInputElement).minLength} caracteres.`;
            } else if (v.tooLong) {
                message = `Máximo ${(input as HTMLInputElement).maxLength} caracteres.`;
            } else if (v.rangeUnderflow) {
                message = `El valor mínimo es ${(input as HTMLInputElement).min}.`;
            } else if (v.rangeOverflow) {
                message = `El valor máximo es ${(input as HTMLInputElement).max}.`;
            } else if (v.stepMismatch) {
                message = 'El valor no es válido para el incremento definido.';
            } else if (v.patternMismatch) {
                message = input.title || 'El formato ingresado no es válido.';
            } else if (v.badInput) {
                message = 'El valor ingresado no es válido.';
            }

            if (message) {
                input.setCustomValidity(message);
                // Limpiar en la próxima edición para que la re-validación
                // funcione sin quedar atrapada en el customError.
                input.addEventListener('input', () => input.setCustomValidity(''), { once: true });
            }
        },
        true, // capture phase: intercepta aunque `invalid` no burbujee
    );
}
