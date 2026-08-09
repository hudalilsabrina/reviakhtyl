---
name: reviactyl-api-key
description: Create or delete a Reviactyl account API key via artisan tinker against the live panel. Use when testing the panel client API with curl on the real installation, or when a chatbot/API test needs bearer auth.
---

# Reviactyl API key lifecycle

Read `APP_URL` and DB credentials from `.env` at runtime — never hardcode them.

## Create an account key (client API)

```bash
php artisan tinker --execute='
$service = app(App\Services\Api\KeyCreationService::class);
$key = $service->setKeyType(App\Models\ApiKey::TYPE_ACCOUNT)->handle([
    "user_id" => 1,
    "memo" => "e2e test",
    "allowed_ips" => [],
]);
echo $key->identifier."\n";
$enc = app(Illuminate\Encryption\Encrypter::class);
echo $enc->decrypt($key->token)."\n";
'
```

- `user_id` 1 = root admin (bypasses server ownership checks).
- Output: `identifier` then `token` on separate lines; the bearer secret is both concatenated.
- Constants: `TYPE_ACCOUNT = 1`, `TYPE_APPLICATION = 2` (`app/Models/ApiKey.php`).
- `allowed_ips: []` = any IP; pass concrete IPs to lock down.
- `memo` must be unique enough to delete later — give every test its own label.
- DB schema note: the column is `memo`, not `description`.

## Delete after the test

```bash
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" -e "DELETE FROM api_keys WHERE memo='e2e test';"
```

## Usage

```bash
curl -s -H "Authorization: Bearer <identifier><token>" -H "Accept: application/json" "$APP_URL/api/client"
```
