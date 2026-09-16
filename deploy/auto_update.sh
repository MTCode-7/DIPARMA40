#!/bin/bash
# DI PARMA | Auto Update wrapper (cron / SSH)
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
php "$ROOT/api/auto_update.php" "${1:-cron}"
