<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\blitz\drivers\generators;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Loop;
use Amp\Sync\LocalSemaphore;
use Craft;
use Exception;
use putyourlightson\blitz\Blitz;
use putyourlightson\blitz\helpers\CacheGeneratorHelper;

use function Amp\Iterator\fromIterable;

/**
 * This generator makes concurrent HTTP requests to generate each individual
 * site URI. It adds a token with a generate action route to requests only if
 * we don't clear on refresh, so that pages that are organically cached in the
 * meantime aren't unnecessarily regenerated.
 *
 * The Amp PHP framework is used for making HTTP requests and a concurrent
 * iterator is used to pool and send the requests concurrently.
 * See https://amphp.org/http-client/concurrent
 * and https://amphp.org/sync/concurrent-iterator
 *
 * @property-read null|string $settingsHtml
 */
class HttpGenerator extends BaseCacheGenerator
{
    /**
     * @var int
     */
    public int $concurrency = 3;

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('blitz', 'HTTP Generator');
    }

    /**
     * @inheritdoc
     */
    public function generateUris(array $siteUris, callable $setProgressHandler = null, bool $queue = true)
    {
        $siteUris = $this->beforeGenerateCache($siteUris);

        if ($queue) {
            CacheGeneratorHelper::addGeneratorJob($siteUris, 'generateUrisWithProgress');
        }
        else {
            $this->generateUrisWithProgress($siteUris, $setProgressHandler);
        }

        $this->afterGenerateCache($siteUris);
    }

    /**
     * Generates site URIs with progress.
     */
    public function generateUrisWithProgress(array $siteUris, callable $setProgressHandler = null)
    {
        $urls = $this->getUrlsToGenerate($siteUris);

        // Event loop for running concurrent requests
        // https://amphp.org/http-client/
        Loop::run(function() use ($urls, $setProgressHandler) {
            $count = 0;
            $total = count($urls);

            $client = HttpClientBuilder::buildDefault();

            try {
                // Approach 4: Concurrent Iterator
                // Yield the promise so we can later catch exceptions.
                // https://amphp.org/sync/concurrent-iterator#approach-4-concurrent-iterator
                yield \Amp\Sync\ConcurrentIterator\each(
                    fromIterable($urls),
                    new LocalSemaphore($this->concurrency),
                    function (string $url) use ($setProgressHandler, &$count, $total, $client) {
                        $count++;

                        /** @var Response $response */
                        $response = yield $client->request(new Request($url));

                        if ($response->getStatus() == 200) {
                            $this->generated++;
                        }
                        else {
                            Blitz::$plugin->debug('{status} error: {reason}', ['status' => $response->getStatus(), 'reason' => $response->getReason()], $url);
                        }

                        if (is_callable($setProgressHandler)) {
                            $progressLabel = Craft::t('blitz', 'Generating {count} of {total} pages.', ['count' => $count, 'total' => $total]);
                            call_user_func($setProgressHandler, $count, $total, $progressLabel);
                        }
                    }
                );
            }
            // Catch all possible exceptions, thrown only outside the yielded iterator
            catch (Exception $exception) {
                Blitz::$plugin->debug($exception->getMessage());
            }
        });
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('blitz/_drivers/generators/http/settings', [
            'generator' => $this,
        ]);
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return [
            [['concurrency'], 'required'],
            [['concurrency'], 'integer', 'min' => 1, 'max' => 100],
        ];
    }
}
