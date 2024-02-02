<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\blitz\console\controllers;

use Craft;
use craft\helpers\Console;
use putyourlightson\blitz\Blitz;
use putyourlightson\blitz\helpers\DiagnosticsHelper;
use putyourlightson\blitz\helpers\SiteUriHelper;
use putyourlightson\blitz\models\SiteUriModel;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\BaseConsole;

class CacheController extends Controller
{
    /**
     * @var bool Whether jobs should be queued only and not run
     */
    public bool $queue = false;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'queue';

        return $options;
    }

    /**
     * @inheritdoc
     */
    public function getHelp(): string
    {
        return 'Blitz actions.';
    }

    /**
     * @inheritdoc
     */
    public function getHelpSummary(): string
    {
        return $this->getHelp();
    }

    /**
     * Deletes all cached pages.
     */
    public function actionClear(): int
    {
        $this->_clearCache();

        return ExitCode::OK;
    }

    /**
     * Deletes all the cached pages in the selected site.
     *
     * @since 4.11.0
     */
    public function actionClearSite(int $siteId = null): int
    {
        if (empty($siteId)) {
            $this->stderr(Craft::t('blitz', 'A site ID must be provided as an argument.') . PHP_EOL, BaseConsole::FG_RED);

            return ExitCode::OK;
        }

        Blitz::$plugin->clearCache->clearSite($siteId);

        $this->stdout(Craft::t('blitz', 'Site successfully cleared.') . PHP_EOL, BaseConsole::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Deletes cached pages with the provided URLs (the `*` wildcard is supported).
     *
     * @since 4.11.0
     */
    public function actionClearUrls(array $urls = []): int
    {
        if (empty($urls)) {
            $this->stderr(Craft::t('blitz', 'One or more URLs must be provided as an argument.') . PHP_EOL, BaseConsole::FG_RED);

            return ExitCode::OK;
        }

        Blitz::$plugin->clearCache->clearCachedUrls($urls);

        $this->stdout(Craft::t('blitz', 'Cached URLs successfully cleared.') . PHP_EOL, BaseConsole::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Deletes cached pages with the provided tags.
     *
     * @since 4.11.0
     */
    public function actionClearTagged(array $tags = []): int
    {
        if (empty($tags)) {
            $this->stderr(Craft::t('blitz', 'One or more tags must be provided as an argument.') . PHP_EOL, BaseConsole::FG_RED);

            return ExitCode::OK;
        }

        Blitz::$plugin->clearCache->clearCacheTags($tags);

        $this->stdout(Craft::t('blitz', 'Tagged cache successfully cleared.') . PHP_EOL, BaseConsole::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Deletes all cache records from the database.
     */
    public function actionFlush(): int
    {
        $this->_flushCache();

        return ExitCode::OK;
    }

    /**
     * Generates all the cacheable pages.
     */
    public function actionGenerate(): int
    {
        if (!Blitz::$plugin->settings->cachingEnabled) {
            $this->stderr(Craft::t('blitz', 'Blitz caching is disabled.') . PHP_EOL, BaseConsole::FG_RED);

            return ExitCode::OK;
        }

        $this->_generateCache(SiteUriHelper::getAllSiteUris());

        return ExitCode::OK;
    }

    /**
     * Deletes all cached pages in the reverse proxy.
     */
    public function actionPurge(): int
    {
        $this->_purgeCache();

        return ExitCode::OK;
    }

    /**
     * Deploys all cached files to the remote location.
     */
    public function actionDeploy(): int
    {
        if (!Blitz::$plugin->settings->cachingEnabled) {
            $this->stderr(Craft::t('blitz', 'Blitz caching is disabled.') . PHP_EOL, BaseConsole::FG_RED);

            return ExitCode::OK;
        }

        $this->_deploy(SiteUriHelper::getAllSiteUris());

        return ExitCode::OK;
    }

    /**
     * Refreshes all the pages according to the “Refresh Mode”.
     */
    public function actionRefresh(): int
    {
        $generateOnRefresh = Blitz::$plugin->settings->generateOnRefresh();

        // Get site URIs to generate before flushing the cache
        if ($generateOnRefresh) {
            $siteUris = array_merge(
                SiteUriHelper::getAllSiteUris(),
                Blitz::$plugin->settings->getCustomSiteUris(),
            );
        }

        if (Blitz::$plugin->settings->clearOnRefresh()) {
            // Release jobs, since we’re anyway clearing the cache.
            Blitz::$plugin->refreshCache->releaseJobs();

            $this->_clearCache();
            $this->_flushCache(null, true);
            $this->_purgeCache();
        }

        if (Blitz::$plugin->settings->expireOnRefresh()) {
            $this->_expireCache();
        }

        if ($generateOnRefresh) {
            $this->_generateCache($siteUris);
            $this->_deploy($siteUris);
        }

        if (Blitz::$plugin->settings->purgeAfterRefresh()) {
            $this->_purgeCache();
        }

        return ExitCode::OK;
    }

    /**
     * Refreshes pages that have expired since they were cached.
     */
    public function actionRefreshExpired(): int
    {
        Blitz::$plugin->refreshCache->refreshExpiredCache();

        if (!$this->queue) {
            Craft::$app->runAction('queue/run');
        }

        DiagnosticsHelper::updateDriverDataAction('refresh-expired-cli');

        $this->stdout(Craft::t('blitz', 'Expired Blitz cache successfully refreshed.') . PHP_EOL, BaseConsole::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Refreshes all the pages in the selected site.
     */
    public function actionRefreshSite(int $siteId = null): int
    {
        if (empty($siteId)) {
            $this->stderr(Craft::t('blitz', 'A site ID must be provided as an argument.') . PHP_EOL, BaseConsole::FG_RED);

            return ExitCode::OK;
        }

        // Get site URIs to generate before flushing the cache
        $siteUris = SiteUriHelper::getSiteUrisForSite($siteId, true);

        foreach (Blitz::$plugin->settings->getCustomSiteUris() as $customSiteUri) {
            if ($customSiteUri['siteId'] == $siteId) {
                $siteUris[] = $customSiteUri;
            }
        }

        if (Blitz::$plugin->settings->clearOnRefresh()) {
            $this->_clearCache($siteUris);
            $this->_flushCache($siteUris, true);
            $this->_purgeCache($siteUris);
        }

        if (Blitz::$plugin->settings->expireOnRefresh()) {
            $this->_expireCache($siteUris);
        }

        if (Blitz::$plugin->settings->generateOnRefresh()) {
            $this->_generateCache($siteUris);
            $this->_deploy($siteUris);
        }

        if (Blitz::$plugin->settings->purgeAfterRefresh()) {
            $this->_purgeCache($siteUris);
        }

        if (!$this->queue) {
            Craft::$app->runAction('queue/run');
        }

        $this->stdout(Craft::t('blitz', 'Site successfully refreshed.') . PHP_EOL, BaseConsole::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Refreshes cached pages with the provided URLs (the `*` wildcard is supported).
     */
    public function actionRefreshUrls(array $urls = []): int
    {
        if (empty($urls)) {
            $this->stderr(Craft::t('blitz', 'One or more URLs must be provided as an argument.') . PHP_EOL, BaseConsole::FG_RED);

            return ExitCode::OK;
        }

        Blitz::$plugin->refreshCache->refreshCachedUrls($urls);

        if (!$this->queue) {
            Craft::$app->runAction('queue/run');
        }

        $this->stdout(Craft::t('blitz', 'Cached URLs successfully refreshed.') . PHP_EOL, BaseConsole::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Refreshes cached pages with the provided tags.
     */
    public function actionRefreshTagged(array $tags = []): int
    {
        if (empty($tags)) {
            $this->stderr(Craft::t('blitz', 'One or more tags must be provided as an argument.') . PHP_EOL, BaseConsole::FG_RED);

            return ExitCode::OK;
        }

        Blitz::$plugin->refreshCache->refreshCacheTags($tags);

        if (!$this->queue) {
            Craft::$app->runAction('queue/run');
        }

        $this->stdout(Craft::t('blitz', 'Tagged cache successfully refreshed.') . PHP_EOL, BaseConsole::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Generates and stores entry expiry dates.
     */
    public function actionGenerateExpiryDates(): int
    {
        Blitz::$plugin->refreshCache->generateExpiryDates();

        $this->stdout(Craft::t('blitz', 'Entry expiry dates successfully generated.') . PHP_EOL, BaseConsole::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Handles setting the progress.
     */
    public function setProgressHandler(int $count, int $total): void
    {
        Console::updateProgress($count, $total);
    }

    private function _clearCache(array $siteUris = null): void
    {
        if ($siteUris !== null) {
            Blitz::$plugin->clearCache->clearUris($siteUris);
        } else {
            Blitz::$plugin->clearCache->clearAll();
        }

        $this->_output('Blitz cache successfully cleared.');
    }

    private function _expireCache(array $siteUris = null): void
    {
        if ($siteUris !== null) {
            Blitz::$plugin->expireCache->expireUris($siteUris);
        } else {
            Blitz::$plugin->expireCache->expireAll();
        }

        $this->_output('Blitz cache successfully marked as expired.');
    }

    private function _flushCache(array $siteUris = null, bool $afterClear = false): void
    {
        if ($siteUris !== null) {
            Blitz::$plugin->flushCache->flushUris($siteUris);
        } else {
            Blitz::$plugin->flushCache->flushAll($afterClear);
        }

        $this->_output('Blitz cache successfully flushed.');
    }

    private function _purgeCache(array $siteUris = null): void
    {
        if (Blitz::$plugin->cachePurger->isDummy) {
            $this->stderr(Craft::t('blitz', 'Cache purging is disabled.') . PHP_EOL, BaseConsole::FG_GREEN);

            return;
        }

        if ($this->queue) {
            if ($siteUris !== null) {
                Blitz::$plugin->cachePurger->purgeUris($siteUris, [$this, 'setProgressHandler']);
            } else {
                Blitz::$plugin->cachePurger->purgeAll([$this, 'setProgressHandler']);
            }

            $this->_output('Blitz cache queued for purging.');

            return;
        }

        $this->stdout(Craft::t('blitz', 'Purging cache...') . PHP_EOL, BaseConsole::FG_YELLOW);

        if ($siteUris !== null) {
            Console::startProgress(0, count($siteUris), '', 0.8);
            Blitz::$plugin->cachePurger->purgeUris($siteUris, [$this, 'setProgressHandler'], false);
            Console::endProgress();
        } else {
            Blitz::$plugin->cachePurger->purgeAll([$this, 'setProgressHandler'], false);
        }

        $this->_output('Purging complete.');
    }

    /**
     * @param SiteUriModel[] $siteUris
     */
    private function _generateCache(array $siteUris): void
    {
        $siteUris = array_merge($siteUris, Blitz::$plugin->settings->getCustomSiteUris());

        if ($this->queue) {
            Blitz::$plugin->cacheGenerator->generateUris($siteUris, [$this, 'setProgressHandler']);
            $this->_output('Blitz cache queued for generation.');

            return;
        }

        $this->stdout(Craft::t('blitz', 'Generating Blitz cache...') . PHP_EOL, BaseConsole::FG_YELLOW);

        Console::startProgress(0, count($siteUris), '', 0.8);
        Blitz::$plugin->cacheGenerator->generateUris($siteUris, [$this, 'setProgressHandler'], false);
        Console::endProgress();

        $generated = Blitz::$plugin->cacheGenerator->generated;
        $total = count($siteUris);

        if ($generated < $total) {
            $this->stdout(Craft::t('blitz', 'Generated {generated} of {total} total possible pages and includes. To see why some pages were not cached, enable the `debug` config setting and then open the `storage/logs/blitz.log` file.', ['generated' => $generated, 'total' => $total]) . PHP_EOL, BaseConsole::FG_CYAN);
        }

        $this->_output('Blitz cache generation complete.');
    }

    /**
     * @param SiteUriModel[] $siteUris
     */
    private function _deploy(array $siteUris): void
    {
        if (Blitz::$plugin->deployer->isDummy) {
            $this->stderr(Craft::t('blitz', 'Deploying is disabled.') . PHP_EOL, BaseConsole::FG_GREEN);

            return;
        }

        $siteUris = array_merge($siteUris, Blitz::$plugin->settings->getCustomSiteUris());

        if ($this->queue) {
            Blitz::$plugin->deployer->deployUris($siteUris, [$this, 'setProgressHandler']);
            $this->_output('Blitz cache queued for deploying.');

            return;
        }

        $this->stdout(Craft::t('blitz', 'Deploying pages...') . PHP_EOL, BaseConsole::FG_YELLOW);

        Console::startProgress(0, count($siteUris), '', 0.8);
        Blitz::$plugin->deployer->deployUris($siteUris, [$this, 'setProgressHandler'], false);
        Console::endProgress();

        $this->_output('Deploying complete.');
    }

    /**
     * Logs and outputs a message to the console.
     */
    private function _output(string $message): void
    {
        Blitz::$plugin->log($message);

        $this->stdout(Craft::t('blitz', $message) . PHP_EOL, BaseConsole::FG_GREEN);
    }
}
