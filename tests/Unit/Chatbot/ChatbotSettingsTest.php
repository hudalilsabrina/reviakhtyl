<?php

use App\Enum\ChatbotToolGroup;
use App\Repositories\Eloquent\SettingsRepository;
use App\Services\Chatbot\ChatbotSettings;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;

/**
 * Builds a ChatbotSettings reading from a stubbed settings repository, so no
 * settings table (and therefore no database) is involved.
 *
 * @param  array<string, mixed>  $stored  values as if written from the admin area
 * @param  array<string, mixed>  $config  values as if set in config/panel.php
 */
function chatbotSettings(array $stored = [], array $config = []): ChatbotSettings
{
    // The concrete repository is mocked rather than the interface: ChatbotSettings
    // calls get() with one argument, which only the concrete signature allows.
    // No constructor runs, so nothing here can reach the database.
    $repository = Mockery::mock(SettingsRepository::class);
    $repository->shouldReceive('get')
        ->andReturnUsing(function (string $key, mixed $default = null) use ($stored) {
            $short = str_replace('settings::panel:chatbot:', '', $key);

            return $stored[$short] ?? $default;
        });

    // A stand-in for the real encrypter: values written by the admin area are
    // stored encrypted, values carried over from an older install are not.
    $encrypter = new class implements Encrypter
    {
        public function encrypt(#[SensitiveParameter] $value, $serialize = true)
        {
            return 'enc:'.$value;
        }

        public function decrypt($payload, $unserialize = true)
        {
            if (! str_starts_with((string) $payload, 'enc:')) {
                throw new DecryptException('The payload is invalid.');
            }

            return substr((string) $payload, 4);
        }

        public function getKey()
        {
            return 'key';
        }

        public function getAllKeys()
        {
            return ['key'];
        }

        public function getPreviousKeys()
        {
            return [];
        }
    };

    return new ChatbotSettings(
        $repository,
        new Repository(['panel' => ['chatbot' => $config]]),
        $encrypter,
    );
}

describe('isEnabled', function () {
    it('requires the flag, a base url and an api key together', function () {
        expect(chatbotSettings([
            'enabled' => true,
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'enc:sk-test',
        ])->isEnabled())->toBeTrue();
    });

    it('is disabled when the flag is off', function () {
        expect(chatbotSettings([
            'enabled' => false,
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'enc:sk-test',
        ])->isEnabled())->toBeFalse();
    });

    it('is disabled without a base url', function () {
        expect(chatbotSettings([
            'enabled' => true,
            'base_url' => '',
            'api_key' => 'enc:sk-test',
        ])->isEnabled())->toBeFalse();
    });

    it('is disabled without an api key', function () {
        expect(chatbotSettings([
            'enabled' => true,
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => '',
        ])->isEnabled())->toBeFalse();
    });

    it('reads the flag from the string forms the settings table stores', function (mixed $stored, bool $expected) {
        expect(chatbotSettings([
            'enabled' => $stored,
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'enc:sk-test',
        ])->isEnabled())->toBe($expected);
    })->with([
        'string true' => ['true', true],
        'string one' => ['1', true],
        'string false' => ['false', false],
        'string zero' => ['0', false],
        'empty string' => ['', false],
        'boolean true' => [true, true],
    ]);

    it('falls back to the config file when nothing is stored', function () {
        expect(chatbotSettings([], [
            'enabled' => true,
            'base_url' => 'https://openrouter.ai/api/v1',
            'api_key' => 'enc:sk-config',
        ])->isEnabled())->toBeTrue();
    });
});

describe('baseUrl', function () {
    it('strips a trailing slash', function () {
        expect(chatbotSettings(['base_url' => 'https://api.openai.com/v1/'])->baseUrl())
            ->toBe('https://api.openai.com/v1');
    });

    it('strips repeated trailing slashes and surrounding whitespace', function () {
        expect(chatbotSettings(['base_url' => "  https://api.openai.com/v1//  \n"])->baseUrl())
            ->toBe('https://api.openai.com/v1');
    });

    it('leaves a clean url alone', function () {
        expect(chatbotSettings(['base_url' => 'https://api.openai.com/v1'])->baseUrl())
            ->toBe('https://api.openai.com/v1');
    });

    it('returns an empty string when unset', function () {
        expect(chatbotSettings()->baseUrl())->toBe('');
    });
});

describe('apiKey', function () {
    it('decrypts a stored key', function () {
        expect(chatbotSettings(['api_key' => 'enc:sk-secret'])->apiKey())->toBe('sk-secret');
    });

    it('passes a key through when it was stored before encryption', function () {
        expect(chatbotSettings(['api_key' => 'sk-plaintext'])->apiKey())->toBe('sk-plaintext');
    });

    it('returns an empty string when unset', function () {
        expect(chatbotSettings()->apiKey())->toBe('');
    });
});

describe('enabledToolGroups', function () {
    it('accepts a JSON encoded list', function () {
        expect(chatbotSettings(['tool_groups' => '["server","files","console"]'])->enabledToolGroups())
            ->toBe(['server', 'files', 'console']);
    });

    it('accepts a real array', function () {
        expect(chatbotSettings(['tool_groups' => ['power', 'startup']])->enabledToolGroups())
            ->toBe(['power', 'startup']);
    });

    it('falls back to the defaults for garbage', function (mixed $stored) {
        expect(chatbotSettings(['tool_groups' => $stored])->enabledToolGroups())
            ->toBe(ChatbotToolGroup::defaults());
    })->with([
        'unparseable string' => ['server, files'],
        'empty string' => [''],
        'a number' => [42],
        'null' => [null],
        'boolean' => [true],
    ]);

    it('drops group names that are not real groups', function () {
        expect(chatbotSettings(['tool_groups' => ['server', 'databases', 'backups', 'files']])->enabledToolGroups())
            ->toBe(['server', 'files']);
    });

    it('returns an empty list when an administrator disabled every group', function () {
        expect(chatbotSettings(['tool_groups' => '[]'])->enabledToolGroups())->toBe([]);
    });

    it('re-indexes the surviving groups', function () {
        expect(array_keys(chatbotSettings(['tool_groups' => ['nope', 'files']])->enabledToolGroups()))
            ->toBe([0]);
    });

    it('answers isToolGroupEnabled from the same list', function () {
        $settings = chatbotSettings(['tool_groups' => ['server', 'files']]);

        expect($settings->isToolGroupEnabled(ChatbotToolGroup::Files))->toBeTrue()
            ->and($settings->isToolGroupEnabled(ChatbotToolGroup::Console))->toBeFalse();
    });
});

describe('numeric clamping', function () {
    it('clamps maxIterations between one and twenty five', function (mixed $stored, int $expected) {
        expect(chatbotSettings(['max_iterations' => $stored])->maxIterations())->toBe($expected);
    })->with([
        'zero' => [0, 1],
        'negative' => [-5, 1],
        'in range' => [12, 12],
        'at the ceiling' => [25, 25],
        'over the ceiling' => [1000, 25],
        'numeric string' => ['4', 4],
        'unset' => [null, 8],
    ]);

    it('holds contextTokens to a floor of two thousand', function (mixed $stored, int $expected) {
        expect(chatbotSettings(['context_tokens' => $stored])->contextTokens())->toBe($expected);
    })->with([
        'below the floor' => [500, 2000],
        'zero' => [0, 2000],
        'at the floor' => [2000, 2000],
        'above the floor' => [128000, 128000],
        'unset' => [null, 24000],
    ]);

    it('holds historyLimit to a floor of two', function (mixed $stored, int $expected) {
        expect(chatbotSettings(['history_limit' => $stored])->historyLimit())->toBe($expected);
    })->with([
        'zero' => [0, 2],
        'one' => [1, 2],
        'in range' => [50, 50],
        'unset' => [null, 30],
    ]);

    it('holds maxTokens to a floor of one', function () {
        expect(chatbotSettings(['max_tokens' => 0])->maxTokens())->toBe(1)
            ->and(chatbotSettings(['max_tokens' => 4096])->maxTokens())->toBe(4096)
            ->and(chatbotSettings()->maxTokens())->toBe(1024);
    });

    it('holds timeout to a floor of five seconds', function () {
        expect(chatbotSettings(['timeout' => 1])->timeout())->toBe(5)
            ->and(chatbotSettings(['timeout' => 300])->timeout())->toBe(300)
            ->and(chatbotSettings()->timeout())->toBe(120);
    });

    it('reads temperature as a float', function () {
        expect(chatbotSettings(['temperature' => '0.7'])->temperature())->toBe(0.7)
            ->and(chatbotSettings()->temperature())->toBe(0.2);
    });
});

describe('compactionEnabled', function () {
    it('reads the string forms correctly', function (mixed $stored, bool $expected) {
        expect(chatbotSettings(['compaction' => $stored])->compactionEnabled())->toBe($expected);
    })->with([
        'string true' => ['true', true],
        'string false' => ['false', false],
        'string one' => ['1', true],
        'string zero' => ['0', false],
        'boolean true' => [true, true],
        'boolean false' => [false, false],
    ]);

    it('defaults to enabled when nothing is stored', function () {
        expect(chatbotSettings()->compactionEnabled())->toBeTrue();
    });
});

describe('the remaining accessors', function () {
    it('falls back to a default model', function () {
        expect(chatbotSettings()->model())->toBe('gpt-4o-mini')
            ->and(chatbotSettings(['model' => '  '])->model())->toBe('gpt-4o-mini')
            ->and(chatbotSettings(['model' => ' deepseek-reasoner '])->model())->toBe('deepseek-reasoner');
    });

    it('returns a null system prompt rather than an empty one', function () {
        expect(chatbotSettings()->systemPrompt())->toBeNull()
            ->and(chatbotSettings(['system_prompt' => "  \n "])->systemPrompt())->toBeNull()
            ->and(chatbotSettings(['system_prompt' => ' Be brief. '])->systemPrompt())->toBe('Be brief.');
    });

    it('requires confirmation unless it is explicitly switched off', function () {
        expect(chatbotSettings()->requiresConfirmation())->toBeTrue()
            ->and(chatbotSettings(['require_confirmation' => 'false'])->requiresConfirmation())->toBeFalse();
    });

    it('lists every key it owns', function () {
        expect(ChatbotSettings::keys())
            ->toContain('panel:chatbot:enabled')
            ->toContain('panel:chatbot:api_key')
            ->toContain('panel:chatbot:tool_groups')
            ->and(ChatbotSettings::keys())->toBe(array_unique(ChatbotSettings::keys()));
    });

    it('survives a settings repository that blows up', function () {
        $repository = Mockery::mock(SettingsRepository::class);
        $repository->shouldReceive('get')->andThrow(new RuntimeException('no such table: settings'));

        $encrypter = Mockery::mock(Encrypter::class);

        $settings = new ChatbotSettings(
            $repository,
            new Repository(['panel' => ['chatbot' => ['model' => 'from-config']]]),
            $encrypter,
        );

        expect($settings->model())->toBe('from-config')
            ->and($settings->isEnabled())->toBeFalse();
    });
});
