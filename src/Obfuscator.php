<?php
declare(strict_types=1);

namespace Shugoi;

class Obfuscator
{
    private const RENAMES = [
        'buildOverlay' => '_wf',
        'checkNotice' => '_wg',
        'hex' => '_wh',
        'stable' => '_wi',
    ];

    // 0xE0000 — start of the (invisible/zero-width) Supplementary Private Use plane.
    // Every source character is shifted by this offset so the encoded payload only
    // ever contains code points >= 0xE0000, never ' " \ ` < / or other JS/HTML
    // metacharacters, so the payload can be delimited by single quotes unescaped.
    private const SPUA_B_OFFSET = 917504;

    // Max representable code point: source chars above U+2FFFF would overflow
    // JS String.fromCodePoint (limit 0x10FFFF). The skeleton never contains such
    // characters; documented as a hard limit in the method doc.
    private const MAX_SOURCE_CODE_POINT = 0x2FFFF;

    public function stripComments(string $code): string
    {
        $result = '';
        foreach ($this->javascriptSegments($code) as [$type, $text]) {
            // Keep line terminators: removing them can change automatic semicolon insertion.
            $result .= $type === 'comment' ? (preg_match('/[\r\n]/', $text) ? "\n" : ' ') : $text;
        }
        return $result;
    }

    public function stripTrace(string $code): string
    {
        $r = $code;
        $r = $this->removeFunction($r, '_sgLogCP');
        $r = $this->removeFunction($r, '_sgErr');
        $r = preg_replace('/_sgLogCP\([^)]*\)\s*[;,]?/', '', $r);
        $r = preg_replace('/_sgErr\(\s*(\'[^\']*\'|"[^"]*"|[A-Za-z_\$][\w\$]*)\s*,\s*(\'[^\']*\'|"[^"]*"|[A-Za-z_\$][\w\$]*)\s*\)\s*[;,]?/', '', $r);
        $r = preg_replace('/var\s+_SG_TRACE\s*=\s*(?:true|false)\s*;\s*/', '', $r);
        $r = preg_replace('/var\s+_sgCP\s*=\s*[^;]*;\s*/', '', $r);
        $r = preg_replace('/;\s*;/', ';', $r);
        return $r;
    }

    public function renameFunctions(string $code, string $seed): string
    {
        $r = $code;
        $entries = [];
        foreach (self::RENAMES as $from => $to) {
            $entries[] = ['from' => $from, 'to' => $to, 'hash' => $this->hash($from . $seed)];
        }
        usort($entries, fn($a, $b) => $a['hash'] <=> $b['hash']);
        foreach ($entries as $entry) {
            $suffix = base_convert((string)($entry['hash'] % 9000 + 1000), 10, 36);
            $newName = $entry['to'] . $suffix;
            $r = preg_replace('/\b' . preg_quote($entry['from'], '/') . '\(/', $newName . '(', $r);
        }
        return $r;
    }

    public function shuffleLines(string $code, string $seed): string
    {
        $lines = explode("\n", $code);
        $depth = array_fill(0, count($lines), 0);
        $d = 0;
        for ($i = 0; $i < count($lines); $i++) {
            $depth[$i] = $d;
            for ($j = 0; $j < strlen($lines[$i]); $j++) {
                $ch = $lines[$i][$j];
                if ($ch === '{') {
                    $d++;
                } elseif ($ch === '}') {
                    $d--;
                }
            }
        }
        $blocks = [];
        $start = null;
        for ($i = 0; $i < count($lines); $i++) {
            $isShuffleable = $depth[$i] === 1
                && preg_match('/^\s*R\.\w+\s*=/', $lines[$i])
                && !preg_match('/[{}]/', $lines[$i])
                && rtrim($lines[$i]) !== '' && $lines[$i][strlen(rtrim($lines[$i])) - 1] === ';';
            if ($isShuffleable && $start === null) $start = $i;
            if (!$isShuffleable && $start !== null) {
                $blocks[] = ['start' => $start, 'end' => $i - 1];
                $start = null;
            }
        }
        if ($start !== null) $blocks[] = ['start' => $start, 'end' => count($lines) - 1];
        $rng = $this->seededRng($seed);
        foreach ($blocks as $blk) {
            $slice = array_slice($lines, $blk['start'], $blk['end'] - $blk['start'] + 1);
            for ($i = count($slice) - 1; $i > 0; $i--) {
                $j = (int) floor($rng() * ($i + 1));
                $tmp = $slice[$i];
                $slice[$i] = $slice[$j];
                $slice[$j] = $tmp;
            }
            array_splice($lines, $blk['start'], count($slice), $slice);
        }
        return implode("\n", $lines);
    }

