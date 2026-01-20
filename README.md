# test-simulator

## Setup

1. Update `config.php` with your `agent_code` and `agent_key` from the aggregator.
2. Start the PHP built-in server:

```bash
php -S 0.0.0.0:8080 -t public
```

## Docker

The repository already includes a `Dockerfile` you can use to build and run the service locally or publish to Docker Hub.

### Build and run locally

```bash
docker build -t test-simulator:local .
docker run --rm -p 8080:8080 test-simulator:local
```

### Build and push to Docker Hub

1. Log in to Docker Hub (replace `YOUR_DOCKERHUB_USERNAME`):

```bash
docker login
```

2. Build and tag the image:

```bash
docker build -t YOUR_DOCKERHUB_USERNAME/test-simulator:latest .
```

3. Push the image:

```bash
docker push YOUR_DOCKERHUB_USERNAME/test-simulator:latest
```

## API Endpoints

### Launch Game

`POST /api/launch`

```json
{
  "agent_code": "YOUR_AGENT_CODE",
  "player_account": "player01",
  "provider_code": "slot",
  "game_code": "islot",
  "currency": "USD",
  "lang": "en",
  "bet_limit": "100"
}
```

### Top Up Credit

`POST /api/credit/topup`

```json
{
  "player_account": "player01",
  "amount": 50
}
```

### Check Balance

`POST /api/credit/balance`

```json
{
  "player_account": "player01"
}
```

### Seamless Callback

`POST /api/callback`

Handles seamless wallet callbacks from the aggregator. Supported actions include `balance`, `bet`, `settle`, `refund`, `resettle`, and `betsettle`.

```json
{
  "action": "bet",
  "agent_code": "YOUR_AGENT_CODE",
  "player_account": "player01",
  "currency": "USD",
  "provider_code": "slot",
  "game_code": "islot",
  "round_id": "round-123",
  "trans_data": [
    {
      "trans_id": "txn-1",
      "bet_trans_id": "bet-1",
      "adjust_amount": -100
    }
  ]
}
```

### Transaction Listing

`POST /api/callbacks/list`

```json
{
  "player_account": "player01"
}
```

### Transaction Delete

`POST /api/callbacks/delete`

```json
{
  "player_account": "player01"
}
```

## Hybrid Wallet (Transfer Wallet) API

These endpoints simulate the hybrid transfer wallet flow, including transaction logging per user in server timezone.

### Deposit

`POST /wallet/deposit?trace_id=TRACE_ID`

```json
{
  "agent_code": "YOUR_AGENT_CODE",
  "provider_code": "slot",
  "player_account": "player01",
  "amount": 1500000,
  "currency": "PHP",
  "transaction_id": "REF004",
  "type": "normal"
}
```

### Withdrawal

`POST /wallet/withdrawal?trace_id=TRACE_ID`

```json
{
  "agent_code": "YOUR_AGENT_CODE",
  "provider_code": "slot",
  "player_account": "player01",
  "amount": 61500,
  "currency": "PHP",
  "transaction_id": "REF034"
}
```

### Get Wallet Balance

`POST /wallet/balance?trace_id=TRACE_ID`

```json
{
  "agent_code": "YOUR_AGENT_CODE",
  "provider_code": "slot",
  "player_account": "player01",
  "currency": "PHP"
}
```

### Check Transaction Status

`POST /wallet/check-trans-status?trace_id=TRACE_ID`

```json
{
  "agent_code": "YOUR_AGENT_CODE",
  "provider_code": "slot",
  "player_account": "player01",
  "transaction_id": "REF034"
}
```

### Void

`POST /wallet/void?trace_id=TRACE_ID`

```json
{
  "agent_code": "YOUR_AGENT_CODE",
  "provider_code": "slot",
  "player_account": "player01",
  "amount": 61500,
  "currency": "PHP",
  "transaction_id": "REF034"
}
```

### Aggregator Hybrid Callback (Add/Deduct Balance)

`POST /api/hybrid/callback?trace_id=TRACE_ID`

```json
{
  "action": "add",
  "agent_code": "YOUR_AGENT_CODE",
  "provider_code": "slot",
  "player_account": "player01",
  "amount": 5000,
  "currency": "PHP",
  "transaction_id": "AGG-001"
}
```

### Hybrid Transaction Listing

`POST /wallet/transactions/list`

```json
{
  "player_account": "player01"
}
```

### Hybrid Transaction Delete

`POST /wallet/transactions/delete`

```json
{
  "player_account": "player01"
}
```
