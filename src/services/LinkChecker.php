<?php

namespace justinholtweb\appleseed\services;

use Craft;
use craft\base\Component;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\TransferStats;
use justinholtweb\appleseed\helpers\Honeypot;
use justinholtweb\appleseed\helpers\UrlGuard;
use justinholtweb\appleseed\helpers\UrlRefusedException;
use justinholtweb\appleseed\models\ScanResult;
use justinholtweb\appleseed\models\Settings;
use justinholtweb\appleseed\Plugin;

class LinkChecker extends Component
{
    private ?Client $_client = null;

    /** @var array<string, float> Per-domain timestamps for rate limiting */
    private array $_domainTimestamps = [];

    /** The status code the handler saw on the last request, which Guzzle may not have accepted */
    private ?int $_handlerStatusCode = null;

    public function checkUrl(string $url): ScanResult
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        // Never request a honeypot trap -- it bans the scanning server's address on any method,
        // HEAD included, and every check after that comes back as a 403
        if (Honeypot::isTrapUrl($url) || $settings->matchesIgnorePattern($url)) {
            return new ScanResult(
                url: $url,
                status: 'ignored',
            );
        }

        // Never request an internal address an editor typed into content — see UrlGuard.
        if (($refusal = UrlGuard::refusal($url)) !== null) {
            return new ScanResult(
                url: $url,
                status: 'ignored',
                errorMessage: $refusal,
            );
        }

        // Rate limit per domain
        $this->_rateLimitForDomain($url, $settings->rateLimitPerSecond);

        $client = $this->_getClient($settings);
        $lastException = null;
        $maxRetries = $settings->maxRetries;

        // Single sign-on redirects (iModules and the like) set a cookie and redirect back to the
        // same URL, looping until the redirect limit without one. A fresh jar per URL keeps one
        // site's cookies from reaching another check.
        $options = [
            RequestOptions::COOKIES => new CookieJar(),
            // Guzzle refuses to build a response with a status outside 100-599 (LinkedIn answers
            // bots with 999), so keep the code the handler saw
            RequestOptions::ON_STATS => $this->_recordHandlerStatusCode(...),
        ];

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            if ($attempt > 0) {
                // Exponential backoff: 1s, 2s, 4s, ...
                $delay = (int) pow(2, $attempt - 1);
                sleep($delay);
            }

