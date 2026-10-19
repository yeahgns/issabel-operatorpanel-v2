#!/bin/bash
# Painel do Operador - instalação e atualização. Pode rodar quantas vezes quiser.
#   cd /var/www/html/modules/painel_operador && git pull && bash install.sh
# O nome desta pasta vira o nome do menu no Issabel. Este script não altera nenhum
# arquivo do repositório (senão o próximo git pull daria conflito).
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
ID="$(basename "$DIR")"
VERSION="$(cat "$DIR/VERSION")"
SHARE="/usr/share/issabel/module_installer/$ID"
CONF=/etc/issabel/painel-operador.conf.php
APACHE="/etc/httpd/conf.d/$ID.conf"

say()  { echo -e "\033[1;34m==>\033[0m $*"; }
warn() { echo -e "   \033[1;33mAviso:\033[0m $*"; }
fail() { echo -e "\033[1;31mErro:\033[0m $*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || fail "rode como root."
[[ "$ID" =~ ^[a-z][a-z0-9_]*$ ]] || fail "o nome da pasta ($ID) vira o nome do menu: use só minúsculas, números e _ (ex.: painel_operador). Renomeie com: mv \"$DIR\" /var/www/html/modules/painel_operador"
[ "$ID" != "control_panel" ] || fail "não use o nome control_panel: é o painel original do Issabel."
[ "$(dirname "$DIR")" = "/var/www/html/modules" ] || fail "esta pasta precisa ficar dentro de /var/www/html/modules (está em $DIR)."
command -v issabel-menumerge >/dev/null || fail "issabel-menumerge não encontrado; isto não parece um Issabel 5."
command -v php >/dev/null || fail "php não encontrado."

say "Instalando Painel do Operador $VERSION como menu '$ID'"

say "Conferindo os arquivos PHP"
for f in "$DIR/index.php" "$DIR"/lib/*.php; do
    php -l "$f" >/dev/null 2>&1 || { php -l "$f"; fail "erro de sintaxe em $f"; }
done

# O Apache (usuário asterisk) só precisa ler. O dono continua root de propósito:
# o git recusa "git pull" como root num repositório que pertence a outro usuário.
chmod -R a+rX "$DIR"

say "Bloqueando o acesso pelo navegador a .git, lib, setup e scripts"
cat > "$APACHE" <<CONF
# Gerado pelo install.sh do Painel do Operador. Recriado a cada instalação; não edite.
<DirectoryMatch "^/var/www/html/modules/$ID/(\.git|lib|setup|build|tests)(/|\$)">
    Require all denied
</DirectoryMatch>
<Directory "/var/www/html/modules/$ID">
    <FilesMatch "^(\.git.*|VERSION|LICENSE|README\.md|install\.sh|uninstall\.sh)\$">
        Require all denied
    </FilesMatch>
</Directory>
CONF
if apachectl configtest >/dev/null 2>&1; then
    systemctl reload httpd 2>/dev/null || apachectl graceful
    sleep 1
    CODE=$(curl -sk -o /dev/null -w '%{http_code}' "https://127.0.0.1/modules/$ID/.git/HEAD" || echo "?")
    case "$CODE" in
        403|404) echo "   .git protegido (o Apache respondeu $CODE)." ;;
        *) warn "o Apache respondeu $CODE para /modules/$ID/.git/HEAD; esperado 403. Confira $APACHE." ;;
    esac
else
    rm -f "$APACHE"
    warn "o Apache recusou a regra de bloqueio (apachectl configtest). A pasta .git pode estar acessível pelo navegador."
fi

say "Registrando o menu"
# Fica no mesmo lugar do Painel do Operador original (normalmente PBX).
PARENT=$(php -r '
  try { $db = new PDO("sqlite:/var/www/db/menu.db");
        $r = $db->query("SELECT IdParent, order_no FROM menu WHERE id = '"'"'control_panel'"'"'")->fetch(PDO::FETCH_NUM);
        if ($r && $r[0] !== "") { echo $r[0], " ", (int) $r[1]; exit; } } catch (Exception $e) {}
  echo "pbxconfig 0";' 2>/dev/null || echo "pbxconfig 0")
PARENT_ID=${PARENT% *}
ORDER=${PARENT#* }
mkdir -p "$SHARE"
cat > "$SHARE/menu.xml" <<XML
<?xml version="1.0" encoding="UTF-8"?>
<module>
  <menulist>
    <menuitem menuid="$ID" desc="Painel do Operador" parent="$PARENT_ID" module="yes" link="" order="$ORDER"></menuitem>
  </menulist>
</module>
XML
cp -f "$DIR/VERSION" "$SHARE/"
issabel-menumerge "$SHARE/menu.xml"
echo "   Menu em: $PARENT_ID > Painel do Operador"

if [ ! -f "$CONF" ]; then
    say "Criando $CONF"
    mkdir -p /etc/issabel
    cp "$DIR/setup/painel-operador.conf.sample.php" "$CONF"
    chown root:asterisk "$CONF"
    chmod 640 "$CONF"
fi

say "Testando a leitura do PABX"
php "$DIR/setup/selftest.php" || warn "a leitura falhou; o painel vai mostrar o mesmo erro. Veja a mensagem acima."

say "Pronto. Abra o Issabel em $PARENT_ID > Painel do Operador."
echo "   Usuários que não são admin precisam de permissão no menu em System > User Management > Group Permission."
