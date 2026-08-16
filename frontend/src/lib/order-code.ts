/**
 * Código corto legible de una orden: los dos primeros segmentos del UUID en
 * mayúscula (ej. `019E7DA6-3C13`). Espejo de `Order::shortCode()` en backend.
 * Único punto de verdad en frontend — reutilizar en tablero, detalle y
 * donde se muestre el id de orden, para no exponer el UUID completo.
 *
 * Es una referencia visual, no una clave única: en UUIDv7 estos segmentos
 * codifican el timestamp, así que órdenes creadas en el mismo instante pueden
 * compartir código (el id completo sigue disponible en el tooltip / detalle).
 */
export function shortOrderCode(id: string | null | undefined): string {
    if (!id) {
        return '';
    }

    return id.split('-').slice(0, 2).join('-').toUpperCase();
}
