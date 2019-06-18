<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\GoogleAnalyticsImporter\Importers\Referrers;


use Piwik\Common;
use Piwik\Config;
use Piwik\Container\StaticContainer;
use Piwik\DataTable;
use Piwik\Date;
use Piwik\Metrics;
use Piwik\Piwik;
use Piwik\Plugins\GoogleAnalyticsImporter\Google\SearchEngineMapper;
use Piwik\Plugins\GoogleAnalyticsImporter\GoogleAnalyticsQueryService;
use Piwik\Plugins\Referrers\Archiver;
use Piwik\Plugins\Referrers\Social;
use Psr\Log\LoggerInterface;

class RecordImporter extends \Piwik\Plugins\GoogleAnalyticsImporter\RecordImporter
{
    const PLUGIN_NAME = 'Referrers';

    private $maximumRowsInDataTableLevelZero;
    private $maximumRowsInSubDataTable;
    private $columnToSortByBeforeTruncation;

    /**
     * @var SearchEngineMapper
     */
    private $searchEngineMapper;

    /**
     * @var DataTable|null
     */
    private $referrerTypeRecord;

    public function __construct(GoogleAnalyticsQueryService $gaQuery, $idSite, LoggerInterface $logger)
    {
        parent::__construct($gaQuery, $idSite, $logger);

        // TODO: code redundancy w/ referrers
        $this->columnToSortByBeforeTruncation = Metrics::INDEX_NB_VISITS;

        // Reading pre 2.0 config file settings
        $this->maximumRowsInDataTableLevelZero = @Config::getInstance()->General['datatable_archiving_maximum_rows_referers'];
        $this->maximumRowsInSubDataTable = @Config::getInstance()->General['datatable_archiving_maximum_rows_subtable_referers'];
        if (empty($this->maximumRowsInDataTableLevelZero)) {
            $this->maximumRowsInDataTableLevelZero = Config::getInstance()->General['datatable_archiving_maximum_rows_referrers'];
            $this->maximumRowsInSubDataTable = Config::getInstance()->General['datatable_archiving_maximum_rows_subtable_referrers'];
        }

        $this->searchEngineMapper = StaticContainer::get(SearchEngineMapper::class);
    }

    public function queryGoogleAnalyticsApi(Date $day)
    {
        $this->referrerTypeRecord = new DataTable();

        $keywordByCampaign = $this->getKeywordByCampaign($day);
        $blob = $keywordByCampaign->getSerialized($this->maximumRowsInDataTableLevelZero, $this->maximumRowsInSubDataTable, $this->columnToSortByBeforeTruncation);
        $this->insertBlobRecord(Archiver::CAMPAIGNS_RECORD_NAME, $blob);
        Common::destroy($keywordByCampaign);

        list($keywordBySearchEngine, $searchEngineByKeyword) = $this->getKeywordsAndSearchEngineRecords($day);

        $blob = $keywordBySearchEngine->getSerialized($this->maximumRowsInDataTableLevelZero, $this->maximumRowsInSubDataTable, $this->columnToSortByBeforeTruncation);
        $this->insertBlobRecord(Archiver::KEYWORDS_RECORD_NAME, $blob);
        Common::destroy($keywordBySearchEngine);

        $blob = $searchEngineByKeyword->getSerialized($this->maximumRowsInDataTableLevelZero, $this->maximumRowsInSubDataTable, $this->columnToSortByBeforeTruncation);
        $this->insertBlobRecord(Archiver::SEARCH_ENGINES_RECORD_NAME, $blob);
        Common::destroy($searchEngineByKeyword);

        list($urlByWebsite, $urlBySocialNetwork) = $this->getUrlByWebsite($day);

        $blob = $urlByWebsite->getSerialized($this->maximumRowsInDataTableLevelZero, $this->maximumRowsInSubDataTable, $this->columnToSortByBeforeTruncation);
        $this->insertBlobRecord(Archiver::WEBSITES_RECORD_NAME, $blob);
        Common::destroy($urlByWebsite);

        $blob = $urlBySocialNetwork->getSerialized($this->maximumRowsInDataTableLevelZero, $this->maximumRowsInSubDataTable, $this->columnToSortByBeforeTruncation);
        $this->insertBlobRecord(Archiver::SOCIAL_NETWORKS_RECORD_NAME, $blob);
        Common::destroy($urlBySocialNetwork);

        $blob = $this->referrerTypeRecord->getSerialized($this->maximumRowsInDataTableLevelZero, $this->maximumRowsInSubDataTable, $this->columnToSortByBeforeTruncation);
        $this->insertBlobRecord(Archiver::REFERRER_TYPE_RECORD_NAME, $blob);
        Common::destroy($this->referrerTypeRecord);
        $this->referrerTypeRecord = null;

        unset($blob);
    }

