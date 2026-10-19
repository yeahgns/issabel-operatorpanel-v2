<?php
/*
 * Números do dia por fila (atendidas, perdidas, nível de serviço, espera média),
 * lidos do queue_log, que é onde o próprio Asterisk registra cada entrada e saída
 * de fila. Diferente do Completed/Abandoned do QueueStatus, que só zera quando
 * alguém roda "queue reset stats" ou o Asterisk reinicia.
 *
 * O arquivo é lido de trás para frente e a leitura para na meia-noite, então o
 * custo é proporcional às linhas de hoje, não ao tamanho do arquivo. O resultado
 * fica em cache por alguns segundos e é compartilhado por todos os navegadores.
 */
if (!defined('POP_ROOT')) exit;

class PopQueueStats
{
    const CACHE_SECONDS = 15;

    /** @return array fila => stats, ou null quando não há fonte disponível */
    public static function today(array $cfg, $now = null)
    {
        if ($cfg['queue_log'] === 'off') return null;
        $now = $now ?: time();
        $cache = pop_cache_dir($cfg) . '/painel-operador-qstats.json';
        $c = @json_decode((string) @file_get_contents($cache), true);
        $day = date('Y-m-d', $now);
        if (is_array($c) && isset($c['day'], $c['at']) && $c['day'] === $day && $now - $c['at'] < self::CACHE_SECONDS) {
            return $c['stats'];
        }
        $start = strtotime($day . ' 00:00:00');
        $rows = self::read($cfg, $start);
        $stats = $rows === null ? null : self::aggregate($rows, (int) $cfg['service_level_seconds']);
        @file_put_contents($cache, json_encode(array('day' => $day, 'at' => $now, 'stats' => $stats)), LOCK_EX);
        return $stats;
    }

    /** Linhas de hoje como arrays [time, callid, queue, agent, event, d1, d2, d3]. null = sem fonte. */
    private static function read(array $cfg, $start)
    {
        $src = $cfg['queue_log'];
        if ($src === 'auto' || ($src !== 'table' && $src[0] === '/')) {
            $file = $src === 'auto' ? '/var/log/asterisk/queue_log' : $src;
            if (@is_readable($file)) return self::readFile($file, $start);
            if ($src !== 'auto') return null;
        }
        return self::readTable($cfg, $start);
    }

    public static function readFile($file, $start)
    {
        $fh = @fopen($file, 'rb');
        if (!$fh) return null;
        $rows = array();
        $pos = filesize($file);
        $buf = '';
        $chunk = 65536;
        $done = false;
        while ($pos > 0 && !$done) {
            $len = min($chunk, $pos);
            $pos -= $len;
            fseek($fh, $pos);
            $buf = fread($fh, $len) . $buf;
            $lines = explode("\n", $buf);
            // A primeira linha pode estar cortada no meio; volta para o buffer.
            $buf = $pos > 0 ? array_shift($lines) : '';
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $l = trim($lines[$i]);
                if ($l === '') continue;
                $f = explode('|', $l);
                if (count($f) < 5) continue;
                if ((int) $f[0] < $start) { $done = true; break; }
                $rows[] = $f;
            }
        }
        fclose($fh);
        return array_reverse($rows);
    }

    private static function readTable(array $cfg, $start)
    {
        try {
            $pdo = pop_pbx_pdo($cfg);
            $t = $pdo->query("SHOW TABLES FROM asteriskcdrdb LIKE 'queue_log'");
            if (!$t || !$t->fetchColumn()) return null;
            $st = $pdo->prepare('SELECT UNIX_TIMESTAMP(time) AS t, callid, queuename, agent, event, data1, data2, data3
                                 FROM asteriskcdrdb.queue_log WHERE time >= FROM_UNIXTIME(?) ORDER BY time, id');
            $st->execute(array($start));
            $rows = array();
            foreach ($st as $r) {
                $rows[] = array($r['t'], $r['callid'], $r['queuename'], $r['agent'], $r['event'], $r['data1'], $r['data2'], $r['data3']);
            }
            return $rows;
        } catch (Exception $e) {
            return null;
        }
    }

    public static function aggregate(array $rows, $sl)
    {
        $q = array();
        foreach ($rows as $f) {
            $queue = isset($f[2]) ? $f[2] : '';
            if ($queue === '' || $queue === 'NONE') continue;
            $ev = $f[4];
            if (!isset($q[$queue])) {
                $q[$queue] = array('offered' => 0, 'answered' => 0, 'lost' => 0, 'abandoned' => 0,
                                   'answered_in_sl' => 0, 'wait_sum' => 0, 'talk_sum' => 0, 'talk_n' => 0);
            }
            $s =& $q[$queue];
            switch ($ev) {
                case 'ENTERQUEUE':
                    $s['offered']++;
                    break;
                case 'CONNECT':
                    $w = (int) (isset($f[5]) ? $f[5] : 0);
                    $s['answered']++;
                    $s['wait_sum'] += $w;
                    if ($w <= $sl) $s['answered_in_sl']++;
                    break;
                case 'COMPLETECALLER':
                case 'COMPLETEAGENT':
                    $s['talk_sum'] += (int) (isset($f[6]) ? $f[6] : 0);
                    $s['talk_n']++;
                    break;
                case 'ABANDON':
                    $s['abandoned']++;
                    $s['lost']++;
                    break;
                case 'EXITWITHTIMEOUT':
                case 'EXITEMPTY':
                case 'EXITWITHKEY':
                    $s['lost']++;
                    break;
            }
            unset($s);
        }
        $out = array();
        foreach ($q as $name => $s) {
            $base = $s['answered'] + $s['lost'];
            $out[(string) $name] = array(
                'offered'   => $s['offered'],
                'answered'  => $s['answered'],
                'lost'      => $s['lost'],
                'abandoned' => $s['abandoned'],
                'sla_pct'   => $base ? round($s['answered_in_sl'] / $base * 100) : null,
                'avg_wait'  => $s['answered'] ? (int) round($s['wait_sum'] / $s['answered']) : null,
                'avg_talk'  => $s['talk_n'] ? (int) round($s['talk_sum'] / $s['talk_n']) : null,
            );
        }
        return $out;
    }
}
