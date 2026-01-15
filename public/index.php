<?php

declare(strict_types=1);

$config = require __DIR__ . '/../config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH) ?: '/';
$queryParams = [];
$queryString = parse_url($requestUri, PHP_URL_QUERY);
if (is_string($queryString)) {
    parse_str($queryString, $queryParams);
}

if ($method !== 'POST') {
    respond(405, ['error' => 'Method not allowed']);
}

$payload = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($payload)) {
    respond(400, ['error' => 'Invalid JSON body']);
}

switch ($path) {
    case '/api/launch':
        handleLaunch($payload, $config);
        break;
    case '/api/credit/topup':
        handleTopup($payload, $config);
        break;
    case '/api/credit/balance':
        handleBalance($payload, $config);
        break;
    case '/api/callback':
        handleCallback($payload, $config);
        break;
    case '/api/callbacks/list':
        handleCallbackList($payload, $config);
        break;
    case '/api/callbacks/delete':
        handleCallbackDelete($payload, $config);
        break;
    case '/wallet/deposit':
        handleWalletDeposit($payload, $config, $queryParams);
        break;
    case '/wallet/withdrawal':
        handleWalletWithdrawal($payload, $config, $queryParams);
        break;
    case '/wallet/balance':
        handleWalletBalance($payload, $config, $queryParams);
        break;
    case '/wallet/check-trans-status':
        handleWalletTransactionStatus($payload, $config, $queryParams);
        break;
    case '/wallet/void':
        handleWalletVoid($payload, $config, $queryParams);
        break;
    case '/wallet/transactions/list':
        handleWalletTransactionList($payload, $config);
        break;
    case '/wallet/transactions/delete':
        handleWalletTransactionDelete($payload, $config);
        break;
    case '/api/hybrid/callback':
        handleHybridCallback($payload, $config, $queryParams);
        break;
    default:
        respond(404, ['error' => 'Not found']);
}

function handleLaunch(array $payload, array $config): void
{
    $required = ['agent_code', 'player_account', 'provider_code', 'game_code', 'currency', 'lang'];
    foreach ($required as $field) {
        if (empty($payload[$field]) || !is_string($payload[$field])) {
            respond(422, ['error' => "Missing or invalid field: {$field}"]);            
        }
    }

    $betLimit = $payload['bet_limit'] ?? null;
    if (!is_null($betLimit) && !is_string($betLimit) && !is_numeric($betLimit)) {
        respond(422, ['error' => 'Invalid field: bet_limit']);
    }

    $playerAccount = $payload['player_account'];
    $playerData = loadPlayer($playerAccount, $config);
    if ($playerData === null) {
        $playerData = createPlayer($playerAccount, $config);
    }

    $token = bin2hex(random_bytes(16));
    $playerData['token'] = $token;
    $playerData['updated_at'] = gmdate('c');
    savePlayer($playerAccount, $playerData, $config);

    $traceId = $payload['trace_id'] ?? generateTraceId();
    $requestBody = [
        'agent_code' => $payload['agent_code'],
        'player_account' => $playerAccount,
        'provider_code' => $payload['provider_code'],
        'game_code' => $payload['game_code'],
        'currency' => $payload['currency'],
        'token' => $token,
        'lang' => $payload['lang'],
    ];

    if ($betLimit !== null) {
        $requestBody['bet_limit'] = $betLimit;
    }

    $requestBody['sign'] = signPayload($requestBody, $config['agent_key']);

    $url = rtrim($config['aggregator_base_url'], '/') . '/game/launch-game?trace_id=' . urlencode($traceId);
    [$statusCode, $response] = postJson($url, $requestBody);

    respond(200, [
        'trace_id' => $traceId,
        'request' => $requestBody,
        'aggregator_status' => $statusCode,
        'aggregator_response' => $response,
    ]);
}

