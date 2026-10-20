<?php
/*
 * Monta o estado do PABX num único JSON para o painel.
 *
 * Fontes:
 *   banco asterisk  -> quais ramais, filas, troncos e conferências existem e seus nomes
 *   AMI             -> estado agora (registro, canais, filas, estacionamento, salas)
 *   queue_log       -> números do dia por fila (QueueStats)
 *
 * Um snapshot é montado no máximo uma vez por segundo e compartilhado entre todos
 * os navegadores abertos (cache em arquivo com lock), então dez telas abertas não
 * viram dez conexões no AMI a cada ciclo.
 */
if (!defined('POP_ROOT')) exit;
require_once __DIR__ . '/Ami.php';
require_once __DIR__ . '/QueueStats.php';

class PopSnapshot
{
    private $cfg;
    private $now;
    private $warnings = array();

    // diretório
    private $ext = array();        // ext => [ext, name, tech, dial]
    private $devToExt = array();   // "PJSIP/201" => "201"
    private $queues = array();     // num => [num, name]
    private $trunks = array();     // id => [...]
    private $chanToTrunk = array();// "SIP/operadora" => trunk id
    private $confs = array();      // exten => name
    private $parkDb = null;

    public function __construct(array $cfg, $now = null)
    {
        $this->cfg = $cfg;
        $this->now = $now ?: time();
    }

    /* ---------------------------------------------------------------- cache */