    public function encryptStrings(string $code, string $seed): string
    {
        $key = $this->deriveKey($seed);
        $r = '';
        foreach ($this->javascriptSegments($code) as [$type, $text]) {
            $r .= $type === 'string'
                ? ' _D("' . $this->xorEncrypt($this->runtimeValue($text), $key) . '")'
                : $text;
        }
        return $r;
    }

    /**
     * Split lexical literals before transforming JavaScript. Regexes and complete
     * template literals remain opaque; quotes/comment markers inside them are data.
     * This intentionally does not rewrite expressions inside template interpolation.
     *
     * @return list<array{string,string}>
     */
    private function javascriptSegments(string $code): array
    {
        $segments = [];
        $length = strlen($code);
        $start = 0;
        $i = 0;
        $expressionStart = true;
        $previousWord = '';
        $parentheses = [];
        while ($i < $length) {
            $ch = $code[$i];
            $type = null;
            $end = $i + 1;
            if ($ch === "'" || $ch === '"') {
                $type = 'string';
                $end = $this->quotedEnd($code, $i, $ch);
                $expressionStart = false;
            } elseif ($ch === '`') {
                $type = 'opaque';
                $end = $this->templateEnd($code, $i);
                $expressionStart = false;
            } elseif ($ch === '/' && ($code[$i + 1] ?? '') === '/') {
                $type = 'comment';
                $end = strpos($code, "\n", $i + 2);
                $end = $end === false ? $length : $end;
            } elseif ($ch === '/' && ($code[$i + 1] ?? '') === '*') {
                $type = 'comment';
                $end = strpos($code, '*/', $i + 2);
                $end = $end === false ? $length : $end + 2;
            } elseif ($ch === '/' && $expressionStart && ($regexEnd = $this->regexEnd($code, $i)) !== null) {
                $type = 'opaque';
                $end = $regexEnd;
                $expressionStart = false;
            }
            if ($type !== null) {
                if ($i > $start) $segments[] = ['code', substr($code, $start, $i - $start)];
                $segments[] = [$type, substr($code, $i, $end - $i)];
                $i = $start = $end;
                if ($type !== 'comment') $previousWord = '';
                continue;
            }
            if (ctype_space($ch)) { ++$i; continue; }
            if (ctype_alpha($ch) || $ch === '_' || $ch === '$') {
                $end = $i + 1;
                while ($end < $length && (ctype_alnum($code[$end]) || $code[$end] === '_' || $code[$end] === '$')) ++$end;
                $previousWord = substr($code, $i, $end - $i);
                $expressionStart = in_array($previousWord, ['return', 'throw', 'case', 'delete', 'void', 'typeof', 'new', 'yield', 'await', 'in', 'of', 'instanceof', 'else', 'do'], true);
                $i = $end;
                continue;
            }
            if ($ch === '(') {
                $parentheses[] = in_array($previousWord, ['if', 'while', 'for', 'with', 'switch', 'catch'], true);
                $expressionStart = true;
            } elseif ($ch === ')') {
                $expressionStart = array_pop($parentheses) ?? false;
            } elseif ($ch === ']' || ctype_digit($ch) || $ch === '.') {
                $expressionStart = false;
            } else {
                $expressionStart = true;
            }
            $previousWord = '';
            ++$i;
        }
        if ($start < $length) $segments[] = ['code', substr($code, $start)];
        return $segments;
    }

    private function quotedEnd(string $code, int $start, string $quote): int
    {
        for ($i = $start + 1, $length = strlen($code); $i < $length; ++$i) {
            if ($code[$i] === '\\') { ++$i; continue; }
            if ($code[$i] === $quote) return $i + 1;
        }
        return strlen($code);
    }

