<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

/**
 * The admin chatbot: a chat UI for root administrators, backed by
 * AdminChatbotService through the session-authenticated /admin/chat API.
 */
class AdminChatbot extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'tabler-message-2';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'tabler-message-2';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.admin-chatbot';

    public static function getNavigationLabel(): string
    {
        return trans('admin/chatbot.navigation.label');
    }

    public static function getNavigationGroup(): ?string
    {
        return trans('admin/chatbot.navigation.group');
    }

    public function getTitle(): string
    {
        return trans('admin/chatbot.page.title');
    }

    public function getHeading(): string
    {
        return trans('admin/chatbot.page.heading');
    }
}