function handleTopup(array $payload, array $config): void
{
    if (empty($payload['player_account']) || !is_string($payload['player_account'])) {
        respond(422, ['error' => 'Missing or invalid field: player_account']);
    }
    if (!isset($payload['amount']) || !is_numeric($payload['amount'])) {
        respond(422, ['error' => 'Missing or invalid field: amount']);
    }

    $amount = (float) $payload['amount'];
    if ($amount <= 0) {
        respond(422, ['error' => 'Amount must be greater than 0']);
    }

    $playerAccount = $payload['player_account'];
    $playerData = loadPlayer($playerAccount, $config);
    if ($playerData === null) {
        $playerData = createPlayer($playerAccount, $config);
    }
    $playerData = normalizePlayerBalance($playerData);

    $playerData['balance'] = round(((float) ($playerData['balance'] ?? 0)) + $amount, 2);
    $playerData['balance_cents'] = (int) round($playerData['balance'] * 100);
    $playerData['updated_at'] = gmdate('c');
    savePlayer($playerAccount, $playerData, $config);

    respond(200, [
        'player_account' => $playerAccount,
        'balance' => $playerData['balance'],
        'balance_cents' => $playerData['balance_cents'],
    ]);
}

function handleBalance(array $payload, array $config): void
{
    if (empty($payload['player_account']) || !is_string($payload['player_account'])) {
        respond(422, ['error' => 'Missing or invalid field: player_account']);
    }

    $playerAccount = $payload['player_account'];
    $playerData = loadPlayer($playerAccount, $config);
    if ($playerData === null) {
        respond(404, ['error' => 'Player not found']);
    }
    $playerData = normalizePlayerBalance($playerData);

    respond(200, [
        'player_account' => $playerAccount,
        'balance' => $playerData['balance'] ?? 0,
        'balance_cents' => $playerData['balance_cents'] ?? 0,
        'token' => $playerData['token'] ?? null,
    ]);
}

function handleCallback(array $payload, array $config): void
{
    $required = ['action', 'agent_code', 'player_account', 'currency', 'provider_code'];
    foreach ($required as $field) {
        if (empty($payload[$field]) || !is_string($payload[$field])) {
            respond(200, ['status' => '206']);
        }
    }

    $action = strtolower($payload['action']);
    $action = str_replace(['-', '_'], '', $action);
    $allowedActions = ['balance', 'bet', 'settle', 'refund', 'resettle', 'betsettle'];
    if (!in_array($action, $allowedActions, true)) {
        respond(200, ['status' => '206']);
    }
    $playerAccount = $payload['player_account'];
    $playerData = loadPlayer($playerAccount, $config);
    if ($playerData === null) {
        respond(200, ['status' => '206']);
    }
    $playerData = normalizePlayerBalance($playerData);

    if ($action === 'balance') {
        appendCallbackLog($playerAccount, [
            'datetime' => date('c'),
            'action' => $action,
            'payload' => $payload,
            'adjustment_cents' => 0,
            'balance_cents' => $playerData['balance_cents'],
        ], $config);

        respond(200, [
            'status' => '000',
            'player_account' => $playerAccount,
            'wallet_balance' => $playerData['balance_cents'],
            'updated_at' => gmdate('c'),
            'currency' => $payload['currency'],
        ]);
    }

    $required = ['game_code', 'round_id', 'trans_data'];
    foreach ($required as $field) {
        if (empty($payload[$field])) {
            respond(200, ['status' => '206']);
        }
    }
    if (!is_array($payload['trans_data'])) {
        respond(200, ['status' => '206']);
    }

    [$adjustment, $transactionIds] = parseTransactionAdjustments($payload['trans_data']);
    $duplicateIds = findDuplicateTransactionIds($transactionIds, $playerAccount, $config);
    if ($duplicateIds !== []) {
        respond(200, ['status' => '234']);
    }

    $newBalance = (int) $playerData['balance_cents'] + $adjustment;
    if ($newBalance < 0) {
        respond(200, ['status' => '227']);
    }

    $playerData['balance_cents'] = $newBalance;
    $playerData['balance'] = round($playerData['balance_cents'] / 100, 2);
    $playerData['updated_at'] = gmdate('c');
    savePlayer($playerAccount, $playerData, $config);

    appendCallbackLog($playerAccount, [
        'datetime' => date('c'),
        'action' => $action,
        'payload' => $payload,
        'adjustment_cents' => $adjustment,
        'transaction_ids' => $transactionIds,
        'balance_cents' => $playerData['balance_cents'],
    ], $config);

    respond(200, [
        'status' => '000',
        'player_account' => $playerAccount,
        'wallet_balance' => $playerData['balance_cents'],
    ]);
}

