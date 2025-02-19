<?php

namespace VersionPress\Database;

use VersionPress\DI\VersionPressServices;
use VersionPress\Utils\Cursor;
use VersionPress\Utils\IdUtil;
use VersionPress\Utils\ReferenceUtils;
use wpdb;

class VpidRepository {
    /** @var Database */
    private $database;
    /** @var DbSchemaInfo */
    private $schemaInfo;
    /** @var string */
    private $vpidTableName;

    public function __construct($database, DbSchemaInfo $schemaInfo) {
        $this->database = $database;
        $this->schemaInfo = $schemaInfo;
        $this->vpidTableName = $schemaInfo->getPrefixedTableName('vp_id');
    }

    /**
     * Returns VPID of entity of given type and id.
     *
     * @param $entityName
     * @param $id
     * @return null|string
     */
    public function getVpidForEntity($entityName, $id) {
        $tableName = $this->schemaInfo->getTableName($entityName);
        return $this->database->get_var("SELECT HEX(vp_id) FROM $this->vpidTableName WHERE id = '$id' AND `table` = '$tableName'");
    }

    public function getIdForVpid($vpid) {
        return intval($this->database->get_var("SELECT id FROM $this->vpidTableName WHERE vp_id = UNHEX('$vpid')"));
    }

    public function replaceForeignKeysWithReferences($entityName, $entity) {
        $entityInfo = $this->schemaInfo->getEntityInfo($entityName);

        foreach ($entityInfo->references as $referenceName => $targetEntity) {

            if (isset($entity[$referenceName])) {
                if ($this->isNullReference($entity[$referenceName])) {
                    $referenceVpids = 0;
                } else {
                    $referenceVpids = $this->replaceIdsInString($targetEntity, $entity[$referenceName]);
                }

                $entity['vp_' . $referenceName] = $referenceVpids;
                unset($entity[$referenceName]);
            }

        }

        foreach ($entityInfo->valueReferences as $referenceName => $targetEntity) {
            list($sourceColumn, $sourceValue, $valueColumn, $pathInStructure) = array_values(ReferenceUtils::getValueReferenceDetails($referenceName));

            if (isset($entity[$sourceColumn]) && $entity[$sourceColumn] == $sourceValue && isset($entity[$valueColumn])) {

                if ($this->isNullReference($entity[$valueColumn])) {
                    continue;
                }

                if ($targetEntity[0] === '@') {
                    $entityNameProvider = substr($targetEntity, 1);
                    $targetEntity = call_user_func($entityNameProvider, $entity);
                    if (!$targetEntity) {
                        continue;
                    }
                }

                if ($pathInStructure) {
                    $entity[$valueColumn] = unserialize($entity[$valueColumn]);
                    $paths = ReferenceUtils::getMatchingPaths($entity[$valueColumn], $pathInStructure);
                } else {
                    $paths = [[]]; // root = the value itself
                }

                /** @var Cursor[] $cursors */
                $cursors = array_map(function ($path) use (&$entity, $valueColumn) { return new Cursor($entity[$valueColumn], $path); }, $paths);

                foreach ($cursors as $cursor) {
                    $ids = $cursor->getValue();
                    $referenceVpids = $this->replaceIdsInString($targetEntity, $ids);
                    $cursor->setValue($referenceVpids);
                }

                if ($pathInStructure) {
                    $entity[$valueColumn] = serialize($entity[$valueColumn]);
                }
            }
        }

        return $entity;
    }

    public function restoreForeignKeys($entityName, $entity) {
        $entityInfo = $this->schemaInfo->getEntityInfo($entityName);

        foreach ($entityInfo->valueReferences as $referenceName => $targetEntity) {
            list($sourceColumn, $sourceValue, $valueColumn, $pathInStructure) = array_values(ReferenceUtils::getValueReferenceDetails($referenceName));

            if ($entity[$sourceColumn] === $sourceValue && isset($entity[$valueColumn])) {

                if ($this->isNullReference($entity[$valueColumn])) {
                    continue;
                }

                if ($pathInStructure) {
                    $entity[$valueColumn] = unserialize($entity[$valueColumn]);
                    $paths = ReferenceUtils::getMatchingPaths($entity[$valueColumn], $pathInStructure);
                } else {
                    $paths = [[]]; // root = the value itself
                }

                /** @var Cursor[] $cursors */
                $cursors = array_map(function ($path) use (&$entity, $valueColumn) { return new Cursor($entity[$valueColumn], $path); }, $paths);

                foreach ($cursors as $cursor) {
                    $vpids = $cursor->getValue();
                    $referenceVpId = $this->restoreIdsInString($vpids);
                    $cursor->setValue($referenceVpId);
                }

                if ($pathInStructure) {
                    $entity[$valueColumn] = serialize($entity[$valueColumn]);
                }
            }
        }

        return $entity;
    }

