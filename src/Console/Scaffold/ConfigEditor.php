<?php
// @codeCoverageIgnoreStart

namespace Pillar\Console\Scaffold;

final class ConfigEditor
{
    /**
     * Add a registry FQCN into config/pillar.php under a 'context_registries' array.
     * If the array or file is missing, this throws a RuntimeException with a helpful message.
     */
    public function addContextRegistryFqcn(string $configPath, string $fqcn): void
    {
        if (!is_file($configPath)) {
            throw new \RuntimeException("Cannot find config file at {$configPath}. Please publish or create config/pillar.php.");
        }

        $code = file_get_contents($configPath);
        $eol = str_contains($code, "\r\n") ? "\r\n" : "\n";

        // Ensure the header exists (if not, we append a section)
        $header = '🧩 Context Registries';
        if (!str_contains($code, $header)) {
            $insertion = $eol . '    /*' . $eol
                . "    |--------------------------------------------------------------------------" . $eol
                . "    | 🧩 Context Registries" . $eol
                . "    |--------------------------------------------------------------------------" . $eol
                . "    |" . $eol
                . "    | Each bounded context defines its own registry of commands, queries, upcasters," . $eol
                . "    | and event listeners. ContextCore will automatically register them on boot." . $eol
                . "    |" . $eol
                . "    | Example:" . $eol
                . "    |   \\Context\\Document\\DocumentContextRegistry::class," . $eol
                . "    |" . $eol
                . "    */" . $eol
                . "    'context_registries' => [" . $eol
                . "        // entries go here" . $eol
                . "    ]," . $eol;

            // Insert before the return array's final closing if possible
            $pos = strrpos($code, '];');
            if ($pos !== false) {
                $code = substr($code, 0, $pos + 2) . $insertion . substr($code, $pos + 2);
            } else {
                $code .= $insertion;
            }
        }

        // Now ensure the array exists and add FQCN if missing
        $pattern = "/'context_registries'\\s*=>\\s*\\[(.*?)\\]/s";
        if (preg_match($pattern, $code, $m)) {
            $block = $m[0];
            $inner = $m[1];

            $line = "        {$fqcn}::class,";
            $already = (strpos($inner, $line) !== false) || (strpos($inner, trim($line)) !== false);

            if (!$already) {
                // Insert our line before the closing ] of the block
                $beforeInner = substr($block, 0, strpos($block, $inner));
                $afterInner  = substr($block, strpos($block, $inner) + strlen($inner));
                $newInner    = rtrim($inner) . $eol . $line . $eol . "    ";
                $newBlock    = $beforeInner . $newInner . $afterInner;

                $code = str_replace($block, $newBlock, $code);
            }
        } else {
            // Create the array at the end
            $code .= $eol . "    'context_registries' => [{$eol}        {$fqcn}::class,{$eol}    ],{$eol}";
        }

        file_put_contents($configPath, $code);
    }
}
// @codeCoverageIgnoreEnd