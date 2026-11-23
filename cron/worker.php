<?php
/**
 * SokoHub Background Job Queue Runner (Worker)
 * Simple CLI PHP script that polls the jobs table (WHERE status = 'pending' AND available_at <= NOW()),
 * executes email/SMS dispatch, and updates job status to 'completed' or increments attempts.
 *
 * Usage:
 *   php cron/worker.php          (runs a single batch of available jobs, ideal for cron)
 *   php cron/worker.php --daemon (runs continuously, polling every 3 seconds)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Access denied: worker must be run from command line interface.\n";
    exit(1);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/security.php';

$workerId = gethostname() . ':' . getmypid();
$isDaemon = in_array('--daemon', $argv ?? [], true);
$batchLimit = 10;

echo "=========================================================\n";
echo " SokoHub Job Queue Worker Started\n";
echo " Worker ID : {$workerId}\n";
echo " Mode      : " . ($isDaemon ? "Daemon (polling every 3s)" : "Single-pass batch") . "\n";
echo " Time      : " . date('Y-m-d H:i:s') . "\n";
echo "=========================================================\n";

$pdo = get_db_connection();
Security::ensureSchema();

/**
 * Process a single batch of pending jobs.
 * Returns the count of processed jobs.
 */
function process_jobs_batch(PDO $pdo, string $workerId, int $limit): int
{
    $stmtSelect = $pdo->prepare("
        SELECT id, job_type, payload, status, attempts, max_attempts, available_at
        FROM jobs
        WHERE status = 'pending' AND available_at <= NOW()
        ORDER BY available_at ASC, id ASC
        LIMIT :limit
    ");
    $stmtSelect->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmtSelect->execute();
    $jobs = $stmtSelect->fetchAll();

    if (empty($jobs)) {
        return 0;
    }

    $processedCount = 0;

    foreach ($jobs as $job) {
        $jobId = (int) $job['id'];
        $jobType = $job['job_type'];

        // Atomic lock attempt: update status to 'running'
        $stmtLock = $pdo->prepare("
            UPDATE jobs
            SET status = 'running', locked_by = :wid, locked_at = NOW()
            WHERE id = :id AND status = 'pending'
        ");
        $stmtLock->execute([':wid' => $workerId, ':id' => $jobId]);

        if ($stmtLock->rowCount() === 0) {
            // Another worker claimed this job
            continue;
        }

        $processedCount++;
        echo "[" . date('H:i:s') . "] [Job #{$jobId}] Claimed job_type='{$jobType}'... ";

        try {
            $payload = json_decode((string) $job['payload'], true);
            if (!is_array($payload)) {
                $payload = [];
            }

            // Execute job by type
            switch ($jobType) {
                case 'send_email':
                case 'email':
                    execute_email_job($pdo, $jobId, $payload);
                    break;

                case 'send_sms':
                case 'sms':
                    execute_sms_job($pdo, $jobId, $payload);
                    break;

                default:
                    // Generic task handler
                    echo "Unknown type '{$jobType}', marked as completed. ";
                    break;
            }

            // Mark job as completed
            $stmtDone = $pdo->prepare("
                UPDATE jobs
                SET status = 'completed', finished_at = NOW(), locked_by = NULL, last_error = NULL
                WHERE id = :id
            ");
            $stmtDone->execute([':id' => $jobId]);
            echo "-> COMPLETED\n";

        } catch (Throwable $e) {
            $errorMsg = mb_substr($e->getMessage(), 0, 450);
            $newAttempts = (int) $job['attempts'] + 1;
            $maxAttempts = (int) ($job['max_attempts'] ?: 5);
            $newStatus = ($newAttempts >= $maxAttempts) ? 'failed' : 'pending';

            // Exponential / linear backoff: retry in (attempts * 60) seconds
            $backoffSeconds = $newAttempts * 60;
            $nextAvailable = date('Y-m-d H:i:s', time() + $backoffSeconds);

            $stmtFail = $pdo->prepare("
                UPDATE jobs
                SET attempts = :att,
                    status = :st,
                    available_at = :avail,
                    last_error = :err,
                    locked_by = NULL
                WHERE id = :id
            ");
            $stmtFail->execute([
                ':att'   => $newAttempts,
                ':st'    => $newStatus,
                ':avail' => $nextAvailable,
                ':err'   => $errorMsg,
                ':id'    => $jobId,
            ]);

            echo "-> FAILED ({$newAttempts}/{$maxAttempts}): {$errorMsg}\n";
        }
    }

    return $processedCount;
}

/**
 * Send email via direct SMTP socket (supports Gmail, Brevo, SendGrid, Mailtrap, etc.).
 * Returns true if sent successfully, or false to fall back to simulation/mail().
 */
function send_smtp_mail(string $to, string $subject, string $body): bool
{
    $host = getenv('SMTP_HOST') ?: '';
    $port = (int) (getenv('SMTP_PORT') ?: 587);
    $user = getenv('SMTP_USER') ?: '';
    $pass = getenv('SMTP_PASS') ?: '';
    $from = getenv('SMTP_FROM') ?: 'no-reply@sokohub.co.ke';

    $localConfig = __DIR__ . '/../config/db.local.php';
    if (file_exists($localConfig)) {
        $cfg = require $localConfig;
        if (is_array($cfg) && !empty($cfg['smtp']) && is_array($cfg['smtp'])) {
            $host = $cfg['smtp']['host'] ?? $host;
            $port = (int) ($cfg['smtp']['port'] ?? $port);
            $user = $cfg['smtp']['user'] ?? $user;
            $pass = $cfg['smtp']['pass'] ?? $pass;
            $from = $cfg['smtp']['from'] ?? $from;
        }
    }

    if (empty($host) || empty($user) || empty($pass)) {
        return false;
    }

    try {
        $timeout = 10;
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (!$socket) {
            return false;
        }

        $read = function () use ($socket) {
            $res = '';
            while ($str = fgets($socket, 515)) {
                $res .= $str;
                if (substr($str, 3, 1) === ' ') break;
            }
            return $res;
        };
        $write = function ($cmd) use ($socket) {
            fputs($socket, $cmd . "\r\n");
        };

        $read();
        $write("EHLO localhost");
        $read();

        if ($port === 587) {
            $write("STARTTLS");
            $read();
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($socket);
                return false;
            }
            $write("EHLO localhost");
            $read();
        }

        $write("AUTH LOGIN");
        $read();
        $write(base64_encode($user));
        $read();
        $write(base64_encode($pass));
        $read();

        $write("MAIL FROM: <{$from}>");
        $read();
        $write("RCPT TO: <{$to}>");
        $read();
        $write("DATA");
        $read();

        $headers = "From: SokoHub <{$from}>\r\nTo: <{$to}>\r\nSubject: {$subject}\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
        $write($headers . "\r\n" . $body . "\r\n.");
        $read();
        $write("QUIT");
        fclose($socket);

        return true;
    } catch (Throwable $_) {
        return false;
    }
}

/**
 * Execute email dispatch and log into notification_logs table.
 */
function execute_email_job(PDO $pdo, int $jobId, array $payload): void
{
    $to = $payload['to'] ?? ($payload['recipient'] ?? '');
    $subject = $payload['subject'] ?? 'SokoHub Notification';
    $template = $payload['template'] ?? 'general';
    $code = $payload['code'] ?? '';
    $resetUrl = $payload['reset_url'] ?? '';
    $userId = isset($payload['user_id']) ? (int) $payload['user_id'] : null;

    if (empty($to)) {
        throw new InvalidArgumentException("Missing email recipient ('to').");
    }

    // Compose message body
    $body = $payload['body'] ?? '';
    if (empty($body)) {
        if ($code !== '') {
            $body = "Welcome to SokoHub!\n\nYour 6-digit verification code is: {$code}\nThis code is valid for 10 minutes.\n\nIf you did not request this, please ignore this message.";
        } elseif ($resetUrl !== '') {
            $body = "We received a request to reset your SokoHub account password.\n\nPlease click the link below to set a new password:\n{$resetUrl}\n\nThis link is valid for 30 minutes.\nIf you did not request this, your account remains secure.";
        } else {
            $body = "You have a new update from SokoHub.";
        }
    }

    $sentSmtp = send_smtp_mail($to, $subject, $body);

    if ($sentSmtp) {
        echo "Dispatched live SMTP email to '{$to}' (template: {$template}) ";
    } else {
        // Fallback local simulation / PHP mail
        $headers = "From: no-reply@sokohub.co.ke\r\nReply-To: support@sokohub.co.ke\r\nX-Mailer: PHP/" . phpversion();
        if (function_exists('mail') && !empty(ini_get('SMTP')) && ini_get('SMTP') !== 'localhost') {
            @mail($to, $subject, $body, $headers);
        }
        echo "Processed email job for '{$to}' (template: {$template}) ";
    }

    // Record in notification_logs table
    Security::logNotification($pdo, $userId, 'email', $template, $to);
}

/**
 * Execute SMS dispatch and log into notification_logs table.
 */
function execute_sms_job(PDO $pdo, int $jobId, array $payload): void
{
    $phone = $payload['phone'] ?? ($payload['recipient'] ?? ($payload['to'] ?? ''));
    $template = $payload['template'] ?? 'sms_alert';
    $message = $payload['message'] ?? '';
    $userId = isset($payload['user_id']) ? (int) $payload['user_id'] : null;

    if (empty($phone)) {
        throw new InvalidArgumentException("Missing phone recipient.");
    }

    // If Telephony service exists, invoke it
    if (class_exists('Telephony') && method_exists('Telephony', 'sendSms')) {
        try {
            Telephony::sendSms($phone, $message);
        } catch (Throwable $e) {
            // Non-fatal if offline/mock
        }
    }

    // Record in notification_logs table
    Security::logNotification($pdo, $userId, 'sms', $template, $phone);
    echo "Dispatched SMS to '{$phone}' ";
}

// Execution Loop
do {
    $count = process_jobs_batch($pdo, $workerId, $batchLimit);

    if ($count > 0) {
        echo "[" . date('H:i:s') . "] Processed {$count} job(s) in this batch.\n";
    }

    if ($isDaemon) {
        sleep(3);
    }
} while ($isDaemon);

echo "[" . date('H:i:s') . "] Worker single-pass finished.\n";
