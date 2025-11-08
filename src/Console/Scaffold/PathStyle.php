<?php
// @codeCoverageIgnoreStart

namespace Pillar\Console\Scaffold;

enum PathStyle: string
{
    case Infer = 'infer';
    case Mirrored = 'mirrored';
    case Split = 'split';
    case Subcontext = 'subcontext';
    case Colocate = 'colocate';

    /** Return all allowed string values (for prompts and validation). */
    public static function values(): array
    {
        return array_map(fn(self $c) => $c->value, self::cases());
    }

    /** Library default if nothing is configured/passed. */
    public static function default(): self
    {
        return self::Colocate;
    }

    /** Config-safe resolution (accepts null/invalid and falls back). */
    public static function fromConfig(?string $value): self
    {
        return self::tryFrom((string)$value) ?? self::default();
    }

    public function label(): string
    {
        return match ($this) {
            self::Colocate   => '📎 Colocate — handler next to message',
            self::Mirrored   => '🪞 Mirrored — Application/Handler/{…}',
            self::Split      => '🗂️ Split — Application/Handler',
            self::Subcontext => '📁 Subcontext — <Sub>/Application/{…}',
            self::Infer      => '🧠 Infer — derive from existing',
        };
    }

    /**
     * Options map for Laravel Prompts:
     *   value => label (e.g. 'colocate' => '📎 Colocate — …')
     */
    public static function promptOptions(): array
    {
        $opts = [];
        foreach (self::cases() as $case) {
            $opts[$case->value] = $case->label();
        }
        return $opts;
    }
}
// @codeCoverageIgnoreEnd