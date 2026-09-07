<?php
$baseUrl = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');

function request(string $url, ?array $form = null): array
{
    $options = ['http' => ['ignore_errors' => true, 'method' => $form === null ? 'GET' : 'POST']];
    if ($form !== null) {
        $options['http']['header'] = "Content-Type: application/x-www-form-urlencoded\r\n";
        $options['http']['content'] = http_build_query($form);
    }

    $context = stream_context_create($options);
    $body = file_get_contents($url, false, $context);
    $status = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $status, $matches);

    return [(int) ($matches[1] ?? 0), $body ?: ''];
}

$checks = [
    ['portfolio', request($baseUrl . '/index.php')[0] === 200],
    ['health', request($baseUrl . '/assets/php/health.php')[0] === 200],
    ['csrf', request($baseUrl . '/assets/php/csrf.php')[0] === 200],
    ['admin login page', request($baseUrl . '/assets/php/admin.php')[0] === 200],
    ['manifest', request($baseUrl . '/site.webmanifest')[0] === 200],
    ['robots', request($baseUrl . '/robots.txt')[0] === 200],
    ['contact validation', request($baseUrl . '/assets/php/contact.php', ['name' => '', 'email' => '', 'message' => ''])[0] === 400],
    ['contact csrf rejection', request($baseUrl . '/assets/php/contact.php', ['name' => 'Test', 'email' => 'test@example.com', 'message' => 'No token'])[0] === 419],
];

$failed = array_filter($checks, static fn (array $check): bool => !$check[1]);
foreach ($checks as [$name, $passed]) {
    echo ($passed ? 'PASS' : 'FAIL') . " {$name}\n";
}

exit($failed ? 1 : 0);
