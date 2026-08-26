<?php

declare(strict_types=1);

while (ob_get_level() > 0) {
    ob_end_flush();
}

ob_implicit_flush(true);

if (str_starts_with($_SERVER['REQUEST_URI'] ?? '/', '/redirect')) {
    header('Location: /');
    header('X-Redirect-Only: yes');

    return;
}

if (str_starts_with($_SERVER['REQUEST_URI'] ?? '/', '/error')) {
    http_response_code(429);
    header('Content-Type: application/json');
    echo '{"error":{"message":"slow down"}}';

    return;
}

if (str_starts_with($_SERVER['REQUEST_URI'] ?? '/', '/api/chat')) {
    header('Content-Type: application/x-ndjson');

    echo "{\"model\":\"test\",\"message\":{\"role\":\"assistant\",\"content\":\"Hel\"},\"done\":false}\n";
    flush();

    usleep(600_000);

    echo "{\"model\":\"test\",\"message\":{\"role\":\"assistant\",\"content\":\"lo\"},\"done\":true,\"prompt_eval_count\":1,\"eval_count\":2}\n";
    flush();

    return;
}

if (str_starts_with($_SERVER['REQUEST_URI'] ?? '/', '/timeout')) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    echo "data: first\n\n";
    flush();

    usleep(2_000_000);

    echo "data: too-late\n\n";
    flush();

    return;
}

if (str_starts_with($_SERVER['REQUEST_URI'] ?? '/', '/header-timeout')) {
    usleep(2_000_000);

    header('Content-Type: text/event-stream');
    echo "data: too-late\n\n";
    flush();

    return;
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

echo "data: first\n\n";
flush();

usleep(600_000);

echo "data: second\n\n";
flush();
