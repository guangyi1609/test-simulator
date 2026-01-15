# test-simulator

## Setup

1. Update `config.php` with your `agent_code` and `agent_key` from the aggregator.
2. Start the PHP built-in server:

```bash
php -S 0.0.0.0:8080 -t public
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
