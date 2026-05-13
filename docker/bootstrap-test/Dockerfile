# Imagen para reproducir el ambiente de cloud-init/UserData de la EC2 y poder
# ejecutar `aws/ec2/install.sh` localmente sin esperar 30 min por una EC2.
#
# Diseño:
#  - Ubuntu 24.04 (igual que la AMI usada en el LaunchTemplate, ondrej/php
#    sigue funcionando ahi).
#  - Instala solo lo mínimo que UserData asume preinstalado (curl, ca-certs,
#    git, sudo, etc.). El resto lo va a instalar install.sh igual que en EC2.
#  - NO incluye systemd como PID1 ni snap; install.sh corre con `--skip-systemd`
#    + `--skip-swap` para evitar pasos no portables.
#
# Para reproducir el bug de "HOME/COMPOSER_HOME no seteado" — bug que solo se
# manifiesta cuando cloud-init invoca UserData con env sanitizado — el run.sh
# lanza el bash final dentro de `env -i PATH=... bash install.sh`, limpiando
# HOME y otras variables como en EC2.

FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive

# Paquetes que cloud-init garantiza en una AMI Ubuntu fresca. Los demas los
# instala install.sh (PHP, nginx, redis-tools, postgresql-client, etc).
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
    ca-certificates curl wget gnupg lsb-release software-properties-common \
    sudo git rsync \
 && rm -rf /var/lib/apt/lists/*

# Estructura de directorios que UserData crea antes de invocar install.sh.
RUN mkdir -p /opt/bootstrap /var/www/flexyflow.restaurante

WORKDIR /var/www/flexyflow.restaurante

# El run.sh va a copiar el repo aqui en cada ejecucion y luego invocar
# install.sh con env vacio. No definimos ENTRYPOINT — el comando se pasa
# desde docker run.