function handleWalletDeposit(array $payload, array $config, array $queryParams): void
{
    $required = ['agent_code', 'provider_code', 'player_account', 'amount', 'currency', 'transaction_id', 'type'];
    foreach ($required as $field) {
        if (!isset($payload[$field]) || $payload[$field] === '') {
            respond(200, ['status' => false, 'code' => '206', 'message' => 'Missing required field']);
        }
    }
    if (!is_string($payload['player_account']) || !is_numeric($payload['amount'])) {
        respond(200, ['status' => false, 'code' => '206', 'message' => 'Invalid request']);
    }

    $amount = (int) $payload['amount'];
    if ($amount <= 0) {
        respond(200, ['status' => false, 'code' => '206', 'message' => 'Invalid amount']);
    }

    $playerAccount = $payload['player_account'];
    $transactionId = (string) $payload['transaction_id'];
    if (hybridTransactionExists($playerAccount, $transactionId, $config)) {
        respond(200, ['status' => false, 'code' => '226', 'message' => 'The transaction reference ID has already been used. It must be unique']);
    }

    $playerData = loadPlayer($playerAccount, $config);
    if ($playerData === null) {
        $playerData = createPlayer($playerAccount, $config);
    }
    $playerData = normalizePlayerBalance($playerData);
    $balanceBefore = (int) $playerData['balance_cents'];
    $playerData['balance_cents'] = $balanceBefore + $amount;
    $playerData['balance'] = round($playerData['balance_cents'] / 100, 2);
    $playerData['updated_at'] = gmdate('c');
    savePlayer($playerAccount, $playerData, $config);

    $timestamp = date('Y-m-d H:i:s');
    appendHybridLog($playerAccount, [
        'datetime' => $timestamp,
        'action' => 'deposit',
        'transaction_id' => $transactionId,
        'transaction_type' => 1,
        'amount' => $amount,
        'balance_before' => $balanceBefore,
        'balance_after' => $playerData['balance_cents'],
        'currency' => (string) $payload['currency'],
        'provider_code' => (string) $payload['provider_code'],
        'agent_code' => (string) $payload['agent_code'],
        'type' => (string) $payload['type'],
        'trace_id' => $queryParams['trace_id'] ?? null,
        'status' => 'complete',
    ], $config);

    $response = [
        'status' => true,
        'code' => '000',
        'message' => 'Success',
        'data' => [
            'player_account' => $playerAccount,
            'wallet_balance' => (string) $playerData['balance_cents'],
            'currency' => (string) $payload['currency'],
            'currency_code' => (string) $payload['currency'],
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ],
    ];
    if (!empty($queryParams['trace_id'])) {
        $response['trace_id'] = (string) $queryParams['trace_id'];
    }

    respond(200, $response);
}

