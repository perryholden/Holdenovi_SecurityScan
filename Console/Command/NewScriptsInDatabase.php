<?php
/**
 * @category    Holdenovi
 * @package     SecurityScan
 * @copyright   Copyright (c) 2021 Holdenovi LLC
 * @license     https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */
namespace Holdenovi\SecurityScan\Console\Command;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class NewScriptsInDatabase extends Command
{
    /**
     * Array of table names and their fields to search
     */
    protected const DEFAULT_SCAN_TABLES = [
        'core_config_data' => ['path', 'value'],
        'cms_block' => ['content'],
        'cms_page' => ['content'],
    ];

    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    public function __construct(
        ResourceConnection $resourceConnection,
        string $name = null
    ) {
        $this->resourceConnection = $resourceConnection;
        parent::__construct($name);
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this->setName('holdenovi:scan:database');
        $this->setDescription('Scans predefined database tables for new JS scripts.');
        parent::configure();
    }

    /**
     * Scans tables and fields for <script> tags
     * NOTE: To reduce load on server, MySQL searches for records with string "script",
     *       and then PHP uses regex to determine if it is a <script> tag.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var AdapterInterface $connection */
        $connection = $this->resourceConnection->getConnection();

        foreach (self::DEFAULT_SCAN_TABLES as $tableName => $tableFields) {

            $tableName = $connection->getTableName($tableName);
            $tableFieldsString = implode(', ', $tableFields);
            if (count($tableFields) > 1) {
                $queryFormat = "SELECT %s FROM %s WHERE CONCAT_WS(%s) LIKE '%%script%%'";
            } else {
                $queryFormat = "SELECT %s FROM %s WHERE %s LIKE '%%script%%'";
            }
            $query = sprintf($queryFormat, $tableFieldsString, $tableName, $tableFieldsString);
            $results = $connection->fetchAll($query);

            foreach ($results as $result) {

                foreach ($result as $column) {

                    preg_match_all('#<script.*?</script>#is', $column, $matches);

                    foreach ($matches as $match) {
                        if (!empty($match)) {

                            $scriptText = $match[0];
                            $hashText = md5($scriptText);

                            $output->writeln("<info>$scriptText</info>");
                            $output->writeln("<error>$hashText</error>");
                        }
                    }

                }
            }
        }

        $output->writeln('<info>Scan complete</info>');
    }
}
