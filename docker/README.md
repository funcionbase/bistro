# Dev environment (Docker)

Levanta los servicios de infraestructura/terceros necesarios para correr la
app localmente con paridad respecto a producción (Supabase + AWS S3 +
Evolution API). **Backend y frontend corren nativos en el host** (`npm run
dev` en la raíz, ver `../README.md`) — Docker es solo para lo que no tiene
sentido instalar a mano: BD, storage S3-compatible y el servidor de WhatsApp.

## Servicios

| Servicio | Imagen | Equivalente en prod |
|----------|--------|---------------------|
| `db` | `postgres:15-alpine` | Supabase managed PostgreSQL |
| `minio` | `minio/minio` | AWS S3 (buckets `*-assets`, `*-documents`) |
| `minio-bootstrap` | `minio/mc` | Equivalente al `aws s3 mb` + ACL + objeto `.health` |
| `pgweb` | `sosedoff/pgweb` | GUI de BD (solo dev) |
| `evolution-db` | `postgres:15-alpine` | Postgres propio de Evolution (Prisma) |
| `evolution-api` | `evoapicloud/evolution-api:v2.3.7` | Evolution API en el host EC2 (mismo puerto 8080) |

## Uso

```bash
docker compose up -d
```

Endpoints expuestos:

| Servicio | URL | Credenciales |
|----------|-----|-------------|
| Postgres | `localhost:5432` | `postgres` / `root`, db `laravel_app` |
| MinIO API (S3) | `http://localhost:9000` | `minioadmin` / `minioadmin` |
| MinIO Console | `http://localhost:9001` | `minioadmin` / `minioadmin` |
| pgweb | `http://localhost:8081` | — |
| Evolution API | `http://localhost:8080` | header `apikey: bistro-dev-local-token` |
| Evolution Manager UI (QR) | `http://localhost:8080/manager` | mismo `apikey` |

El servicio `minio-bootstrap` corre una sola vez al levantar y crea:

- Bucket `bistro-panel-local-assets` (download público, ACL anonymous).
- Bucket `bistro-panel-local-documents` (privado, sin ACL pública).
- Objeto `.health` en ambos (lo lee `HealthController::ready()`).

## Conectar la app Laravel

Editar `backend/.env`:

```ini
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=laravel_app
DB_USERNAME=postgres
DB_PASSWORD=root

FILESYSTEM_DISK=s3
INVOICE_STORAGE_DISK=s3_documents
AWS_ACCESS_KEY_ID=minioadmin
AWS_SECRET_ACCESS_KEY=minioadmin
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=bistro-panel-local-assets
AWS_BUCKET_DOCUMENTS=bistro-panel-local-documents
AWS_ENDPOINT=http://localhost:9000
AWS_USE_PATH_STYLE_ENDPOINT=true
AWS_URL=http://localhost:9000/bistro-panel-local-assets
```

> **Importante:** `AWS_USE_PATH_STYLE_ENDPOINT=true` es **crítico** para MinIO.
> Sin él, el SDK construye URLs vhost-style (`bucket.minio:9000`) que MinIO no resuelve.

## Verificar que funciona

```bash
cd backend
php artisan tinker --execute '
echo "assets:    " . (Storage::disk("s3")->exists(".health") ? "ok" : "fail") . PHP_EOL;
echo "documents: " . (Storage::disk("s3_documents")->exists(".health") ? "ok" : "fail") . PHP_EOL;
Storage::disk("s3")->put("test.txt", "hola");
echo "uploaded: " . Storage::disk("s3")->url("test.txt") . PHP_EOL;
'
```

Esperado:

```
assets:    ok
documents: ok
uploaded: http://localhost:9000/bistro-panel-local-assets/test.txt
```

Abrir esa URL en el browser → debe descargar `test.txt`.

## Conectar WhatsApp (Evolution API)

Descomentar el bloque "DEV LOCAL" de la sección `EVOLUTION API` en
`backend/.env.example` (copiarlo a `backend/.env`):

```ini
EVOLUTION_BASE_URL=http://localhost:8080
EVOLUTION_GLOBAL_TOKEN=bistro-dev-local-token
```

`bistro-dev-local-token` es el valor fijo de `AUTHENTICATION_API_KEY` que
`docker-compose.yml` le pasa al contenedor — mismo token en ambos lados o el
backend recibe 401.

Verificar que responde:

```bash
curl -H "apikey: bistro-dev-local-token" http://localhost:8080/instance/fetchInstances
```

Esperado: `[]` (sin instancias todavía). Para vincular un WhatsApp de prueba,
usar el flujo normal de la app (creación de canal → QR) o entrar directo al
Manager UI (`http://localhost:8080/manager`, mismo `apikey`).

## Resetear datos

```bash
docker compose down -v
rm -rf docker/postgres-data docker/minio-data docker/evolution-db-data
docker compose up -d
cd backend && php artisan migrate --seed
```

## Diferencias con prod

- **MinIO no es S3 real:** signed URLs (`temporaryUrl()`) funcionan, pero CORS y
  políticas IAM no se replican. Si una funcionalidad depende de eso (ej.
  CloudFront delante), validar adicionalmente en qa.
- **Postgres local no tiene `pg_cron`:** las cron jobs (purge_sessions,
  purge_cache, purge_cache_locks) sólo corren en Supabase. Localmente ejecutar
  el `DELETE` manualmente si se necesita probar el comportamiento.
- **No hay paridad de IAM:** en local cualquier credencial MinIO root accede a
  todo. En prod las EC2 usan IAM role con permisos limitados a los buckets.
- **Evolution local no tiene guard de líder:** en qa/pdn con N≥2 instancias EC2
  hay un mecanismo de líder único (`docs/wiki/WhatsApp-Bot.md`); en local solo
  hay un contenedor, no aplica.

## Referencias

- Issue #43 — T7 (mirror local de S3).
- `aws/iac/cloudformation/stacks/03-storage.yaml` — definición de los buckets en AWS.
- `docs/wiki/WhatsApp-Bot.md` — contrato de Evolution API / bot de WhatsApp.
- `backend/config/evolution.php` — config del cliente Evolution.