function handleWalletWithdrawal(array $payload, array $config, array $queryParams): void
{
    $required = ['agent_code', 'provider_code', 'player_account', 'amount', 'currency', 'transaction_id'];
    foreach ($required as $field) {
        if (!isset($payload[$field]) || $payload[$field] === '') {
            respond(200, ['status' => false, 'code' => '206', 'message' => 'Missing required field']);
        }
    }
    if (!is_string($payload['player_account']) || !is_numeric($payload['amount'])) {
        respond(200, ['status' => false, 'code' => '206', 'message' => 'Invalid request']);
    }

    $amount = (int) $payload['amount'];
    if ($amount <= 0) {
        respond(200, ['status' => false, 'code' => '206', 'message' => 'Invalid amount']);
    }

    $playerAccount = $payload['player_account'];
    $transactionId = (string) $payload['transaction_id'];
    if (hybridTransactionExists($playerAccount, $transactionId, $config)) {
        respond(200, ['status' => false, 'code' => '226', 'message' => 'The transaction reference ID has already been used. It must be unique']);
    }

    $playerData = loadPlayer($playerAccount, $config);
    if ($playerData === null) {
        $playerData = createPlayer($playerAccount, $config);
    }
    $playerData = normalizePlayerBalance($playerData);
    $balanceBefore = (int) $playerData['balance_cents'];
    $balanceAfter = $balanceBefore - $amount;
    if ($balanceAfter < 0) {
        respond(200, ['status' => false, 'code' => '227', 'message' => 'Insufficient balance']);
    }

    $playerData['balance_cents'] = $balanceAfter;
    $playerData['balance'] = round($playerData['balance_cents'] / 100, 2);
    $playerData['updated_at'] = gmdate('c');
    savePlayer($playerAccount, $playerData, $config);

    $timestamp = date('Y-m-d H:i:s');
    appendHybridLog($playerAccount, [
        'datetime' => $timestamp,
        'action' => 'withdrawal',
        'transaction_id' => $transactionId,
        'transaction_type' => 2,
        'amount' => $amount,
        'balance_before' => $balanceBefore,
        'balance_after' => $playerData['balance_cents'],
        'currency' => (string) $payload['currency'],
        'provider_code' => (string) $payload['provider_code'],
        'agent_code' => (string) $payload['agent_code'],
        'trace_id' => $queryParams['trace_id'] ?? null,
        'status' => 'complete',
    ], $config);

    $response = [
        'status' => true,
        'code' => '000',
        'message' => 'Success',
        'data' => [
            'player_account' => $playerAccount,
            'wallet_balance' => (string) $playerData['balance_cents'],
            'currency' => (string) $payload['currency'],
            'currency_code' => (string) $payload['currency'],
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ],
    ];
    if (!empty($queryParams['trace_id'])) {
        $response['trace_id'] = (string) $queryParams['trace_id'];
    }

    respond(200, $response);
}

function handleWalletBalance(array $payload, array $config, array $queryParams): void
{
    $required = ['agent_code', 'provider_code', 'player_account', 'currency'];
    foreach ($required as $field) {
        if (!isset($payload[$field]) || $payload[$field] === '') {
            respond(200, ['status' => false, 'code' => '206', 'message' => 'Missing required field']);
        }
    }
    if (!is_string($payload['player_account'])) {
        respond(200, ['status' => false, 'code' => '206', 'message' => 'Invalid request']);
    }

    $playerAccount = $payload['player_account'];
    $playerData = loadPlayer($playerAccount, $config);
    if ($playerData === null) {
        respond(200, ['status' => false, 'code' => '226', 'message' => 'Player account provided is invalid or does not exist']);
    }
    $playerData = normalizePlayerBalance($playerData);

    $timestamp = date('Y-m-d H:i:s');
    $response = [
        'status' => true,
        'code' => '000',
        'message' => 'Success',
        'data' => [
            'player_account' => $playerAccount,
            'wallet_balance' => (string) $playerData['balance_cents'],
            'currency' => (string) $payload['currency'],
            'currency_code' => (string) $payload['currency'],
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ],
    ];
    if (!empty($queryParams['trace_id'])) {
        $response['trace_id'] = (string) $queryParams['trace_id'];
    }

    respond(200, $response);
}

function handleWalletTransactionStatus(array $payload, array $config, array $queryParams): void
{
    $required = ['agent_code', 'provider_code', 'transaction_id'];
    foreach ($required as $field) {
        if (!isset($payload[$field]) || $payload[$field] === '') {
            respond(200, ['status' => false, 'code' => '206', 'message' => 'Missing required field']);
        }
    }

    $transactionId = (string) $payload['transaction_id'];
    $entry = findHybridTransaction($payload['player_account'] ?? null, $transactionId, $config);
    if ($entry === null) {
        respond(200, ['status' => false, 'code' => '209', 'message' => 'No transaction found with the provided ID']);
    }

    $response = [
        'status' => true,
        'code' => '000',
        'message' => 'Success',
        'data' => [
            'transaction_id' => $transactionId,
            'transaction_amount' => (string) ($entry['amount'] ?? 0),
            'transaction_type' => $entry['transaction_type'] ?? null,
            'balance_before' => (string) ($entry['balance_before'] ?? 0),
            'balance_after' => (string) ($entry['balance_after'] ?? 0),
            'status' => $entry['status'] ?? 'complete',
            'player_account' => $entry['player_account'] ?? null,
            'created_at' => $entry['datetime'] ?? null,
        ],
    ];
    if (!empty($queryParams['trace_id'])) {
        $response['trace_id'] = (string) $queryParams['trace_id'];
    }

    respond(200, $response);
}

