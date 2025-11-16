<?php
// @codeCoverageIgnoreStart

namespace Pillar\Console\Scaffold;

use ReflectionClass;
use RuntimeException;

final class RegistryEditor
{
    public function registerCommand(object $registry, object $plan): void
    {
        $this->insertArrayItemIntoMethod($registry, 'commands', $this->lineFor($plan));
    }

    public function registerQuery(object $registry, object $plan): void
    {
        $this->insertArrayItemIntoMethod($registry, 'queries', $this->lineFor($plan));
    }

    public function registerAggregateRootId(object $registry, object $plan): void
    {
        $this->insertArrayItemIntoMethod($registry, 'aggregateRootIds', $this->lineForAggregateRootId($plan));
    }

    /**
     * Builds: \FQCN::class => \HandlerFQCN::class,
     * Always uses leading backslashes, robust if already present.
     */
    private function lineFor(object $plan): string
    {
        return sprintf('\\%s::class => \\%s::class,', ltrim($plan->messageClass, '\\'), ltrim($plan->handlerClass, '\\'));
    }

    /**
     * Builds: \IdFQCN::class,
     * Always uses leading backslash, robust if already present.
     */
    private function lineForAggregateRootId(object $plan): string
    {
        return sprintf('\\%s::class,', ltrim($plan->idClass, '\\'));
    }

    /**
     * Inserts $arrayItem into the return array of $methodName() in the registry source file.
     * - No anchors required.
     * - Idempotent (skips if already present).
     * - Falls back to appending a helpful comment at EOF if it cannot find a safe insertion point.
     */
    private function insertArrayItemIntoMethod(object $registry, string $methodName, string $arrayItem): void
    {
        $ref  = new ReflectionClass($registry);
        $file = $ref->getFileName();
        if (!$file || !is_file($file)) {
            throw new RuntimeException('Cannot locate source file for registry.');
        }

        $code = file_get_contents($file);
        $eol  = str_contains($code, "\r\n") ? "\r\n" : "\n";

        // Idempotency: if the exact pair already exists, bail early.
        if (str_contains($code, $arrayItem)) {
            return;
        }

        // 1) Locate "function <methodName>("
        $methodPattern = '/function\s+' . preg_quote($methodName, '/') . '\s*\(/m';
        if (!preg_match($methodPattern, $code, $m, PREG_OFFSET_CAPTURE)) {
            // Last resort: append guidance
            $this->appendGuidance($file, $code, $eol, $methodName, $arrayItem);
            return;
        }
        $methodPos = $m[0][1];

        // 2) Find the opening brace "{" of the method body
        $slice      = substr($code, $methodPos);
        $braceLocal = strpos($slice, '{');
        if ($braceLocal === false) {
            $this->appendGuidance($file, $code, $eol, $methodName, $arrayItem);
            return;
        }
        $bodyStart = $methodPos + $braceLocal + 1;
        $body      = substr($code, $bodyStart);

        // 3) Find "return [" (short array syntax) inside the method body
        if (!preg_match('/return\s*\[/', $body, $rm, PREG_OFFSET_CAPTURE)) {
            $this->appendGuidance($file, $code, $eol, $methodName, $arrayItem);
            return;
        }
        $returnLocal = $rm[0][1];
        $returnPos   = $bodyStart + $returnLocal;

        // 4) From the start of "return [", scan to the matching closing bracket "]"
        $closePos = $this->findMatchingBracketClose($code, $returnPos, '[', ']');
        if ($closePos === null) {
            $this->appendGuidance($file, $code, $eol, $methodName, $arrayItem);
            return;
        }

        // 5) Insert just before the closing bracket with indentation for array entries
        $indent = $this->guessIndentBefore($code, $returnPos) . '    '; // one level deeper
        $closingIndent = $this->guessIndentBefore($code, $closePos);
        $insertion = $eol . $indent . $arrayItem . $eol . $closingIndent;

        $new = substr($code, 0, $closePos) . $insertion . substr($code, $closePos);
        file_put_contents($file, $new);
    }

    /**
     * Finds the position of the matching closing bracket, counting nested pairs.
     * Returns null if unmatched.
     */
    private function findMatchingBracketClose(string $code, int $openPos, string $open='[', string $close=']'): ?int
    {
        $depth = 0;
        $len   = strlen($code);
        $inStr = false;
        $strCh = null;

        for ($i = $openPos; $i < $len; $i++) {
            $ch = $code[$i];

            // rudimentary string skipping to avoid brackets inside strings
            if ($inStr) {
                if ($ch === $strCh && $code[$i-1] !== '\\') {
                    $inStr = false;
                    $strCh = null;
                }
                continue;
            } else {
                if ($ch === '"' || $ch === "'") {
                    $inStr = true;
                    $strCh = $ch;
                    continue;
                }
            }

            if ($ch === $open) {
                $depth++;
            } elseif ($ch === $close) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return null;
    }

    /**
     * Guess a sensible indentation based on the line preceding $pos.
     */
    private function guessIndentBefore(string $code, int $pos): string
    {
        $lineStart = strrpos(substr($code, 0, $pos), "\n");
        if ($lineStart === false) {
            return '        '; // 8 spaces fallback
        }
        $line = substr($code, $lineStart + 1, $pos - $lineStart - 1);
        preg_match('/^\s*/', $line, $m);
        return $m[0] ?? '        ';
    }

    private function appendGuidance(string $file, string $code, string $eol, string $method, string $arrayItem): void
    {
        $append = $eol . $eol
            . '// Pillar registrations' . $eol
            . '// Could not find a safe insertion point in ' . $method . '().' . $eol
            . '// Add this line to the returned array:' . $eol
            . '// ' . $arrayItem . $eol;

        file_put_contents($file, $code . $append);
    }
}
// @codeCoverageIgnoreEnd