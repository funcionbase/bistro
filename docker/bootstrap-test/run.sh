#!/usr/bin/env bash
# Lanza un contenedor que reproduce el ambiente de cloud-init/UserData de la
# EC2 para probar `aws/ec2/install.sh` localmente — sin esperar a un instance
# refresh ni a healthchecks de ALB.
#
# Uso:
#   docker/bootstrap-test/run.sh             # corre install.sh completo (sin npm build)
#   docker/bootstrap-test/run.sh --build     # corre install.sh con npm run build (lento)
#   docker/bootstrap-test/run.sh --shell     # entra a bash sin correr install.sh
#
# Banderas que paso a install.sh:
#   --skip-clone     repo ya esta montado en /var/www/flexyflow.restaurante
#   --skip-swap      Docker no soporta swapon de forma confiable
#   --skip-systemd   no hay PID1=systemd, asi que systemctl y snap son no-ops
#   --skip-build     omite `npm run build` (toggleable con flag --build aqui)
#
# Reproduce el bug de HOME via `env -i PATH=...`, que arranca con env vacio
# igual que cloud-init.

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$HERE/../.." && pwd)"
IMAGE="flexyflow-bootstrap-test"

# En Git Bash / MSYS sobre Windows, `/d/path` se convierte automaticamente a
# `D:\path` al pasarlo a docker, lo que confunde el daemon Linux. Convertimos
# de forma controlada usando cygpath si existe (Windows), sino dejamos la
# ruta tal cual (Linux/macOS). MSYS_NO_PATHCONV=1 evita conversiones
# inesperadas en los argumentos de docker.
export MSYS_NO_PATHCONV=1
to_docker_path() {
    if command -v cygpath >/dev/null 2>&1; then
        cygpath -w "$1" | tr '\\' '/'
    else
        printf '%s' "$1"
    fi
}
REPO_ROOT_DOCKER="$(to_docker_path "$REPO_ROOT")"
HERE_DOCKER="$(to_docker_path "$HERE")"
echo "[run.sh] REPO_ROOT host       = $REPO_ROOT"
echo "[run.sh] REPO_ROOT for docker = $REPO_ROOT_DOCKER"

DO_BUILD=0
DO_SHELL=0
for arg in "$@"; do
    case "$arg" in
        --build) DO_BUILD=1 ;;
        --shell) DO_SHELL=1 ;;
        -h|--help)
            sed -n '2,18p' "$0"
            exit 0
            ;;
        *) echo "[run.sh] flag desconocida: $arg" >&2; exit 1 ;;
    esac
done

echo "[run.sh] Building $IMAGE..."
docker build -t "$IMAGE" "$HERE_DOCKER"

if [[ $DO_SHELL -eq 1 ]]; then
    echo "[run.sh] Entrando a shell en contenedor (sin correr install.sh)"
    docker run --rm -it \
        -v "${REPO_ROOT_DOCKER}:/src:ro" \
        --name "${IMAGE}-shell" \
        "$IMAGE" \
        bash -c "cp -r /src/. /var/www/flexyflow.restaurante/ 2>/dev/null; cd /var/www/flexyflow.restaurante && exec bash"
    exit 0
fi

INSTALL_FLAGS=(
    --skip-clone
    --skip-swap
    --skip-systemd
)
if [[ $DO_BUILD -eq 0 ]]; then
    INSTALL_FLAGS+=( --skip-build )
fi

# Construyo el comando interno como una sola linea segura.
# rsync con --exclude es 10-20x mas rapido que cp -r sobre el bind mount
# de Windows porque salta node_modules/vendor/.git (centenares de MBs que
# install.sh va a recrear igual con composer install / npm ci).
INNER_CMD="set -e
echo '[run.sh] Sincronizando repo a /var/www (excluyendo node_modules, vendor, .git)'
rsync -a \\
  --exclude='/.git/' \\
  --exclude='node_modules/' \\
  --exclude='vendor/' \\
  --exclude='/application/public/build/' \\
  --exclude='/application/storage/logs/' \\
  --exclude='/application/storage/framework/cache/' \\
  --exclude='/application/storage/framework/sessions/' \\
  --exclude='/application/storage/framework/views/' \\
  --exclude='/docker/postgres-data/' \\
  --exclude='/testing/_temp/' \\
  --exclude='/_temp/' \\
  /src/ /var/www/flexyflow.restaurante/
cd /var/www/flexyflow.restaurante
echo
echo '[run.sh] ===== ENV antes de env -i ====='
env | sort
echo
echo '[run.sh] ===== Invocando install.sh con env limpio (env -i) ====='
exec env -i PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \\
    bash /var/www/flexyflow.restaurante/aws/ec2/install.sh ${INSTALL_FLAGS[*]}
"

echo "[run.sh] Lanzando contenedor con flags: ${INSTALL_FLAGS[*]}"
docker run --rm \
    -v "$REPO_ROOT:/src:ro" \
    --name "$IMAGE" \
    "$IMAGE" \
    bash -c "$INNER_CMD"