    public function identifyEntity($entityName, $data, $id) {
        if ($this->schemaInfo->getEntityInfo($entityName)->usesGeneratedVpids) {
            $data['vp_id'] = IdUtil::newId();
            $this->saveId($entityName, $id, $data['vp_id']);


            $data[$this->schemaInfo->getEntityInfo($entityName)->idColumnName] = $id;
        }
        $data = $this->fillId($entityName, $data, $id);

        return $data;
    }

    public function deleteId($entityName, $id) {
        $vpIdTableName = $this->schemaInfo->getPrefixedTableName('vp_id');
        $tableName = $this->schemaInfo->getTableName($entityName);
        $deleteQuery = "DELETE FROM $vpIdTableName WHERE `table` = \"$tableName\" AND id = '$id'";
        $this->database->query($deleteQuery);
    }

    private function saveId($entityName, $id, $vpId) {
        $vpIdTableName = $this->schemaInfo->getPrefixedTableName('vp_id');
        $tableName = $this->schemaInfo->getTableName($entityName);
        $query = "INSERT INTO $vpIdTableName (`vp_id`, `table`, `id`) VALUES (UNHEX('$vpId'), \"$tableName\", $id)";
        $this->database->query($query);
    }

    private function fillId($entityName, $data, $id) {
        $idColumnName = $this->schemaInfo->getEntityInfo($entityName)->idColumnName;
        if (!isset($data[$idColumnName])) {
            $data[$idColumnName] = $id;
        }
        return $data;
    }

    private function isNullReference($id) {
        return (is_numeric($id) && intval($id) === 0) || $id === '';
    }

    private function replaceIdsInString($targetEntity, $stringWithIds) {
        return preg_replace_callback('/(\d+)/', function ($match) use ($targetEntity) {
            return $this->getVpidForEntity($targetEntity, $match[0]) ?: $match[0];
        }, $stringWithIds);
    }

    private function restoreIdsInString($stringWithVpids) {
        $stringWithIds = preg_replace_callback(IdUtil::getRegexMatchingId(), function ($match) {
            return $this->getIdForVpid($match[0]) ?: $match[0];
        }, $stringWithVpids);

        return is_numeric($stringWithIds) ? intval($stringWithIds) : $stringWithIds;

    }

    /**
     * Function used in wordpress-schema.yml.
     * Maps menu item with given postmeta (_menu_item_object_id) to target entity (post/category/custom url).
     *
     * @param $postmeta
     * @return null|string
     */
    public static function getMenuReference($postmeta) {
        global $versionPressContainer;
        /** @var \VersionPress\Storages\StorageFactory $storageFactory */
        $storageFactory = $versionPressContainer->resolve(VersionPressServices::STORAGE_FACTORY);
        /** @var \VersionPress\Storages\PostMetaStorage $postmetaStorage */
        $postmetaStorage = $storageFactory->getStorage('postmeta');
        $menuItemTypePostmeta = $postmetaStorage->loadEntityByName('_menu_item_type', $postmeta['vp_post_id']);
        $menuItemType = $menuItemTypePostmeta['meta_value'];

        if ($menuItemType === 'taxonomy') {
            return 'term_taxonomy';
        }

        if ($menuItemType === 'post_type') {
            return 'post';
        }

        // Special case - reference to homepage (WP sets it as 'custom', but actually it is 'post_type')
        if ($menuItemType === 'custom' && is_numeric($postmeta['meta_value'])) {
            return 'post';
        }

        return null; // custom url or unknown target
    }
}
