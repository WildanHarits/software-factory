<?php

namespace App\Data\Providers;

/**
 * Where a new repository can be created: the token owner's account, an organization, or a GitLab group.
 */
readonly class RepositoryNamespace
{
    public function __construct(
        public string $id,
        public string $path,
        public string $kind,
    ) {
        //
    }

    /**
     * @return array{id: string, path: string, kind: string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'path' => $this->path, 'kind' => $this->kind];
    }
}
