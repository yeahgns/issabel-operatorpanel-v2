#!/bin/bash
# Remove o menu e a regra do Apache. A pasta do módulo (o repositório) fica; apague à mão.
# Use --purge para apagar também /etc/issabel/painel-operador.conf.php.
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "Rode como root." >&2; exit 1; }
DIR="$(cd "$(dirname "$0")" && pwd)"
ID="$(basename "$DIR")"
issabel-menuremove "$ID" || true
rm -rf "/usr/share/issabel/module_installer/$ID"
rm -f "/etc/httpd/conf.d/$ID.conf"
systemctl reload httpd 2>/dev/null || apachectl graceful || true
rm -f /tmp/painel-operador-*.json /tmp/painel-operador.lock 2>/dev/null || true
[ "${1:-}" = "--purge" ] && rm -f /etc/issabel/painel-operador.conf.php
echo "Menu removido. Para apagar os arquivos: rm -rf $DIR"
