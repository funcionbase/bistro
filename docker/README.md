# Dev environment (Docker)

Levanta los servicios necesarios para correr la app localmente con paridad
respecto a producción (Supabase + AWS S3).

## Servicios

| Servicio | Imagen | Equivalente en prod |
|----------|--------|---------------------|
| `db` | `postgres:15-alpine` | Supabase managed PostgreSQL |
| `minio` | `minio/minio` | AWS S3 (buckets `*-assets`, `*-documents`) |
| `minio-bootstrap` | `minio/mc` | Equivalente al `aws s3 mb` + ACL + objeto `.health` |

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

El servicio `minio-bootstrap` corre una sola vez al levantar y crea:

- Bucket `flexyflow-restaurante-local-assets` (download público, ACL anonymous).
- Bucket `flexyflow-restaurante-local-documents` (privado, sin ACL pública).
- Objeto `.health` en ambos (lo lee `HealthController::ready()`).

## Conectar la app Laravel

Editar `application/.env`:

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
AWS_BUCKET=flexyflow-restaurante-local-assets
AWS_BUCKET_DOCUMENTS=flexyflow-restaurante-local-documents
AWS_ENDPOINT=http://localhost:9000
AWS_USE_PATH_STYLE_ENDPOINT=true
AWS_URL=http://localhost:9000/flexyflow-restaurante-local-assets
```

> **Importante:** `AWS_USE_PATH_STYLE_ENDPOINT=true` es **crítico** para MinIO.
> Sin él, el SDK construye URLs vhost-style (`bucket.minio:9000`) que MinIO no resuelve.

## Verificar que funciona

```bash
cd application
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
uploaded: http://localhost:9000/flexyflow-restaurante-local-assets/test.txt
```

Abrir esa URL en el browser → debe descargar `test.txt`.

## Resetear datos

```bash
docker compose down -v
rm -rf docker/postgres-data docker/minio-data
docker compose up -d
cd application && php artisan migrate --seed
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

## Referencias

- Issue #43 — T7 (mirror local de S3).
- `aws/iac/cloudformation/stacks/06-storage.yaml` — definición de los buckets en AWS.
