<?php
/* Gera uma página única (HTML + CSS + JS embutidos) com o painel em modo demonstração.
   Uso: php build/build-demo.php > painel-demo.html */
$a = dirname(__DIR__) . '/assets/';
$css = file_get_contents($a . 'painel.css');
$demo = file_get_contents($a . 'painel-demo.js');
$js = file_get_contents($a . 'painel.js');
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Painel do operador (demonstração)</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root { box-sizing: border-box; padding-top: env(safe-area-inset-top, 0px); padding-bottom: env(safe-area-inset-bottom, 0px); background: #F3F6FA; }
html, body { margin: 0; min-height: 100%; }
body { background: #F3F6FA; }
@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]), :root:not([data-theme="light"]) body { background: #0D1621; } }
html:has(.pop[data-theme="dark"]), html:has(.pop[data-theme="dark"]) body { background: #0D1621; }
html:has(.pop[data-theme="light"]), html:has(.pop[data-theme="light"]) body { background: #F3F6FA; }
<?php echo $css; ?>
</style>
</head>
<body>
<div class="pop" id="pop-painel" data-api="" data-poll="2" data-demo="1" data-company=""></div>
<script>
(function () {
  var r = document.getElementById('pop-painel');
  if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) r.setAttribute('data-theme', 'dark');
})();
</script>
<script><?php echo $demo; ?></script>
<script><?php echo $js; ?></script>
</body>
</html>