function handleWalletVoid(array $payload, array $config, array $queryParams): void
{
    $required = ['agent_code', 'provider_code', 'player_account', 'amount', 'currency', 'transaction_id'];
    foreach ($required as $field) {
        if (!isset($payload[$field]) || $payload[$field] === '') {
            respond(200, ['status' => false, 'code' => '206', 'message' => 'Missing required field']);
        }
    }
    if (!is_string($payload['player_account']) || !is_numeric($payload['amount'])) {
        respond(200, ['status' => false, 'code' => '206', 'message' => 'Invalid request']);
    }

    $amount = (int) $payload['amount'];
    if ($amount <= 0) {
        respond(200, ['status' => false, 'code' => '206', 'message' => 'Invalid amount']);
    }

    $playerAccount = $payload['player_account'];
    $transactionId = (string) $payload['transaction_id'];
    $existing = findHybridTransaction($playerAccount, $transactionId, $config);
    if ($existing === null) {
        respond(200, ['status' => false, 'code' => '209', 'message' => 'No transaction found with the provided ID']);
    }
    if (($existing['action'] ?? '') === 'void') {
        respond(200, ['status' => false, 'code' => '226', 'message' => 'The transaction reference ID has already been used. It must be unique']);
    }

    $playerData = loadPlayer($playerAccount, $config);
    if ($playerData === null) {
        $playerData = createPlayer($playerAccount, $config);
    }
    $playerData = normalizePlayerBalance($playerData);
    $balanceBefore = (int) $playerData['balance_cents'];

    $balanceAfter = $balanceBefore;
    if (($existing['action'] ?? '') === 'deposit') {
        $balanceAfter -= $amount;
    } elseif (($existing['action'] ?? '') === 'withdrawal') {
        $balanceAfter += $amount;
    }

    if ($balanceAfter < 0) {
        respond(200, ['status' => false, 'code' => '227', 'message' => 'Insufficient balance']);
    }

    $playerData['balance_cents'] = $balanceAfter;
    $playerData['balance'] = round($playerData['balance_cents'] / 100, 2);
    $playerData['updated_at'] = gmdate('c');
    savePlayer($playerAccount, $playerData, $config);

    $timestamp = date('Y-m-d H:i:s');
    appendHybridLog($playerAccount, [
        'datetime' => $timestamp,
        'action' => 'void',
        'transaction_id' => $transactionId,
        'transaction_type' => 3,
        'amount' => $amount,
        'balance_before' => $balanceBefore,
        'balance_after' => $playerData['balance_cents'],
        'currency' => (string) $payload['currency'],
        'provider_code' => (string) $payload['provider_code'],
        'agent_code' => (string) $payload['agent_code'],
        'trace_id' => $queryParams['trace_id'] ?? null,
        'status' => 'complete',
    ], $config);

    $response = [
        'status' => true,
        'code' => '000',
        'message' => 'Success',
        'data' => [
            'player_account' => $playerAccount,
            'wallet_balance' => (string) $playerData['balance_cents'],
            'currency' => (string) $payload['currency'],
            'currency_code' => (string) $payload['currency'],
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ],
    ];
    if (!empty($queryParams['trace_id'])) {
        $response['trace_id'] = (string) $queryParams['trace_id'];
    }

    respond(200, $response);
}

function handleWalletTransactionList(array $payload, array $config): void
{
    if (empty($payload['player_account']) || !is_string($payload['player_account'])) {
        respond(422, ['error' => 'Missing or invalid field: player_account']);
    }

    $playerAccount = $payload['player_account'];
    $transactions = loadHybridTransactions($playerAccount, $config);

    respond(200, [
        'player_account' => $playerAccount,
        'transactions' => $transactions,
    ]);
}

