<?php
// Comme Apache avec UseCanonicalName Off : le nom du serveur vient de l'en-tête Host.
$_SERVER['SERVER_NAME'] = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '');
$_SERVER['SERVER_ADDR'] ??= '127.0.0.1';
return false;