            try {
                // Try HEAD first
                $this->_handlerStatusCode = null;
                $response = $client->head($url, $options);
                $statusCode = $response->getStatusCode();

                // If HEAD returns 405/400/403, some servers don't support HEAD -- try GET
                if (in_array($statusCode, [405, 400, 403], true)) {
                    $response = $client->get($url, $options);
                }

                return $this->_buildResult($url, $response);
            } catch (UrlRefusedException $e) {
                // Refused at a redirect hop: there is nothing to retry.
                return new ScanResult(
                    url: $url,
                    status: 'ignored',
                    errorMessage: $e->getMessage(),
                );
            } catch (ConnectException $e) {
                $lastException = $e;
            } catch (\Throwable $e) {
                $rawStatusCode = $this->_getHandlerStatusCode();
                if ($rawStatusCode !== null && $rawStatusCode >= 600) {
                    // A non-standard status is a server turning the checker away, not a dead link
                    return new ScanResult(
                        url: $url,
                        status: 'blocked',
                        statusCode: $rawStatusCode,
                        errorMessage: "The server answered with non-standard status {$rawStatusCode}, which sites such as LinkedIn use to turn away automated requests. Check this link in a browser.",
                    );
                }

                $lastException = $e;
            }
        }

        // All retries failed (connection errors only -- HTTP errors are handled above)
        return $this->_buildErrorResult($url, $lastException);
    }

    private function _buildResult(string $url, \Psr\Http\Message\ResponseInterface $response): ScanResult
    {
        $statusCode = $response->getStatusCode();

        // Detect redirects via Guzzle's track_redirects
        $redirectHistory = $response->getHeader('X-Guzzle-Redirect-History');
        $redirectStatusHistory = $response->getHeader('X-Guzzle-Redirect-Status-History');
        $redirectChain = !empty($redirectHistory) ? $redirectHistory : null;
        $finalUrl = !empty($redirectHistory) ? end($redirectHistory) : null;

        if (($blockedBy = $this->_botProtection($response)) !== null) {
            return new ScanResult(
                url: $url,
                status: 'blocked',
                statusCode: $statusCode,
                redirectUrl: $finalUrl,
                redirectChain: $redirectChain,
                errorMessage: "{$blockedBy} challenged the request as a bot, so the link could not be checked. Check it in a browser.",
            );
        }

        // Classify
        if (!empty($redirectHistory)) {
            $status = 'redirect';
        } elseif ($statusCode >= 200 && $statusCode < 300) {
            $status = 'working';
        } elseif ($statusCode >= 400 && $statusCode < 500) {
            $status = 'broken';
        } elseif ($statusCode >= 500) {
            $status = 'server_error';
        } else {
            $status = 'working';
        }

        return new ScanResult(
            url: $url,
            status: $status,
            statusCode: $statusCode,
            redirectUrl: $finalUrl,
            redirectChain: $redirectChain,
        );
    }

    private function _getHandlerStatusCode(): ?int
    {
        return $this->_handlerStatusCode;
    }

    private function _recordHandlerStatusCode(TransferStats $stats): void
    {
        $code = $stats->getHandlerStat('http_code');
        $this->_handlerStatusCode = is_numeric($code) ? (int) $code : null;
    }

    /**
     * Names the bot protection that answered instead of the site, or null for a real response.
     * A challenge only proves the checker isn't a browser -- it says nothing about the link.
     */
    private function _botProtection(\Psr\Http\Message\ResponseInterface $response): ?string
    {
        $statusCode = $response->getStatusCode();
        if ($statusCode !== 403 && $statusCode !== 503) {
            return null;
        }

        if (strtolower($response->getHeaderLine('cf-mitigated')) === 'challenge') {
            return 'Cloudflare';
        }

        // Akamai's "Access Denied" page links its reference number to errors.edgesuite.net
        if ($statusCode === 403) {
            $body = $response->getBody();
            if ($body->isSeekable()) {
                $body->rewind();
            }
            // The page entity-encodes its text, dots included
            if (str_contains(html_entity_decode($body->read(4096), ENT_QUOTES | ENT_HTML5), 'errors.edgesuite.net')) {
                return 'Akamai';
            }
        }

        return null;
    }

    private function _buildErrorResult(string $url, ?\Throwable $exception): ScanResult
    {
        $message = $exception?->getMessage() ?? 'Unknown error';

        // Classify connection errors
        if ($exception instanceof ConnectException) {
            $errorMsg = strtolower($message);
            if (str_contains($errorMsg, 'timed out') || str_contains($errorMsg, 'timeout')) {
                return new ScanResult(
                    url: $url,
                    status: 'timeout',
                    errorMessage: $message,
                );
            }
            if (str_contains($errorMsg, 'could not resolve') || str_contains($errorMsg, 'name or service not known')) {
                return new ScanResult(
                    url: $url,
                    status: 'dns_error',
                    errorMessage: $message,
                );
            }
        }

        return new ScanResult(
            url: $url,
            status: 'broken',
            errorMessage: $message,
        );
    }

    private function _rateLimitForDomain(string $url, float $rateLimit): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return;
        }

        $now = microtime(true);
        $minInterval = 1.0 / $rateLimit;

        if (isset($this->_domainTimestamps[$host])) {
            $elapsed = $now - $this->_domainTimestamps[$host];
            if ($elapsed < $minInterval) {
                $sleepMs = (int) (($minInterval - $elapsed) * 1_000_000);
                usleep($sleepMs);
            }
        }

        $this->_domainTimestamps[$host] = microtime(true);
    }

    private function _getClient(Settings $settings): Client
    {
        if ($this->_client === null) {
            $this->_client = Craft::createGuzzleClient([
                RequestOptions::TIMEOUT => $settings->timeout,
                RequestOptions::CONNECT_TIMEOUT => $settings->timeout,
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::ALLOW_REDIRECTS => [
                    'max' => 10,
                    'track_redirects' => true,
                    // A public URL that redirects to an internal one is refused at the hop, not
                    // followed: the check above only saw the first address.
                    'on_redirect' => [UrlGuard::class, 'onRedirect'],
                ],
                RequestOptions::VERIFY => true,
                RequestOptions::HEADERS => [
                    'User-Agent' => $settings->getUserAgentParsed(),
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ],
            ]);
        }

        return $this->_client;
    }
}
