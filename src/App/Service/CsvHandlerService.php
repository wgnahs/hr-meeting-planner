<?php

namespace App\Service;

use Ddeboer\DataImport\Reader;

class CsvHandlerService
{
    public function getRows($fullPath)
    {
        // The CSV reader requires a SplFileObject
        $csv = new \SplFileObject($fullPath);

        // Auto detect the delimiter
        $delimiter = $this->getFileDelimiter($fullPath);

        // Convert CSV data to an array
        $reader = new Reader\CsvReader($csv, $delimiter);
        $reader->setHeaderRowNumber(0);

        return $reader;
    }

    public function scanForFiles($files)
    {
        $return = [];

        if($handle = opendir($files))
        {
            while(false !== ($entry = readdir($handle)))
            {
                // Filter out stuff we don't need, including processed files
                if($entry != ".." && substr($entry, 0, 1) != '.' && !strstr($entry, 'processed_'))
                {
                    $return[] = $entry;
                }
            }

            closedir($handle);
        }

        return $return;
    }

    private function getFileDelimiter($fullPath)
    {
        $file = new \SplFileObject($fullPath);

        $delimiters = [
            ',',
            '\t',
            ';',
            '|',
            ':'
        ];

        $results = [];
        $i = 0;

        while($file->valid() && $i <= 2)
        {
            $line = $file->fgets();

            foreach($delimiters as $delimiter)
            {
                $regExp = '/['.$delimiter.']/';
                $fields = preg_split($regExp, $line);

                if(count($fields) > 1)
                {
                    if(!empty($results[$delimiter]))
                    {
                        $results[$delimiter]++;
                    }
                    else
                    {
                        $results[$delimiter] = 1;
                    }
                }
            }

            $i++;
        }

        $results = array_keys($results, max($results));

        return $results[0];
    }
}