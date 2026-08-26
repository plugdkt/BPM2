#!/bin/sh
set -e

# dev-only convenience: สร้าง config/config.php อัตโนมัติจาก template ของ docker ถ้ายังไม่มี
# (config/config.php ถูก .gitignore ไว้ ห้าม commit — บน production ต้องสร้างมือตามข้อ 11 ใน spec.md เสมอ ไม่ใช้ entrypoint นี้)
if [ ! -f /var/www/html/config/config.php ]; then
    cp /var/www/html/docker/config.docker.php /var/www/html/config/config.php
    echo "[bpm-entrypoint] สร้าง config/config.php จาก docker/config.docker.php ให้อัตโนมัติแล้ว (dev only)"
fi

exec "$@"
