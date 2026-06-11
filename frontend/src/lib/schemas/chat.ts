import { z } from 'zod';
import { plainTextLong, plainTextShort } from './common';

export const chatMessageSchema = z.object({
    body: plainTextLong(4000, { optional: false }),
});

export const chatContactSchema = z.object({
    name: plainTextShort(120, { optional: true }),
    phone: z
        .string()
        .nullish()
        .refine((v) => v == null || v === '' || /^[0-9+\-\s]+$/.test(v), {
            message: 'Teléfono inválido.',
        }),
    notes: plainTextLong(2000, { optional: true }),
});
