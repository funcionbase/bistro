import { z } from 'zod';
import { plainTextShort } from './common';

/**
 * Schemas zod para flujos de autenticación (registro, perfil).
 */

export const registerSchema = z.object({
    first_name: plainTextShort(100),
    last_name: plainTextShort(100),
    email: z.string().email({ message: 'Correo inválido.' }).max(255),
    password: z.string().min(8, { message: 'Mínimo 8 caracteres.' }),
    password_confirmation: z.string().min(8),
});

export const profileSchema = z.object({
    first_name: plainTextShort(100),
    last_name: plainTextShort(100),
    email: z.string().email({ message: 'Correo inválido.' }).max(255),
    cedula: z
        .string()
        .nullish()
        .refine((v) => v == null || v === '' || /^[0-9]{5,20}$/.test(v), {
            message: 'La cédula debe contener solo dígitos (5 a 20 caracteres).',
        }),
});
