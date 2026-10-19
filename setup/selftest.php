<?php
/* Testa a leitura do PABX pela linha de comando. Uso: php setup/selftest.php
   Usa uma pasta temporária própria, para não deixar arquivos de root no cache do Apache. */
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/Snapshot.php';

$cfg = pop_config();
$tmp = sys_get_temp_dir() . '/pop-selftest-' . getmypid();
@mkdir($tmp, 0700);
$cfg['cache_dir'] = $tmp;
try {
    $s = new PopSnapshot($cfg);
    $d = $s->build(false);
} catch (Exception $e) {
    fwrite(STDERR, "   FALHOU: " . $e->getMessage() . "\n");
    array_map('unlink', glob("$tmp/*") ?: array());
    @rmdir($tmp);
    exit(1);
}
array_map('unlink', glob("$tmp/*") ?: array());
@rmdir($tmp);

$on = 0; $real = 0;
foreach ($d['extensions'] as $e) { if ($e['state'] !== 'virtual') $real++; if ($e['online']) $on++; }
$tOn = 0; $tAll = 0;
foreach ($d['trunks'] as $t) { if ($t['status'] === 'disabled') continue; $tAll++; if ($t['status'] === 'online' || $t['status'] === 'warn') $tOn++; }
$hasToday = false;
foreach ($d['queues'] as $q) if ($q['today'] !== null) $hasToday = true;
printf("   Ramais: %d (%d online)\n", $real, $on);
printf("   Filas: %d%s\n", count($d['queues']), $d['queues'] ? ($hasToday ? ', com números do dia pelo queue_log' : ', sem queue_log (mostra a contagem do Asterisk)') : '');
printf("   Troncos: %d de %d respondendo\n", $tOn, $tAll);
printf("   Ligações agora: %d\n", count($d['calls']));
foreach ($d['warnings'] as $w) echo "   Aviso: $w\n";