function handleWalletTransactionDelete(array $payload, array $config): void
{
    if (empty($payload['player_account']) || !is_string($payload['player_account'])) {
        respond(422, ['error' => 'Missing or invalid field: player_account']);
    }

    $playerAccount = $payload['player_account'];
    $path = hybridLogFilePath($playerAccount, $config);
    $deleted = false;
    if (file_exists($path)) {
        $deleted = unlink($path);
    }

    respond(200, [
        'player_account' => $playerAccount,
        'deleted' => $deleted,
    ]);
}

function handleHybridCallback(array $payload, array $config, array $queryParams): void
{
    $required = ['action', 'agent_code', 'provider_code', 'player_account', 'amount', 'currency', 'transaction_id'];
    foreach ($required as $field) {
        if (!isset($payload[$field]) || $payload[$field] === '') {
            respond(200, ['status' => false, 'code' => '206', 'message' => 'Missing required field']);
        }
    }

    $action = strtolower((string) $payload['action']);
    if (!in_array($action, ['add', 'deduct'], true)) {
        respond(200, ['status' => false, 'code' => '206', 'message' => 'Invalid action']);
    }
    if (!is_string($payload['player_account']) || !is_numeric($payload['amount'])) {
        respond(200, ['status' => false, 'code' => '206', 'message' => 'Invalid request']);
    }

    $amount = (int) $payload['amount'];
    if ($amount <= 0) {
        respond(200, ['status' => false, 'code' => '206', 'message' => 'Invalid amount']);
    }

    $playerAccount = $payload['player_account'];
    $transactionId = (string) $payload['transaction_id'];
    if (hybridTransactionExists($playerAccount, $transactionId, $config)) {
        respond(200, ['status' => false, 'code' => '226', 'message' => 'The transaction reference ID has already been used. It must be unique']);
    }

    $playerData = loadPlayer($playerAccount, $config);
    if ($playerData === null) {
        $playerData = createPlayer($playerAccount, $config);
    }
    $playerData = normalizePlayerBalance($playerData);
    $balanceBefore = (int) $playerData['balance_cents'];
    $balanceAfter = $balanceBefore + ($action === 'add' ? $amount : -$amount);
    if ($balanceAfter < 0) {
        respond(200, ['status' => false, 'code' => '227', 'message' => 'Insufficient balance']);
    }

    $playerData['balance_cents'] = $balanceAfter;
    $playerData['balance'] = round($playerData['balance_cents'] / 100, 2);
    $playerData['updated_at'] = gmdate('c');
    savePlayer($playerAccount, $playerData, $config);

    $timestamp = date('Y-m-d H:i:s');
    appendHybridLog($playerAccount, [
        'datetime' => $timestamp,
        'action' => "agg_{$action}",
        'transaction_id' => $transactionId,
        'transaction_type' => $action === 'add' ? 4 : 5,
        'amount' => $amount,
        'balance_before' => $balanceBefore,
        'balance_after' => $playerData['balance_cents'],
        'currency' => (string) $payload['currency'],
        'provider_code' => (string) $payload['provider_code'],
        'agent_code' => (string) $payload['agent_code'],
        'trace_id' => $queryParams['trace_id'] ?? null,
        'status' => 'complete',
    ], $config);

    $response = [
        'status' => true,
        'code' => '000',
        'message' => 'Success',
        'data' => [
            'player_account' => $playerAccount,
            'wallet_balance' => (string) $playerData['balance_cents'],
            'currency' => (string) $payload['currency'],
            'currency_code' => (string) $payload['currency'],
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ],
    ];
    if (!empty($queryParams['trace_id'])) {
        $response['trace_id'] = (string) $queryParams['trace_id'];
    }

    respond(200, $response);
}

