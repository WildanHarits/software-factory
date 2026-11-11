<?php

namespace App\Support;

/**
 * Turns what people paste ("owner/repo", a web URL, an SSH clone URL) into "owner/repo".
 * GitLab paths may contain subgroups: "group/subgroup/repo".
 */
class RepositoryReference
{
    public static function parse(string $input): ?string
    {
        $value = trim($input);

        // git@host:owner/repo.git
        if (preg_match('#^[\w.-]+@[\w.-]+:(.+)$#', $value, $matches)) {
            $value = $matches[1];
        }

        // https://host/owner/repo(.git)(/tree/main...)
        if (preg_match('#^https?://[^/]+/(.+)$#i', $value, $matches)) {
            $value = $matches[1];
            // Drop GitHub/GitLab page suffixes such as /tree/main or /-/issues.
            $value = (string) preg_replace('#/(-|tree|blob|issues|pulls|merge_requests)(/.*)?$#', '', $value);
        }

        $value = trim((string) preg_replace('#\.git$#', '', $value), '/');

        if (! preg_match('#^[\w.-]+(/[\w.-]+)+$#', $value)) {
            return null;
        }

        return $value;
    }
}
