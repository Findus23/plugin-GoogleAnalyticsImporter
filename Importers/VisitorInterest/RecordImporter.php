<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\GoogleAnalyticsImporter\Importers\VisitorInterest;


use Piwik\Common;
use Piwik\DataTable;
use Piwik\Date;
use Piwik\Plugins\VisitorInterest\Archiver;

class RecordImporter extends \Piwik\Plugins\GoogleAnalyticsImporter\RecordImporter
{
    const PLUGIN_NAME = 'VisitorInterest';

    private $secondsGap;

    public function importRecords(Date $day)
    {
        $this->secondsGap = Archiver::getSecondsGap();

        $this->queryDimension($day, 'ga:sessionCount', Archiver::$visitNumberGap, Archiver::VISITS_COUNT_RECORD_NAME,
            function ($value) { return $this->getVisitByNumberLabel($value); });
        $this->queryDimension($day, 'ga:daysSinceLastSession', Archiver::$daysSinceLastVisitGap, Archiver::DAYS_SINCE_LAST_RECORD_NAME,
            function ($value) { return $this->getVisitsByDaysSinceLastLabel($value); });
        $this->queryVisitsByDuration($day);
    }

    private function queryDimension(Date $day, $dimension, $gap, $recordName, $labelMapper)
    {
        $record = $this->createTableFromGap($gap);

        $gaQuery = $this->getGaQuery();
        $table = $gaQuery->query($day, [$dimension], $this->getConversionAwareVisitMetrics());
        foreach ($table->getRows() as $row) {
            $label = $row->getMetadata($dimension);
            $label = $labelMapper($label);
            $this->addRowToTable($record, $row, $label);
        }

        $this->insertRecord($recordName, $record);

        Common::destroy($record);
    }

    private function queryVisitsByDuration(Date $day)
    {
        $record = $this->createTableFromGap($this->secondsGap);

        $gaQuery = $this->getGaQuery();
        $table = $gaQuery->query($day, ['ga:sessionDurationBucket'], $this->getConversionAwareVisitMetrics());
        foreach ($table->getRows() as $row) {
            $durationInSecs = $row->getMetadata('ga:sessionDurationBucket');
            $label = $this->getDurationGapLabel($durationInSecs);

            $this->addRowToTable($record, $row, $label);
        }

        $this->insertRecord(Archiver::TIME_SPENT_RECORD_NAME, $record);

        Common::destroy($record);
    }

    private function insertRecord($recordName, DataTable $record)
    {
        $blob = $record->getSerialized();
        $this->insertBlobRecord($recordName, $blob);
    }

    private function getVisitByNumberLabel($value)
    {
        return $this->getGapLabel(Archiver::$visitNumberGap, $value);
    }

    private function getVisitsByDaysSinceLastLabel($value)
    {
        return $this->getGapLabel(Archiver::$daysSinceLastVisitGap, $value);
    }

    private function getDurationGapLabel($value)
    {
        return $this->getGapLabel($this->secondsGap, $value);
    }

    private function getGapLabel(array $gap, $value)
    {
        $range = null;

        foreach ($gap as $bounds) {
            $upperBound = end($bounds);
            if ($value <= $upperBound) {
                $range = reset($bounds) . ' - ' . $upperBound;
                break;
            }
        }

        if (empty($range)) {
            $lowerBound = reset($bounds);
            $range = ($lowerBound + 1) . urlencode('+');
        }

        return $range;
    }

    private function createTableFromGap($gap)
    {
        $record = new DataTable();
        foreach ($gap as $bounds) {
            $row = new DataTable\Row();
            if (count($bounds) === 1) {
                $row->setColumn('label', ($bounds[0] + 1) . urlencode('+'));
            } else {
                $row->setColumn('label', $bounds[0]. ' - ' . $bounds[1]);
            }
            $record->addRow($row);
        }
        return $record;
    }
}