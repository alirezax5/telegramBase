<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Middleware;

use Illuminate\Pipeline\Pipeline;
use telegramBotApiPhp\Telegram;

/**
 * Middleware pipeline built on Illuminate\Pipeline\Pipeline.
 *
 * Middlewares are executed in registration order; each may halt the chain
 * (return without calling $next) or pass the update onward. The `$final`
 * callable is invoked when every middleware has passed the update through.
 */
final class MiddlewarePipeline
{
    /** @var array<int, MiddlewareInterface|class-string> */
    private array $middlewares = [];

    /**
     * Register a middleware (instance or class string).
     */
    public function add(MiddlewareInterface|string $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    /**
     * Whether any middleware is registered.
     */
    public function isEmpty(): bool
    {
        return $this->middlewares === [];
    }

    /**
     * Execute the pipeline.
     *
     * @param object   $update  Telegram update object
     * @param Telegram $Telegram Bot API instance
     * @param callable $final   Invoked when all middlewares pass; args: (object $update, Telegram $Telegram)
     */
    public function run(object $update, Telegram $Telegram, callable $final): void
    {
        if ($this->isEmpty()) {
            $final($update, $Telegram);
            return;
        }

        $payload = [$update, $Telegram];

        $pipes = array_map(
            static fn (MiddlewareInterface|string $mw) =>
                static function (mixed $passable, callable $next) use ($mw): mixed {
                    [$update, $telegram] = $passable;

                    if (is_string($mw)) {
                        $mw = new $mw();
                    }

                    // Adapter: our $next() calls Illuminate $next($passable) for us.
                    $adapterNext = static fn () => $next($passable);

                    return $mw->handle($update, $telegram, $adapterNext);
                },
            $this->middlewares
        );

        $destination = static function (mixed $passable) use ($final): void {
            [$update, $telegram] = $passable;
            $final($update, $telegram);
        };

        (new Pipeline())->send($payload)->through($pipes)->then($destination);
    }
}
