<?php
/* Teste fora do Issabel: php tests/run_snapshot.php (com tests/mock_ami.py rodando na 15038). */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/Snapshot.php';

$dir = sys_get_temp_dir() . '/pop-test';
@mkdir($dir);
$dbf = "$dir/pbx.sqlite";
@unlink($dbf);
$db = new PDO("sqlite:$dbf");
$db->exec("CREATE TABLE devices (id TEXT, tech TEXT, dial TEXT, description TEXT);
CREATE TABLE users (extension TEXT, name TEXT);
CREATE TABLE queues_config (extension TEXT, descr TEXT);
CREATE TABLE trunks (trunkid INT, name TEXT, tech TEXT, channelid TEXT, outcid TEXT, disabled TEXT);
CREATE TABLE meetme (exten TEXT, description TEXT);");
$exts = array('200' => array('pjsip', 'Atendimento'), '201' => array('pjsip', 'Maria Souza'), '202' => array('sip', 'Julia Gomes'),
    '203' => array('pjsip', 'Gerlane Maia'), '204' => array('pjsip', 'Financeiro'), '205' => array('pjsip', 'Samuel Luciano'),
    '206' => array('pjsip', 'Eduarda Kessler'), '210' => array('sip', 'Teste Callcenter'), '211' => array('pjsip', 'Guilherme Nunes'),
    '212' => array('pjsip', 'Gabriel Yoshino'), '214' => array('pjsip', 'Israel Freitas'), '215' => array('pjsip', 'Sarah Pereira'),
    '999' => array('custom', 'Suporte'));
foreach ($exts as $e => $x) {
    $db->exec("INSERT INTO devices VALUES ('$e', '{$x[0]}', '" . strtoupper($x[0]) . "/$e', '{$x[1]}')");
    $db->exec("INSERT INTO users VALUES ('$e', '{$x[1]}')");
}
$db->exec("INSERT INTO queues_config VALUES ('600','Comercial Geral'),('602','Suporte'),('603','Financeiro'),('610','Plantão');
INSERT INTO trunks VALUES (1,'08004440001','sip','operadora','','off'),(2,'Operadora antiga','sip','trunk2','','off'),(3,'WhatsApp','pjsip','whatsapp','','off'),(4,'Backup','sip','backup','','on'),(5,'1- (19)3909-9662','sip','linha1','','off');
INSERT INTO meetme VALUES ('800','Sala de reunião');");

$t0 = strtotime('today 08:00');
$ql = "$dir/queue_log";
$lines = array(
    ($t0 - 3600 * 12) . "|old.1|600|NONE|ENTERQUEUE||119|1",   // ontem: deve ser ignorado
    "$t0|a.1|600|NONE|ENTERQUEUE||1199|1", ($t0 + 8) . "|a.1|600|PJSIP/201|CONNECT|8|a.2|3",
    ($t0 + 100) . "|a.1|600|PJSIP/201|COMPLETECALLER|8|92|1",
    ($t0 + 200) . "|b.1|600|NONE|ENTERQUEUE||1188|1", ($t0 + 250) . "|b.1|600|NONE|ABANDON|1|1|50",
    ($t0 + 300) . "|c.1|602|NONE|ENTERQUEUE||1177|1", ($t0 + 330) . "|c.1|602|PJSIP/205|CONNECT|30|c.2|3",
);
file_put_contents($ql, implode("\n", $lines) . "\n");

$cfg = array_replace_recursive(pop_defaults(), array(
    'ami' => array('host' => '127.0.0.1', 'port' => 15038, 'user' => 'x', 'pass' => 'y'),
    'pbx_db' => array('dsn' => "sqlite:$dbf"),
    'queue_log' => $ql, 'cache_dir' => $dir, 'hide_extensions' => array('999'),
));
@unlink("$dir/painel-operador-qstats.json");
$s = new PopSnapshot($cfg);
$out = $s->build(in_array('--debug', $argv, true));
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
