<?php
/**
 * Minimal OpenAI-compatible chat caller (OpenRouter, OpenAI, Groq, Together,
 * DeepInfra, local LM servers, …). Returns ['ok'=>bool,'content'|'error'].
 * Pass $url to target any /chat/completions endpoint; defaults to OpenRouter.
 */
function or_chat(array $messages, string $key, string $model, string $url = 'https://openrouter.ai/api/v1/chat/completions'): array
{
    $url = $url !== '' ? $url : 'https://openrouter.ai/api/v1/chat/completions';
    $payload = json_encode([
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => 0.5,
        'max_tokens'  => 600,
    ]);

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $key,
    ];
    // OpenRouter-specific attribution headers (ignored by other providers, but
    // only sent to OpenRouter to stay clean).
    if (stripos($url, 'openrouter.ai') !== false) {
        $headers[] = 'HTTP-Referer: https://saathi.neermedia.com';
        $headers[] = 'X-Title: Saathi';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $headers,
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
