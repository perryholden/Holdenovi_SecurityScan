<?php
/**
 * @category    Holdenovi
 * @package     SecurityScan
 * @copyright   Copyright (c) 2021 Holdenovi LLC
 * @license     https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */
namespace Holdenovi\SecurityScan\Console\Command;

use Holdenovi\SecurityScan\Model\EmailNotify;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class NewScriptsInDatabase extends Command
{
    /**
     * Used for setting or resetting the initial status file
     */
    protected const SET_STATUS = 'set-status';

    /**
     * Name of the directory under var in which the status file will be saved
     */
    protected const FOLDER_PATH = 'scan';

    /**
     * Name of the status file
     */
    protected const STATUS_FILE_NAME = 'status.json';

    /**
     * Array of table names and their fields to search
     */
    protected const DEFAULT_SCAN_TABLES = [
        'core_config_data' => [
            'key' => 'config_id',
            'search' => ['value']
        ],
        'cms_block' => [
            'key' => 'block_id',
            'search' => ['content']
        ],
        'cms_page' => [
            'key' => 'page_id',
            'search' => ['content', 'layout_update_xml']
        ]
    ];

    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var Json
     */
    protected $json;

    /**
     * @var EmailNotify
     */
    protected $sendEmail;

    /**
     * @param ResourceConnection $resourceConnection
     * @param Filesystem $filesystem
     * @param Json $json
     * @param EmailNotify $sendEmail
     * @param string|null $name
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        Filesystem $filesystem,
        Json $json,
        EmailNotify $sendEmail,
        string $name = null
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->filesystem = $filesystem;
        $this->json = $json;
        $this->sendEmail = $sendEmail;
        parent::__construct($name);
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this->setName('holdenovi:scan:database');
        $this->setDescription('Scans predefined database tables for new JS scripts.');
        $this->addOption(
            self::SET_STATUS,
            null,
            InputOption::VALUE_NONE,
            'Run with this flag to set or reset the status file'
        );
        parent::configure();
    }

    /**
     * Scans tables and fields for <script> tags
     * NOTE: To reduce load on server, MySQL searches for records with string "script",
     *       and then PHP uses regex to determine if it is a <script> tag.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $statusOutput = [];
        $setStatus = $input->getOption(self::SET_STATUS);

        /** @var AdapterInterface $connection */
        $connection = $this->resourceConnection->getConnection();

        foreach (self::DEFAULT_SCAN_TABLES as $tableName => $tableFields) {

            $tableName = $connection->getTableName($tableName);
            $tableFieldsString = implode(', ', $tableFields['search']);

            // Setup query based on number of search fields
            if (count($tableFields['search']) > 1) {
                $queryFormat = "SELECT %s, %s FROM %s WHERE CONCAT_WS(',', %s) LIKE '%%script%%'";
            } else {
                $queryFormat = "SELECT %s, %s FROM %s WHERE %s LIKE '%%script%%'";
            }
            $query = sprintf($queryFormat, $tableFields['key'], $tableFieldsString, $tableName, $tableFieldsString);
            $results = $connection->fetchAll($query);

            // Search through all returned rows
            foreach ($results as $result) {

                // Search through columns for script tags
                foreach ($result as $columnId => $columnValue) {

                    // Skip searching table identifier, but we will use its value in reporting below
                    if ($tableFields['key'] === $columnId) {
                        continue;
                    }

                    // Search for script tags and pull values for hashing
                    preg_match_all('#<script.*?</script>#is', $columnValue, $matches);

                    foreach ($matches as $match) {
                        if (!empty($match)) {

                            foreach ($match as $singleMatch) {

                                $hashText = md5($singleMatch);
                                $identifierValue = $result[$tableFields['key']];

                                $statusOutput[$tableName][$identifierValue][$columnId][] = $hashText;
                            }
                        }
                    }
                }
            }
        }

        $processResult = $this->processResults($statusOutput, $setStatus);

        // If there are no changes, do not output anything
        if (!empty($processResult)) {

            if (is_array($processResult)) {
                // Write any error messages to output and build email contents
                $emailBody = "<p>New or modified script in the following records:</p>\n";
                $output->writeln('<error>New or modified script in the following records:</error>');
                
                foreach ($processResult as $result) {
                    $emailBody .= "<p>$result</p>\n";
                    $output->writeln("<info>$result</info>");
                }
                $this->sendEmail->sendEmail(
                    [
                        'email_subject' => 'Security Alert: New or Modified Scripts',
                        'email_body' => $emailBody,
                    ]
                );
            } else {

                // Write success message to output (e.g. after status save) 
                $output->writeln("<info>$processResult</info>");
            }

        }
    }

    /**
     * @param array $statusOutput
     * @param boolean $setStatus
     * @return array|string
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    protected function processResults($statusOutput, $setStatus)
    {
        $serializedOutput = $this->json->serialize($statusOutput);

        // If "set-status", then merely write the file for use in future scans
        if ($setStatus) {

            /** @var WriteInterface $writeDirectory */
            $writeDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
            $writeDirectory->writeFile(self::FOLDER_PATH . DS . self::STATUS_FILE_NAME, $serializedOutput);

            return 'Status successfully saved';

        }

        // First, we want to get the current values saved in the status file
        /** @var ReadInterface $readDirectory */
        $readDirectory = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);
        $unserializedStatus = $this->json->unserialize($readDirectory->readFile(self::FOLDER_PATH . DS . self::STATUS_FILE_NAME));

        // Secondly, we want to compare the current status output with the saved status file
        return $this->compareStatuses($statusOutput, $unserializedStatus);
    }

    /**
     * @param $statusOutput
     * @param $unserializedStatus
     * @return array
     */
    protected function compareStatuses($statusOutput, $unserializedStatus)
    {
        $alerts = [];

        foreach ($statusOutput as $tableName => $tableData) {

            // Find the associated table record
            if (!empty($unserializedStatus[$tableName])) {

                foreach ($tableData as $keyId => $keyData) {

                    // Find associated key record
                    if (!empty($unserializedStatus[$tableName][$keyId])) {

                        foreach ($keyData as $columnName => $hashes) {

                            // Find associated column record
                            if (!empty($unserializedStatus[$tableName][$keyId][$columnName])) {

                                foreach ($hashes as $hashKey => $hashValue) {

                                    // Process hashes and remove hash keys that are found
                                    if (($key = array_search($hashValue, $unserializedStatus[$tableName][$keyId][$columnName], true)) !== false) {
                                        // This leaves modified scripts, or new scripts are added to existing records
                                        unset($unserializedStatus[$tableName][$keyId][$columnName][$key]);
                                        // This allows us to see if any new scripts are added to records that did not have them before
                                        unset($statusOutput[$tableName][$keyId][$columnName][$key]);
                                    }
                                }

                                // Delete column records if empty
                                if (empty($unserializedStatus[$tableName][$keyId][$columnName])) {
                                    unset($unserializedStatus[$tableName][$keyId][$columnName]);
                                }
                                if (empty($statusOutput[$tableName][$keyId][$columnName])) {
                                    unset($statusOutput[$tableName][$keyId][$columnName]);
                                }
                            }
                        }

                        // Delete key records if empty
                        if (empty($unserializedStatus[$tableName][$keyId])) {
                            unset($unserializedStatus[$tableName][$keyId]);
                        }
                        if (empty($statusOutput[$tableName][$keyId])) {
                            unset($statusOutput[$tableName][$keyId]);
                        }
                    }
                }

                // Delete table records if empty
                if (empty($unserializedStatus[$tableName])) {
                    unset($unserializedStatus[$tableName]);
                }
                if (empty($statusOutput[$tableName])) {
                    unset($statusOutput[$tableName]);
                }
            }
        }

        // If both are empty, then nothing has changed
        if (empty($unserializedStatus) && empty($statusOutput)) {
            return [];
        }

        // Alert for existing records that have been modified
        foreach ($unserializedStatus as $tableName => $tableData) {
            foreach ($tableData as $keyId => $keyData) {
                foreach ($keyData as $columnName => $hashes) {
                    if (!empty($hashes)) {
                        $alerts[] = "Table:'$tableName', Record: '$keyId', Column: '$columnName'";
                    }
                }
            }
        }
        // Alert for new records
        foreach ($statusOutput as $tableName => $tableData) {
            foreach ($tableData as $keyId => $keyData) {
                foreach ($keyData as $columnName => $hashes) {
                    if (!empty($hashes)) {
                        $alerts[] = "Table:'$tableName', Record: '$keyId', Column: '$columnName'";
                    }
                }
            }
        }

        return array_unique($alerts);
    }
}
