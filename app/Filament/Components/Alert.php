<?php

namespace App\Filament\Components;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Support\Htmlable;

class Alert extends Component
{
    public const ACTIONS_SCHEMA_KEY = 'actions';

    /**
     * @var view-string
     */
    protected string $view = 'filament.components.alert';

    protected string|Htmlable|Closure|null $message;

    protected string|Htmlable|Closure|null $title = null;

    protected string|Closure $type = 'info';

    final public function __construct(string|Htmlable|Closure|null $message)
    {
        $this->message($message);
    }

    public static function make(string|Htmlable|Closure|null $message): static
    {
        $static = app(static::class, ['message' => $message]);
        $static->configure();

        return $static;
    }

    public function message(string|Htmlable|Closure|null $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function title(string|Htmlable|Closure|null $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function type(string|Closure $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function info(): static
    {
        return $this->type('info');
    }

    public function success(): static
    {
        return $this->type('success');
    }

    public function warning(): static
    {
        return $this->type('warning');
    }

    public function danger(): static
    {
        return $this->type('danger');
    }

    /**
     * @param  array<Action | ActionGroup> | Closure  $actions
     */
    public function actions(array|Closure $actions): static
    {
        $this->childComponents(
            Actions::make($actions),
            static::ACTIONS_SCHEMA_KEY,
        );

        return $this;
    }

    public function getMessage(): string|Htmlable|null
    {
        return $this->evaluate($this->message);
    }

    public function getTitle(): string|Htmlable|null
    {
        return $this->evaluate($this->title);
    }

    public function getType(): string
    {
        $type = $this->evaluate($this->type);

        return in_array($type, ['info', 'success', 'warning', 'danger'], true) ? $type : 'info';
    }
}
