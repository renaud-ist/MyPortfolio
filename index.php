<?php
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header("Content-Security-Policy: default-src 'self'; connect-src 'self' http://127.0.0.1:8787 https://myportfolio-api-proxy.yangdarenaud893.workers.dev https://formspree.io; img-src 'self' data:; style-src 'self' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self' https://formspree.io;");
readfile(__DIR__ . '/index.html');
?>
