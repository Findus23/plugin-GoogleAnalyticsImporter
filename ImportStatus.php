<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\GoogleAnalyticsImporter;


use Piwik\Config;
use Piwik\Exception\UnexpectedWebsiteFoundException;
use Piwik\Option;
use Piwik\Date;
use Piwik\Piwik;
use Piwik\Site;

// TODO: maybe make an import status entity class
class ImportStatus
{
    const OPTION_NAME_PREFIX = 'GoogleAnalyticsImporter.importStatus_';
    const IMPORTED_DATE_RANGE_PREFIX = 'GoogleAnalyticsImporter.importedDateRange_';

    const STATUS_STARTED = 'started';
    const STATUS_ONGOING = 'ongoing';
    const STATUS_FINISHED = 'finished';
    const STATUS_ERRORED = 'errored';
    const STATUS_RATE_LIMITED = 'rate_limited';

    public function startingImport($propertyId, $accountId, $viewId, $idSite, $extraCustomDimensions = [])
    {
        try {
            $status = $this->getImportStatus($idSite);
            if ($status['status'] != self::STATUS_FINISHED) {
                throw new \Exception(Piwik::translate('GoogleAnalyticsImporter_CancelExistingImportFirst', [$idSite]));
            }
        } catch (\Exception $ex) {
            // ignore
        }

        $now = Date::getNowTimestamp();
        $status = [
            'status' => self::STATUS_STARTED,
            'idSite' => $idSite,
            'ga' => [
                'property' => $propertyId,
                'account' => $accountId,
                'view' => $viewId,
            ],
            'last_date_imported' => null,
            'import_start_time' => $now,
            'import_end_time' => null,
            'last_job_start_time' => $now,
            'last_day_archived' => null,
            'import_range_start' => null,
            'import_range_end' => null,
            'extra_custom_dimensions' => $extraCustomDimensions,
            'days_finished_since_rate_limit' => 0,
        ];

        $this->saveStatus($status);
    }

    public function getImportedDateRange($idSite)
    {
        $optionName = self::IMPORTED_DATE_RANGE_PREFIX . $idSite;
        $existingValue = Option::get($optionName);

        $dates = ['', ''];
        if (!empty($existingValue)) {
            $dates = explode(',', $existingValue);
        }
        return $dates;
    }

    public function dayImportFinished($idSite, Date $date)
    {
        $status = $this->getImportStatus($idSite);
        $status['status'] = self::STATUS_ONGOING;

        if (empty($status['last_date_imported'])
            || !Date::factory($status['last_date_imported'])->isLater($date)
        ) {
            $status['last_date_imported'] = $date->toString();

            $this->setImportedDateRange($idSite, $startDate = null, $date);
        }

        if (isset($status['days_finished_since_rate_limit'])
            && is_int($status['days_finished_since_rate_limit'])
        ) {
            $status['days_finished_since_rate_limit'] += 1;
        }

        $this->saveStatus($status);
    }

    public function setImportDateRange($idSite, Date $startDate = null, Date $endDate = null)
    {
        $status = $this->getImportStatus($idSite);
        $status['import_range_start'] = $startDate ? $startDate->toString() : '';
        $status['import_range_end'] = $endDate ? $endDate->toString() : '';

        if (!empty($status['import_range_start'])
            && !empty($status['import_range_end'])
            && Date::factory($status['import_range_start'])->isLater(Date::factory($status['import_range_end']))
        ) {
            throw new \Exception("The start date cannot be past the end date.");
        }

        $this->saveStatus($status);

        $this->setImportedDateRange($idSite, $startDate, null);
    }

    public function setIsVerboseLoggingEnabled($idSite, $isVerboseLoggingEnabled)
    {
        $status = $this->getImportStatus($idSite);
        $status['is_verbose_logging_enabled'] = $isVerboseLoggingEnabled;
        $this->saveStatus($status);
    }

    public function resumeImport($idSite)
    {
        $status = $this->getImportStatus($idSite);
        $status['status'] = self::STATUS_ONGOING;
        $status['last_job_start_time'] = Date::getNowTimestamp();
        $status['days_finished_since_rate_limit'] = 0;
        $this->saveStatus($status);
    }

    public function importArchiveFinished($idSite, Date $date)
    {
        $status = $this->getImportStatus($idSite);
        $status['last_day_archived'] = $date->toString();
        $this->saveStatus($status);
    }

    public function getImportStatus($idSite)
    {
        $optionName = $this->getOptionName($idSite);
        Option::clearCachedOption($optionName);
        $data = Option::get($optionName);
        if (empty($data)) {
            throw new \Exception("Import was cancelled.");
        }
        $data = json_decode($data, true);
        return $data;
    }

    public function finishedImport($idSite)
    {
        $status = $this->getImportStatus($idSite);
        $status['status'] = self::STATUS_FINISHED;
        $status['import_end_time'] = Date::getNowTimestamp();
        $this->saveStatus($status);
    }

