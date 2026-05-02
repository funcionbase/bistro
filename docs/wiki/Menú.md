# Menú

> Estado: Estable
> Versión API: v1
> Owner: equipo de plataforma

---

## Visión general

Cada empresa puede tener varios menús; **solo uno puede estar `active`** simultáneamente. El menú activo se entrega públicamente al bot y al cliente final vía `GET /api/v1/public/menu/{companyNit}`. Cada menú se compone de categorías, y cada categoría de ítems (platos).

---

## Estructura JSON v3

El menú se almacena en `restaurant_menus.structure` como JSON v3 (única versión soportada):

```json
{
  "version": 3,
  "categories": [
    {
      "id": "uuid",
      "name": "Platos principales",
      "description": "...",
      "sort_order": 1,
      "items": [
        {
          "id": "uuid",
          "name": "Bandeja paisa",
          "description": "...",
          "price": 32000,
          "available": true,
          "image_url": "/storage/menus/12/bandeja.webp",
          "sort_order": 1
        }
      ]
    }
  ]
}
```

`active_days` es un JSON array adicional (`[1,2,3,4,5]` = lun–vie; convención Carbon donde 0 = domingo).

---

## Endpoints

### CRUD de menús

| Método | Ruta | Permiso | Descripción |
|--------|------|---------|-------------|
| `GET` | `/api/v1/menus` | `menu.read,read` | Lista menús de la empresa |
| `POST` | `/api/v1/menus` | `menu.create,create` | Crea menú vacío |
| `GET` | `/api/v1/menus/{id}` | `menu.read,read` | Detalle de un menú |
| `PUT` | `/api/v1/menus/{id}` | `menu.update,update` | Renombra/edita metadatos |
| `DELETE` | `/api/v1/menus/{id}` | `menu.delete,delete` | Elimina menú |
| `POST` | `/api/v1/menus/{id}/duplicate` | `menu.create,create` | Clona como `draft` |
| `PATCH` | `/api/v1/menus/{id}/activate` | `menu.update,update` | Activa (desactiva los demás) |
| `PATCH` | `/api/v1/menus/{id}/schedule` | `menu.update,update` | Define `active_days` |
| `POST` | `/api/v1/menus/sync-schedule` | `menu.update,update` | Recalcula activación según día actual |

### CRUD de categorías

| Método | Ruta | Permiso |
|--------|------|---------|
| `POST` | `/api/v1/menus/{id}/categories` | `menu.create,create` |
| `PUT` | `/api/v1/menus/{id}/categories/{catId}` | `menu.update,update` |
| `DELETE` | `/api/v1/menus/{id}/categories/{catId}` | `menu.delete,delete` |

### CRUD de ítems

| Método | Ruta | Permiso |
|--------|------|---------|
| `POST` | `/api/v1/menus/{id}/categories/{catId}/items` | `menu.create,create` |
| `PUT` | `/api/v1/menus/{id}/categories/{catId}/items/{itemId}` | `menu.update,update` |
| `PUT` | `/api/v1/menus/{id}/items/{itemId}` | `menu.update,update` (edición directa) |
| `DELETE` | `/api/v1/menus/{id}/categories/{catId}/items/{itemId}` | `menu.delete,delete` |
| `POST` | `/api/v1/menus/{id}/items/{itemId}/image` | `menu.update,update` |
| `PATCH` | `/api/v1/menus/{id}/categories/{catId}/items/{itemId}/availability` | `menu.update,update` |

### Menú público

| Método | Ruta | Auth | Descripción |
|--------|------|------|-------------|
| `GET` | `/api/v1/public/menu/{companyNit}` | `jwt` (sin `company.access`) | Devuelve solo ítems disponibles del menú activo. Usado por bot y app pública |

---

## Ejemplos

### Crear ítem

```http
POST /api/v1/menus/12/categories/uuid-cat/items HTTP/1.1
Content-Type: application/json

{
  "name": "Limonada de coco",
  "description": "Fresca y cremosa",
  "price": 8000,
  "available": true
}
```

```http
HTTP/1.1 201 Created
{
  "item": {
    "id": "uuid-new",
    "name": "Limonada de coco",
    "price": 8000,
    "available": true,
    "image_url": null,
    "sort_order": 5
  }
}
```

### Activar menú

```http
PATCH /api/v1/menus/12/activate HTTP/1.1
```

```http
HTTP/1.1 200 OK
{ "menu": { "id": 12, "status": "active" } }
```

Si ya hay otro activo: `422 MENU_ALREADY_ACTIVE` (el frontend muestra modal de confirmación que reintenta con `force=true`).

### Toggle de disponibilidad

```http
PATCH /api/v1/menus/12/categories/uuid-cat/items/uuid-item/availability HTTP/1.1
Content-Type: application/json

{ "available": false }
```

---

## Imágenes de platos

- Suben con `POST /menus/{id}/items/{itemId}/image` como `multipart/form-data`.
- Validación: JPG/PNG, máx. 2 MB.
- Almacenadas en el disco de `filesystems.php` (default `public`); ruta guardada en el ítem.
- URL temporal de 60 min cuando el disco es S3.

---

## Programación

Cada menú lleva `active_days: number[]` (0=domingo, 6=sábado). El comando programado y el endpoint `/menus/sync-schedule` activan automáticamente el menú correspondiente al día actual cuando hay solapamiento.

---

## Notas de seguridad

- Solo un menú `active` por empresa.
- El menú público es accesible con cualquier JWT válido (incluido el del bot) **sin** verificar membresía — la única autenticación es la firma del JWT. No incluir información sensible en el menú.
- El backend cachea el menú activo de cada empresa para reducir lecturas; cualquier mutación lo invalida.
