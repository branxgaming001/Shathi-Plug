<?php
declare(strict_types=1);
/**
 * Email delivery. Uses Brevo or Resend HTTP API when a key is configured;
 * otherwise "dev delivery" returns the code so OTP is testable without SMTP.
 */
function mailer_configured(): bool {
    return (bool)(cfg('BREVO_API_KEY') || cfg('RESEND_API_KEY'));
}

function send_email(string $to, string $subject, string $html): bool {
    $from = cfg('MAIL_FROM') ?: 'no-reply@saathi.app';
    $name = cfg('MAIL_FROM_NAME') ?: 'Saathi';

    if ($k = cfg('BREVO_API_KEY')) {
        $body = json_encode(['sender'=>['email'=>$from,'name'=>$name],'to'=>[['email'=>$to]],'subject'=>$subject,'htmlContent'=>$html]);
        return _post_json('https://api.brevo.com/v3/smtp/email', $body, ['api-key: '.$k,'Content-Type: application/json']) < 400;
    }
    if ($k = cfg('RESEND_API_KEY')) {
        $body = json_encode(['from'=>$name.' <'.$from.'>','to'=>[$to],'subject'=>$subject,'html'=>$html]);
        return _post_json('https://api.resend.com/emails', $body, ['Authorization: Bearer '.$k,'Content-Type: application/json']) < 400;
    }
    return false; // dev mode
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
