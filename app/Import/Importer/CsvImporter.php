<?php
/**
 * CsvImporter.php
 * Copyright (C) 2016 thegrumpydictator@gmail.com
 *
 * This software may be modified and distributed under the terms
 * of the MIT license.  See the LICENSE file for details.
 */

declare(strict_types = 1);

namespace FireflyIII\Import\Importer;

use FireflyIII\Import\Converter\ConverterInterface;
use FireflyIII\Import\ImportEntry;
use FireflyIII\Import\Specifics\SpecificInterface;
use FireflyIII\Models\ImportJob;
use Illuminate\Support\Collection;
use League\Csv\Reader;
use Log;

/**
 * Class CsvImporter
 *
 * @package FireflyIII\Import\Importer
 */
class CsvImporter implements ImporterInterface
{
    /** @var  Collection */
    public $collection;
    /** @var  ImportJob */
    public $job;

    /**
     * CsvImporter constructor.
     */
    public function __construct()
    {
        $this->collection = new Collection;
    }

    /**
     * Run the actual import
     *
     * @return Collection
     */
    public function createImportEntries(): Collection
    {
        $config  = $this->job->configuration;
        $content = $this->job->uploadFileContents();

        // create CSV reader.
        $reader = Reader::createFromString($content);
        $reader->setDelimiter($config['delimiter']);
        $start   = $config['has-headers'] ? 1 : 0;
        $results = $reader->fetch();

        Log::notice('Building importable objects from CSV file.');

        foreach ($results as $index => $row) {
            if ($index >= $start) {
                $line = $index + 1;
                Log::debug('----- import entry build start --');
                Log::debug(sprintf('Now going to import row %d.', $index));
                $importEntry = $this->importSingleRow($index, $row);
                $this->collection->put($line, $importEntry);
                $this->job->addTotalSteps(3);
                $this->job->addStepsDone(1);
            }
        }
        Log::debug(sprintf('Import collection contains %d entries', $this->collection->count()));
        Log::notice(sprintf('Built %d importable object(s) from your CSV file.', $this->collection->count()));

        return $this->collection;
    }

    /**
     * @param ImportJob $job
     */
    public function setJob(ImportJob $job)
    {
        $this->job = $job;
    }

    /**
     * @param int   $index
     * @param array $row
     *
     * @return ImportEntry
     */
    private function importSingleRow(int $index, array $row): ImportEntry
    {
        // create import object. This is where each entry ends up.
        $object = new ImportEntry;

        Log::debug(sprintf('Now at row %d', $index));

        // set some vars:
        $object->setUser($this->job->user);
        $config = $this->job->configuration;

        // hash the row:
        $hash = hash('sha256', json_encode($row));
        $object->importValue('hash', 100, $hash);

        // and this is the point where the specifix go to work.
        foreach ($config['specifics'] as $name => $enabled) {
            /** @var SpecificInterface $specific */
            $specific = app('FireflyIII\Import\Specifics\\' . $name);

            // it returns the row, possibly modified:
            $row = $specific->run($row);
        }

        foreach ($row as $rowIndex => $value) {
            // find the role for this column:
            $role           = $config['column-roles'][$rowIndex] ?? '_ignore';
            $doMap          = $config['column-do-mapping'][$rowIndex] ?? false;
            $converterClass = config('csv.import_roles.' . $role . '.converter');
            $mapping        = $config['column-mapping-config'][$rowIndex] ?? [];
            /** @var ConverterInterface $converter */
            $converter = app('FireflyIII\\Import\\Converter\\' . $converterClass);
            // set some useful values for the converter:
            $converter->setMapping($mapping);
            $converter->setDoMap($doMap);
            $converter->setUser($this->job->user);
            $converter->setConfig($config);

            // run the converter for this value:
            $convertedValue = $converter->convert($value);
            $certainty      = $converter->getCertainty();

            // log it.
            Log::debug('Value ', ['index' => $rowIndex, 'value' => $value, 'role' => $role]);

            // store in import entry:
            Log::debug('Going to import', ['role' => $role, 'value' => $value, 'certainty' => $certainty]);
            $object->importValue($role, $certainty, $convertedValue);
        }


        return $object;

    }
}
