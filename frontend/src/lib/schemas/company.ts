import { z } from 'zod';
import { plainTextLong, plainTextShort } from './common';

export const companySchema = z.object({
    commercial_name: plainTextShort(255),
    legal_name: plainTextShort(255),
    breb_key: plainTextLong(255, { optional: true }),
});

export const branchSchema = z.object({
    name: plainTextShort(120),
    address: plainTextLong(255, { optional: true }),
    city: plainTextShort(120, { optional: true }),
});
