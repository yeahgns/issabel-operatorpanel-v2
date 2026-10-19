<?php
if (!defined('POP_ROOT')) exit;

/* HTML do painel. Usado pelo módulo do Issabel e pelo build da demo. */
function pop_html(array $o)
{
    $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $v = $h(POP_VERSION);
    $a = $h($o['assets']);
    $demoJs = $o['demo'] ? '<script src="' . $a . '/painel-demo.js?v=' . $v . '"></script>' : '';
    return <<<HTML
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{$a}/painel.css?v={$v}">
<div class="pop" id="pop-painel" data-api="{$h($o['api'])}" data-poll="{$h($o['poll'])}" data-demo="{$h($o['demo'] ? '1' : '0')}" data-company="{$h($o['company'])}"></div>
{$demoJs}
<script src="{$a}/painel.js?v={$v}"></script>
HTML;
}
