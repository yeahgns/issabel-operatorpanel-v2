<?php
/* Configuração do Painel do Operador. Copiado para /etc/issabel/painel-operador.conf.php
   na primeira instalação; atualizações nunca sobrescrevem. Tudo é opcional. */
return array(
    'company'               => '',     // aparece no topo do painel
    'demo'                  => false,  // true = dados fictícios, para ver a interface
    'poll_seconds'          => 2,      // intervalo de atualização
    'service_level_seconds' => 20,     // espera na fila acima disso fica vermelha (e conta para "No prazo")
    'long_ring_seconds'     => 20,     // ramal tocando há mais que isso fica vermelho

    // Ramais que não aparecem no quadro (continuam nas ligações, se usados).
    'hide_extensions' => array(
        // '999', '2000',
    ),

    // Nome amigável por fila ou tronco, sobrescreve o que está cadastrado no PABX.
    'queue_labels' => array(
        // '600' => 'Comercial',
    ),
    'trunk_labels' => array(
        // 'operadora' => 'Vivo',     // chave: nome do peer (channelid) ou o nome do tronco
    ),

    // Grupos do filtro de ramais. Vazio = um grupo por fila, com quem atende cada uma.
    'groups' => array(
        // 'Recepção'   => array('200', '201'),
        // 'Financeiro' => array('203', '204'),
    ),

    // Estatísticas do dia por fila: 'auto' (arquivo /var/log/asterisk/queue_log, depois
    // a tabela asteriskcdrdb.queue_log), um caminho de arquivo, 'table' ou 'off'.
    'queue_log' => 'auto',
);
