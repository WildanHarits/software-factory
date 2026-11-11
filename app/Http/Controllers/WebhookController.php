<?php

namespace App\Http\Controllers;

use App\Data\Providers\PullRequest;
use App\Enums\CredentialProvider;
use App\Models\Project;
use App\Tickets\PullRequestTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pull/merge request events from GitHub and GitLab. Each project has its own secret:
 * GitHub signs the body with it, GitLab sends it as a token.
 */
class WebhookController extends Controller
{
    public function __construct(private PullRequestTracker $tracker)
    {
        //
    }

    public function github(Request $request, int $projectId): JsonResponse
    {
        $project = $this->project($projectId, CredentialProvider::GitHub);

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), (string) $project->webhook_secret);

        abort_unless(hash_equals($expected, (string) $request->header('X-Hub-Signature-256')), 403, 'Invalid signature.');

        $project->forceFill(['webhook_last_received_at' => now()])->save();

        $event = (string) $request->header('X-GitHub-Event');

        if ($event === 'ping') {
            return response()->json(['ok' => true, 'message' => 'pong']);
        }

        if ($event !== 'pull_request' || ! is_array($pullRequest = $request->input('pull_request'))) {
            return $this->ignored();
        }

        $state = match (true) {
            ! empty($pullRequest['merged']) => PullRequest::MERGED,
            ($pullRequest['state'] ?? null) === 'closed' => PullRequest::CLOSED,
            default => PullRequest::OPEN,
        };

        return $this->apply($project, (int) ($pullRequest['number'] ?? 0), $pullRequest['head']['ref'] ?? null, $state);
    }

    public function gitlab(Request $request, int $projectId): JsonResponse
    {
        $project = $this->project($projectId, CredentialProvider::GitLab);

        abort_unless(hash_equals((string) $project->webhook_secret, (string) $request->header('X-Gitlab-Token')), 403, 'Invalid token.');

        $project->forceFill(['webhook_last_received_at' => now()])->save();

        $attributes = $request->input('object_attributes');

        if ($request->header('X-Gitlab-Event') !== 'Merge Request Hook' || ! is_array($attributes)) {
            return $this->ignored();
        }

        $state = match ($attributes['state'] ?? null) {
            'merged' => PullRequest::MERGED,
            'closed' => PullRequest::CLOSED,
            default => PullRequest::OPEN,
        };

        return $this->apply($project, (int) ($attributes['iid'] ?? 0), $attributes['source_branch'] ?? null, $state);
    }

    private function project(int $id, CredentialProvider $provider): Project
    {
        $project = Project::query()->whereKey($id)->where('provider', $provider)->first();

        abort_if(! $project || ! $project->webhook_secret, 404);

        return $project;
    }

    private function apply(Project $project, int $number, ?string $branch, string $state): JsonResponse
    {
        $ticket = $number > 0 ? $this->tracker->ticketFor($project, $number, is_string($branch) ? $branch : null) : null;

        if (! $ticket) {
            return $this->ignored();
        }

        $this->tracker->apply($ticket, $state);

        return response()->json(['ok' => true, 'ticket' => $ticket->number, 'state' => $state]);
    }

    private function ignored(): JsonResponse
    {
        return response()->json(['ok' => true, 'ignored' => true], 202);
    }
}
