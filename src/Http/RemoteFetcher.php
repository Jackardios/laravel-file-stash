<?php

namespace Jackardios\FileStash\Http;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use Jackardios\FileStash\Contracts\File;
use Jackardios\FileStash\Exceptions\FailedToRetrieveFileException;
use Jackardios\FileStash\Exceptions\FileIsTooLargeException;
use Jackardios\FileStash\Exceptions\HostNotAllowedException;
use Jackardios\FileStash\Exceptions\MimeTypeIsNotAllowedException;
use Jackardios\FileStash\Support\ConfigNormalizer;
use Jackardios\FileStash\Support\HostValidator;
use Jackardios\FileStash\Support\MimeGuard;
use Jackardios\FileStash\Support\Url;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * HTTP layer of the file cache: existence checks and downloads of remote
 * files, including host validation, retries with exponential backoff, and
 * size-limited streaming.
 *
 * @phpstan-import-type NormalizedConfig from ConfigNormalizer
 */
class RemoteFetcher
{
    /**
     * Upper bound for the exponential retry backoff in milliseconds.
     */
    protected const MAX_BACKOFF_MS = 30000;

    /**
     * HTTP client used for remote file operations: the injected one, or the
     * default client once the first request built it (see client()).
     */
    protected ?Client $client;

    /**
     * Validator applied to request URLs and every redirect target.
     */
    protected HostValidator $hostValidator;

    /**
     * @param  NormalizedConfig  $config
     */
    public function __construct(
        protected array $config,
        ?Client $client = null,
        protected LoggerInterface $logger = new NullLogger,
    ) {
        $this->hostValidator = new HostValidator($config['allowed_hosts'], $config['block_private_hosts']);
        $this->client = $client;
    }

    /**
     * Check whether the remote file exists via a HEAD request.
     *
     * Only a definitive answer counts as "does not exist": a 3xx the
     * redirect budget did not resolve, or a 4xx other than 429. Rate
     * limits, server errors, and network errors (after retries) say nothing
     * about the file and throw.
     *
     * @throws MimeTypeIsNotAllowedException
     * @throws FileIsTooLargeException
     * @throws HostNotAllowedException
     * @throws FailedToRetrieveFileException On 429/5xx after retries.
     * @throws GuzzleException On network errors after retries.
     */
    public function exists(File $file): bool
    {
        $this->hostValidator->validate($file->getUrl());

        try {
            return $this->executeWithRetries($file, 'HEAD', function () use ($file): bool {
                $response = $this->head($file);

                if (! empty($this->config['mime_types'])) {
                    // Deny by default: without a Content-Type we cannot prove
                    // the file passes the whitelist (mirrors the disk
                    // behavior for unknown MIME types).
                    MimeGuard::ensureAllowed($response->getHeaderLine('content-type'), $this->config['mime_types']);
                }

                $maxBytes = $this->config['max_file_size'];
                $contentLength = $response->getHeaderLine('content-length');
                if ($maxBytes >= 0 && is_numeric($contentLength) && (int) $contentLength > $maxBytes) {
                    throw FileIsTooLargeException::create($maxBytes);
                }

                return true;
            });
        } catch (FailedToRetrieveFileException $exception) {
            if ($this->isRetryable($exception, $exception->statusCode)) {
                throw $exception;
            }

            return false;
        } catch (TooManyRedirectsException) {
            return false;
        }
    }

