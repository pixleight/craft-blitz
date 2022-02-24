<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\blitz\jobs;

use craft\helpers\App;
use craft\queue\BaseJob;
use putyourlightson\blitz\Blitz;
use putyourlightson\blitz\models\SiteUriModel;
use yii\queue\Queue;
use yii\queue\RetryableJobInterface;

/**
 * @property-read int $ttr
 */
class DriverJob extends BaseJob implements RetryableJobInterface
{
    /**
     * @var SiteUriModel[]
     */
    public array $siteUris;

    /**
     * @var string
     */
    public string $driverId;

    /**
     * @var string
     */
    public string $driverMethod;

    /**
     * @var int|null
     */
    public ?int $delay;

    /**
     * @var Queue
     */
    private Queue $_queue;

    /**
     * @inheritdoc
     */
    public function getTtr(): int
    {
        return Blitz::$plugin->settings->queueJobTtr;
    }

    /**
     * @inheritdoc
     */
    public function canRetry($attempt, $error): bool
    {
        return $attempt < Blitz::$plugin->settings->maxRetryAttempts;
    }

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        App::maxPowerCaptain();

        $this->_queue = $queue;

        // Get driver from ID
        $driver = Blitz::$plugin->get($this->driverId);

        if ($driver !== null && is_callable([$driver, $this->driverMethod])) {
            call_user_func([$driver, $this->driverMethod], $this->siteUris, [$this, 'setProgressHandler'], $this->delay);
        }
    }

    /**
     * Handles setting the progress.
     */
    public function setProgressHandler(int $count, int $total, string $label = null)
    {
        $progress = $total > 0 ? ($count / $total) : 0;

        $this->setProgress($this->_queue, $progress, $label);
    }
}
