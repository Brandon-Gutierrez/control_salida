#!/bin/sh
# Si no hay certificado en /etc/nginx/certs, genera uno autofirmado para que
# nginx pueda arrancar. Para producción real, coloca fullchain.pem y privkey.pem
# en la carpeta docker/certs del host (se monta en esa ruta) y reinicia el contenedor.
set -e

CERT_DIR=/etc/nginx/certs
CERT="$CERT_DIR/fullchain.pem"
KEY="$CERT_DIR/privkey.pem"

# Sin la carpeta montada no hay dónde dejarlo.
if [ ! -d "$CERT_DIR" ]; then
    exit 0
fi

if [ -s "$CERT" ] && [ -s "$KEY" ]; then
    exit 0
fi

# El CN es el nombre público sin puerto.
CN="${PUBLIC_HOST:-localhost}"
CN="${CN%%:*}"

echo "[certs] No hay certificado en $CERT_DIR: se genera uno autofirmado para $CN."
echo "[certs] Los navegadores y la app móvil lo marcarán como no confiable; usa un certificado real en producción."

# Si el CN es una IP se añade como IP en el SAN; si no, como DNS.
case "$CN" in
    *[!0-9.]*|"") SAN="DNS:$CN,DNS:localhost" ;;
    *) SAN="IP:$CN,DNS:localhost" ;;
esac

openssl req -x509 -nodes -newkey rsa:2048 -days 825 \
    -keyout "$KEY" -out "$CERT" \
    -subj "/CN=$CN" \
    -addext "subjectAltName=$SAN"

chmod 600 "$KEY"
