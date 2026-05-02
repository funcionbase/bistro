# FlexyFlow Restaurante — Wiki

> Documentación técnica y funcional de la plataforma SaaS de gestión de restaurantes.
> Estado del repositorio: rama `feature/v1`. Última actualización: 2026-05-02.

---

## ¿Qué es FlexyFlow?

Plataforma multi-empresa para la gestión operativa de restaurantes en Colombia. Cubre:

- Carta digital con menús programables.
- Pedidos en tiempo real (kanban, integración con bot de WhatsApp).
- Domicilios con asignación y reasignación de repartidores.
- Cupones promocionales con validaciones avanzadas.
- Horarios comerciales con excepciones.
- Métricas operativas y exportes PDF.
- Cobranza mensual con planes de suscripción.
- RBAC granular por feature y acción.

---

## Stack

| Capa | Tecnología |
|------|-----------|
| Backend | Laravel 12 + PHP 8.2 |
| SPA | Inertia.js v2 + React 19 + TypeScript |
| Estilos | Tailwind CSS v4 |
| BD | PostgreSQL |
| Auth | Google OAuth + JWT custom (HS256, payload AES-256-CBC) |
| PDF | DomPDF |
| Almacenamiento | Local / S3 |
| Caché y Colas | Redis o Database |

---

## Índice de páginas

### Autenticación y Acceso
- [Autenticación](Autenticaci%C3%B3n.md) — Flujo OAuth Google, JWT, middleware.
- [Usuarios, Roles y Permisos](Usuarios-Roles-Permisos.md) — RBAC, matriz de permisos, invitaciones.

### Dominios funcionales
- [Empresas](Empresas.md) — Multi-tenancy, registro, configuración.
- [Menú](Men%C3%BA.md) — CRUD, programación, menú público.
- [Pedidos](Pedidos.md) — Kanban, estados, items JSON.
- [Repartidores](Repartidores.md) — Asignación, métricas.
- [Cupones](Cupones.md) — Tipos, redenciones, validación.
- [Horarios](Horarios.md) — Base + excepciones.
- [Dashboard y Métricas](Dashboard-M%C3%A9tricas.md) — KPIs, heatmaps, caché.
- [Facturación](Facturaci%C3%B3n.md) — Planes, facturas, PDFs, cron.

### Integraciones
- [WhatsApp Bot](WhatsApp-Bot.md) — JWT del bot, endpoints externos, sesión de carrito.

### Frontend
- [Frontend](Frontend.md) — Arquitectura React + Inertia, componentes, patrones.

### Operación
- [Variables de Entorno](Variables-de-Entorno.md) — Configuración `.env`.
- [Errores API](Errores-API.md) — Códigos HTTP y de aplicación.
- [Guía de Contribución](Gu%C3%ADa-de-Contribuci%C3%B3n.md) — Flujo de trabajo, DoD, checklist de PR.

---

## Convenciones de las páginas

Cada página comienza con un encabezado que indica:

```
> Estado: Estable | En desarrollo | Deprecado
> Versión API: v1
> Owner: equipo de plataforma
```

Los endpoints se documentan en tablas con el formato:

| Método | Ruta | Auth | Permiso | Descripción |
|--------|------|------|---------|-------------|

Los ejemplos de request/response usan bloques `http` y `json` con datos representativos pero sin información sensible.

---

## ¿Cómo se publica este wiki?

Los archivos `.md` viven bajo `docs/wiki/` en la raíz del repo principal y se versionan junto con el código. El despliegue al wiki de GitHub (`{repo}.wiki.git`) se realiza con `git push wiki main` desde un script de operación (futuro CI cuando `WIKI_AUTO_UPDATE=true`).
