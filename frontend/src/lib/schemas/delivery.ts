import { z } from 'zod';
import { plainTextLong } from './common';

export const deliveryRejectSchema = z.object({
    reason: plainTextLong(255, { optional: true }),
});

export const deliveryAddressSchema = z.object({
    delivery_address: plainTextLong(500, { optional: true }),
    notes: plainTextLong(500, { optional: true }),
});
