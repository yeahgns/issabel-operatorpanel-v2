<?php
/*
 * Cliente AMI mínimo e síncrono. Faz login com "Events: off" (não recebe o fluxo
 * de eventos do PABX, só as respostas das ações que pede) e devolve cada ação de
 * lista (QueueStatus, CoreShowChannels...) como um array de eventos.
 */
if (!defined('POP_ROOT')) exit;

class PopAmi
{
    private $sock = null;
    private $seq = 0;
    private $timeout;
    public $raw = null;   // quando é array, guarda tudo que chegou (modo diagnóstico)

    public function __construct(array $a)
    {
        $this->timeout = max(1, (int) $a['timeout']);
        $errno = 0; $errstr = '';
        $this->sock = @stream_socket_client('tcp://' . $a['host'] . ':' . (int) $a['port'], $errno, $errstr, $this->timeout);
        if (!$this->sock) throw new Exception('sem conexão com o Asterisk Manager em ' . $a['host'] . ':' . $a['port'] . " ($errstr)");
        stream_set_timeout($this->sock, $this->timeout);
        $banner = fgets($this->sock, 1024);
        if ($banner === false || stripos($banner, 'Asterisk Call Manager') === false) {
            throw new Exception('o serviço na porta ' . $a['port'] . ' não respondeu como Asterisk Manager');
        }
        $r = $this->send('Login', array('Username' => $a['user'], 'Secret' => $a['pass'], 'Events' => 'off'));
        if (!isset($r['Response']) || $r['Response'] !== 'Success') {
            throw new Exception('login no Asterisk Manager recusado para o usuário "' . $a['user'] . '"');
        }
    }

    public function __destruct()
    {
        if ($this->sock) {
            @fwrite($this->sock, "Action: Logoff\r\n\r\n");
            @fclose($this->sock);
        }
    }

    private function write($action, array $params, $id)
    {
        $s = 'Action: ' . $action . "\r\nActionID: " . $id . "\r\n";
        foreach ($params as $k => $v) {
            $s .= $k . ': ' . str_replace(array("\r", "\n"), '', (string) $v) . "\r\n";
        }
        $s .= "\r\n";
        if (@fwrite($this->sock, $s) === false) throw new Exception('conexão com o Asterisk Manager caiu');
    }

    /* Lê um pacote (linhas até a linha em branco). null = tempo esgotado. */
    private function readPacket()
    {
        $p = array();
        while (true) {
            $line = fgets($this->sock, 65536);
            if ($line === false) {
                $meta = stream_get_meta_data($this->sock);
                if (!empty($meta['timed_out'])) return null;
                throw new Exception('conexão com o Asterisk Manager caiu');
            }
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                if ($p) return $p;
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos === false) continue;
            $k = substr($line, 0, $pos);
            $v = ltrim(substr($line, $pos + 1));
            // Chaves repetidas (raras) ficam com o primeiro valor.
            if (!isset($p[$k])) $p[$k] = $v;
        }
    }

    /* Ação simples: devolve o pacote de resposta. */
    public function send($action, array $params = array())
    {
        $id = 'pop-' . (++$this->seq);
        $this->write($action, $params, $id);
        while (($p = $this->readPacket()) !== null) {
            if (isset($p['ActionID']) && $p['ActionID'] === $id && isset($p['Response'])) return $p;
        }
        throw new Exception("o Asterisk não respondeu à ação $action a tempo");
    }

    /*
     * Ação de lista: devolve os eventos até o "...Complete" (EventList: Complete).
     * Ações que o Asterisk não conhece ou que respondem Error devolvem null
     * (ex.: ConfbridgeListRooms sem salas, chan_sip não carregado).
     */
    public function lst($action, array $params = array())
    {
        $id = 'pop-' . (++$this->seq);
        $this->write($action, $params, $id);
        $events = array();
        $resp = null;
        $deadline = microtime(true) + $this->timeout * 2;
        while (microtime(true) < $deadline) {
            $p = $this->readPacket();
            if ($p === null) break;
            if (!isset($p['ActionID']) || $p['ActionID'] !== $id) continue;
            if (is_array($this->raw)) $this->raw[$action][] = $p;
            if (isset($p['Response'])) {
                $resp = $p;
                if ($p['Response'] !== 'Success') return null;
                // Algumas ações sem itens já avisam na própria resposta.
                if (isset($p['EventList']) && strtolower($p['EventList']) === 'complete') return $events;
                continue;
            }
            if (isset($p['Event'])) {
                if ((isset($p['EventList']) && strtolower($p['EventList']) === 'complete')
                    || substr($p['Event'], -8) === 'Complete') {
                    return $events;
                }
                $events[] = $p;
            }
        }
        if ($resp === null) return null;
        throw new Exception("o Asterisk não terminou a lista $action a tempo");
    }
}
