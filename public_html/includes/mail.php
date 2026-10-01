<?php
/**
 * Lightweight outbound email for Hostinger (mail() + optional SMTP).
 */

function public_base_url(): string
{
    $cfg = app_config();
    $configured = trim((string) ($cfg['app_url'] ?? ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host . app_base_path();
}

function public_page_url(string $page, array $query = []): string
{
    $query = array_merge(['page' => $page], $query);
    return public_base_url() . '/index.php?' . http_build_query($query);
}

/**
 * True when Forgot password is likely to actually send mail.
 * Empty / sample mail_from without SMTP is treated as not ready.
 */
function app_mail_reset_is_ready(): bool
{
    $cfg = function_exists('app_config') ? app_config() : [];
    if (trim((string) ($cfg['smtp_host'] ?? '')) !== '') {
        return true;
    }
    $from = strtolower(trim((string) ($cfg['mail_from'] ?? '')));
    if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $domain = strtolower((string) (explode('@', $from)[1] ?? ''));
    $placeholders = ['localhost', 'your-domain.com', 'example.com', 'example.org'];
    return $domain !== '' && !in_array($domain, $placeholders, true);
}

/**
 * Send a plain-text email. Returns true on success.
 */
function send_app_mail(string $to, string $subject, string $body): bool
{
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $cfg = app_config();
    $fromEmail = trim((string) ($cfg['mail_from'] ?? ''));
    if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        // Fallback: same domain as app host (Hostinger often accepts this)
        $host = preg_replace('/^www\./i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $fromEmail = 'noreply@' . $host;
    }
    $fromName = trim((string) ($cfg['mail_from_name'] ?? ($cfg['app_name'] ?? 'TechxForm')));
    $app = (string) ($cfg['app_name'] ?? 'TechxForm');

    $subject = preg_replace('/[\r\n]+/', ' ', $subject) ?? $subject;
    $fullBody = $body . "\n\n—\n" . $app . "\n";

    $smtpHost = trim((string) ($cfg['smtp_host'] ?? ''));
    if ($smtpHost !== '') {
        return send_app_mail_smtp($to, $subject, $fullBody, $fromEmail, $fromName, $cfg);
    }

    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'From: ' . sprintf('"%s" <%s>', addcslashes($fromName, '"\\'), $fromEmail);
    $headers[] = 'Reply-To: ' . $fromEmail;
    $headers[] = 'X-Mailer: TechxForm';

    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $fullBody, implode("\r\n", $headers));
}

/**
 * Minimal SMTP client (ssl/tls) for Hostinger when mail() is blocked.
 *
 * @param array<string,mixed> $cfg
 */
function send_app_mail_smtp(
    string $to,
    string $subject,
    string $body,
    string $fromEmail,
    string $fromName,
    array $cfg
): bool {
    $host = (string) ($cfg['smtp_host'] ?? '');
    $port = (int) ($cfg['smtp_port'] ?? 465);
    $user = (string) ($cfg['smtp_user'] ?? '');
    $pass = (string) ($cfg['smtp_pass'] ?? '');
    $secure = strtolower((string) ($cfg['smtp_secure'] ?? 'ssl'));

    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host;
    $fp = @fsockopen($remote, $port, $errno, $errstr, 20);
    if (!$fp) {
        return false;
    }
    stream_set_timeout($fp, 20);

    $read = static function () use ($fp): string {
        $data = '';
        while ($line = fgets($fp, 515)) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    $write = static function (string $cmd) use ($fp): void {
        fwrite($fp, $cmd . "\r\n");
    };

    $ok = static function (string $resp, string $code): bool {
        return str_starts_with($resp, $code);
    };

    try {
        if (!$ok($read(), '220')) {
            fclose($fp);
            return false;
        }
        $write('EHLO localhost');
        $ehlo = $read();
        if (!$ok($ehlo, '250')) {
            $write('HELO localhost');
            if (!$ok($read(), '250')) {
                fclose($fp);
                return false;
            }
        }
        if ($secure === 'tls') {
            $write('STARTTLS');
            if (!$ok($read(), '220')) {
                fclose($fp);
                return false;
            }
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($fp);
                return false;
            }
            $write('EHLO localhost');
            if (!$ok($read(), '250')) {
                fclose($fp);
                return false;
            }
        }
        if ($user !== '') {
            $write('AUTH LOGIN');
            if (!$ok($read(), '334')) {
                fclose($fp);
                return false;
            }
            $write(base64_encode($user));
            if (!$ok($read(), '334')) {
                fclose($fp);
                return false;
            }
            $write(base64_encode($pass));
            if (!$ok($read(), '235')) {
                fclose($fp);
                return false;
            }
        }
        $write('MAIL FROM:<' . $fromEmail . '>');
        if (!$ok($read(), '250')) {
            fclose($fp);
            return false;
        }
        $write('RCPT TO:<' . $to . '>');
        if (!$ok($read(), '250')) {
            fclose($fp);
            return false;
        }
        $write('DATA');
        if (!$ok($read(), '354')) {
            fclose($fp);
            return false;
        }
        $headers = 'From: "' . addcslashes($fromName, '"\\') . '" <' . $fromEmail . ">\r\n"
            . 'To: <' . $to . ">\r\n"
            . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "\r\n"
            . str_replace("\n.", "\n..", $body)
            . "\r\n.";
        $write($headers);
        if (!$ok($read(), '250')) {
            fclose($fp);
            return false;
        }
        $write('QUIT');
        fclose($fp);
        return true;
    } catch (Throwable $e) {
        fclose($fp);
        return false;
    }
}
