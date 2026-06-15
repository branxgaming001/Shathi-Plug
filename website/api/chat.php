<?php
/** Landing-page chat endpoint. POST JSON {message, history[]}. */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
require __DIR__ . '/../lib/demo.php';
require __DIR__ . '/../lib/openrouter.php';

$raw = file_get_contents('php://input');
$in  = json_decode($raw, true) ?: [];
$msg = trim((string) ($in['message'] ?? ''));
$hist = is_array($in['history'] ?? null) ? $in['history'] : [];

if ($msg === '' || mb_strlen($msg) > 2000) {
    echo json_encode(['reply' => 'Please type a short message to start.']);
    exit;
}

$key   = getenv('OPENROUTER_API_KEY') ?: '';
$model = getenv('OPENROUTER_MODEL') ?: 'deepseek/deepseek-chat-v3-0324:free';

if ($key !== '') {
    $messages = [['role' => 'system', 'content' => saathi_system_prompt()]];
    foreach (array_slice($hist, -8) as $h) {
        if (isset($h['role'], $h['content'])) {
            $messages[] = ['role' => ($h['role'] === 'assistant' ? 'assistant' : 'user'), 'content' => (string) $h['content']];
        }
    }
    $r = or_chat($messages, $key, $model);
    if ($r['ok']) {
        $out = ['reply' => $r['content']];
        if (wants_products($msg)) {
            $out['products'] = demo_products();
        }
        $out['followups'] = ['question' => 'Want to explore more?', 'options' => ['Show a product demo', 'See pricing', 'Is setup easy?']];
        echo json_encode($out);
        exit;
    }
}

// No key or API error → graceful offline answers (still fully demoable).
echo json_encode(canned_reply($msg));
