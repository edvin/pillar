<?php

declare(strict_types=1);

namespace Pillar\Support\UI;

use Illuminate\Container\Attributes\Config;

final class UISettings
{
    public bool $enabled;
    public string $path;
    public ?string $guard;
    /** @var string[] */
    public array $skipAuthIn;
    public int $pageSize;
    public int $recentLimit;

    public function __construct(
        #[Config('pillar.ui.enabled')] bool                   $enabled = true,
        #[Config('pillar.ui.path')] string                    $path = 'pillar',
        #[Config('pillar.ui.guard')] ?string                  $guard = 'web',
        #[Config('pillar.ui.skip_auth_in')] string|array|null $skipAuthIn = 'local',
        #[Config('pillar.ui.page_size')] int                  $pageSize = 100,
        #[Config('pillar.ui.recent_limit')] int               $recentLimit = 20,
    )
    {
        $this->enabled = $enabled;
        $this->path = trim($path, '/');
        $this->guard = $guard;
        $this->skipAuthIn = $this->normalizeList($skipAuthIn);
        $this->pageSize = $pageSize;
        $this->recentLimit = $recentLimit;
    }

    /**
     * @param string|array|null $v
     * @return string[]
     */
    private function normalizeList(string|array|null $v): array
    {
        if (is_array($v)) {
            return array_values(array_filter(array_map('trim', $v), fn($x) => $x !== ''));
        }
        if ($v === null || $v === '') {
            return [];
        }
        return preg_split('/\s*,\s*/', $v, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}