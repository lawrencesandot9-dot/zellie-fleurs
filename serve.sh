#!/data/data/com.termux/files/usr/bin/bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
HOST="${HOST:-127.0.0.1}"
PORT="${PORT:-8000}"

if [ ! -f "$ROOT/data/shop.db" ]; then
    echo "Preparing the Zellie Fleurs database…"
    php "$ROOT/bin/init_db.php"
fi

echo ""
echo "Zellie Fleurs is running at http://${HOST}:${PORT}"
echo "Storefront: http://${HOST}:${PORT}/"
echo "Studio:     http://${HOST}:${PORT}/admin/login.php"
echo "Press Ctrl+C to stop."
echo ""

exec php -S "${HOST}:${PORT}" -t "$ROOT/public"
