# 🍽️ bistro

> **SaaS open source de gestión de restaurantes** para el mercado colombiano: pedidos y KDS, caja y cobros, facturación electrónica DIAN, inventario y compras, RBAC multi-empresa/multi-sede, WhatsApp y fidelización — todo en un solo lugar   Laravel + React.

[![License: MIT](https://img.shields.io/badge/license-MIT-22c55e)](LICENSE)
[![Backend release](https://img.shields.io/badge/backend-v1.50.0-FF2D20?logo=laravel&logoColor=white)](https://github.com/funcionbase/bistro/releases)
[![Frontend release](https://img.shields.io/badge/frontend-v1.66.0-61DAFB?logo=react&logoColor=black)](https://github.com/funcionbase/bistro/releases)
[![PRs welcome](https://img.shields.io/badge/PRs-welcome-blueviolet)](docs/wiki/Guía-de-Contribución.md)

**Backend** &nbsp; ![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel&logoColor=white) ![PHP](https://img.shields.io/badge/PHP-8.2-777BB4?logo=php&logoColor=white) ![Pest](https://img.shields.io/badge/Pest-3-5A67D8) ![PostgreSQL](https://img.shields.io/badge/PostgreSQL-336791?logo=postgresql&logoColor=white)

**Frontend** ![React](https://img.shields.io/badge/React-19-61DAFB?logo=react&logoColor=black) ![Vite](https://img.shields.io/badge/Vite-6-646CFF?logo=vite&logoColor=white) ![TS](https://img.shields.io/badge/TypeScript-5.7-3178C6?logo=typescript&logoColor=white) ![Tailwind](https://img.shields.io/badge/Tailwind-v4-38BDF8?logo=tailwindcss&logoColor=white)

---

## ¿Qué es bistro?

**bistro** es una plataforma multi-empresa y multi-sede para operar restaurantes de punta a punta: desde que el cliente pide por mesa, mostrador, domicilio o WhatsApp, hasta que la cocina lo prepara, la caja lo cobra, se factura ante la DIAN y el inventario se descuenta solo.

Nace de un producto real en producción — no es un starter kit ni un proyecto de juguete. Se publica como open source para que otros equipos puedan usarlo, adaptarlo o aprender de su arquitectura.

## ✨ Funcionalidades

- 🧾 **Pedidos y cocina** — tablero Kanban en tiempo real, KDS por estación, mesas, domicilios con autoasignación de repartidores, impresión térmica ESC/POS.
- 💳 **Caja y cobros** — apertura/cierre de turno, egresos, devoluciones, desglose tributario, múltiples medios de pago.
- 🧮 **Facturación electrónica DIAN** — documentos POS/FEV/notas crédito con CUFE/CUDE, régimen Simple, resoluciones por sede.
- 📦 **Inventario y compras** — insumos con costo promedio ponderado, recetas BOM con consumo automático, proveedores, órdenes de compra con flujo de aprobación.
- 📊 **Inteligencia operativa** — food cost en tiempo real, menu engineering (popularidad × margen), alertas accionables de margen/costo/stock.
- 🏢 **Multi-tenancy real** — una empresa (NIT) → N sedes → N bodegas, aislamiento a nivel de query, RBAC granular con ~82 permisos por dominio.
- 💬 **WhatsApp Cloud API** — bot de pedidos, chats con clientes, notificaciones automáticas.
- 🎁 **CRM y fidelización** — segmentación básica de clientes, puntos y tiers cross-sede, cupones (incluye happy hour programado).
- 📱 **PWA offline-first** — manifest dinámico por empresa, notificaciones push, sincronización idempotente sin conexión.

Manual funcional completo: [`docs/wiki/FUNCIONALIDADES_APP.md`](docs/wiki/FUNCIONALIDADES_APP.md).

## 🧱 Arquitectura

Monolito server-rendered con **Inertia.js v2** (React en el cliente, Laravel en el servidor) — sin capa REST pública para la SPA; los endpoints `/api/v1/*` existen para datos asíncronos e integraciones externas (bots de WhatsApp, webhooks).

| Capa | Tecnología |
|---|---|
| Backend | Laravel 12 + PHP 8.2, Pest para tests |
| SPA | React 19 + TypeScript + Tailwind CSS v4 |
| Base de datos | PostgreSQL |
| Caché / colas | Redis (o driver `database` en local) |
| Autenticación | Google OAuth + JWT propio |
| Almacenamiento | S3-compatible (MinIO en local) |
| PDF | DomPDF |
| WhatsApp | Evolution API |

Detalle de arquitectura, módulos y decisiones de diseño en la [wiki](docs/wiki/Home.md).

## 🚀 Quick start

Requisitos: PHP 8.2 + Composer, Node 20+, Docker (para BD/MinIO/Evolution API).

```bash
git clone https://github.com/funcionbase/bistro.git
cd bistro

docker compose -f docker/docker-compose.yml up -d   # PostgreSQL + MinIO + Evolution API

cd backend  && composer install && cp .env.example .env && php artisan key:generate && php artisan migrate --seed
cd ../frontend && npm install && cp .env.example .env

cd .. && npm run dev   # API + cola + Vite (HMR) en paralelo
```

Backend en `http://localhost`, frontend en `http://localhost:5173`. Detalle de
los servicios Docker (BD, MinIO, Evolution API) en
[`docker/README.md`](docker/README.md). Variables de entorno requeridas: [`docs/wiki/Variables-de-Entorno.md`](docs/wiki/Variables-de-Entorno.md).

## 📁 Estructura del repo

```
backend/    API Laravel 12 (app, config, database, routes, tests)
frontend/   SPA React 19 + TypeScript (src, public)
docker/     Infra local: PostgreSQL, MinIO, Evolution API
docs/wiki/  Documentación técnica y funcional
```

## 📚 Documentación

- [Wiki técnica y funcional](docs/wiki/Home.md) — mapa completo por área (auth, pedidos, caja, inventario, DIAN, etc.).
- [Manual funcional exhaustivo](docs/wiki/FUNCIONALIDADES_APP.md) — qué hace cada feature, endpoints, validaciones.
- [Guía de contribución](docs/wiki/Guía-de-Contribución.md) — setup local, convenciones, cómo proponer cambios.

## 🤝 Contribuir

Las contribuciones son bienvenidas — desde reportar un bug hasta proponer una feature. Antes de abrir un PR, revisá la [Guía de Contribución](docs/wiki/Guía-de-Contribución.md).

## 📄 Licencia

[MIT](LICENSE) © [funcionbase.com](https://funcionbase.com)
