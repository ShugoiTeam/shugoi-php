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

    public function stripComments(string $code): string
    {
        $r = preg_replace('/\/\/.*$/m', '', $code);
        $r = preg_replace('/\/\*[\s\S]*?\*\//', '', $r);
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