function handleCallbackList(array $payload, array $config): void
{
    if (empty($payload['player_account']) || !is_string($payload['player_account'])) {
        respond(422, ['error' => 'Missing or invalid field: player_account']);
    }

    $playerAccount = $payload['player_account'];
    $path = callbackLogFilePath($playerAccount, $config);
    if (!file_exists($path)) {
        respond(200, [
            'player_account' => $playerAccount,
            'transactions' => [],
        ]);
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        respond(500, ['error' => 'Failed to read callback transactions']);
    }

    $transactions = [];
    foreach ($lines as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $transactions[] = $decoded;
        }
    }

    respond(200, [
        'player_account' => $playerAccount,
        'transactions' => $transactions,
    ]);
}

function handleCallbackDelete(array $payload, array $config): void
{
    if (empty($payload['player_account']) || !is_string($payload['player_account'])) {
        respond(422, ['error' => 'Missing or invalid field: player_account']);
    }

    $playerAccount = $payload['player_account'];
    $path = callbackLogFilePath($playerAccount, $config);
    $deleted = false;
    if (file_exists($path)) {
        $deleted = unlink($path);
    }

    respond(200, [
        'player_account' => $playerAccount,
        'deleted' => $deleted,
    ]);
}

function signPayload(array $payload, string $agentKey): string
{
    unset($payload['sign']);
    ksort($payload);
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Failed to encode JSON for signing.');
    }

    return hash_hmac('sha256', $json, $agentKey);
}

function postJson(string $url, array $payload): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return [0, ['error' => 'Failed to initialize cURL']];
    }

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_TIMEOUT => 30,
    ]);

    $responseBody = curl_exec($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($responseBody === false) {
        return [$statusCode, ['error' => $error ?: 'Unknown cURL error']];
    }

    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        return [$statusCode, ['raw' => $responseBody]];
    }

    return [$statusCode, $decoded];
}

function loadPlayer(string $playerAccount, array $config): ?array
{
    $path = playerFilePath($playerAccount, $config);
    if (!file_exists($path)) {
        return null;
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        return null;
    }

    $data = json_decode($contents, true);
    return is_array($data) ? $data : null;
}

function createPlayer(string $playerAccount, array $config): array
{
    $data = [
        'player_account' => $playerAccount,
        'balance' => 0,
        'balance_cents' => 0,
        'token' => null,
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
    ];

    savePlayer($playerAccount, $data, $config);

    return $data;
}

function savePlayer(string $playerAccount, array $data, array $config): void
{
    $path = playerFilePath($playerAccount, $config);
    $directory = dirname($path);
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        respond(500, ['error' => 'Failed to encode player data']);
    }

    $tempFile = $path . '.tmp';
    file_put_contents($tempFile, $json, LOCK_EX);
    rename($tempFile, $path);
}

function normalizePlayerBalance(array $playerData): array
{
    $balance = isset($playerData['balance']) ? (float) $playerData['balance'] : 0.0;
    $balanceCents = $playerData['balance_cents'] ?? null;
    if ($balanceCents === null || !is_numeric($balanceCents)) {
        $balanceCents = (int) round($balance * 100);
    }

    $playerData['balance'] = round($balanceCents / 100, 2);
    $playerData['balance_cents'] = (int) $balanceCents;

    return $playerData;
}

function parseTransactionAdjustments(array $transactions): array
{
    $adjustment = 0;
    $transactionIds = [];

    foreach ($transactions as $transaction) {
        if (!is_array($transaction)) {
            respond(200, ['status' => '206']);
        }
        $transId = $transaction['trans_id'] ?? null;
        $betTransId = $transaction['bet_trans_id'] ?? null;
        if (!is_string($transId) || $transId === '' || !is_string($betTransId) || $betTransId === '') {
            respond(200, ['status' => '206']);
        }
        if (!isset($transaction['adjust_amount']) || !is_numeric($transaction['adjust_amount'])) {
            respond(200, ['status' => '206']);
        }
        $transactionIds[] = $transId;
        $adjustment += (int) $transaction['adjust_amount'];
    }

    return [$adjustment, $transactionIds];
}

