<?php

declare(strict_types=1);

namespace App\Ai\ClaudeCode;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Providers\Concerns\GeneratesText;
use Laravel\Ai\Providers\Concerns\HasTextGateway;
use Laravel\Ai\Providers\Concerns\StreamsText;
use Laravel\Ai\Providers\Provider;
use Override;

/**
 * Text provider that answers by running Claude Code headless (`claude -p`).
 */
final class ClaudeCodeProvider extends Provider implements TextProvider
{
    use GeneratesText;
    use HasTextGateway;
    use StreamsText;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(protected array $config, protected Dispatcher $events) {}

    /**
     * Claude Code uses the login of the machine it runs on: there is no key.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function providerCredentials(): array
    {
        return [];
    }

    public function textGateway(): StepTextGateway
    {
        return $this->textGateway ??= new ClaudeCodeGateway($this->config);
    }

    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? 'opus';
    }

    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? 'haiku';
    }

    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? 'opus';
    }
}