    private function regexEnd(string $code, int $start): ?int
    {
        $inClass = false;
        for ($i = $start + 1, $length = strlen($code); $i < $length; ++$i) {
            $ch = $code[$i];
            if ($ch === '\\') { ++$i; continue; }
            if ($ch === "\n" || $ch === "\r") return null;
            if ($ch === '[') $inClass = true;
            if ($ch === ']') $inClass = false;
            if ($ch === '/' && !$inClass) {
                while ($i + 1 < $length && ctype_alpha($code[$i + 1])) ++$i;
                return $i + 1;
            }
        }
        return null;
    }

    private function templateEnd(string $code, int $start): int
    {
        $length = strlen($code);
        for ($i = $start + 1; $i < $length; ++$i) {
            if ($code[$i] === '\\') { ++$i; continue; }
            if ($code[$i] === '`') return $i + 1;
            if ($code[$i] !== '$' || ($code[$i + 1] ?? '') !== '{') continue;
            $depth = 1;
            $i += 2;
            while ($i < $length && $depth > 0) {
                $ch = $code[$i];
                if ($ch === "'" || $ch === '"') { $i = $this->quotedEnd($code, $i, $ch); continue; }
                if ($ch === '`') { $i = $this->templateEnd($code, $i); continue; }
                if ($ch === '/' && ($code[$i + 1] ?? '') === '*') {
                    $end = strpos($code, '*/', $i + 2);
                    $i = $end === false ? $length : $end + 2;
                    continue;
                }
                if ($ch === '/' && ($code[$i + 1] ?? '') === '/') {
                    $end = strpos($code, "\n", $i + 2);
                    $i = $end === false ? $length : $end;
                    continue;
                }
                if ($ch === '/' && ($end = $this->regexEnd($code, $i)) !== null) { $i = $end; continue; }
                if ($ch === '{') ++$depth;
                if ($ch === '}') --$depth;
                ++$i;
            }
            --$i;
        }
        return $length;
    }

    public function injectDecoder(string $seed): string
    {
        $key = $this->deriveKey($seed);
        $kb = $this->hexToBytes($key);
        $ks = implode('', array_map(function($b) {
            return '\\x' . str_pad(dechex($b), 2, '0', STR_PAD_LEFT);
        }, $kb));
        // Encode UTF-16 code units, matching JavaScript strings exactly (including
        // surrogate pairs and lone surrogates), rather than interpreting UTF-8
        // bytes as Latin-1 at runtime. No eval or TextDecoder is required.
        return 'var _D=function(h){var k="' . $ks . '",r="";for(var i=0;i<h.length;i+=4){var a=parseInt(h.substr(i,2),16)^k.charCodeAt((i/2)%' . count($kb) . '),b=parseInt(h.substr(i+2,2),16)^k.charCodeAt((i/2+1)%' . count($kb) . ');r+=String.fromCharCode((a<<8)|b)}return r};';
    }

    public function escapeClosingTags(string $code): string
    {
        return preg_replace('/<\/(script|style)/i', '<\\/$1', $code);
    }

    public function fixComputedProperties(string $code): string
    {
        return preg_replace('/([{,])(\s*)_D\("([^"]*)"\)(\s*:)/', '$1$2[_D("$3")]$4', $code);
    }

    public function obfuscate(string $code, string $seed): string
    {
        $r = $this->stripComments($code);
        $r = $this->stripTrace($r);
        $r = $this->renameFunctions($r, $seed);
        $r = $this->shuffleLines($r, $seed);
        $r = $this->encryptStrings($r, $seed);
        $decoder = $this->injectDecoder($seed);
        if (preg_match('/^\s*\(function\(\)\{/', $r)) {
            $r = preg_replace('/^\s*\(function\(\)\{/', '$0' . $decoder, $r);
        } else {
            $r = $decoder . $r;
        }
        $r = $this->escapeClosingTags($r);
        $r = $this->fixComputedProperties($r);
        return $r;
    }

