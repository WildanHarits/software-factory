<?php

namespace App\Data\Providers;

readonly class RemoteRepository
{
    public function __construct(
        public string $externalId,
        public string $fullName,
        public string $webUrl,
        public string $cloneUrl,
        public string $defaultBranch,
        public bool $private,
        public bool $canPush,
    ) {
        //
    }
}
