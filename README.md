# 🍽️ flexyflow · Bistro

> SaaS multi-empresa para restaurantes en Colombia: empresas, sedes, menús, pedidos, facturación de suscripción y RBAC granular. Deploys e IaC viven en [`apps-flexyflow-co`](https://github.com/cristianmarint/apps-flexyflow-co).

[![Bistro PDN](https://img.shields.io/badge/app-bistro.flexyflow.co-22c55e?logo=cloudflare&logoColor=white)](https://bistro.flexyflow.co)
[![API PDN](https://img.shields.io/badge/api-panel--api.flexyflow.co-22c55e?logo=amazonaws&logoColor=white)](https://bistro-api.flexyflow.co)
[![Ops App Deploy](https://github.com/cristianmarint/apps-flexyflow-co/actions/workflows/ops-app-deploy.yml/badge.svg)](https://github.com/cristianmarint/apps-flexyflow-co/actions/workflows/ops-app-deploy.yml)
[![License](https://img.shields.io/badge/license-proprietary-red)](LICENSE)

[![Backend release](https://img.shields.io/badge/backend-v1.46.0-FF2D20?logo=laravel&logoColor=white)](https://github.com/cristianmarint/bistro-flexyflow-co/releases)
[![Frontend release](https://img.shields.io/badge/frontend-v1.63.0-61DAFB?logo=react&logoColor=black)](https://github.com/cristianmarint/bistro-flexyflow-co/releases)

**Backend** &nbsp; ![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel&logoColor=white) ![PHP](https://img.shields.io/badge/PHP-8.2-777BB4?logo=php&logoColor=white) ![Pest](https://img.shields.io/badge/Pest-3-5A67D8) ![PostgreSQL](https://img.shields.io/badge/PostgreSQL-336791?logo=postgresql&logoColor=white)

**Frontend** ![React](https://img.shields.io/badge/React-19-61DAFB?logo=react&logoColor=black) ![Vite](https://img.shields.io/badge/Vite-6-646CFF?logo=vite&logoColor=white) ![TS](https://img.shields.io/badge/TypeScript-5.7-3178C6?logo=typescript&logoColor=white) ![Tailwind](https://img.shields.io/badge/Tailwind-v4-38BDF8?logo=tailwindcss&logoColor=white)

---

## 🧭 Arquitectura

**Split deploy**: backend y frontend independientes, infra distinta. La infra
([`../aws/`](../aws/)) y el dev local ([`../docker/`](../docker/)) son recursos
compartidos del monorepo.

| Plano | Stack | Despliegue | Dominio |
|-------|-------|-----------|---------|
| **Backend** | Laravel 12 · PHP 8.2 · PostgreSQL · API JWT | AWS EC2 (ASG) vía SSM — workflow *Ops App Deploy* (`qa`/`pdn`) | `bistro-api.flexyflow.co` |
| **Frontend** | React 19 · Vite · Tailwind v4 · React Router 7 · TanStack Query | Cloudflare — worker `bistro-flexyflow-co` (`wrangler deploy`) | `bistro.flexyflow.co` |

```
bistro/
├── backend/      Laravel · API REST
├── frontend/     React · SPA
└── package.json  orquesta dev: backend + cola + frontend
```

> 🔁 **Rebranding en curso**: la marca `panel` → `bistro` ya se aplicó en carpeta,
> worker Cloudflare y workflows. Los **hosts** (`bistro.flexyflow.co` /
> `bistro-api.flexyflow.co`) y los recursos AWS migran a `bistro.*` en un cutover
> pendiente — ver [`../plan-ordenamiento.md`](../plan-ordenamiento.md) §10 y
> [`../aws/NAMING_CONVENTION.md`](../aws/NAMING_CONVENTION.md).

## ✨ Qué hace

🏢 Multi-empresa / multi-sede (`company_nit` + `branch_id`) &nbsp;·&nbsp; 🔐 RBAC granular &nbsp;·&nbsp; 🧾 Facturación de suscripción &nbsp;·&nbsp; 📧 Notificaciones billing idempotentes &nbsp;·&nbsp; 🍽️ Menús, pedidos y onboarding &nbsp;·&nbsp; 🪪 Auth Google OAuth + JWT.

## 🚀 Quick start

```bash
git clone https://github.com/cristianmarint/bistro-flexyflow-co.git
cd bistro-flexyflow-co

cd backend  && composer install && cp .env.example .env && php artisan key:generate && php artisan migrate --seed
cd ../frontend && npm install && cp .env.example .env

cd .. && npm run dev   # API + cola + Vite (HMR) en paralelo
```

> `docker compose up -d` en [`../docker/`](../docker/) levanta PostgreSQL + MinIO para dev.

## 📚 Docs

[Índice del wiki](../docs/wiki/Home.md) · [Backend](../docs/wiki/BACKEND_FILES.md) · [UI](frontend/FRONTEND_UI_GUIDELINES.md) · [RBAC / Contabilidad](backend/constants/) · [Infra](../aws/)

## 📄 Licencia

Software **propietario** © 2026 flexyflow — no es código abierto. Uso/copia/despliegue requieren autorización por escrito. Ver [`../LICENSE`](LICENSE).

Mantenedor: **Cristian Marin** · [@cristianmarint](https://github.com/cristianmarint) · cristian@flexyflow.co