    /**
     * Wraps obfuscated JS into an "invisible eval" payload.
     *
     * Every character of $code is shifted by +0xE0000 (917504). The resulting
     * string contains only code points >= 0xE0000 — i.e. it displays as an
     * almost-empty line and never contains ', ", \, `, <, / or `</script>`,
     * so it can be embedded in a single-quoted JS literal without escaping.
     *
     * The runtime wrapper is:
     *   var <off>=917504,<cp>=String.fromCodePoint;
     *   eval([...'<payload>'].map(function(<it>){
     *     return <cp>(<it>.codePointAt(0)-<off>)
     *   }).join(''));
     *
     * Identifiers are derived from the seed (seededRng + hash), so the wrapper
     * rotates per site/seed and cannot be matched by a single static regex.
     *
     * Hard limits:
     *  - source code points must be <= U+2FFFF (encoded <= 0x1F02FF < 0x10FFFF,
     *    the JS String.fromCodePoint ceiling). The skeleton never exceeds this.
     *  - encoded payload is ~4x the source size (every code point >= 0x10000 is
     *    encoded as 4 UTF-8 bytes).
     *  - requires ES6 (spread, String.fromCodePoint, codePointAt) in the browser.
     */
    public function invisibleEval(string $code, string $seed): string
    {
        $payload = '';
        foreach (preg_split('//u', $code, -1, PREG_SPLIT_NO_EMPTY) as $char) {
            $payload .= $this->chrUtf8($this->ordUtf8($char) + self::SPUA_B_OFFSET);
        }

        $off = $this->ident($seed, 0);
        $cp = $this->ident($seed, 1);
        $item = $this->ident($seed, 2);

        return "var {$off}=917504,{$cp}=String.fromCodePoint;"
            . "eval([...'{$payload}'].map(function({$item}){"
            . "return {$cp}({$item}.codePointAt(0)-{$off})}).join(''));";
    }

    private function ident(string $seed, int $idx): string
    {
        $pool = ['_m', '_n', '_p', '_q', '_v', '_w', '_x', '_y', '_z', '_t'];
        $rng = $this->seededRng($seed . '_ident' . $idx);
        $base = $pool[(int) floor($rng() * count($pool))];
        $h = $this->hash($seed . '_ident' . $idx);
        $suffix = $idx . base_convert((string)($h % 8999 + 1000), 10, 36);
        return $base . $suffix;
    }

    private function ordUtf8(string $char): int
    {
        if (function_exists('mb_ord')) {
            return mb_ord($char, 'UTF-8');
        }
        if (class_exists('IntlChar')) {
            return \IntlChar::ord($char);
        }
        $b = ord($char[0]);
        if ($b < 0x80) return $b;
        $extra = 0;
        $cp = 0;
        if (($b & 0xE0) === 0xC0) {
            $cp = $b & 0x1F;
            $extra = 1;
        } elseif (($b & 0xF0) === 0xE0) {
            $cp = $b & 0x0F;
            $extra = 2;
        } elseif (($b & 0xF8) === 0xF0) {
            $cp = $b & 0x07;
            $extra = 3;
        }
        for ($i = 1; $i <= $extra; $i++) {
            $cp = ($cp << 6) | (ord($char[$i]) & 0x3F);
        }
        return $cp;
    }

    private function chrUtf8(int $codePoint): string
    {
        if (function_exists('mb_chr')) {
            return mb_chr($codePoint, 'UTF-8');
        }
        if (class_exists('IntlChar')) {
            $s = \IntlChar::chr($codePoint);
            if ($s !== null && $s !== false) return $s;
        }
        if ($codePoint < 0x80) return chr($codePoint);
        if ($codePoint < 0x800) {
            return chr(0xC0 | ($codePoint >> 6)) . chr(0x80 | ($codePoint & 0x3F));
        }
        if ($codePoint < 0x10000) {
            return chr(0xE0 | ($codePoint >> 12))
                . chr(0x80 | (($codePoint >> 6) & 0x3F))
                . chr(0x80 | ($codePoint & 0x3F));
        }
        return chr(0xF0 | ($codePoint >> 18))
            . chr(0x80 | (($codePoint >> 12) & 0x3F))
            . chr(0x80 | (($codePoint >> 6) & 0x3F))
            . chr(0x80 | ($codePoint & 0x3F));
    }

    private function hash(string $s): int
    {
        $h = 0;
        for ($i = 0; $i < strlen($s); $i++) {
            $h = (($h << 5) - $h) + ord($s[$i]);
            $h = $h & 0xFFFFFFFF;
            if ($h & 0x80000000) {
                $h -= 0x100000000;
            }
        }
        return abs($h);
    }

    private function seededRng(string $seed): \Closure
    {
        $s = $this->hash($seed . '_shuffle');
        return function() use (&$s): float {
            $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF;
            return $s / 2147483647.0;
        };
    }

