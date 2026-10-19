<?php
/*
 * Painel do Operador para Issabel 5 - base compartilhada.
 * Compatível com PHP 7.4 (Issabel 5 / Rocky 8). Sem dependências.
 */
if (defined('POP_ROOT')) return;
define('POP_ROOT', dirname(__DIR__));
define('POP_VERSION', trim((string) @file_get_contents(POP_ROOT . '/VERSION')) ?: 'dev');
define('POP_CONF_FILE', '/etc/issabel/painel-operador.conf.php');

function pop_defaults()
{
    return array(
        'company'               => '',
        'demo'                  => false,
        'poll_seconds'          => 2,
        'service_level_seconds' => 20,   // espera na fila acima disso fica vermelha
        'long_ring_seconds'     => 20,   // ramal tocando há mais que isso ganha destaque
        'hide_extensions'       => array(),
        'queue_labels'          => array(),
        'trunk_labels'          => array(),
        'groups'                => array(),   // 'Recepção' => array('200','201'); vazio = agrupar pelas filas
        // AMI: vazio = ler /etc/issabel.conf (usuário admin) ou /etc/amportal.conf.
        'ami' => array('host' => '127.0.0.1', 'port' => 5038, 'user' => '', 'pass' => '', 'timeout' => 3),
        // Banco do IssabelPBX: vazio = ler /etc/amportal.conf. 'dsn' permite apontar para outro PDO (testes).
        'pbx_db' => array('host' => 'localhost', 'name' => 'asterisk', 'user' => '', 'pass' => '', 'dsn' => ''),
        // Estatísticas do dia por fila: 'auto' (arquivo, depois tabela), caminho do arquivo, 'table' ou 'off'.
        'queue_log' => 'auto',
        'cache_dir' => '',
    );
}

/* Configuração opcional fora do webroot. Atualizações nunca sobrescrevem esse arquivo. */
function pop_config()
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $user = array();
    if (is_file(POP_CONF_FILE) && is_readable(POP_CONF_FILE)) {
        $loaded = include POP_CONF_FILE;
        if (is_array($loaded)) $user = $loaded;
    }
    $cfg = array_replace_recursive(pop_defaults(), $user);
    // Listas substituem por inteiro (o merge recursivo misturaria índices).
    foreach (array('hide_extensions', 'queue_labels', 'trunk_labels', 'groups') as $k) {
        if (isset($user[$k])) $cfg[$k] = $user[$k];
    }
    $cfg['poll_seconds'] = max(1, (int) $cfg['poll_seconds']);
    return $cfg;
}

function pop_read_kv_file($path)
{
    $out = array();
    if (!@is_readable($path)) return $out;
    foreach (file($path) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';' || $line[0] === '[') continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $out[trim(substr($line, 0, $pos))] = trim(trim(substr($line, $pos + 1)), "\"'");
    }
    return $out;
}

/* Credenciais do AMI: config > /etc/issabel.conf (admin, como o Issabel faz) > /etc/amportal.conf. */
function pop_ami_credentials(array $cfg)
{
    $a = $cfg['ami'];
    if ($a['user'] !== '' && $a['pass'] !== '') return $a;
    $iss = pop_read_kv_file('/etc/issabel.conf');
    if (!empty($iss['amiadminpwd'])) {
        $a['user'] = 'admin';
        $a['pass'] = $iss['amiadminpwd'];
        return $a;
    }
    $amp = pop_read_kv_file('/etc/amportal.conf');
    if (!empty($amp['AMPMGRUSER'])) {
        $a['user'] = $amp['AMPMGRUSER'];
        $a['pass'] = isset($amp['AMPMGRPASS']) ? $amp['AMPMGRPASS'] : '';
        if (!empty($amp['ASTMANAGERPORT'])) $a['port'] = (int) $amp['ASTMANAGERPORT'];
    }
    return $a;
}

function pop_pbx_pdo(array $cfg)
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $db = $cfg['pbx_db'];
    $opts = array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC);
    if (!empty($db['dsn'])) {
        $pdo = new PDO($db['dsn'], $db['user'] ?: null, $db['pass'] ?: null, $opts);
        return $pdo;
    }
    if ($db['user'] === '') {
        $amp = pop_read_kv_file('/etc/amportal.conf');
        $db['user'] = isset($amp['AMPDBUSER']) ? $amp['AMPDBUSER'] : 'asteriskuser';
        $db['pass'] = isset($amp['AMPDBPASS']) ? $amp['AMPDBPASS'] : '';
        if (!empty($amp['AMPDBHOST'])) $db['host'] = $amp['AMPDBHOST'];
        if (!empty($amp['AMPDBNAME'])) $db['name'] = $amp['AMPDBNAME'];
    }
    $pdo = new PDO('mysql:host=' . $db['host'] . ';dbname=' . $db['name'] . ';charset=utf8', $db['user'], $db['pass'], $opts);
    return $pdo;
}

function pop_cache_dir(array $cfg)
{
    $d = $cfg['cache_dir'] !== '' ? $cfg['cache_dir'] : sys_get_temp_dir();
    return rtrim($d, '/');
}

function pop_json($data, $code = 200)
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
    }
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
}
