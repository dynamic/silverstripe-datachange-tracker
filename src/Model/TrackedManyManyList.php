<?php

namespace Symbiote\DataChange\Model;

use SilverStripe\ORM\DB;
use SilverStripe\ORM\ManyManyList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * A replacement manymany list that tracks add and remove calls
 *
 * @author marcus
 */
class TrackedManyManyList extends ManyManyList
{
    public $trackedRelationships = [];

    public function add($item, $extraFields = [])
    {
        $id = is_int($item) ? $item : $item->ID;

        $existingItem = $this->byID($id);
        $shouldRecordChange = false;

        if (!$existingItem) {
            $shouldRecordChange = true;
        } elseif (!empty($extraFields)) {
            $currentExtraData = $this->getExtraData($this->getJoinTable(), $item->ID);
            foreach ($extraFields as $field => $value) {
                if (!array_key_exists($field, $currentExtraData) || $currentExtraData[$field] !== $value) {
                    $shouldRecordChange = true;
                    break;
                }
            }
        }

        if ($shouldRecordChange) {
            $this->recordManyManyChange(__FUNCTION__, $item);
        }

        $result = parent::add($item, $extraFields);
        return $result;
    }

    public function remove($item)
    {
        $id = is_int($item) ? $item : $item->ID;
        $existingItem = $this->byID($id);

        // Only record the change if the item actually exists in the relationship
        if ($existingItem) {
            $this->recordManyManyChange(__FUNCTION__, $item);
        }

        $result = parent::remove($item);
        return $result;
    }

    public function setByIDList($idList)
    {
        // Only track changes if this relationship is in our tracked list
        $joinName = $this->getJoinTable();
        if (in_array($joinName, $this->trackedRelationships)) {
            // Get current IDs to determine what's being added/removed
            $currentIds = $this->getIDList();
            $newIds = array_filter($idList ?? []);

            // Find items being removed (in current but not in new)
            $removedIds = array_diff($currentIds, $newIds);

            // Find items being added (in new but not in current)
            $addedIds = array_diff($newIds, $currentIds);

            // Track removals
            foreach ($removedIds as $removedId) {
                $item = $this->dataClass()::get()->byID($removedId);
                if ($item) {
                    $this->recordManyManyChange('remove', $item);
                }
            }

            // Track additions
            foreach ($addedIds as $addedId) {
                $item = $this->dataClass()::get()->byID($addedId);
                if ($item) {
                    $this->recordManyManyChange('add', $item);
                }
            }
        }

        // Call the parent method to actually update the relationship
        return parent::setByIDList($idList);
    }

    protected function recordManyManyChange($type, $item)
    {
        $joinName = $this->getJoinTable();
        if (!in_array($joinName, $this->trackedRelationships)) {
            return;
        }
        $parts = explode('_', $joinName);
        if (isset($parts[0]) && count($parts) > 1) {
            // table name could be sometihng like Symbiote_DataChange_Tests_TestObject_Kids
            // which is ClassName_RelName, with
            $tableName = $parts;
            $relationName = array_pop($tableName);
            $tableName = implode('_', $tableName);

            $addingToClass = $this->tableClass($tableName);
            if (!$addingToClass) {
                return;
            }
            if (!class_exists($addingToClass)) {
                return;
            }
            $onItem = $addingToClass::get()->byID($this->getForeignID());
            if (!$onItem) {
                return;
            }
            if ($item && !($item instanceof DataObject)) {
                $class = $this->dataClass();
                $item = $class::get()->byID($item);
            }
            $join = $type === 'add' ? ' to ' : ' from ';
            $type = ucfirst($type) . ' "' . $item->Title . '"' . $join . $relationName;
            $onItem->RelatedItem = $item->ClassName . ' #' . $item->ID;
            $changeRecord = singleton('DataChangeTrackService')->track($onItem, $type);

            if ($changeRecord && $changeRecord->hasMethod('AffectedPages')) {
                foreach ($changeRecord->AffectedPages() as $page) {
                    if ($page && $page->isPublished() && $item->hasExtension(Versioned::class) && $item->isPublished()) {
                        // Update the LastEdited value for the SiteTree_Live record directly via SQL
                        DB::query(sprintf(
                            "UPDATE \"SiteTree_Live\" SET \"LastEdited\" = '%s' WHERE \"ID\" = %d",
                            $changeRecord->Created,
                            $page->ID
                        ));
                    }
                }
            }
        }
    }

    /**
     * Find the class for the given table.
     *
     * Stripped down version from framework that does not attempt to strip _Live and _versions postfixes as
     * that throws errors in its preg_match(). (At least it did as of 2018-06-22 on SilverStripe 4.1.1)
     *
     * @param string $table
     * @return string|null The FQN of the class, or null if not found
     */
    private function tableClass($table)
    {
        $tables = DataObject::getSchema()->getTableNames();
        $class = array_search($table, $tables, true);
        if ($class) {
            return $class;
        }
        return null;
    }
}
