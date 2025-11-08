<?php
// @codeCoverageIgnoreStart

namespace Pillar\Console\Scaffold;

final class PlacementResolver
{
    public function resolve(object $registry, string $kind, array $options, callable $ask): array
    {
        $styleOpt = $options['style'] ?? null;   // may be string or PathStyle
        $sub = $options['subcontext'] ?? null;

        $contextName = method_exists($registry, 'name') ? $registry->name() : 'Context';
        $registryFqcn = get_class($registry);

        $makeCfg = (array)config('pillar.make', []);
        $defaultBasePath = (string)($makeCfg['contexts_base_path'] ?? base_path('app'));
        $defaultBaseNs = (string)($makeCfg['contexts_base_namespace'] ?? 'App');
        $cfgDefaultStyle = PathStyle::fromConfig($makeCfg['default_style'] ?? null);

        $overrides = (array)($makeCfg['overrides'] ?? []);
        $ctxCfg = $overrides[$registryFqcn] ?? ($overrides[$contextName] ?? []);

        $basePath = (string)($ctxCfg['base_path'] ?? rtrim($defaultBasePath, '/'));
        $baseNs = (string)($ctxCfg['base_namespace'] ?? $defaultBaseNs);

        // normalize style to enum: option > ctx override > config default
        $styleEnum = $styleOpt instanceof PathStyle
            ? $styleOpt
            : (PathStyle::tryFrom((string)$styleOpt)
                ?? PathStyle::tryFrom((string)($ctxCfg['style'] ?? ''))
                ?? $cfgDefaultStyle);

        if (!$sub && array_key_exists('subcontext', $ctxCfg)) {
            $sub = $ctxCfg['subcontext'] ?: null;
        }

        $messageDir = 'Application/' . ($kind === 'command' ? 'Command' : 'Query');
        $handlerKindDir = ($kind === 'command' ? 'Command' : 'Query');

        $handlerDir = match ($styleEnum) {
            PathStyle::Mirrored => 'Application/Handler/' . $handlerKindDir,
            PathStyle::Split => 'Application/Handler',
            PathStyle::Subcontext => ($sub ? $sub . '/Application/Handler' : 'Application/Handler'),
            PathStyle::Colocate,
            PathStyle::Infer => $messageDir, // infer behaves like colocate by default
        };

        if ($styleEnum === PathStyle::Subcontext && $sub) {
            $messageDir = $sub . '/' . $messageDir;
        }

        $ctxPath = rtrim($basePath, '/') . '/' . $contextName;
        $ctxNs = rtrim($baseNs, '\\') . '\\' . $contextName;

        return [
            'basePath' => $ctxPath,
            'baseNamespace' => $ctxNs,
            'kind' => $kind,
            'style' => $styleEnum, // pass the enum onward
            'subcontext' => $sub,
            'paths' => [
                'messagePath' => $ctxPath . '/' . $messageDir,
                'handlerPath' => $ctxPath . '/' . $handlerDir,
            ],
            'namespaces' => [
                'messageNs' => $ctxNs . '\\' . str_replace('/', '\\', $messageDir),
                'handlerNs' => $ctxNs . '\\' . str_replace('/', '\\', $handlerDir),
            ],
        ];
    }
}
// @codeCoverageIgnoreEnd