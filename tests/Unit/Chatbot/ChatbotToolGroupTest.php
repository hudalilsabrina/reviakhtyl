<?php

use App\Enum\ChatbotToolGroup;

it('enables only the low risk groups on a fresh installation', function () {
    expect(ChatbotToolGroup::defaults())->toBe(['server', 'power', 'files', 'startup']);
});

it('leaves the high consequence groups switched off by default', function (ChatbotToolGroup $group) {
    expect(ChatbotToolGroup::defaults())->not->toContain($group->value);
})->with([
    'console' => [ChatbotToolGroup::Console],
    'subusers' => [ChatbotToolGroup::Subusers],
    'plugins' => [ChatbotToolGroup::Plugins],
    'mods' => [ChatbotToolGroup::Mods],
]);

it('only ever defaults to real groups', function () {
    expect(ChatbotToolGroup::defaults())
        ->each->toBeIn(array_column(ChatbotToolGroup::cases(), 'value'));
});

it('offers every server case as an option', function () {
    $options = ChatbotToolGroup::options();

    // The Admin group is intentionally absent: it drives the admin chatbot,
    // which has no per-server group toggles, and a dead switch in the server
    // settings would only mislead.
    expect($options)->toHaveCount(count(ChatbotToolGroup::cases()) - 1)
        ->not->toHaveKey('admin');

    foreach (ChatbotToolGroup::cases() as $case) {
        if ($case === ChatbotToolGroup::Admin) {
            continue;
        }

        expect($options)->toHaveKey($case->value)
            ->and($options[$case->value])->toBe($case->label());
    }
});

it('gives every case a distinct, non-empty label and description', function () {
    $labels = [];
    $descriptions = [];

    foreach (ChatbotToolGroup::cases() as $case) {
        expect($case->label())->not->toBe('')
            ->and($case->description())->not->toBe('');

        $labels[] = $case->label();
        $descriptions[] = $case->description();
    }

    expect($labels)->toBe(array_unique($labels))
        ->and($descriptions)->toBe(array_unique($descriptions));
});

it('keeps its backing values stable', function () {
    // These strings are persisted in the settings table and referenced from
    // the admin UI, so renaming one silently disables a group.
    expect(array_column(ChatbotToolGroup::cases(), 'value'))
        ->toBe(['server', 'power', 'console', 'files', 'subusers', 'startup', 'plugins', 'mods', 'web', 'admin']);
});
