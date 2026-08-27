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
        $r = '';
        $len = strlen($code);
        $i = 0;
        $inSingle = false;
        $inDouble = false;
        while ($i < $len) {
            $ch = $code[$i];
            if ($inSingle) {
                $r .= $ch;
                if ($ch === '\\') {
                    $r .= $code[$i + 1] ?? '';
                    $i += 2;
                    continue;
                }
                if ($ch === "'") $inSingle = false;
                $i++;
                continue;
            }
            if ($inDouble) {
                $r .= $ch;
                if ($ch === '\\') {
                    $r .= $code[$i + 1] ?? '';
                    $i += 2;
                    continue;
                }
                if ($ch === '"') $inDouble = false;
                $i++;
                continue;
            }
            if ($ch === "'") {
                $inSingle = true;
                $r .= $ch;
                $i++;
                continue;
            }
            if ($ch === '"') {
                $inDouble = true;
                $r .= $ch;
                $i++;
                continue;
            }
            if ($ch === '/' && ($code[$i + 1] ?? '') === '/') {
                while ($i < $len && $code[$i] !== "\n") $i++;
                continue;
            }
            if ($ch === '/' && ($code[$i + 1] ?? '') === '*') {
                $end = strpos($code, '*/', $i + 2);
                $i = $end === false ? $len : $end + 2;
                continue;
            }
            $r .= $ch;
            $i++;
        }
        $r = preg_replace('/\n{3,}/', "\n\n", $r);
        return $r;
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
        $i = 0;
        $len = strlen($code);
        while ($i < $len) {
            $ch = $code[$i];
            if ($ch === "'" || $ch === '"') {
                $q = $ch;
                $j = $i + 1;
                while ($j < $len) {
                    if ($code[$j] === '\\') {
                        $j += 2;
                        continue;
                    }
                    if ($code[$j] === $q) break;
                    $j++;
                }
                if ($j < $len) {
                    $val = $this->runtimeValue(substr($code, $i, $j - $i + 1));
                    $r .= '_D("' . $this->xorEncrypt($val, $key) . '")';
                    $i = $j + 1;
                } else {
                    $r .= $code[$i];
                    $i++;
                }
            } else {
                $r .= $code[$i];
                $i++;
            }
        }
        return $r;
    }

    public function injectDecoder(string $seed): string
    {
        $key = $this->deriveKey($seed);
        $kb = $this->hexToBytes($key);
        $ks = implode('', array_map(function($b) {
            return '\\x' . str_pad(dechex($b), 2, '0', STR_PAD_LEFT);
        }, $kb));
        return 'var _D=function(h){var k="' . $ks . '",r="";for(var i=0;i<h.length;i+=2){r+=String.fromCharCode(parseInt(h.substr(i,2),16)^k.charCodeAt((i/2)%' . count($kb) . '))}return r};';
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
        $s = substr($str, 1, -1);
        $escapeMap = [
            "'" => "'", '"' => '"', '\\' => '\\',
            'b' => "\x08", 'f' => "\x0C", 'n' => "\x0A",
            'r' => "\x0D", 't' => "\x09", 'v' => "\x0B",
            '0' => "\x00",
        ];
        $s = preg_replace_callback('/\\\\([\'\"\\\\bfnrtv0])/', function($m) use ($escapeMap) {
            return $escapeMap[$m[1]];
        }, $s);
        $s = preg_replace_callback('/\\\\(u\{([\da-fA-F]+)\}|u([\da-fA-F]{4})|x([\da-fA-F]{2}))/', function($m) {
            if (!empty($m[2])) {
                return mb_chr((int)hexdec($m[2]), 'UTF-8');
            } elseif (!empty($m[3])) {
                return mb_chr((int)hexdec($m[3]), 'UTF-8');
            } else {
                return chr((int)hexdec($m[4]));
            }
        }, $s);
        return $s;
    }

    private function removeFunction(string $code, string $name): string
    {
        $r = preg_replace('/function\s+' . preg_quote($name, '/') . '\s*\([^)]*\)\s*\{[^{}]*\}/', '', $code);
        $r = preg_replace('/function\s+' . preg_quote($name, '/') . '\s*\([^)]*\)\s*\{[^}]*\{[^}]*\}[^}]*\}/', '', $r);
        return $r;
    }
}