    public static function cached(array $cfg)
    {
        $dir = pop_cache_dir($cfg);
        $file = $dir . '/painel-operador-snap.json';
        $fresh = function () use ($file) {
            $c = @json_decode((string) @file_get_contents($file), true);
            return (is_array($c) && isset($c['built_at']) && microtime(true) - $c['built_at'] < 0.9) ? $c : null;
        };
        if ($c = $fresh()) return $c;
        $lock = @fopen($dir . '/painel-operador.lock', 'c');
        if ($lock) flock($lock, LOCK_EX);
        try {
            if ($c = $fresh()) return $c;      // outro navegador acabou de montar
            $s = new self($cfg);
            $data = $s->build(false);
            $data['built_at'] = microtime(true);
            @file_put_contents($file . '.tmp', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
            @rename($file . '.tmp', $file);
            return $data;
        } finally {
            if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }

    /* ---------------------------------------------------------------- montagem */

    public function build($debug = false)
    {
        $this->loadDirectory();

        $ami = new PopAmi(pop_ami_credentials($this->cfg));
        if ($debug) $ami->raw = array();

        $L = array();
        foreach (array(
            'endpoints' => 'PJSIPShowEndpoints',
            'contacts'  => 'PJSIPShowContacts',
            'pjreg'     => 'PJSIPShowRegistrationsOutbound',
            'sippeers'  => 'SIPpeers',
            'iaxpeers'  => 'IAXpeerlist',
            'hints'     => 'ExtensionStateList',
            'devstates' => 'DeviceStateList',
            'channels'  => 'CoreShowChannels',
            'queues'    => 'QueueStatus',
            'lots'      => 'Parkinglots',
            'parked'    => 'ParkedCalls',
            'confbridge'=> 'ConfbridgeListRooms',
            'meetme'    => 'MeetmeListRooms',
            'vm'        => 'VoicemailUsersList',
        ) as $k => $action) {
            try {
                $L[$k] = $ami->lst($action);
            } catch (Exception $e) {
                $L[$k] = null;
                $this->warnings[] = "Falha ao ler $action: " . $e->getMessage();
            }
        }
        if ($L['channels'] === null) throw new Exception('o Asterisk não devolveu a lista de canais (CoreShowChannels)');

        $chans = $this->parseChannels($L['channels']);
        $this->fixInboundTrunks($ami, $chans);
        $this->trackAnswers($chans);
        $groups = $this->groupCalls($chans);

        $extensions = $this->buildExtensions($L, $chans, $groups);
        $queues = $this->buildQueues($L['queues'], $groups);
        $out = array(
            'source'      => 'live',
            'now'         => $this->now,
            'version'     => POP_VERSION,
            'company'     => $this->cfg['company'],
            'settings'    => array(
                'service_level_seconds' => (int) $this->cfg['service_level_seconds'],
                'long_ring_seconds'     => (int) $this->cfg['long_ring_seconds'],
            ),
            'extensions'  => $extensions,
            'queues'      => $queues,
            'calls'       => $this->buildCalls($groups),
            'trunks'      => $this->buildTrunks($L, $groups),
            'parking'     => $this->buildParking($L['lots'], $L['parked']),
            'conferences' => $this->buildConferences($L['confbridge'], $L['meetme']),
            'groups'      => $this->buildGroups($queues),
            'warnings'    => $this->warnings,
        );
        if ($debug) {
            $out['debug'] = array('ami' => $ami->raw, 'trunks_db' => array_values($this->trunks), 'devices' => $this->devToExt);
        }
        return $out;
    }

    /* ---------------------------------------------------------------- diretório (banco) */

    private function loadDirectory()
    {
        $pdo = pop_pbx_pdo($this->cfg);
        $hide = array_flip(array_map('strval', (array) $this->cfg['hide_extensions']));

        $rows = $pdo->query("SELECT d.id AS ext, d.tech, d.dial, d.description, u.name AS uname
                             FROM devices d LEFT JOIN users u ON u.extension = d.id")->fetchAll();
        usort($rows, function ($a, $b) { return strnatcmp($a['ext'], $b['ext']); });
        foreach ($rows as $r) {
            $e = (string) $r['ext'];
            $name = trim((string) ($r['uname'] !== null && trim($r['uname']) !== '' ? $r['uname'] : $r['description']));
            $this->ext[$e] = array('ext' => $e, 'name' => $name, 'tech' => strtolower((string) $r['tech']),
                                   'dial' => (string) $r['dial'], 'hidden' => isset($hide[$e]));
            if ($r['dial'] !== '') $this->devToExt[strtoupper($r['dial'])] = $e;
        }

        try {
            foreach ($pdo->query('SELECT extension, descr FROM queues_config') as $r) {
                $n = (string) $r['extension'];
                $this->queues[$n] = array('num' => $n, 'name' => $this->label('queue_labels', $n, $r['descr']));
            }
        } catch (Exception $e) { $this->warnings[] = 'Não foi possível ler as filas do banco.'; }

        try {
            // SELECT * porque outcid não existe em todas as versões do IssabelPBX.
            foreach ($pdo->query('SELECT * FROM trunks') as $r) {
                $r += array('outcid' => '', 'disabled' => '');
                $tech = strtolower((string) $r['tech']);
                $prefix = array('sip' => 'SIP', 'pjsip' => 'PJSIP', 'iax' => 'IAX2', 'iax2' => 'IAX2', 'dahdi' => 'DAHDI', 'zap' => 'DAHDI');
                $chan = isset($prefix[$tech]) ? $prefix[$tech] . '/' . $r['channelid'] : (string) $r['channelid'];
                $id = 't' . $r['trunkid'];
                $label = trim((string) $r['name']) !== '' ? $r['name'] : $chan;
                $this->trunks[$id] = array(
                    'id' => $id, 'tech' => $tech, 'peer' => (string) $r['channelid'], 'channel' => $chan,
                    'numbers' => self::trunkNumbers(array($r['name'], $r['channelid'], $r['outcid'])),
                    'label' => $this->label('trunk_labels', (string) $r['channelid'], $this->label('trunk_labels', $label, $label)),
                    'disabled' => strtolower((string) $r['disabled']) === 'on',
                );
                $this->chanToTrunk[strtoupper($chan)] = $id;
            }
        } catch (Exception $e) { $this->warnings[] = 'Não foi possível ler os troncos do banco.'; }

        try {
            foreach ($pdo->query('SELECT exten, description FROM meetme') as $r) {
                $this->confs[(string) $r['exten']] = trim((string) $r['description']);
            }
        } catch (Exception $e) { /* módulo de conferências não instalado */ }

        try {
            $r = $pdo->query('SELECT parkpos, numslots FROM parkplus ORDER BY id LIMIT 1')->fetch();
            if ($r) $this->parkDb = array('start' => (int) $r['parkpos'], 'count' => (int) $r['numslots']);
        } catch (Exception $e) { /* sem parkplus */ }
    }

    private function label($kind, $key, $fallback)
    {
        $m = $this->cfg[$kind];
        return isset($m[$key]) && $m[$key] !== '' ? $m[$key] : trim((string) $fallback);
    }

    /* ---------------------------------------------------------------- canais */

    /* "PJSIP/201-0000001a" -> "PJSIP/201"; Local/... -> null */
    public static function chanDevice($ch)
    {
        $ch = preg_replace('/<[A-Z]+>$/', '', $ch);
        if (stripos($ch, 'Local/') === 0) return null;
        if (preg_match('/^(.+)-[0-9a-f]+(?:;\d+)?$/i', $ch, $m)) return $m[1];
        return $ch;
    }

    private static function clean($v)
    {
        $v = trim((string) $v);
        return ($v === '' || $v === '<unknown>' || $v === 'unknown') ? null : $v;
    }

    private static function hms($s)
    {
        $p = array_map('intval', explode(':', (string) $s));
        if (count($p) === 3) return $p[0] * 3600 + $p[1] * 60 + $p[2];
        return (int) $s;
    }

    private function parseChannels(array $events)
    {
        $out = array();
        foreach ($events as $e) {
            if (!isset($e['Channel'])) continue;
            $dev = self::chanDevice($e['Channel']);
            $devU = $dev !== null ? strtoupper($dev) : null;
            $ext = $devU !== null && isset($this->devToExt[$devU]) ? $this->devToExt[$devU] : null;
            $trunk = null;
            if ($ext === null && $devU !== null) {
                if (isset($this->chanToTrunk[$devU])) $trunk = $this->chanToTrunk[$devU];
                elseif (strpos($devU, 'DAHDI/') === 0) $trunk = $this->firstTrunkOfTech('dahdi');
            }
            $uid = isset($e['Uniqueid']) ? $e['Uniqueid'] : $e['Channel'];
            $out[] = array(
                'channel'  => $e['Channel'],
                'uid'      => $uid,
                'linkedid' => isset($e['Linkedid']) && $e['Linkedid'] !== '' ? $e['Linkedid'] : $uid,
                'ext'      => $ext,
                'trunk'    => $trunk,
                'local'    => stripos($e['Channel'], 'Local/') === 0,
                'state'    => isset($e['ChannelStateDesc']) ? $e['ChannelStateDesc'] : '',
                'cid_num'  => self::clean(isset($e['CallerIDNum']) ? $e['CallerIDNum'] : ''),
                'cid_name' => self::clean(isset($e['CallerIDName']) ? $e['CallerIDName'] : ''),
                'con_num'  => self::clean(isset($e['ConnectedLineNum']) ? $e['ConnectedLineNum'] : ''),
                'con_name' => self::clean(isset($e['ConnectedLineName']) ? $e['ConnectedLineName'] : ''),
                'context'  => isset($e['Context']) ? $e['Context'] : '',
                'exten'    => isset($e['Exten']) ? $e['Exten'] : '',
                'app'      => isset($e['Application']) ? $e['Application'] : '',
                'appdata'  => isset($e['ApplicationData']) ? $e['ApplicationData'] : '',
                'bridged'  => !empty($e['BridgeId']),
                'since'    => $this->now - self::hms(isset($e['Duration']) ? $e['Duration'] : 0),
            );
        }
        return $out;
    }

    private function firstTrunkOfTech($tech)
    {
        foreach ($this->trunks as $id => $t) if ($t['tech'] === $tech) return $id;
        return null;
    }

    /* Número discado tirado do Dial(): "SIP/operadora/011999998888,300,..." -> "011999998888" */
    private static function dialedNumber($appdata)
    {
        $first = explode(',', (string) $appdata);
        $first = explode('&', $first[0]);
        if (preg_match_all('/\d{3,}/', $first[0], $m)) {
            $best = '';
            foreach ($m[0] as $d) if (strlen($d) > strlen($best)) $best = $d;
            return $best;
        }
        return null;
    }

    /* Agrupa os canais por ligação (Linkedid) e descobre fila, tronco e sentido. */
    private function groupCalls(array $chans)
    {
        $g = array();
        foreach ($chans as $c) $g[$c['linkedid']][] = $c;
        $calls = array();
        foreach ($g as $lid => $list) {
            usort($list, function ($a, $b) { return strcmp($a['uid'], $b['uid']); });
            $orig = $list[0];
            foreach ($list as $c) if ($c['uid'] === $lid) { $orig = $c; break; }

            $queue = null; $trunk = null; $exts = array(); $trunkChans = array();
            foreach ($list as $c) {
                if (strcasecmp($c['app'], 'Queue') === 0) {
                    $q = explode(',', $c['appdata']);
                    if (isset($this->queues[trim($q[0])])) $queue = trim($q[0]);
                }
                if (!$queue && $c['local'] && preg_match('/^Local\/\d+@from-queue/i', $c['channel']) && $c['exten'] !== '' && isset($this->queues[$c['exten']])) {
                    $queue = $c['exten'];
                }
                if ($c['trunk']) { $trunk = $trunk ?: $c['trunk']; $trunkChans[] = $c; }
                if ($c['ext'] !== null) $exts[] = $c;
            }
            if (!$exts && !$trunkChans) continue;   // só canais Local: nada para mostrar

            $origIsTrunk = $orig['trunk'] !== null;
            $dir = $trunk ? ($origIsTrunk ? 'in' : 'out') : 'int';
            if ($trunk && !$origIsTrunk && $orig['ext'] === null) {
                // Originado por Local/Originate (callback, discador): quem tem tronco é o outro lado.
                $dir = 'out';
            }

            $up = false; $ringing = false; $answered = null;
            foreach ($list as $c) {
                if ($c['bridged'] && !$c['local']) $up = true;
                if ($c['ext'] !== null && $c['state'] === 'Ringing') $ringing = true;
            }
            if ($up) $state = 'up';
            elseif ($ringing) $state = 'ringing';
            elseif ($queue) $state = 'queue';
            elseif ($dir === 'in' && $orig['state'] === 'Up') $state = 'ivr';
            elseif ($orig['state'] === 'Up') $state = 'app';      // caixa postal, conferência, recursos *xx
            else $state = 'dialing';

            $calls[$lid] = array(
                'id' => $lid, 'dir' => $dir, 'state' => $state, 'since' => $orig['since'],
                'queue' => $queue, 'trunk' => $trunk, 'orig' => $orig, 'exts' => $exts, 'trunk_chans' => $trunkChans,
                'list' => $list,
            );
        }
        return $calls;
    }

    /* Quando cada canal entrou em conversa. O AMI não diz; anotamos a 1ª vez que o vemos em ponte. */
    private function trackAnswers(array &$chans)
    {
        $file = pop_cache_dir($this->cfg) . '/painel-operador-track.json';
        $t = @json_decode((string) @file_get_contents($file), true);
        $fresh = is_array($t) && isset($t['at']) && $this->now - $t['at'] < 30;
        $seen = $fresh && isset($t['seen']) ? $t['seen'] : array();
        $next = array();
        foreach ($chans as &$c) {
            if (!$c['bridged']) continue;
            if (isset($seen[$c['uid']])) $next[$c['uid']] = $seen[$c['uid']];
            elseif ($fresh) $next[$c['uid']] = $this->now;      // acabou de atender
            else $next[$c['uid']] = null;                        // já estava em conversa quando o painel abriu
            $c['answered_at'] = $next[$c['uid']];
        }
        unset($c);
        $this->answered = $next;
        @file_put_contents($file, json_encode(array('at' => $this->now, 'seen' => $next)), LOCK_EX);
    }
    private $answered = array();

    private function extRef($num)
    {
        if ($num !== null && isset($this->ext[$num])) return array('num' => $num, 'name' => $this->ext[$num]['name'], 'ext' => $num);
        return null;
    }

    /* ---------------------------------------------------------------- ramais */

    private function buildExtensions(array $L, array $chans, array $calls)
    {
        $pj = array(); $pjContacts = array(); $sip = array(); $iax = array(); $hint = array(); $dnd = array(); $vm = array();
        foreach ((array) $L['endpoints'] as $e) if (isset($e['ObjectName'])) $pj[$e['ObjectName']] = $e;
        foreach ((array) $L['contacts'] as $e) {
            $ep = isset($e['Endpoint']) ? $e['Endpoint'] : (isset($e['Aor']) ? $e['Aor'] : null);
            if ($ep !== null && $ep !== '') $pjContacts[$ep][] = $e;
        }
        foreach ((array) $L['sippeers'] as $e) if (isset($e['ObjectName'])) $sip[$e['ObjectName']] = $e;
        foreach ((array) $L['iaxpeers'] as $e) if (isset($e['ObjectName'])) $iax[$e['ObjectName']] = $e;
        foreach ((array) $L['hints'] as $e) {
            if (isset($e['Exten'], $e['Status']) && (!isset($e['Context']) || $e['Context'] === 'ext-local')) $hint[$e['Exten']] = (int) $e['Status'];
        }
        foreach ((array) $L['devstates'] as $e) {
            if (isset($e['Device']) && preg_match('/^Custom:DND(\d+)$/i', $e['Device'], $m)) {
                $dnd[$m[1]] = in_array(strtoupper($e['State']), array('BUSY', 'INUSE', 'UNAVAILABLE', 'ONHOLD'), true);
            }
        }
        foreach ((array) $L['vm'] as $e) {
            if (isset($e['VoiceMailbox'])) $vm[$e['VoiceMailbox']] = (int) (isset($e['NewMessageCount']) ? $e['NewMessageCount'] : 0);
        }

        // canais por ramal
        $byExt = array();
        foreach ($calls as $call) foreach ($call['exts'] as $c) $byExt[$c['ext']][] = array($c, $call);

        $out = array();
        foreach ($this->ext as $e => $info) {
            $e = (string) $e;   // chaves numéricas viram int no PHP
            $online = null; $ip = null; $agent = null; $rtt = null;
            $peer = preg_replace('/^[^\/]+\//', '', $info['dial']);
            if ($info['tech'] === 'pjsip' && isset($pj[$peer])) {
                $ds = $pj[$peer]['DeviceState'];
                $online = !in_array($ds, array('Unavailable', 'Invalid'), true);
                if (isset($pjContacts[$peer])) {
                    foreach ($pjContacts[$peer] as $ct) {
                        $st = isset($ct['Status']) ? $ct['Status'] : '';
                        if ($st === 'Unreachable') continue;
                        $online = true;
                        if (isset($ct['Uri']) && preg_match('/@\[?([0-9a-f.:]+?)\]?(?::\d+)?(?:;|$)/i', $ct['Uri'], $m)) $ip = $m[1];
                        if (!empty($ct['UserAgent'])) $agent = $ct['UserAgent'];
                        if (!empty($ct['RoundtripUsec']) && is_numeric($ct['RoundtripUsec'])) $rtt = (int) round($ct['RoundtripUsec'] / 1000);
                        break;
                    }
                }
            } elseif (($info['tech'] === 'sip' && isset($sip[$peer])) || ($info['tech'] === 'iax2' && isset($iax[$peer]))) {
                $p = $info['tech'] === 'sip' ? $sip[$peer] : $iax[$peer];
                $ipa = isset($p['IPaddress']) ? $p['IPaddress'] : '-none-';
                $st = isset($p['Status']) ? $p['Status'] : '';
                $online = $ipa !== '-none-' && $ipa !== '' && stripos($st, 'UNREACHABLE') === false && stripos($st, 'UNKNOWN') === false;
                if ($online) $ip = $ipa;
                if (preg_match('/\((\d+) ms\)/', $st, $m)) $rtt = (int) $m[1];
            }
            $h = isset($hint[$e]) ? $hint[$e] : null;
            if ($online === null && $h !== null) $online = $h !== 4;

            $mine = isset($byExt[$e]) ? $byExt[$e] : array();
            $items = array();
            $hasUp = false; $hasRinging = false; $hasOther = false;
            foreach ($mine as $pair) {
                list($c, $call) = $pair;
                $items[] = $this->extCallItem($e, $c, $call);
                if ($c['bridged'] || $c['state'] === 'Up') $hasUp = true;
                elseif ($c['state'] === 'Ringing') $hasRinging = true;
                else $hasOther = true;
            }
            if ($hasUp) $state = ($h !== null && ($h & 16)) ? 'hold' : 'incall';
            elseif ($hasRinging) $state = 'ringing';
            elseif ($hasOther) $state = 'dialing';
            elseif (!empty($dnd[$e])) $state = 'dnd';
            elseif ($online === false) $state = 'offline';
            elseif ($online === null) $state = in_array($info['tech'], array('custom', 'virtual', 'dahdi'), true) ? 'virtual' : 'unknown';
            else $state = 'free';

            $out[] = array(
                'ext' => $e, 'name' => $info['name'], 'tech' => $info['tech'], 'hidden' => $info['hidden'],
                'state' => $state, 'online' => $online, 'dnd' => !empty($dnd[$e]),
                'ringing_too' => $hasUp && $hasRinging,
                'ip' => $ip, 'agent' => $agent, 'rtt_ms' => $rtt,
                'vm_new' => isset($vm[$e]) ? $vm[$e] : null,
                'calls' => $items,
            );
        }
        return $out;
    }

    private function extCallItem($e, array $c, array $call)
    {
        $incoming = strcasecmp($c['app'], 'AppDial') === 0;
        $num = $c['con_num']; $name = $c['con_name'];
        if ($num === $e) { $num = null; $name = null; }
        if (!$incoming && $num === null && strcasecmp($c['app'], 'Dial') === 0) $num = self::dialedNumber($c['appdata']);
        if (!$incoming && $num === null && preg_match('/^\+?\d{3,}$/', $c['exten']) && $c['exten'] !== $e) $num = $c['exten'];

        $a = strtolower($c['app']);
        $conf = $a === 'confbridge' || $a === 'meetme';
        if ($conf) $st = 'app';               // ConfBridge também usa ponte, mas não há "com quem"
        elseif ($c['bridged']) $st = 'up';
        elseif ($c['state'] === 'Ringing') $st = 'ringing';
        elseif ($c['state'] === 'Up') $st = 'app';
        else $st = 'dialing';

        $app = null;
        if ($conf) {
            $room = explode(',', $c['appdata']);
            $room = trim($room[0]);
            if (!preg_match('/^\d+$/', $room)) $room = preg_match('/^\d+$/', $c['exten']) ? $c['exten'] : '';
            $app = 'Conferência' . ($room !== '' ? ' ' . $room : '') . ($room !== '' && !empty($this->confs[$room]) ? ' (' . $this->confs[$room] . ')' : '');
            $num = null; $name = null;
        } elseif ($st === 'app') {
            $a = strtolower($c['app']);
            if (strpos($a, 'voicemail') === 0) $app = 'Caixa postal';
            elseif ($a === 'park' || $a === 'parkedcall') $app = 'Estacionamento';
            elseif ($a === 'queue') $app = 'Fila';
            else $app = null;
        }
        $peerExt = $num !== null && isset($this->ext[$num]) ? $num : null;
        return array(
            'id' => $call['id'], 'dir' => $incoming ? 'in' : 'out', 'state' => $st,
            'since' => $c['since'], 'answered_at' => isset($c['answered_at']) ? $c['answered_at'] : (isset($this->answered[$c['uid']]) ? $this->answered[$c['uid']] : null),
            'peer_num' => $num, 'peer_name' => $peerExt ? $this->ext[$peerExt]['name'] : $name, 'peer_ext' => $peerExt,
            'external' => $call['trunk'] !== null,
            'queue' => $call['queue'], 'trunk' => $call['trunk'], 'app' => $app,
        );
    }

    /* ---------------------------------------------------------------- ligações */

    private function buildCalls(array $calls)
    {
        $out = array();
        foreach ($calls as $call) {
            $o = $call['orig'];
            $exts = $call['exts'];
            $answered = null; $talking = array(); $ringing = array();
            foreach ($exts as $c) {
                if ($c['bridged']) { $talking[] = $c['ext']; if (isset($c['answered_at']) && $c['answered_at']) $answered = $answered ? min($answered, $c['answered_at']) : $c['answered_at']; }
                elseif ($c['state'] === 'Ringing') $ringing[] = $c['ext'];
            }
            if ($answered === null) foreach ($call['trunk_chans'] as $c) if (!empty($c['answered_at'])) $answered = $c['answered_at'];

            $ext = function ($n) { return $this->extRef($n); };
            if ($call['dir'] === 'in') {
                $tc = $call['trunk_chans'] ? $call['trunk_chans'][0] : $o;
                $from = array('num' => $tc['cid_num'], 'name' => $tc['cid_name'], 'ext' => null);
                $to = array();
                foreach (($talking ?: $ringing) as $x) $to[] = $ext($x);
                $origExt = null;
            } elseif ($call['dir'] === 'out') {
                $src = null;
                foreach ($exts as $c) if ($c['uid'] === $o['uid'] || !$src) $src = $c;
                $from = $src ? $ext($src['ext']) : array('num' => $o['cid_num'], 'name' => $o['cid_name'], 'ext' => null);
                $num = $src ? $src['con_num'] : null;
                if ($src && $num === $src['ext']) $num = null;
                if ($num === null && $src && strcasecmp($src['app'], 'Dial') === 0) $num = self::dialedNumber($src['appdata']);
                if ($num === null && $src && preg_match('/^\+?\d{6,}$/', $src['exten'])) $num = $src['exten'];
                if ($num === null && $call['trunk_chans']) {
                    $tc = $call['trunk_chans'][0];
                    $num = $tc['con_num'] && !isset($this->ext[$tc['con_num']]) ? $tc['con_num'] : $tc['cid_num'];
                }
                $to = array(array('num' => $num, 'name' => $src ? ($src['con_name'] !== $src['ext'] ? $src['con_name'] : null) : null, 'ext' => null));
            } else {
                $from = $o['ext'] !== null ? $ext($o['ext']) : array('num' => $o['cid_num'], 'name' => $o['cid_name'], 'ext' => null);
                $to = array();
                foreach ($exts as $c) if ($c['ext'] !== $o['ext']) $to[] = $ext($c['ext']);
                if (!$to) {
                    $a = strtolower($o['app']);
                    $label = strpos($a, 'voicemail') === 0 ? 'Caixa postal' : (($a === 'confbridge' || $a === 'meetme') ? 'Conferência' : null);
                    if ($label || $o['exten'] !== '') $to[] = array('num' => $o['exten'] !== '' && $o['exten'] !== 's' ? $o['exten'] : null, 'name' => $label, 'ext' => null);
                }
            }
            $out[] = array(
                'id' => $call['id'], 'dir' => $call['dir'], 'state' => $call['state'],
                'since' => $call['since'], 'answered_at' => $answered,
                'from' => $from, 'to' => array_values(array_filter($to)),
                'queue' => $call['queue'], 'trunk' => $call['trunk'],
                'did' => isset($o['did']) ? $o['did'] : null,   // número discado (entrada), para conferir no debug
            );
        }
        usort($out, function ($a, $b) { return $a['since'] - $b['since']; });
        return $out;
    }

    /* ---------------------------------------------------------------- filas */

    private static function memberExt($m)
    {
        foreach (array('StateInterface', 'Location', 'Interface', 'Name', 'MemberName') as $k) {
            if (isset($m[$k]) && preg_match('#^(?:Local|PJSIP|SIP|IAX2|hint|Agent)[/:](\d+)#i', $m[$k], $r)) return $r[1];
        }
        return null;
    }

    private function buildQueues($events, array $calls)
    {
        $stats = PopQueueStats::today($this->cfg, $this->now);
        $q = array();
        foreach ($this->queues as $n => $info) {
            $q[$n] = array('num' => (string) $n, 'name' => $info['name'], 'strategy' => null, 'members' => array(), 'waiting' => array(),
                           'today' => isset($stats[$n]) ? $stats[$n] : ($stats !== null ? array('offered' => 0, 'answered' => 0, 'lost' => 0, 'abandoned' => 0, 'sla_pct' => null, 'avg_wait' => null, 'avg_talk' => null) : null),
                           'since_reset' => null, 'talking' => 0);
        }
        $memberState = array(0 => 'unknown', 1 => 'free', 2 => 'busy', 3 => 'busy', 4 => 'offline', 5 => 'offline', 6 => 'ringing', 7 => 'busy', 8 => 'busy');
        foreach ((array) $events as $e) {
            if (!isset($e['Event'], $e['Queue']) || !isset($q[$e['Queue']])) continue;
            $n = (string) $e['Queue'];
            if ($e['Event'] === 'QueueParams') {
                $q[$n]['strategy'] = isset($e['Strategy']) ? $e['Strategy'] : null;
                $q[$n]['since_reset'] = array('answered' => (int) (isset($e['Completed']) ? $e['Completed'] : 0), 'lost' => (int) (isset($e['Abandoned']) ? $e['Abandoned'] : 0));
            } elseif ($e['Event'] === 'QueueMember') {
                $x = self::memberExt($e);
                $st = isset($e['Status']) ? (int) $e['Status'] : 0;
                $paused = isset($e['Paused']) && $e['Paused'] === '1';
                $name = isset($e['Name']) ? $e['Name'] : (isset($e['MemberName']) ? $e['MemberName'] : '');
                if ($x !== null && isset($this->ext[$x])) $name = $this->ext[$x]['name'];   // mesmo nome do quadro de ramais
                $q[$n]['members'][] = array(
                    'ext' => $x, 'name' => $name,
                    'status' => $paused ? 'paused' : (isset($memberState[$st]) ? $memberState[$st] : 'unknown'),
                    'paused' => $paused, 'pause_reason' => isset($e['PausedReason']) && $e['PausedReason'] !== '' ? $e['PausedReason'] : null,
                    'dynamic' => isset($e['Membership']) && $e['Membership'] === 'dynamic',
                    'calls_taken' => (int) (isset($e['CallsTaken']) ? $e['CallsTaken'] : 0),
                    'last_call' => !empty($e['LastCall']) ? (int) $e['LastCall'] : null,
                );
            } elseif ($e['Event'] === 'QueueEntry') {
                $q[$n]['waiting'][] = array(
                    'pos' => (int) (isset($e['Position']) ? $e['Position'] : 0),
                    'num' => self::clean(isset($e['CallerIDNum']) ? $e['CallerIDNum'] : ''),
                    'name' => self::clean(isset($e['CallerIDName']) ? $e['CallerIDName'] : ''),
                    'since' => $this->now - (int) (isset($e['Wait']) ? $e['Wait'] : 0),
                );
            }
        }
        foreach ($calls as $c) if ($c['queue'] && $c['state'] === 'up' && isset($q[$c['queue']])) $q[$c['queue']]['talking']++;
        foreach ($q as &$x) {
            usort($x['waiting'], function ($a, $b) { return $a['pos'] - $b['pos']; });
            usort($x['members'], function ($a, $b) { return strnatcmp((string) $a['ext'], (string) $b['ext']); });
        }
        unset($x);
        uksort($q, 'strnatcmp');
        return array_values($q);
    }

    private function buildGroups(array $queues)
    {
        $out = array();
        if ($this->cfg['groups']) {
            $i = 0;
            foreach ($this->cfg['groups'] as $label => $exts) {
                $out[] = array('id' => 'g' . (++$i), 'label' => (string) $label, 'exts' => array_values(array_map('strval', (array) $exts)));
            }
            return $out;
        }
        foreach ($queues as $q) {
            $exts = array();
            foreach ($q['members'] as $m) if ($m['ext'] !== null) $exts[] = $m['ext'];
            if ($exts) $out[] = array('id' => 'q' . $q['num'], 'label' => $q['name'] !== '' ? $q['name'] : 'Fila ' . $q['num'], 'exts' => array_values(array_unique($exts)));
        }
        return $out;
    }

    /* ---------------------------------------------------------------- troncos */

    private function buildTrunks(array $L, array $calls)
    {
        $sip = array(); $iax = array(); $pj = array(); $pjRtt = array(); $pjHost = array(); $reg = array();
        foreach ((array) $L['sippeers'] as $e) if (isset($e['ObjectName'])) $sip[$e['ObjectName']] = $e;
        foreach ((array) $L['iaxpeers'] as $e) if (isset($e['ObjectName'])) $iax[$e['ObjectName']] = $e;
        foreach ((array) $L['endpoints'] as $e) if (isset($e['ObjectName'])) $pj[$e['ObjectName']] = $e;
        foreach ((array) $L['contacts'] as $e) {
            if (!empty($e['Endpoint']) && !empty($e['RoundtripUsec']) && is_numeric($e['RoundtripUsec'])) $pjRtt[$e['Endpoint']] = (int) round($e['RoundtripUsec'] / 1000);
            if (!empty($e['Endpoint']) && !isset($pjHost[$e['Endpoint']]) && !empty($e['Uri'])) {
                $h = self::uriHost($e['Uri']);
                if ($h !== null) $pjHost[$e['Endpoint']] = $h;
            }
        }
        foreach ((array) $L['pjreg'] as $e) if (isset($e['ObjectName'])) $reg[$e['ObjectName']] = $e;

        $count = array();
        foreach ($calls as $c) {
            $seen = array();
            foreach ($c['trunk_chans'] as $tc) $seen[$tc['trunk']] = true;
            foreach (array_keys($seen) as $t) $count[$t] = (isset($count[$t]) ? $count[$t] : 0) + 1;
        }

        $out = array();
        foreach ($this->trunks as $id => $t) {
            $status = 'unknown'; $detail = 'sem monitoramento'; $rtt = null; $ip = null;
            if ($t['disabled']) { $status = 'disabled'; $detail = 'desativado no PABX'; }
            elseif ($t['tech'] === 'sip' || $t['tech'] === 'iax' || $t['tech'] === 'iax2') {
                $p = $t['tech'] === 'sip' ? (isset($sip[$t['peer']]) ? $sip[$t['peer']] : null) : (isset($iax[$t['peer']]) ? $iax[$t['peer']] : null);
                if ($p) {
                    $st = isset($p['Status']) ? $p['Status'] : '';
                    $ipa = isset($p['IPaddress']) ? $p['IPaddress'] : '-none-';
                    if ($ipa !== '-none-' && $ipa !== '' && $ipa !== '(null)') $ip = $ipa;
                    if (preg_match('/^OK/i', $st)) { $status = 'online'; $detail = 'respondendo'; }
                    elseif (preg_match('/^LAGGED/i', $st)) { $status = 'warn'; $detail = 'respondendo com atraso'; }
                    elseif (preg_match('/^UNREACHABLE/i', $st)) { $status = 'offline'; $detail = 'sem resposta da operadora'; }
                    elseif (preg_match('/^Unmonitored/i', $st)) { $status = $ipa !== '-none-' ? 'online' : 'unknown'; $detail = $ipa !== '-none-' ? 'sem verificação (qualify desligado)' : 'sem verificação'; }
                    else { $status = 'offline'; $detail = 'ainda sem resposta'; }
                    if (preg_match('/\((\d+) ms\)/', $st, $m)) $rtt = (int) $m[1];
                } else { $detail = 'não encontrado no Asterisk'; }
            } elseif ($t['tech'] === 'pjsip') {
                $p = isset($pj[$t['peer']]) ? $pj[$t['peer']] : null;
                if ($p) {
                    $status = $p['DeviceState'] === 'Unavailable' ? 'offline' : 'online';
                    $detail = $status === 'online' ? 'respondendo' : 'sem resposta da operadora';
                    if (isset($pjRtt[$t['peer']])) $rtt = $pjRtt[$t['peer']];
                    if (isset($pjHost[$t['peer']])) $ip = $pjHost[$t['peer']];
                } else { $detail = 'não encontrado no Asterisk'; }
                if (isset($reg[$t['peer']])) {
                    $rs = isset($reg[$t['peer']]['Status']) ? $reg[$t['peer']]['Status'] : '';
                    if ($rs === 'Registered') { if ($status !== 'offline') $detail = 'registrado'; }
                    elseif ($rs !== '') { $status = 'offline'; $detail = $rs === 'Rejected' ? 'registro recusado pela operadora' : 'não registrado'; }
                }
            }
            $out[] = array('id' => $id, 'label' => $t['label'], 'channel' => $t['channel'], 'tech' => $t['tech'], 'ip' => $ip,
                           'status' => $status, 'detail' => $detail, 'rtt_ms' => $rtt, 'calls' => isset($count[$id]) ? $count[$id] : 0);
        }
        return $out;
    }

    /*
     * Por qual tronco a ligação entrou.
     *
     * O nome do canal (SIP/08004440001-...) diz qual peer o chan_sip escolheu, e quando
     * vários troncos da mesma operadora usam o mesmo IP ele escolhe o primeiro peer daquele
     * IP, não o número discado. O que identifica a linha é o DID (FROM_DID, gravado pelo
     * IssabelPBX na entrada). Se o DID bate com o número de um único tronco (no nome, no
     * peer ou no CallerID de saída), a ligação passa a ser desse tronco.
     */
    private function fixInboundTrunks(PopAmi $ami, array &$chans)
    {
        $asked = 0;
        foreach ($chans as &$c) {
            if ($c['trunk'] === null || $c['uid'] !== $c['linkedid'] || $asked >= 40) continue;  // só quem originou (entrada)
            $asked++;
            $did = null;
            foreach (array('FROM_DID', 'CALLERID(dnid)') as $var) {
                try {
                    $r = $ami->send('Getvar', array('Channel' => $c['channel'], 'Variable' => $var));
                } catch (Exception $e) { break; }
                $v = isset($r['Value']) ? trim($r['Value']) : '';
                if (isset($r['Response']) && $r['Response'] === 'Success' && preg_match('/\d{8,}/', $v)) { $did = $v; break; }
            }
            $c['did'] = $did;
            if ($did === null) continue;
            $key = substr(preg_replace('/\D/', '', $did), -8);
            $match = array();
            foreach ($this->trunks as $id => $t) if (in_array($key, $t['numbers'], true)) $match[] = $id;
            if (count($match) === 1 && $match[0] !== $c['trunk']) {
                $c['trunk_peer'] = $c['trunk'];
                $c['trunk'] = $match[0];
            }
        }
        unset($c);
    }

    /* Últimos 8 dígitos de cada número que aparece no nome, peer ou CallerID do tronco. */
    private static function trunkNumbers(array $fields)
    {
        $out = array();
        foreach ($fields as $f) {
            if (preg_match_all('/\d[\d\s().-]{6,}\d/', (string) $f, $m)) {
                foreach ($m[0] as $n) {
                    $d = preg_replace('/\D/', '', $n);
                    if (strlen($d) >= 8) $out[] = substr($d, -8);
                }
            }
        }
        return array_values(array_unique($out));
    }

    /* "sip:gw@200.100.10.5:5060;transport=udp" -> "200.100.10.5"; também aceita nome e IPv6 entre colchetes. */
    public static function uriHost($uri)
    {
        $u = preg_replace('/^<?sips?:/i', '', trim((string) $uri));
        $at = strrpos($u, '@');
        if ($at !== false) $u = substr($u, $at + 1);
        if (preg_match('/^\[([0-9a-f:.]+)\]/i', $u, $m)) return $m[1];
        $u = preg_split('/[:;>?\/]/', $u, 2);
        $h = trim($u[0]);
        return $h !== '' ? $h : null;
    }

    /* ---------------------------------------------------------------- estacionamento e conferências */

    private function buildParking($lots, $parked)
    {
        $slots = array();
        $ranges = array();
        foreach ((array) $lots as $l) {
            if (isset($l['StartSpace'], $l['StopSpace'])) $ranges[] = array((int) $l['StartSpace'], (int) $l['StopSpace'], isset($l['Name']) ? $l['Name'] : 'default');
        }
        if (!$ranges && $this->parkDb && $this->parkDb['count'] > 0) {
            $ranges[] = array($this->parkDb['start'], $this->parkDb['start'] + $this->parkDb['count'] - 1, 'default');
        }
        foreach ($ranges as $r) for ($s = $r[0]; $s <= $r[1] && $s - $r[0] < 50; $s++) $slots[(string) $s] = array('space' => (string) $s, 'lot' => $r[2], 'call' => null);

        foreach ((array) $parked as $p) {
            $space = isset($p['ParkingSpace']) ? $p['ParkingSpace'] : (isset($p['Exten']) ? $p['Exten'] : null);
            if ($space === null) continue;
            $num = self::clean(isset($p['ParkeeCallerIDNum']) ? $p['ParkeeCallerIDNum'] : (isset($p['CallerIDNum']) ? $p['CallerIDNum'] : ''));
            $name = self::clean(isset($p['ParkeeCallerIDName']) ? $p['ParkeeCallerIDName'] : (isset($p['CallerIDName']) ? $p['CallerIDName'] : ''));
            $dur = isset($p['ParkingDuration']) ? (int) $p['ParkingDuration'] : 0;
            $timeout = isset($p['ParkingTimeout']) ? (int) $p['ParkingTimeout'] : (isset($p['Timeout']) ? (int) $p['Timeout'] : 0);
            $by = null;
            $dial = isset($p['ParkerDialString']) ? $p['ParkerDialString'] : (isset($p['From']) ? self::chanDevice($p['From']) : '');
            if ($dial && isset($this->devToExt[strtoupper($dial)])) $by = $this->devToExt[strtoupper($dial)];
            $slots[(string) $space] = array(
                'space' => (string) $space, 'lot' => isset($p['ParkingLot']) ? $p['ParkingLot'] : 'default',
                'call' => array('num' => $num, 'name' => $name, 'since' => $this->now - $dur,
                                'timeout_at' => $timeout ? $this->now - $dur + $timeout : null, 'by' => $by),
            );
        }
        uksort($slots, 'strnatcmp');
        return array_values($slots);
    }

    private function buildConferences($confbridge, $meetme)
    {
        $parties = array();
        foreach (array((array) $confbridge, (array) $meetme) as $list) {
            foreach ($list as $r) if (isset($r['Conference'])) $parties[$r['Conference']] = (int) (isset($r['Parties']) ? $r['Parties'] : 0);
        }
        $out = array();
        foreach ($this->confs as $n => $name) $out[] = array('num' => (string) $n, 'name' => $name, 'parties' => isset($parties[$n]) ? $parties[$n] : 0);
        foreach ($parties as $n => $p) if (!isset($this->confs[$n])) $out[] = array('num' => (string) $n, 'name' => '', 'parties' => $p);
        usort($out, function ($a, $b) { return strnatcmp($a['num'], $b['num']); });
        return $out;
    }
}