    public function erroredImport($idSite, $errorMessage)
    {
        $status = $this->getImportStatus($idSite);
        $status['status'] = self::STATUS_ERRORED;
        $status['error'] = $errorMessage;
        $this->saveStatus($status);
    }

    public function rateLimitReached($idSite)
    {
        $status = $this->getImportStatus($idSite);
        $status['status'] = self::STATUS_RATE_LIMITED;
        $this->saveStatus($status);
    }

    public function getAllImportStatuses()
    {
        $optionValues = Option::getLike(self::OPTION_NAME_PREFIX . '%');

        $result = [];
        foreach ($optionValues as $optionValue) {
            $status = json_decode($optionValue, true);
            $status = $this->enrichStatus($status);
            $result[] = $status;
        }
        return $result;
    }

    public function deleteStatus($idSite)
    {
        $optionName = $this->getOptionName($idSite);
        Option::delete($optionName);

        $hostname = Config::getHostname();

        $importLogFile = Tasks::getImportLogFile($idSite, $hostname);
        @unlink($importLogFile);

        $archiveLogFile = Tasks::getImportLogFile($idSite, $hostname);
        @unlink($archiveLogFile);
    }

    private function saveStatus($status)
    {
        $optionName = $this->getOptionName($status['idSite']);
        Option::set($optionName, json_encode($status));
    }

    private function getOptionName($idSite)
    {
        return self::OPTION_NAME_PREFIX . $idSite;
    }

    private function enrichStatus($status)
    {
        if (isset($status['idSite'])) {
            try {
                $status['site'] = new Site($status['idSite']);
            } catch (UnexpectedWebsiteFoundException $ex) {
                $status['site'] = null;
            }
        }

        if (isset($status['import_start_time'])) {
            $status['import_start_time'] = $this->getDatetime($status['import_start_time']);
        }

        if (isset($status['import_end_time'])) {
            $status['import_end_time'] = $this->getDatetime($status['import_end_time']);
        }

        if (isset($status['last_job_start_time'])) {
            $status['last_job_start_time'] = $this->getDatetime($status['last_job_start_time']);
        }

        if (!empty($status['import_range_start'])) {
            $status['import_range_start'] = $this->getDateString($status['import_range_start']);
        }

        if (!empty($status['import_range_end'])) {
            $status['import_range_end'] = $this->getDateString($status['import_range_end']);

            $status['estimated_days_left_to_finish'] = self::getEstimatedDaysLeftToFinish($status);
        }

        $status['gaInfoPretty'] = 'Property: ' . $status['ga']['property'] . "\nAccount: " . $status['ga']['account']
            . "\nView: " . $status['ga']['view'];

        return $status;
    }

    public static function getEstimatedDaysLeftToFinish($status)
    {
        try {
            if (!empty($status['last_date_imported'])
                && !empty($status['import_range_end'])
            ) {
                $lastDateImported = Date::factory($status['last_date_imported']);
                $importEndDate = Date::factory($status['import_range_end']);

                $importStartTime = Date::factory($status['import_start_time']);

                if (isset($status['import_range_start'])) {
                    $importRangeStart = Date::factory($status['import_range_start']);
                } else {
                    $importRangeStart = Date::factory(Site::getCreationDateFor($status['idSite']));
                }

                $daysRunning = floor((Date::now()->getTimestamp() - $importStartTime->getTimestamp()) / 86400);
                if ($daysRunning == 0) {
                    return null;
                }

                $totalDaysLeft = floor(($importEndDate->getTimestamp() - $lastDateImported->getTimestamp()) / 86400);
                $totalDaysImported = floor(($lastDateImported->getTimestamp() - $importRangeStart->getTimestamp()) / 86400);

                $rateOfImport = $totalDaysImported / $daysRunning;
                if ($rateOfImport <= 0) {
                    return lcfirst(Piwik::translate('General_Unknown'));
                }

                $totalTimeLeftInDays = ceil($totalDaysLeft / $rateOfImport);

                return max(0, $totalTimeLeftInDays);
            } else {
                return lcfirst(Piwik::translate('General_Unknown'));
            }
        } catch (\Exception $ex) {
            return lcfirst(Piwik::translate('General_Unknown'));
        }
    }

    private function setImportedDateRange($idSite, Date $startDate = null, Date $endDate = null) // TODO: unit test
    {
        $optionName = self::IMPORTED_DATE_RANGE_PREFIX . $idSite;
        $dates = $this->getImportedDateRange($idSite);

        if (!empty($startDate)) {
            $dates[0] = $startDate->toString();
        }

        if (!empty($endDate)) {
            $dates[1] = $endDate->toString();
        }

        $value = implode(',', $dates);

        Option::set($optionName, $value);
    }

    private function getDatetime($str)
    {
        try {
            return Date::factory($str)->getDatetime();
        } catch (\Exception $ex) {
            return $str;
        }
    }

    private function getDateString($str)
    {
        try {
            return Date::factory($str)->toString();
        } catch (\Exception $ex) {
            return $str;
        }
    }
}