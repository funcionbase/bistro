# 🍽️ bistro · Bistro

## 🚀 Quick start

Requisitos: PHP 8.2 + Composer, Node 20+, Docker (para BD/MinIO/Evolution API).

```bash
git clone https://github.com/funcionbase-com/bistro.git
cd bistro

docker compose -f docker/docker-compose.yml up -d   # PostgreSQL + MinIO + Evolution API

cd backend  && composer install && cp .env.example .env && php artisan key:generate && php artisan migrate --seed
cd ../frontend && npm install && cp .env.example .env

cd .. && npm run dev   # API + cola + Vite (HMR) en paralelo
```

Backend en `http://localhost`, frontend en `http://localhost:5173`. Detalle de
los servicios Docker (BD, MinIO, Evolution API) en
[`docker/README.md`](docker/README.md).
