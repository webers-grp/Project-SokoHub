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
 * Process a single batch of pending jobs (delegates to Security::processJobsBatch).
 */
function process_jobs_batch(PDO $pdo, string $workerId, int $limit): int
{
    return Security::processJobsBatch($pdo, $workerId, $limit, function ($msg) {
        echo $msg;
    });
}

/**
 * Send email via direct SMTP socket (delegates to Security::sendSmtpMail).
 */
function send_smtp_mail(string $to, string $subject, string $body): bool
{
    return Security::sendSmtpMail($to, $subject, $body);
}

/**
 * Execute email dispatch (delegates to Security::executeEmailJob).
 */
function execute_email_job(PDO $pdo, int $jobId, array $payload): void
{
    Security::executeEmailJob($pdo, $jobId, $payload, function ($msg) {
        echo $msg;
    });
}

/**
 * Execute SMS dispatch (delegates to Security::executeSmsJob).
 */
function execute_sms_job(PDO $pdo, int $jobId, array $payload): void
{
    Security::executeSmsJob($pdo, $jobId, $payload, function ($msg) {
        echo $msg;
    });
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