function findDuplicateTransactionIds(array $transactionIds, string $playerAccount, array $config): array
{
    if ($transactionIds === []) {
        return [];
    }

    $duplicates = [];
    $counts = array_count_values($transactionIds);
    foreach ($counts as $id => $count) {
        if ($count > 1) {
            $duplicates[] = $id;
        }
    }

    $path = callbackLogFilePath($playerAccount, $config);
    if (!file_exists($path)) {
        return $duplicates;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        respond(200, ['status' => '500']);
    }

    $existingIds = [];
    foreach ($lines as $line) {
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            continue;
        }
        $loggedIds = $decoded['transaction_ids'] ?? [];
        if (!is_array($loggedIds)) {
            continue;
        }
        foreach ($loggedIds as $loggedId) {
            if (is_string($loggedId) && $loggedId !== '') {
                $existingIds[$loggedId] = true;
            }
        }
    }

    foreach ($transactionIds as $transactionId) {
        if (isset($existingIds[$transactionId])) {
            $duplicates[] = $transactionId;
        }
    }

    return array_values(array_unique($duplicates));
}

function playerFilePath(string $playerAccount, array $config): string
{
    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $playerAccount)) {
        respond(422, ['error' => 'player_account must be alphanumeric with optional underscore or dash']);
    }

    return rtrim($config['storage_path'], '/') . '/' . $playerAccount . '.json';
}

function callbackLogFilePath(string $playerAccount, array $config): string
{
    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $playerAccount)) {
        respond(422, ['error' => 'player_account must be alphanumeric with optional underscore or dash']);
    }

    return rtrim($config['callback_log_path'], '/') . '/' . $playerAccount . '.log';
}

function appendCallbackLog(string $playerAccount, array $entry, array $config): void
{
    $path = callbackLogFilePath($playerAccount, $config);
    $directory = dirname($path);
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($line === false) {
        respond(500, ['error' => 'Failed to encode callback log entry']);
    }

    file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function hybridLogFilePath(string $playerAccount, array $config): string
{
    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $playerAccount)) {
        respond(422, ['error' => 'player_account must be alphanumeric with optional underscore or dash']);
    }

    return rtrim($config['hybrid_log_path'], '/') . '/' . $playerAccount . '.log';
}

function appendHybridLog(string $playerAccount, array $entry, array $config): void
{
    $entry['player_account'] = $playerAccount;
    $path = hybridLogFilePath($playerAccount, $config);
    $directory = dirname($path);
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($line === false) {
        respond(500, ['error' => 'Failed to encode hybrid log entry']);
    }

    file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function loadHybridTransactions(string $playerAccount, array $config): array
{
    $path = hybridLogFilePath($playerAccount, $config);
    if (!file_exists($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        respond(500, ['error' => 'Failed to read hybrid transactions']);
    }

    $transactions = [];
    foreach ($lines as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $transactions[] = $decoded;
        }
    }

    return $transactions;
}

function hybridTransactionExists(string $playerAccount, string $transactionId, array $config): bool
{
    $transactions = loadHybridTransactions($playerAccount, $config);
    foreach ($transactions as $transaction) {
        if (!is_array($transaction)) {
            continue;
        }
        if (($transaction['transaction_id'] ?? null) === $transactionId) {
            return true;
        }
    }

    return false;
}

function findHybridTransaction(?string $playerAccount, string $transactionId, array $config): ?array
{
    if ($playerAccount !== null && $playerAccount !== '') {
        $transactions = loadHybridTransactions($playerAccount, $config);
        foreach ($transactions as $transaction) {
            if (($transaction['transaction_id'] ?? null) === $transactionId) {
                return $transaction;
            }
        }
        return null;
    }

    $directory = rtrim($config['hybrid_log_path'], '/');
    if (!is_dir($directory)) {
        return null;
    }

    $files = glob($directory . '/*.log') ?: [];
    foreach ($files as $file) {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            continue;
        }
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && ($decoded['transaction_id'] ?? null) === $transactionId) {
                return $decoded;
            }
        }
    }

    return null;
}

function generateTraceId(): string
{
    return bin2hex(random_bytes(16));
}

function respond(int $statusCode, array $body): void
{
    http_response_code($statusCode);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