    private function deriveKey(string $seed): string
    {
        return substr(hash('sha256', $seed . 'sg_val_v1'), 0, 32);
    }

    private function hexToBytes(string $hex): array
    {
        $b = [];
        for ($i = 0; $i < strlen($hex); $i += 2) {
            $b[] = hexdec(substr($hex, $i, 2));
        }
        return $b;
    }

    private function xorEncrypt(string $str, string $hexKey): string
    {
        $kb = $this->hexToBytes($hexKey);
        $enc = '';
        for ($i = 0; $i < strlen($str); $i++) {
            $cc = ord($str[$i]) ^ $kb[$i % count($kb)];
            $enc .= str_pad(dechex($cc), 2, '0', STR_PAD_LEFT);
        }
        return $enc;
    }

    private function runtimeValue(string $str): string
    {
        $characters = preg_split('//u', substr($str, 1, -1), -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) throw new \InvalidArgumentException('JavaScript string must be valid UTF-8 source');
        $escapeMap = [
            'b' => 8, 'f' => 12, 'n' => 10, 'r' => 13, 't' => 9, 'v' => 11,
        ];
        $result = '';
        for ($i = 0, $length = count($characters); $i < $length; ++$i) {
            $character = $characters[$i];
            if ($character !== '\\') {
                $result .= $this->utf16CodePoint($this->ordUtf8($character));
                continue;
            }
            if (++$i >= $length) throw new \InvalidArgumentException('Incomplete JavaScript string escape');
            $character = $characters[$i];
            if ($character === "\r" || $character === "\n" || $character === "\u{2028}" || $character === "\u{2029}") {
                if ($character === "\r" && ($characters[$i + 1] ?? '') === "\n") ++$i;
                continue;
            }
            if (isset($escapeMap[$character])) {
                $result .= $this->utf16CodePoint($escapeMap[$character]);
            } elseif ($character === 'x' || $character === 'u') {
                $hex = '';
                if ($character === 'u' && ($characters[$i + 1] ?? '') === '{') {
                    $i += 2;
                    while ($i < $length && $characters[$i] !== '}') $hex .= $characters[$i++];
                    if ($i >= $length || strlen($hex) > 6) throw new \InvalidArgumentException('Invalid JavaScript Unicode escape');
                } else {
                    $digits = $character === 'x' ? 2 : 4;
                    for ($j = 0; $j < $digits; ++$j) $hex .= $characters[++$i] ?? '';
                    if (strlen($hex) !== $digits) throw new \InvalidArgumentException('Incomplete JavaScript Unicode escape');
                }
                if ($hex === '' || !ctype_xdigit($hex) || hexdec($hex) > 0x10FFFF) throw new \InvalidArgumentException('Invalid JavaScript Unicode escape');
                $result .= $this->utf16CodePoint((int)hexdec($hex));
            } elseif (strlen($character) === 1 && $character >= '0' && $character <= '7') {
                $octal = $character;
                $maxDigits = $character <= '3' ? 3 : 2;
                while (strlen($octal) < $maxDigits && isset($characters[$i + 1])
                    && strlen($characters[$i + 1]) === 1 && $characters[$i + 1] >= '0' && $characters[$i + 1] <= '7') {
                    $octal .= $characters[++$i];
                }
                $result .= $this->utf16CodePoint((int)octdec($octal));
            } else {
                // Identity escapes include escaped slashes, quotes and backslashes.
                // Decode once: "\\\\u0061" must remain the six characters \u0061.
                $result .= $this->utf16CodePoint($this->ordUtf8($character));
            }
        }
        return $result;
    }

    private function utf16CodePoint(int $codePoint): string
    {
        if ($codePoint <= 0xFFFF) return pack('n', $codePoint);
        $codePoint -= 0x10000;
        return pack('nn', 0xD800 + ($codePoint >> 10), 0xDC00 + ($codePoint & 0x3FF));
    }

    private function removeFunction(string $code, string $name): string
    {
        $r = preg_replace('/function\s+' . preg_quote($name, '/') . '\s*\([^)]*\)\s*\{[^{}]*\}/', '', $code);
        $r = preg_replace('/function\s+' . preg_quote($name, '/') . '\s*\([^)]*\)\s*\{[^}]*\{[^}]*\}[^}]*\}/', '', $r);
        return $r;
    }
}
