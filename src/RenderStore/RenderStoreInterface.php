<?php
declare(strict_types=1);

namespace Shugoi\RenderStore;

interface RenderStoreInterface
{
    public function store(string $token, string $html, int $ttlMs = 120_000, int $maxReads = -1, bool $contentReplace = false): void;

    /** @return array{html:string,found?:bool,contentReplace?:bool}|null */
    public function retrieve(string $token): ?array;

    /** @return array{html:string,token:string}|null */
    public function hasFreshToken(string $siteKey, bool $contentReplace = false): ?array;

    public function remove(string $token): void;

    public function isCrossProcessSafe(): bool;
}
