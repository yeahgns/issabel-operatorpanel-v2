<?php
/*
 * Painel do Operador para Issabel 5.
 *
 * O Issabel só chama _moduleContent() para usuários logados com permissão neste
 * menu, inclusive nas requisições rawmode=yes do JSON. Não existe endpoint sem login.
 * O nome do menu é o nome desta pasta (o install.sh registra com ele).
 */
function _moduleContent(&$smarty, $module_name)
{
    require_once __DIR__ . '/lib/bootstrap.php';
    $cfg = pop_config();
    $action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';

    if ($action === 'snapshot' || $action === 'debug') {
        // Libera a sessão: o painel pede dados a cada poucos segundos e não pode
        // travar as outras abas do Issabel enquanto o AMI responde.
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        if (!empty($cfg['demo'])) return pop_json(array('source' => 'demo'));
        require_once __DIR__ . '/lib/Snapshot.php';
        try {
            if ($action === 'debug') {
                $s = new PopSnapshot($cfg);
                return pop_json($s->build(true));
            }
            return pop_json(PopSnapshot::cached($cfg));
        } catch (Exception $e) {
            return pop_json(array('error' => 'Não foi possível ler o PABX: ' . $e->getMessage()), 500);
        }
    }

    require_once __DIR__ . '/lib/view.php';
    return pop_html(array(
        'api'     => '?menu=' . rawurlencode($module_name) . '&action=snapshot&rawmode=yes',
        'assets'  => 'modules/' . rawurlencode($module_name) . '/assets',
        'demo'    => !empty($cfg['demo']),
        'poll'    => (int) $cfg['poll_seconds'],
        'company' => $cfg['company'],
    ));
}
