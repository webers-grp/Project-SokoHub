<?php
/**
 * SokoHub Automated Verification & Test Runner Entrypoint
 *
 * Runs the complete integration and unit test suite against the local test database.
 * Usage: php docs/test/run_tests.php
 */

define('SOKOHUB_TEST_RUNNER', true);

$mainTestRunner = dirname(__DIR__, 2) . '/tests/run_tests.php';

if (!file_exists($mainTestRunner)) {
    fwrite(STDERR, "Error: Master test runner not found at: {$mainTestRunner}\n");
    exit(1);
}

echo "====================================================\n";
echo "   SokoHub Integration & Unit Test Suite Runner     \n";
echo "====================================================\n";
echo "Executing master suite: tests/run_tests.php\n\n";

require $mainTestRunner;
