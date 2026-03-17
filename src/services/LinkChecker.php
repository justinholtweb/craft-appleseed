<?php

namespace justinholtweb\appleseed\services;

use Craft;
use craft\base\Component;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\RequestOptions;
use justinholtweb\appleseed\models\ScanResult;
use justinholtweb\appleseed\models\Settings;
use justinholtweb\appleseed\Plugin;

class LinkChecker extends Component
{
    private ?Client $_client = null;

    /** @var array<string, float> Per-domain timestamps for rate limiting */
    private array $_domainTimestamps = [];

    public function checkUrl(string $url): ScanResult
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        // Check ignore patterns
        foreach ($settings->getIgnorePatternsArray() as $pattern) {
            try {
                if (preg_match($pattern, $url)) {
                    return new ScanResult(
                        url: $url,
                        status: 'ignored',
                    );
                }
            } catch (\Throwable) {
                // Invalid regex, skip
            }
        }

        // Rate limit per domain
        $this->_rateLimitForDomain($url, $settings->rateLimitPerSecond);

        $client = $this->_getClient($settings);
        $lastException = null;
        $maxRetries = $settings->maxRetries;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            if ($attempt > 0) {
                // Exponential backoff: 1s, 2s, 4s, ...
                $delay = (int) pow(2, $attempt - 1);
                sleep($delay);
            }

            try {
                // Try HEAD first
                $response = $client->head($url);
                $statusCode = $response->getStatusCode();

                // If HEAD returns 405/400/403, some servers don't support HEAD -- try GET
                if (in_array($statusCode, [405, 400, 403])) {
                    $response = $client->get($url);
                }

                return $this->_buildResult($url, $response);
            } catch (ConnectException $e) {
                $lastException = $e;
            } catch (\Throwable $e) {
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
