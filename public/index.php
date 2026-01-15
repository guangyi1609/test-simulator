<?php

declare(strict_types=1);

$config = require __DIR__ . '/../config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

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

    $playerData['balance'] = round(((float) ($playerData['balance'] ?? 0)) + $amount, 2);
    $playerData['updated_at'] = gmdate('c');
    savePlayer($playerAccount, $playerData, $config);

    respond(200, [
        'player_account' => $playerAccount,
        'balance' => $playerData['balance'],
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

    respond(200, [
        'player_account' => $playerAccount,
        'balance' => $playerData['balance'] ?? 0,
        'token' => $playerData['token'] ?? null,
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

function playerFilePath(string $playerAccount, array $config): string
{
    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $playerAccount)) {
        respond(422, ['error' => 'player_account must be alphanumeric with optional underscore or dash']);
    }

    return rtrim($config['storage_path'], '/') . '/' . $playerAccount . '.json';
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
