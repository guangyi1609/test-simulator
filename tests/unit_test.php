<?php

declare(strict_types=1);

define('TEST_SIMULATOR_SKIP_BOOTSTRAP', true);

require __DIR__ . '/../public/index.php';

final class TestFailure extends RuntimeException
{
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        $detail = sprintf('%s Expected: %s; Actual: %s', $message, var_export($expected, true), var_export($actual, true));
        throw new TestFailure($detail);
    }
}

function assertTrueCondition(bool $condition, string $message): void
{
    if (!$condition) {
        throw new TestFailure($message);
    }
}

function createTempDir(): string
{
    $path = sys_get_temp_dir() . '/test-simulator-' . bin2hex(random_bytes(8));
    if (!mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('Failed to create temp directory');
    }

    return $path;
}

function deleteDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            deleteDir($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

$tests = [];

$tests['normalizePlayerBalance populates missing cents'] = function (): void {
    $input = [
        'balance' => 12.34,
        'balance_cents' => null,
    ];
    $result = normalizePlayerBalance($input);

    assertSameValue(12.34, $result['balance'], 'Balance should be normalized to two decimals.');
    assertSameValue(1234, $result['balance_cents'], 'Balance cents should be derived from balance.');
};

$tests['parseTransactionAdjustments returns adjustment sum and ids'] = function (): void {
    $transactions = [
        [
            'trans_id' => 't-1',
            'bet_trans_id' => 'b-1',
            'adjust_amount' => -200,
        ],
        [
            'trans_id' => 't-2',
            'bet_trans_id' => 'b-2',
            'adjust_amount' => 150,
        ],
    ];

    [$adjustment, $ids] = parseTransactionAdjustments($transactions);

    assertSameValue(-50, $adjustment, 'Adjustment sum should match transaction amounts.');
    assertSameValue(['t-1', 't-2'], $ids, 'Transaction ids should be preserved.');
};

$tests['findDuplicateTransactionIds includes logged duplicates'] = function (): void {
    $tempDir = createTempDir();
    register_shutdown_function('deleteDir', $tempDir);

    $config = [
        'callback_log_path' => $tempDir,
    ];

    $playerAccount = 'player01';
    $path = $tempDir . '/' . $playerAccount . '.log';

    $logEntry = [
        'transaction_ids' => ['txn-2'],
    ];
    file_put_contents($path, json_encode($logEntry) . PHP_EOL, FILE_APPEND);

    $duplicates = findDuplicateTransactionIds(['txn-1', 'txn-2', 'txn-1'], $playerAccount, $config);

    sort($duplicates);
    assertSameValue(['txn-1', 'txn-2'], $duplicates, 'Duplicates should include repeated and logged ids.');
};

$tests['hybridTransactionExists detects existing transaction id'] = function (): void {
    $tempDir = createTempDir();
    register_shutdown_function('deleteDir', $tempDir);

    $config = [
        'hybrid_log_path' => $tempDir,
    ];

    $playerAccount = 'player02';
    appendHybridLog($playerAccount, [
        'transaction_id' => 'ref-1',
        'amount' => 100,
    ], $config);

    assertTrueCondition(
        hybridTransactionExists($playerAccount, 'ref-1', $config),
        'hybridTransactionExists should return true for stored id.'
    );
    assertTrueCondition(
        !hybridTransactionExists($playerAccount, 'ref-2', $config),
        'hybridTransactionExists should return false for missing id.'
    );
};

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS: {$name}" . PHP_EOL;
    } catch (Throwable $exception) {
        $failures++;
        echo "FAIL: {$name}" . PHP_EOL;
        echo $exception->getMessage() . PHP_EOL;
    }
}

if ($failures > 0) {
    exit(1);
}
