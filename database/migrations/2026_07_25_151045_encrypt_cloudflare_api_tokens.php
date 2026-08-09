<?php

use App\Models\Setting;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $setting = Setting::query()->where('key', 'settings::panel:cloudflare:api_token')->first();

        if (! $setting || empty($setting->value)) {
            return;
        }

        $encrypter = app(Encrypter::class);

        try {
            $encrypter->decrypt($setting->value);
            // Already encrypted, skip.
        } catch (Throwable) {
            // Plaintext, encrypt it.
            $setting->value = $encrypter->encrypt($setting->value);
            $setting->save();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $setting = Setting::query()->where('key', 'settings::panel:cloudflare:api_token')->first();

        if (! $setting || empty($setting->value)) {
            return;
        }

        $encrypter = app(Encrypter::class);

        try {
            $decrypted = $encrypter->decrypt($setting->value);
            $setting->value = $decrypted;
            $setting->save();
        } catch (Throwable) {
            // Already plaintext or corrupt, leave as-is.
        }
    }
};
