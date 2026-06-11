import { z } from 'zod';
import { plainTextLong, plainTextShort } from './common';

/**
 * Schemas zod para gestión de menús.
 */

export const menuSchema = z.object({
    name: plainTextShort(128),
    description: plainTextLong(512, { optional: true }),
});

export const menuCategorySchema = z.object({
    name: plainTextShort(128),
    description: plainTextLong(512, { optional: true }),
});

export const menuItemSchema = z.object({
    name: plainTextShort(128),
    description: plainTextLong(512, { optional: true }),
    price: z.coerce.number().min(0),
});
