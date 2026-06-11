import { z } from 'zod';

/**
 * Configuración global de Zod: mensajes de error en español.
 *
 * `z.config(z.locales.es())` aplica el pack de traducciones ES integrado de
 * Zod 4 a TODOS los issues de validación. Así, cualquier validador sin un
 * `message` explícito (p. ej. `z.string().min(8)`) muestra español en vez
 * del inglés por defecto de Zod.
 *
 * Los `message` explícitos de cada schema siguen teniendo prioridad sobre
 * este locale — solo cubre los que no lo definen.
 *
 * Importar este módulo (side-effect) una sola vez en el entry point, antes
 * del primer render.
 */
z.config(z.locales.es());
