<?php
declare(strict_types=1);
/**
 * Email delivery. Uses Brevo or Resend HTTP API when a key is configured;
 * otherwise "dev delivery" returns the code so OTP is testable without SMTP.
 */
function mailer_configured(): bool {
    return (bool)(cfg('BREVO_API_KEY') || cfg('RESEND_API_KEY') || (cfg('SMTP_HOST') && cfg('SMTP_USER')));
}

function send_email(string $to, string $subject, string $html): bool {
    $from = cfg('MAIL_FROM') ?: 'no-reply@saathi.app';
    $name = cfg('MAIL_FROM_NAME') ?: 'Saathi';

    // Prefer brand SMTP (e.g. Hostinger saathi@neermedia.com) when fully
    // configured — so mail is sent from your own domain. Opt-in: until SMTP
    // host+user+pass are all set, delivery falls back to Brevo/Resend.
    if (cfg('SMTP_HOST') && cfg('SMTP_USER') && cfg('SMTP_PASS')) {
        return _smtp_send($to, $subject, $html, $from, $name);
    }
    if ($k = cfg('BREVO_API_KEY')) {
        $body = json_encode(['sender'=>['email'=>$from,'name'=>$name],'to'=>[['email'=>$to]],'subject'=>$subject,'htmlContent'=>$html]);
        return _post_json('https://api.brevo.com/v3/smtp/email', $body, ['api-key: '.$k,'Content-Type: application/json']) < 400;
    }
    if ($k = cfg('RESEND_API_KEY')) {
        $body = json_encode(['from'=>$name.' <'.$from.'>','to'=>[$to],'subject'=>$subject,'html'=>$html]);
        return _post_json('https://api.resend.com/emails', $body, ['Authorization: Bearer '.$k,'Content-Type: application/json']) < 400;
    }
    if (cfg('SMTP_HOST') && cfg('SMTP_USER')) {
        return _smtp_send($to, $subject, $html, $from, $name);
    }
    return false; // dev mode
}

/** Minimal, dependency-free SMTP sender (SSL :465 or STARTTLS :587, AUTH LOGIN). */
function _smtp_send(string $to, string $subject, string $html, string $from, string $name): bool {
    $host = (string) cfg('SMTP_HOST', '');
    $user = (string) cfg('SMTP_USER', '');
    $pass = (string) cfg('SMTP_PASS', '');
    if ($host === '' || $user === '') return false;
    $secure = strtolower((string) cfg('SMTP_SECURE', ''));      // ssl | tls | none
    $port   = (int) (cfg('SMTP_PORT', '') ?: 0);
    if ($secure === '') $secure = ($port === 465) ? 'ssl' : 'tls';
    if ($port === 0)    $port   = ($secure === 'ssl') ? 465 : 587;

    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $proto = ($secure === 'ssl') ? 'ssl://' : 'tcp://';
    $fp = @stream_socket_client($proto . $host . ':' . $port, $eno, $estr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) { error_log("[saathi-smtp] connect failed to $host:$port — $estr"); return false; }
    stream_set_timeout($fp, 20);

    $read = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) { $data .= $line; if (isset($line[3]) && $line[3] === ' ') break; }
        return $data;
    };
    $code = function (string $r): string { return substr($r, 0, 3); };
    $cmd  = function (string $c) use ($fp, $read) { fwrite($fp, $c . "\r\n"); return $read(); };
    $ehlo = (string) (parse_url((string) cfg('PUBLIC_URL', 'https://saathi.neermedia.com'), PHP_URL_HOST) ?: 'saathi.neermedia.com');

    if ($code($read()) !== '220') { fclose($fp); error_log('[saathi-smtp] no 220 greeting'); return false; }
    if ($code($cmd('EHLO ' . $ehlo)) !== '250') { $cmd('HELO ' . $ehlo); }

    if ($secure === 'tls') {
        if ($code($cmd('STARTTLS')) !== '220') { fclose($fp); error_log('[saathi-smtp] STARTTLS refused'); return false; }
        $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        if (!@stream_socket_enable_crypto($fp, true, $crypto)) { fclose($fp); error_log('[saathi-smtp] TLS handshake failed'); return false; }
        $cmd('EHLO ' . $ehlo);
    }

    if ($code($cmd('AUTH LOGIN')) !== '334') { fclose($fp); error_log('[saathi-smtp] AUTH LOGIN refused'); return false; }
    if ($code($cmd(base64_encode($user))) !== '334') { fclose($fp); error_log('[saathi-smtp] username refused'); return false; }
    if ($code($cmd(base64_encode($pass))) !== '235') { fclose($fp); error_log('[saathi-smtp] auth failed'); return false; }
    if ($code($cmd('MAIL FROM:<' . $from . '>')) !== '250') { fclose($fp); error_log('[saathi-smtp] MAIL FROM rejected'); return false; }
    $rcpt = $code($cmd('RCPT TO:<' . $to . '>'));
    if ($rcpt !== '250' && $rcpt !== '251') { fclose($fp); error_log('[saathi-smtp] RCPT rejected'); return false; }
    if ($code($cmd('DATA')) !== '354') { fclose($fp); error_log('[saathi-smtp] DATA refused'); return false; }

    $mimeName = preg_match('/[^\x20-\x7e]/', $name) ? '=?UTF-8?B?' . base64_encode($name) . '?=' : $name;
    $headers  = 'From: ' . $mimeName . ' <' . $from . '>' . "\r\n"
              . 'To: <' . $to . '>' . "\r\n"
              . 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=' . "\r\n"
              . 'MIME-Version: 1.0' . "\r\n"
              . 'Content-Type: text/html; charset=UTF-8' . "\r\n"
              . 'Date: ' . date('r') . "\r\n"
              . 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $ehlo . '>' . "\r\n";
    $bodyData = preg_replace('/^\./m', '..', $html);          // dot-stuffing
    fwrite($fp, $headers . "\r\n" . $bodyData . "\r\n.\r\n");
    $ok = $code($read()) === '250';
    $cmd('QUIT'); fclose($fp);
    if (!$ok) error_log('[saathi-smtp] message not accepted');
    return $ok;
}

function _post_json(string $url, string $body, array $headers): int {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$body, CURLOPT_HTTPHEADER=>$headers, CURLOPT_TIMEOUT=>20]);
    curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return $code;
}

function otp_email_html(string $code): string {
    return '<div style="font-family:system-ui,sans-serif;max-width:460px;margin:auto">
      <h2 style="color:#6D5DFB">Your Saathi code</h2>
      <p>Use this one-time code to sign in. It expires in 10 minutes.</p>
      <div style="font-size:32px;font-weight:800;letter-spacing:8px;color:#1c1340;background:#f3f0ff;padding:16px;border-radius:12px;text-align:center">'.$code.'</div>
      <p style="color:#888;font-size:12px;margin-top:16px">If you didn\'t request this, ignore this email.</p></div>';
}
