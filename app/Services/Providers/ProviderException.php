<?php

namespace App\Services\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * A provider API call failed. The message is written for the person using the app.
 */
class ProviderException extends RuntimeException
{
    public static function fromResponse(string $provider, Response $response, string $action): self
    {
        $detail = self::detail($response);

        $message = match ($response->status()) {
            401 => "{$provider} rejected the token. It may be wrong, expired or revoked.",
            403 => "The token isn't allowed to {$action} on {$provider}.",
            404 => "{$provider} couldn't find it, or the token can't see it.",
            422, 400 => "{$provider} couldn't {$action}.",
            429 => "{$provider} is rate limiting requests. Try again in a minute.",
            default => "{$provider} returned an error ({$response->status()}) while trying to {$action}.",
        };

        return new self($detail ? "{$message} {$provider} said: {$detail}" : $message, $response->status());
    }

    public static function unreachable(string $provider, ConnectionException $exception): self
    {
        return new self("Couldn't reach {$provider}. Check the URL and your network connection.", 0, $exception);
    }

    private static function detail(Response $response): ?string
    {
        $json = $response->json();

        if (! is_array($json)) {
            return null;
        }

        // GitHub: {message, errors: [{message}]}. GitLab: {message} or {message: {field: [..]}} or {error}.
        // Anthropic: {error: {message}}.
        $parts = [];

        $message = $json['message'] ?? $json['error'] ?? null;

        if (is_array($message)) {
            $message = $message['message'] ?? collect($message)->flatten()->implode(' ');
        }

        if (is_string($message) && $message !== '') {
            $parts[] = $message;
        }

        foreach ($json['errors'] ?? [] as $error) {
            if (is_array($error) && isset($error['message'])) {
                $parts[] = $error['message'];
            }
        }

        $text = trim(implode(' ', $parts));

        return $text === '' ? null : rtrim($text, '.').'.';
    }
}