    private function getKeywordByCampaign(Date $day)
    {
        $gaQuery = $this->getGaQuery();
        $table = $gaQuery->query($day, $dimensions = ['ga:campaign', 'ga:keyword'], $this->getConversionAwareVisitMetrics());

        $keywordByCampaign = new DataTable();
        foreach ($table->getRows() as $row) {
            $campaign = $row->getMetadata('ga:campaign');
            if (empty($campaign)) {
                continue;
            }

            $keyword = $row->getMetadata('ga:keyword');

            $topLevelRow = $this->addRowToTable($keywordByCampaign, $row, $campaign);
            $this->addRowToSubtable($topLevelRow, $row, $keyword);

            // add to referrer type table
            $this->addRowToTable($this->referrerTypeRecord, $row, Common::REFERRER_TYPE_CAMPAIGN);
        }
        return $keywordByCampaign;
    }

    private function getUrlByWebsite(Date $day)
    {
        $social = Social::getInstance();

        $gaQuery = $this->getGaQuery();
        $table = $gaQuery->query($day, $dimensions = ['ga:fullReferrer'], $this->getConversionAwareVisitMetrics());

        $urlByWebsite = new DataTable();
        $urlBySocialNetwork = new DataTable();
        foreach ($table->getRows() as $row) {
            $referrerUrl = $row->getMetadata('ga:fullReferrer');

            // URLs don't have protocols in GA
            $referrerUrl = 'http://' . $referrerUrl;

            // invalid rows for direct entries and search engines (TODO: check for more possibilities?)
            if ($referrerUrl == '(direct)') {
                $this->addRowToTable($this->referrerTypeRecord, $row, Common::REFERRER_TYPE_DIRECT_ENTRY);
                continue;
            }

            if (strrpos($referrerUrl, '/') !== strlen($referrerUrl) - 1) {
                continue;
            }

            $socialNetwork = $social->getSocialNetworkFromDomain($referrerUrl);
            if (!empty($socialNetwork)
                && $socialNetwork !== Piwik::translate('General_Unknown')
            ) {
                $topLevelRow = $this->addRowToTable($urlBySocialNetwork, $row, $socialNetwork);
                $this->addRowToSubtable($topLevelRow, $row, $referrerUrl);

                $this->addRowToTable($this->referrerTypeRecord, $row, Common::REFERRER_TYPE_SOCIAL_NETWORK);
            } else {
                $parsedUrl = @parse_url($referrerUrl);
                $host = isset($parsedUrl['host']) ? $parsedUrl['host'] : null;
                $path = isset($parsedUrl['path']) ? $parsedUrl['path'] : null;

                $topLevelRow = $this->addRowToTable($urlByWebsite, $row, $host);
                $this->addRowToSubtable($topLevelRow, $row, $path);

                $this->addRowToTable($this->referrerTypeRecord, $row, Common::REFERRER_TYPE_WEBSITE);
            }
        }

        Common::destroy($table);

        return [$urlByWebsite, $urlBySocialNetwork];
    }

    private function getKeywordsAndSearchEngineRecords(Date $day)
    {
        $keywordBySearchEngine = new DataTable();
        $searchEngineByKeyword = new DataTable();

        $gaQuery = $this->getGaQuery();
        $table = $gaQuery->query($day, $dimensions = ['ga:source', 'ga:medium', 'ga:keyword'], $this->getConversionAwareVisitMetrics());

        foreach ($table->getRows() as $row) {
            $source = $row->getMetadata('ga:source');
            $medium = $row->getMetadata('ga:medium');
            $keyword = $row->getMetadata('ga:keyword');

            if ($medium == 'referral') {
                $searchEngineName = $this->searchEngineMapper->mapReferralMediumToSearchEngine($medium);
            } else if ($medium == 'organic') { // not a search engine referrer
                $searchEngineName = $this->searchEngineMapper->mapSourceToSearchEngine($source);
            }

            if (!isset($searchEngineName)) {
                continue;
            }

            if (empty($keyword)) {
                $keyword = '(not provided)';
            }

            // add to keyword by search engine record
            $topLevelRow = $this->addRowToTable($keywordBySearchEngine, $row, $keyword);
            $this->addRowToSubtable($topLevelRow, $row, $searchEngineName);

            // add to search engine by keyword record
            $topLevelRow = $this->addRowToTable($searchEngineByKeyword, $row, $searchEngineName);
            $this->addRowToSubtable($topLevelRow, $row, $keyword);

            $this->addRowToTable($this->referrerTypeRecord, $row, Common::REFERRER_TYPE_SEARCH_ENGINE);
        }

        Common::destroy($table);

        return [$keywordBySearchEngine, $searchEngineByKeyword];
    }
}