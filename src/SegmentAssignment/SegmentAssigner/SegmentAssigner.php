<?php

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Enterprise License (PEL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PEL
 */

namespace CustomerManagementFrameworkBundle\SegmentAssignment\SegmentAssigner;

use CustomerManagementFrameworkBundle\Model\CustomerSegmentInterface;
use CustomerManagementFrameworkBundle\SegmentAssignment\TypeMapper\TypeMapperInterface;
use Pimcore\Db\Connection;
use Pimcore\Logger;
use Pimcore\Model\Element\ElementInterface;
use function PHPSTORM_META\elementType;

class SegmentAssigner implements SegmentAssignerInterface
{
    /**
     * @var Connection
     */
    private $db = null;

    /**
     * @var TypeMapperInterface
     */
    private $typeMapper = null;

    /**
     * @var string
     */
    private $segmentAssignmentTable = '';

    /**
     * @var string
     */
    private $segmentAssignmentQueueTable = '';

    /**
     * @var string
     */
    private $segmentAssignmentIndexTable = '';

    /**
     * @inheritDoc
     */
    public function __construct(string $segmentAssignmentTable, string $segmentAssignmentQueueTable, string $segmentAssignmentIndexTable, Connection $db, TypeMapperInterface $typeMapper)
    {
        $this->setSegmentAssignmentTable($segmentAssignmentTable);
        $this->setSegmentAssignmentQueueTable($segmentAssignmentQueueTable);
        $this->setSegmentAssignmentIndexTable($segmentAssignmentIndexTable);
        $this->setDb($db);
        $this->setTypeMapper($typeMapper);
    }

    /**
     * @return Connection
     */
    public function getDb(): Connection
    {
        return $this->db;
    }

    /**
     * @param Connection $db
     */
    public function setDb(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * @return TypeMapperInterface
     */
    public function getTypeMapper(): TypeMapperInterface
    {
        return $this->typeMapper;
    }

    /**
     * @param TypeMapperInterface $typeMapper
     */
    public function setTypeMapper(TypeMapperInterface $typeMapper)
    {
        $this->typeMapper = $typeMapper;
    }

    /**
     * @return string
     */
    public function getSegmentAssignmentTable(): string
    {
        return $this->segmentAssignmentTable;
    }

    /**
     * @param string $segmentAssignmentTable
     */
    public function setSegmentAssignmentTable(string $segmentAssignmentTable)
    {
        $this->segmentAssignmentTable = $segmentAssignmentTable;
    }

    /**
     * @return string
     */
    public function getSegmentAssignmentQueueTable(): string
    {
        return $this->segmentAssignmentQueueTable;
    }

    /**
     * @param string $segmentAssignmentQueueTable
     */
    public function setSegmentAssignmentQueueTable(string $segmentAssignmentQueueTable)
    {
        $this->segmentAssignmentQueueTable = $segmentAssignmentQueueTable;
    }

    /**
     * @return string
     */
    public function getSegmentAssignmentIndexTable(): string
    {
        return $this->segmentAssignmentIndexTable;
    }

    /**
     * @param string $segmentAssignmentIndexTable
     */
    public function setSegmentAssignmentIndexTable(string $segmentAssignmentIndexTable)
    {
        $this->segmentAssignmentIndexTable = $segmentAssignmentIndexTable;
    }

    /**
     * @inheritDoc
     */
    public function assign(ElementInterface $element, bool $breaksInheritance, array $segments): bool
    {
        $segmentIds = array_map(function ($segment) {
            return $segment instanceof CustomerSegmentInterface ? $segment->getId() : $segment;
        }, $segments);

        return $this->assignById($element->getId(), $this->getTypeMapper()->getTypeStringByObject($element), $breaksInheritance, $segmentIds);
    }

    /**
     * @inheritDoc
     */
    public function assignById(string $elementId, string $type, bool $breaksInheritance, array $segmentIds): bool
    {
        try {
            $formatArguments = [
                1 => $this->getSegmentAssignmentTable(),
                2 => $this->getSegmentAssignmentQueueTable(),
                3 => $elementId,
                4 => $type,
                5 => (int) $breaksInheritance,
                6 => join(',', $segmentIds)];

            $statement = vsprintf(
                'START TRANSACTION;'.
                'INSERT INTO `%1$s` (`elementId`, `elementType`, `breaksInheritance`, `segments`) VALUES (%3$s, "%4$s", %5$s, "%6$s") ON DUPLICATE KEY UPDATE `breaksInheritance` = %5$s, `segments` = "%6$s";'.
                'INSERT INTO `%2$s` (`elementId`, `elementType`) VALUES (%3$s, "%4$s") ON DUPLICATE KEY UPDATE `elementId` = `elementId`;'.
                'COMMIT;', $formatArguments);

            $this->getDb()->query($statement);
            $this->enqueueChildren($elementId, $type);

            return true;
        } catch (\Throwable $exception) {
            Logger::error($exception->getMessage());

            return false;
        }
    }

    /**
     * @inheritdoc
     */
    public function removeElementById(string $elementId, string $type): bool
    {
        try {
            $deletePattern = 'DELETE FROM %s WHERE `elementId` = %s AND `elementType` = "%s";';

            $formatArguments = [
                1 => sprintf($deletePattern, $this->getSegmentAssignmentTable(), $elementId, $type),
                2 => sprintf($deletePattern, $this->getSegmentAssignmentQueueTable(), $elementId, $type),
                3 => sprintf($deletePattern, $this->getSegmentAssignmentIndexTable(), $elementId, $type),
            ];

            $statement = vsprintf('START TRANSACTION;' .
                '%1$s' .
                '%2$s' .
                '%3$s' .
                'COMMIT;', $formatArguments);

            $this->getDb()->query($statement);

            return true;
        } catch (\Throwable $exception) {
            Logger::error($exception->getMessage());

            return false;
        }
    }

    /**
     * @inheritdoc
     */
    public function enqueueChildren(string $elementId, string $type): bool
    {
        try {
            $formatArguments = [
                1 => $this->getSegmentAssignmentQueueTable(),
                2 => $type === 'object' ? 'o_id' : 'id',
                3 => $type,
                4 => $type . 's',
                5 => $type === 'object' ? 'o_path' : 'path',
                6 => $type === 'object' ? 'o_key' : 'key',
                7 => $elementId
            ];

            $enqueueStatement = vsprintf('START TRANSACTION; ' .
                'INSERT INTO `%1$s` (`elementId`, `elementType`) ' .
                'SELECT `%2$s` AS elementId, "%3$s" AS elementType FROM `%4$s` ' .
                'WHERE `%5$s` LIKE CONCAT( ' .
                    '(SELECT CONCAT(`%5$s`, `%6$s`) FROM `%4$s` WHERE `%2$s` = "%7$s")' .
                ', "%%") ON DUPLICATE KEY UPDATE `elementId` = `elementId`; ' .
                'COMMIT;', $formatArguments);

            $this->getDb()->query($enqueueStatement);

            return true;
        } catch (\Throwable $exception) {
            Logger::error($exception->getMessage());

            return false;
        }
    }
}
