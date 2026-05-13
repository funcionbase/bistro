# bootstrap-test

Setup local para probar `aws/ec2/install.sh` dentro de Docker — itera en
segundos en lugar de los 15-30 min que cuesta un instance refresh + ASG
cycling en EC2.

## Qué reproduce

- Ubuntu 24.04 (misma distro que la AMI del LaunchTemplate)
- Invocación de `install.sh` con **env vacío** (`env -i PATH=...`), reproduciendo
  el ambiente sanitizado que cloud-init le pasa a UserData en EC2 — donde
  variables como `HOME` no están seteadas por defecto.

## Qué NO reproduce

- ARM64 (Docker en Windows corre amd64 por defecto; los bugs de scripting,
  permisos y env son independientes de la arquitectura).
- `systemd` como PID1 (los pasos que dependen exclusivamente de systemctl
  hacen `early-return` con `--skip-systemd`).
- `swapon` (Docker bloquea swap; usamos `--skip-swap`).
- Servicios reales corriendo (nginx/php-fpm/queue/scheduler quedan
  configurados en disco pero no se levantan).
- Métadata de EC2 (IAM role, Instance Metadata Service v2, etc).

## Uso

```bash
# Test rápido (sin npm run build): ~3-5 min la 1ra vez, ~1 min las siguientes
docker/bootstrap-test/run.sh

# Test completo incluyendo npm run build (más lento pero igual al EC2 real)
docker/bootstrap-test/run.sh --build

# Entrar a un shell del contenedor (sin correr install.sh)
docker/bootstrap-test/run.sh --shell
```

## Cómo funciona

1. `Dockerfile` construye una imagen Ubuntu 24.04 con solo los paquetes
   que cloud-init garantiza en una AMI fresca (curl, ca-certs, git, sudo).
2. `run.sh` lanza un contenedor que:
   - Monta el repo del host (`/src`, read-only)
   - Copia el repo a `/var/www/flexyflow.restaurante` dentro del contenedor
   - Invoca `install.sh` con `env -i PATH=...` para limpiar HOME y reproducir
     el ambiente de cloud-init
   - Pasa los flags `--skip-clone --skip-swap --skip-systemd` (y opcionalmente
     `--skip-build`)

## Ciclo de iteración rápido

```
# Editar install.sh
docker/bootstrap-test/run.sh

# Si el output muestra el bug → fix → re-correr (la mayoría de capas Docker
# están cacheadas, así que solo se re-ejecuta install.sh)
# Cuando pase → commit + push → validar en EC2 vía iac-stacks + app-deploy
```
