<?php


namespace EasySwoole\Mysqli\Export;


use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Exception\DumpException;
use EasySwoole\Mysqli\Exception\Exception;
use EasySwoole\Mysqli\Utility;

class DataBase
{
    /** @var Client $client */
    protected $client;

    /** @var Config $config */
    protected $config;

    function __construct(Client $client, ?Config $config = null)
    {
        if ($config == null) {
            $config = new Config();
        }

        $this->client = $client;
        $this->config = $config;
    }

    function showTables(): array
    {
        $tables = [];
        $tableNames = $this->client->rawQuery('SHOW TABLES;');

        $tableNames = array_map(function ($tableName) {
            return current($tableName);
        }, $tableNames);

        // 指定的表
        $inTable = $this->config->getInTable();
        if ($inTable) {
            $tableNames = array_intersect($tableNames, $inTable);
        }

        // 排除的表
        $notInTable = $this->config->getNotInTable();
        if ($notInTable) {
            $tableNames = array_diff($tableNames, $notInTable);
        }

        foreach ($tableNames as $tableName) {
            $tables[] = new Table($this->client, $tableName, $this->config);
        }
        return $tables;
    }


    function export(&$output)
    {
        $startTime = date('Y-m-d H:i:s', time());

        $tables = $this->showTables();

        if (!$tables) {
            return;
        }

        /** EasySwoole Mysql dump start */
        $serverInfo = $this->client->mysqlClient()->serverInfo;
        $version = current(current($this->client->rawQuery('SELECT VERSION();')));
        $front = '-- EasySwoole Mysql dump, for ' . PHP_OS . PHP_EOL;
        $front .= '--' . PHP_EOL;
        $front .= "-- Host: {$serverInfo['host']}    Database: {$serverInfo['database']}" . PHP_EOL;
        $front .= '-- ------------------------------------------------------' . PHP_EOL;
        $front .= "-- Server version	{$version}   Date: $startTime" . PHP_EOL . PHP_EOL;

        /** names */
        $names = $this->config->getNames();
        if ($names) {
            $front .= "SET NAMES {$names};" . PHP_EOL;
        }

        /** 外键约束 */
        if ($this->config->isCloseForeignKeyChecks()) {
            $front .= 'SET FOREIGN_KEY_CHECKS = 0;' . PHP_EOL;
        }

        $front .= PHP_EOL;

        $writeFrontCallback = $this->config->getCallback(Event::onWriteFront);
        is_callable($writeFrontCallback) && $front = call_user_func($writeFrontCallback, $this->client, $front);
        Utility::writeSql($output, $front);

        /** Table data */
        /** @var Table $table */
        foreach ($tables as $table) {
            $table->export($output);
        }

        /** EasySwoole Mysql dump completed */
        $completedTime = date('Y-m-d H:i:s');
        $end = "-- Dump completed on {$completedTime}" . PHP_EOL;

        $writeCompletedCallback = $this->config->getCallback(Event::onWriteCompleted);
        is_callable($writeCompletedCallback) && $end = call_user_func($writeCompletedCallback, $this->client, $front);
        Utility::writeSql($output, $end);
    }

    function import($file, $mode = 'r+'): Result
    {
        // file 文件检测
        $resource = false;
        file_exists($file) && $resource = fopen($file, $mode);
        if ($resource === false || !is_resource($resource)) {
            throw new DumpException('Not a valid resource');
        }

        // result
        $result = new Result();
        $successNum = 0;
        $errorNum = 0;

        // init sql
        $sqls = [];
        $createTableSql = '';

        // config
        $size = $this->config->getSize();
        $maxFails = $this->config->getMaxFails();
        $continueOnError = $this->config->isContinueOnError();

        $beforeResult = null;
        $beforeCallback = $this->config->getCallback(Event::onBeforeImportTableData);
        is_callable($beforeCallback) && $beforeResult = call_user_func($beforeCallback, $this->client, $resource);

        $importingCallback = $this->config->getCallback(Event::onImportingTableData);

        while (!feof($resource)) {
            $line = fgets($resource);

            is_callable($importingCallback) && call_user_func($importingCallback, $this->client, $beforeResult);

            // 为空 或者 是注释
            if ((trim($line) == '') || preg_match('/^--*?/', $line, $match)) {
                if (empty($sqls)) continue;
            } else if (!preg_match('/;/', $line, $match) || preg_match('/ENGINE=/', $line, $match)) {
                // 将本次sql语句与创建表sql连接存起来
                $createTableSql .= $line;
                // 如果包含了创建表的最后一句
                if (preg_match('/ENGINE=/', $createTableSql, $match)) {
                    // 则将其合并到sql数组
                    $sqls [] = $createTableSql;
                    // 清空当前，准备下一个表的创建
                    $createTableSql = '';
                }
                if (empty($sqls)) continue;
            } else {
                $sqls[] = $line;
            }

            // 数组长度等于限制长度或者资源到底 执行sql
            if ((count($sqls) == $size) || feof($resource)) {
                foreach ($sqls as $sql) {
                    //重置次数
                    $attempts = 0;
                    $sql = str_replace("\n", "", $sql);
                    while ($attempts <= $maxFails) {
                        try {
                            $this->client->rawQuery(trim($sql));
                            $successNum++;
                            break;
                        } catch (Exception $exception) {
                            $errorNum++;
                            $result->setErrorMsg($exception->getMessage());
                            $result->setErrorSql($sql);

                            if (++$attempts > $maxFails && !$continueOnError) {
                                throw $exception;
                            }
                        }
                    }
                }
                // 清空sql组
                $sqls = [];
            }
        }

        $afterCallback = $this->config->getCallback(Event::onAfterImportTableData);
        is_callable($afterCallback) && call_user_func($afterCallback, $this->client);

        $result->setSuccessNum($successNum);
        $result->setErrorNum($errorNum);
        return $result;
    }

    function repair(bool $noWriteToBinLog = false, bool $quick = false, bool $extended = false, bool $useFrm = false)
    {
        $tableNames = '';
        foreach ($this->config->getInTable() as $tableName) {
            $tableNames .= "`{$tableName}`,";
        }

        if (!$tableNames) {
            return false;
        }

        $tableNames = trim($tableNames, ',');

        $repairSql = 'REPAIR';
        if ($noWriteToBinLog) {
            $repairSql .= ' NO_WRITE_TO_BINLOG';
        }

        $repairSql .= " TABLE {$tableNames}";

        if ($quick) {
            $repairSql .= ' QUICK';
        }

        if ($extended) {
            $repairSql .= ' EXTENDED';
        }

        if ($useFrm) {
            $repairSql .= ' USE_FRM';
        }

        $ret = $this->client->rawQuery($repairSql);
        return $ret;
    }

    function optimize(bool $noWriteToBinLog = false)
    {
        $tableNames = '';
        foreach ($this->config->getInTable() as $tableName) {
            $tableNames .= "`{$tableName}`,";
        }

        if (!$tableNames) {
            return false;
        }

        $tableNames = trim($tableNames, ',');


        $optimizeSql = 'OPTIMIZE';
        if ($noWriteToBinLog) {
            $optimizeSql .= ' NO_WRITE_TO_BINLOG';
        }

        $optimizeSql .= " TABLE {$tableNames};";
        $ret = $this->client->rawQuery($optimizeSql);
        return $ret;
    }
}