<?php

namespace Symbiote\DataChange\Extension;

use Symbiote\DataChange\Service\DataChangeTrackService;
use Symbiote\DataChange\Model\DataChangeRecord;
use SilverStripe\ORM\DataExtension;
use SilverStripe\Core\Config\Config;

/**
 * Add to classes you want changes recorded for
 *
 * @author  marcus@symbiote.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class ChangeRecordable extends DataExtension
{

    /**
     *
     * @var DataChangeTrackService
     */
    public $dataChangeTrackService;

    private static $ignored_fields = [];

    protected $isNewObject = false;

    protected $changeType = 'Change';

    public function __construct()
    {
        parent::__construct();
    }

    public function onBeforeWrite()
    {
        $confirmSkipTracking = function ($record) {
            $changedFields = $record->getChangedFields(true, 2);

            foreach ($changedFields as $field => $change) {
                if ($field == 'SecurityID') {
                    continue;
                }
                $before[$field] = $change['before'];
                $after[$field] = $change['after'];
            }

            if (isset($before)
                && count($before) == 1
                && count($after) == 1
                && array_key_exists('Version', $before)
                && array_key_exists('Version', $after)
            ) {
                return true;
            }

            return false;
        };

        parent::onBeforeWrite();
        if ($this->owner->isInDB()) {
            if (!$confirmSkipTracking($this->owner)) {
                $this->dataChangeTrackService->track($this->owner, $this->changeType);
            }
        } else {
            $this->isNewObject = true;
            $this->changeType = 'New';
        }
    }

    public function onAfterWrite()
    {
        parent::onAfterWrite();
        if ($this->isNewObject) {
            $this->dataChangeTrackService->track($this->owner, $this->changeType);
            $this->isNewObject = false;
        }
    }

    public function onBeforeDelete()
    {
        parent::onBeforeDelete();
        $this->dataChangeTrackService->track($this->owner, 'Delete');
    }

    public function getIgnoredFields()
    {
        $ignored = Config::inst()->get(ChangeRecordable::class, 'ignored_fields');
        $class = $this->owner->ClassName;
        if (isset($ignored[$class])) {
            return array_combine($ignored[$class], $ignored[$class]);
        }
    }

    public function onBeforeVersionedPublish($from, $to)
    {
        if ($this->owner->isInDB()) {
            $this->dataChangeTrackService->track($this->owner, 'Publish ' . $from . ' to ' . $to);
        }
    }

    /**
     * Get the list of data changes for this item
     *
     * @return \SilverStripe\ORM\DataList
     */
    public function getDataChangesList()
    {
        return DataChangeRecord::get()->filter([
            'ChangeRecordID' => $this->owner->ID,
            'ChangeRecordClass' => $this->owner->ClassName,
        ]);
    }
}
