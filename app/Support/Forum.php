<?php

namespace App\Support;

/**
 * One forum from the inventory.
 *
 * The two keys are separate properties on purpose: `key` reaches every summary endpoint,
 * and `detailKey` reaches the one endpoint that returns an error's message and trace. A
 * forum with no detail key cannot be asked for one.
 */
final class Forum
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $url,
        #[\SensitiveParameter] public readonly string $key,
        #[\SensitiveParameter] public readonly ?string $detailKey = null,
    ) {}

    public function hasDetailKey(): bool
    {
        return $this->detailKey !== null;
    }

    /**
     * What a tool may say about a forum: everything but its keys.
     *
     * @return array{id: string, name: string, url: string, error_detail: bool}
     */
    public function describe(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
            'error_detail' => $this->hasDetailKey(),
        ];
    }
}
