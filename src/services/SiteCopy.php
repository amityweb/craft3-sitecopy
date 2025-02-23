<?php
/**
 * @link      https://www.goldinteractive.ch
 * @copyright Copyright (c) 2018 Gold Interactive
 */

namespace goldinteractive\sitecopy\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\elements\Entry;
use craft\commerce\elements\Product;
use craft\events\ElementEvent;
use craft\models\Site;
use yii\base\Event;

/**
 * Class SiteCopy
 *
 * @package goldinteractive\sitecopy\services
 */
class SiteCopy extends Component
{
    public static function getCriteriaFields()
    {
        return [
            [
                'value' => 'id',
                'label' => Craft::t('sitecopy', 'Entry id'),
            ],
            [
                'value' => 'type',
                'label' => Craft::t('sitecopy', 'Entry type (handle)'),
            ],
            [
                'value' => 'section',
                'label' => Craft::t('sitecopy', 'Section (handle)'),
            ],
            [
                'value' => 'site',
                'label' => Craft::t('sitecopy', 'Site (handle)'),
            ],
        ];
    }

    public static function getOperators()
    {
        return [
            [
                'value' => 'eq',
                'label' => Craft::t('sitecopy', 'Equals'),
            ],
            [
                'value' => 'neq',
                'label' => Craft::t('sitecopy', 'Does not equal'),
            ],
        ];
    }

    /**
     * Indicates if we are already syncing
     *
     * @var bool
     */
    private static $syncing = false;

    /**
     * Get list of sites to sync to.
     *
     * @param array $sites
     * @param array $exclude
     * @return array
     */
    public function getSiteInputOptions(array $sites = [], $exclude = [])
    {
        $sites = $sites ?: Craft::$app->getSites()->getAllSites();
        $sites = array_map(
            function ($site) use ($exclude) {
                if (!$site instanceof Site) {
                    $siteId = $site['siteId'] ?? $site ?? null;
                    if ($siteId !== null) {
                        $site = Craft::$app->sites->getSiteById($siteId);
                    }
                }

                if ($site instanceof Site && !in_array($site->id, $exclude)) {
                    $site = [
                        'label' => $site->name,
                        'value' => $site->id,
                    ];
                } else {
                    $site = null;
                }

                return $site;
            },
            $sites
        );

        return array_filter($sites);
    }

    /**
     * @param ElementEvent $event
     * @param array        $elementSettings
     * @throws \Throwable
     */
    public function syncElementContent(ElementEvent $event, array $elementSettings)
    {
        /** @var Entry $entry */
        $entry = $event->element;

        if (!$entry instanceof Entry && !$entry instanceof Product) {
            return;
        }

        if (self::$syncing) {
            return;
        }

        self::$syncing = true;

        // elementSettings will be null in HUD, where we want to continue with defaults
        if ($elementSettings !== null && ($event->isNew || empty($elementSettings['enabled']))) {
            return;
        }

        $supportedSites = $entry->getSupportedSites();

        $targets = $elementSettings['targets'] ?? [];

        if (!is_array($targets)) {
            $targets = [$targets];
        }

        foreach ($supportedSites as $supportedSite) {
            $siteId = $supportedSite['siteId'];
            if(!$siteId)
            {
            	$siteId = $supportedSite; // For Products as no siteId key exists
            }
            $siteElement = Craft::$app->elements->getElementById(
                $entry->id,
                null,
                $siteId
            );
            $matchingTarget = $targets === '*' || in_array($siteId, $targets);

            if ($siteElement && $matchingTarget && $entry->siteId !== $siteId) {
                $fieldsLocation = Craft::$app->getRequest()->getParam('fieldsLocation', 'fields');
                $siteElement->setFieldValuesFromRequest($fieldsLocation);

                Craft::$app->elements->saveElement($siteElement);
            }
        }

        self::$syncing = false;
    }

