<?php
/** Minimal OpenRouter chat caller. Returns ['ok'=>bool,'content'|'error']. */
function or_chat(array $messages, string $key, string $model): array
{
    $payload = json_encode([
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => 0.5,
        'max_tokens'  => 600,
    ]);

    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
            'HTTP-Referer: https://website-production-7e70.up.railway.app',
            'X-Title: Saathi',
        ],
        CURLOPT_TIMEOUT        => 45,
    ]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($res === false) {
        return ['ok' => false, 'error' => 'network: ' . $err];
    }
    $j = json_decode($res, true);
    if ($code >= 400 || !isset($j['choices'][0]['message']['content'])) {
        return ['ok' => false, 'error' => 'api ' . $code];
    }
    $c = (string) $j['choices'][0]['message']['content'];
    // Strip any reasoning tags just in case.
    $c = preg_replace('#<think>.*?</think>#is', ' ', $c);
    $c = preg_replace('#<think>.*$#is', '', $c);
    return ['ok' => true, 'content' => trim($c)];
}