    /**
     * Download a remote file into the given target handle.
     *
     * The download streams through a SizeLimitedStream over the caller's
     * handle, so oversized bodies abort the transfer as soon as the limit is
     * crossed. Every retry attempt truncates the target first, so a retried
     * download can never append to a partial body. The handle stays open.
     *
     * @param  resource  $target  Writable, seekable handle.
     *
     * @throws GuzzleException
     * @throws FileIsTooLargeException
     * @throws FailedToRetrieveFileException
     * @throws HostNotAllowedException
     */
    public function fetch(File $file, $target): void
    {
        $this->hostValidator->validate($file->getUrl());

        $maxBytes = $this->config['max_file_size'];

        $this->executeWithRetries($file, 'GET', function () use ($file, $target, $maxBytes): void {
            $sink = new SizeLimitedStream($target, $maxBytes);

            try {
                $response = $this->client()->get(Url::encode($file->getUrl()), [
                    ...$this->requestOptions(),
                    'sink' => $sink,
                    // No ResponseInterface type on purpose: MockHandler passes
                    // queued exceptions through on_headers as well.
                    'on_headers' => static function ($response) use ($sink, $maxBytes): void {
                        if (! $response instanceof ResponseInterface) {
                            return;
                        }

                        $statusCode = $response->getStatusCode();
                        if ($statusCode >= 400) {
                            // Abort before the error body is downloaded into the target.
                            throw FailedToRetrieveFileException::create(
                                "HTTP request failed with status code {$statusCode}",
                                statusCode: $statusCode
                            );
                        }

                        // Guzzle reuses the sink across redirect hops: discard
                        // redirect-hop bodies entirely (they must neither end
                        // up in the file nor count against the size limit).
                        $isRedirect = $statusCode >= 300;
                        $sink->reset(discardBody: $isRedirect);

                        if ($isRedirect) {
                            return;
                        }

                        $contentLength = $response->getHeaderLine('content-length');
                        if ($maxBytes >= 0 && is_numeric($contentLength) && (int) $contentLength > $maxBytes) {
                            throw FileIsTooLargeException::create($maxBytes);
                        }
                    },
                ]);
            } catch (GuzzleException $exception) {
                if ($sink->limitExceeded()) {
                    throw FileIsTooLargeException::create($maxBytes);
                }

                // on_headers exceptions arrive wrapped in a RequestException.
                $previous = $exception->getPrevious();
                if ($previous instanceof FileIsTooLargeException || $previous instanceof FailedToRetrieveFileException) {
                    throw $previous;
                }

                $statusCode = $this->extractStatusCode($exception);
                if ($statusCode >= 400) {
                    // http_errors=true clients throw for 4xx/5xx responses.
                    throw FailedToRetrieveFileException::create(
                        "HTTP request failed with status code {$statusCode}",
                        previous: $exception,
                        statusCode: $statusCode
                    );
                }

                throw $exception;
            } finally {
                $sink->close();
            }

            if ($sink->limitExceeded()) {
                // Non-curl handlers (e.g. MockHandler) ignore short writes.
                throw FileIsTooLargeException::create($maxBytes);
            }

            // Any non-2xx final response is a failure: a 3xx here means the
            // redirect chain was NOT followed to a document (e.g. the
            // max_redirects budget ran out), so there is nothing to cache.
            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                throw FailedToRetrieveFileException::create(
                    "HTTP request failed with status code {$statusCode}",
                    statusCode: $statusCode
                );
            }
        });
    }

    /**
     * Perform the HEAD request, normalizing HTTP failures into
     * FailedToRetrieveFileException carrying the status code.
     *
     * @throws GuzzleException
     * @throws FailedToRetrieveFileException
     */
    protected function head(File $file): ResponseInterface
    {
        try {
            $response = $this->client()->head(Url::encode($file->getUrl()), $this->requestOptions());
        } catch (GuzzleException $exception) {
            $statusCode = $this->extractStatusCode($exception);
            if ($statusCode >= 400) {
                // http_errors=true clients throw for 4xx/5xx responses.
                throw FailedToRetrieveFileException::create(
                    "HTTP HEAD request failed with status code {$statusCode}",
                    previous: $exception,
                    statusCode: $statusCode
                );
            }

            throw $exception;
        }

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            throw FailedToRetrieveFileException::create(
                "HTTP HEAD request failed with status code {$statusCode}",
                statusCode: $statusCode
            );
        }

        return $response;
    }

    /**
     * Run an HTTP operation, retrying retryable failures with exponential backoff.
     *
     * @template T
     *
     * @param  Closure(): T  $attempt
     * @return T
     *
     * @throws GuzzleException
     * @throws FailedToRetrieveFileException
     */
    protected function executeWithRetries(File $file, string $method, Closure $attempt)
    {
        $maxRetries = $this->config['http_retries'];
        $retryDelay = $this->config['http_retry_delay'];
        $attemptNo = 0;

        while (true) {
            $attemptNo++;

            try {
                return $attempt();
            } catch (GuzzleException|FailedToRetrieveFileException $exception) {
                $statusCode = $exception instanceof FailedToRetrieveFileException
                    ? $exception->statusCode
                    : $this->extractStatusCode($exception);

                if ($attemptNo > $maxRetries || ! $this->isRetryable($exception, $statusCode)) {
                    throw $exception;
                }

                $context = ['exception' => Url::redactUrls($exception->getMessage())];
                if ($statusCode > 0) {
                    $context['status_code'] = $statusCode;
                }

                $this->backoff($file, $method, $attemptNo, $maxRetries, $retryDelay, $context);
            }
        }
    }

    /**
     * Determine whether a failure is worth retrying: network errors
     * (status 0), 429 and 5xx. An exhausted redirect budget fails the same
     * way every time.
     */
    protected function isRetryable(GuzzleException|FailedToRetrieveFileException $exception, int $statusCode): bool
    {
        if ($exception instanceof TooManyRedirectsException) {
            return false;
        }

        return $statusCode === 0 || $statusCode === 429 || $statusCode >= 500;
    }

    /**
     * Extract the HTTP status code of an HTTP error response (4xx/5xx from
     * a client with http_errors=true), or 0 for anything else.
     *
     * A transfer that broke after the headers arrived may carry the partial
     * response, but its status is not the reason for the failure: it counts
     * as a network error. BadResponseException exposes a non-null response
     * on both Guzzle 7 and 8 (Guzzle 8 removed RequestException::getResponse()).
     */
    protected function extractStatusCode(GuzzleException $exception): int
    {
        return $exception instanceof BadResponseException
            ? $exception->getResponse()->getStatusCode()
            : 0;
    }

    /**
     * Log and sleep before the next retry attempt (exponential backoff with jitter).
     *
     * @param  array<string, mixed>  $context
     */
    protected function backoff(File $file, string $method, int $attempt, int $maxRetries, int $retryDelay, array $context): void
    {
        $this->logger->warning("HTTP {$method} request failed, retrying ({$attempt}/{$maxRetries})", [
            'url' => Url::sanitizeForLogging($file->getUrl()),
            ...$context,
        ]);

        // Jitter before the clamp, so MAX_BACKOFF_MS is a hard ceiling on the
        // actual sleep. This sleep runs while the caller holds the entry's
        // claim lock and the shared lifecycle lock — every worker waiting on
        // this URL (and any pending clear()) waits with us.
        $delay = min((float) $retryDelay * 2 ** ($attempt - 1), 2.0 * self::MAX_BACKOFF_MS);
        $actual = min(random_int((int) ($delay * 0.5), (int) ($delay * 1.5)), self::MAX_BACKOFF_MS);
        usleep($actual * 1000);
    }

    /**
     * Security- and correctness-critical options, applied per request.
     *
     * These are set on every request (not only as client defaults in
     * makeClient) so that an injected client is still subject to the
     * configured timeouts, the redirect budget, and — most importantly — the
     * on_redirect host validation; per-request options win over client
     * config (Guzzle shallow-merges them). The `curl` and `allow_redirects`
     * arrays would therefore REPLACE an injected client's own arrays, so
     * they are merged explicitly: the client's other curl options and
     * redirect settings (protocols, referer, ...) survive, and its own
     * on_redirect callback runs after the host validation passed.
     *
     * `read_timeout` maps to curl's low-speed abort (CURLOPT_LOW_SPEED_*): the
     * transfer fails when it stalls below 1 byte/s for that many seconds (-1
     * or 0: no stall limit). The Guzzle `read_timeout` option only applies to
     * the PHP stream handler and would be a no-op with the (default) curl
     * handler.
     *
     * @return array{
     *   timeout: float,
     *   connect_timeout: float,
     *   allow_redirects: array{
     *     max: int,
     *     on_redirect: Closure(RequestInterface, ResponseInterface, UriInterface): void,
     *     strict?: bool,
     *     referer?: bool,
     *     protocols?: non-empty-array<array-key, string>,
     *     track_redirects?: bool
     *   },
     *   curl?: array<array-key, mixed>
     * }
     */
    protected function requestOptions(): array
    {
        $clientRedirects = $this->clientOption('allow_redirects');
        $clientOnRedirect = $clientRedirects['on_redirect'] ?? null;
        /** @var array{strict?: bool, referer?: bool, protocols?: non-empty-array<array-key, string>, track_redirects?: bool} $inherited Guzzle's documented shape of these client options. */
        $inherited = array_intersect_key($clientRedirects, array_flip(['strict', 'referer', 'protocols', 'track_redirects']));

        $options = [
            'timeout' => max($this->config['timeout'], 0),
            'connect_timeout' => max($this->config['connect_timeout'], 0),
            'allow_redirects' => [
                ...$inherited,
                'max' => $this->config['max_redirects'],
                'on_redirect' => function (
                    RequestInterface $request,
                    ResponseInterface $response,
                    UriInterface $uri
                ) use ($clientOnRedirect): void {
                    $this->hostValidator->validate((string) $uri);

                    if (is_callable($clientOnRedirect)) {
                        $clientOnRedirect($request, $response, $uri);
                    }
                },
            ],
        ];

        $curl = $this->clientOption('curl');
        $readTimeout = $this->config['read_timeout'];
        if ($readTimeout > 0) {
            // Not a spread: it would renumber the integer CURLOPT_* keys.
            $curl = [
                \CURLOPT_LOW_SPEED_LIMIT => 1,
                \CURLOPT_LOW_SPEED_TIME => max(1, (int) ceil($readTimeout)),
            ] + $curl;
        }

        if ($curl !== []) {
            $options['curl'] = $curl;
        }

        return $options;
    }

    /**
     * An array option of the client's config ([] when absent or not an
     * array, and before the default client is built).
     *
     * @return array<array-key, mixed>
     */
    protected function clientOption(string $name): array
    {
        $value = $this->client?->getConfig($name);

        return is_array($value) ? $value : [];
    }

    /**
     * The HTTP client, building the default one on first use: a cache that
     * only serves storage disks or hot entries never pays for it.
     */
    protected function client(): Client
    {
        return $this->client ??= $this->makeClient();
    }

    /**
     * Create the default Guzzle HTTP client.
     *
     * Timeouts and redirect handling are not client defaults: requestOptions()
     * applies them to every request, and clientOption() would otherwise read
     * them back from this client and chain our on_redirect onto itself.
     */
    protected function makeClient(): Client
    {
        return new Client([
            'http_errors' => false,
            'headers' => [
                'User-Agent' => $this->config['user_agent'],
            ],
        ]);
    }
}
