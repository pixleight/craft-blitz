<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\blitz\records;

use craft\db\ActiveRecord;
use yii\db\ActiveQueryInterface;

/**
 * @property int $cacheId
 * @property string $tag
 * @property CacheRecord $cache
 */
class CacheTagRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     *
     * @return string
     */
    public static function tableName(): string
    {
        return '{{%blitz_cachetags}}';
    }

    /**
     * Returns the associated cache
     *
     * @return ActiveQueryInterface
     */
    public function getCache(): ActiveQueryInterface
    {
        return $this->hasOne(CacheRecord::class, ['id' => 'cacheId']);
    }
}