    /**
     * @param Entry $element
     * @return array
     */
    public function handleSiteCopyActiveState(craft\elements\Entry $element)
    {
        $siteCopyEnabled = false;
        $selectedSite = null;

        $settings = $this->getCombinedSettings();

        foreach ($settings['settings'] as $setting) {
            $criteriaField = $setting[0] ?? null;
            $operator = $setting[1] ?? null;
            $value = $setting[2] ?? null;
            $sourceId = $setting[3] ?? null;
            $targetId = $setting[4] ?? null;

            if (!empty($criteriaField) && !empty($operator) && !empty($value) && !empty($sourceId) && !empty($targetId)) {
                if (($sourceId != '*' && (int)$sourceId != $element->siteId) || ($criteriaField !== 'typeHandle' && !$element->hasProperty($criteriaField))) {
                    continue;
                }

                $checkFrom = false;

                if ($criteriaField === 'id') {
                    $checkFrom = $element->id;
                } elseif (isset($element[$criteriaField]['handle'])) {
                    $checkFrom = $element[$criteriaField]['handle'];
                }

                $check = false;

                if ($operator === 'eq') {
                    $check = $checkFrom == $value;
                } elseif ($operator === 'neq') {
                    $check = $checkFrom != $value;
                }

                if ($check && (int)$targetId !== $element->siteId) {
                    $siteCopyEnabled = true;
                    $selectedSite = (int)$targetId;

                    if ($settings['method'] == 'or') {
                        break;
                    }
                } elseif ($settings['method'] == 'and' && (int)$targetId !== $element->siteId) {
                    // check failed, revert values to default
                    $siteCopyEnabled = false;
                    $selectedSite = null;

                    break;
                }
            }
        }

        return [
            'siteCopyEnabled' => $siteCopyEnabled,
            'selectedSite'    => $selectedSite,
        ];
    }

	public function handleSiteCopyActiveStateProduct(craft\commerce\elements\Product $element)
    {
        $siteCopyEnabled = false;
        $selectedSite = null;

        $settings = $this->getCombinedSettings();

        foreach ($settings['settings'] as $setting) {
            $criteriaField = $setting[0] ?? null;
            $operator = $setting[1] ?? null;
            $value = $setting[2] ?? null;
            $sourceId = $setting[3] ?? null;
            $targetId = $setting[4] ?? null;

            if (!empty($criteriaField) && !empty($operator) && !empty($value) && !empty($sourceId) && !empty($targetId)) {
                if (($sourceId != '*' && (int)$sourceId != $element->siteId) || ($criteriaField !== 'typeHandle' && !$element->hasProperty($criteriaField))) {
                    continue;
                }

                $checkFrom = false;

                if ($criteriaField === 'id') {
                    $checkFrom = $element->id;
                } elseif (isset($element[$criteriaField]['handle'])) {
                    $checkFrom = $element[$criteriaField]['handle'];
                }

                $check = false;

                if ($operator === 'eq') {
                    $check = $checkFrom == $value;
                } elseif ($operator === 'neq') {
                    $check = $checkFrom != $value;
                }

                if ($check && (int)$targetId !== $element->siteId) {
                    $siteCopyEnabled = true;
                    $selectedSite = (int)$targetId;

                    if ($settings['method'] == 'or') {
                        break;
                    }
                } elseif ($settings['method'] == 'and' && (int)$targetId !== $element->siteId) {
                    // check failed, revert values to default
                    $siteCopyEnabled = false;
                    $selectedSite = null;

                    break;
                }
            }
        }

        return [
            'siteCopyEnabled' => $siteCopyEnabled,
            'selectedSite'    => $selectedSite,
        ];
    }
    
    
    /**
     * @return array
     */
    public function getCombinedSettings()
    {
        $combinedSettings = [];

        // default set to or for backwards compatibility
        $combinedSettingsCheckMethod = 'or';

        $settings = \goldinteractive\sitecopy\SiteCopy::getInstance()->getSettings();

        if ($settings && isset($settings->combinedSettings) && is_array($settings->combinedSettings)) {
            $combinedSettings = $settings->combinedSettings;
        }

        if ($settings && isset($settings->combinedSettingsCheckMethod) && is_string($settings->combinedSettingsCheckMethod)) {
            $combinedSettingsCheckMethod = $settings->combinedSettingsCheckMethod;
        }

        return ['settings' => $combinedSettings, 'method' => $combinedSettingsCheckMethod];
    }
}
