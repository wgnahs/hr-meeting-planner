<?php

namespace App\Command;

use Knp\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Question\ConfirmationQuestion;

use Jsvrcek\ICS\Model\Calendar;
use Jsvrcek\ICS\Model\CalendarEvent;
use Jsvrcek\ICS\Model\Relationship\Attendee;
use Jsvrcek\ICS\Model\Relationship\Organizer;
use Jsvrcek\ICS\Utility\Formatter;
use Jsvrcek\ICS\CalendarStream;
use Jsvrcek\ICS\CalendarExport;

use Ddeboer\DataImport\Reader;

class ReadCsvCommand extends Command
{
    private $csvHeaders = [
        'Name employee',
        'E-mail employee',
        'E-mail HR employee',
        'Start date contract',
        'Duration contract (in months)'
    ];

    protected function configure()
    {
        $this
            ->setName('app:read-csv')
            ->setDescription('This console script will read all unprocessed CSV files and send an e-mail afterwards.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $app = $this->getSilexApplication();

        $output->writeln('<info>Reading folder..</info>');

        // Scan for directory
        $dir = $this->getProjectDirectory().'files/csv/';
        if(!file_exists($dir))
        {
            throw new \Exception('Folder not found! Make sure you have a folder created at: '.$dir);
        }

        // Scan for files, built it in a private function to prevent a mess
        $files = $this->scanForFiles($dir);
        if(!count($files))
        {
            return $output->writeln('<comment>No files to process, are you sure it\'s located at: '.$dir.'?</comment>');
        }

        // Count the amount of files to process
        $countedFiles = count($files);

        $output->writeln('<info>Found '.$countedFiles.' unprocessed file(s). Starting processing..</info>');

        foreach($files as $file)
        {
            $output->writeln('<fg=black;bg=cyan>Current file: '.$file.'</>');

            // We need a full path for the SplFileObject
            $fullPath = $dir.$file;

            // The CSV reader requires a SplFileObject
            $csv = new \SplFileObject($fullPath);

            // Auto detect the delimiter
            $delimiter = $this->getFileDelimiter($fullPath);

            // Convert CSV data to an array
            $reader = new Reader\CsvReader($csv, $delimiter);
            $reader->setHeaderRowNumber(0);

            // We're going to use this to generate a nice view to verify the data
            $table = new Table($output);

            $table->setHeaders($this->csvHeaders);

            foreach($reader as $value)
            {
                $table->addRow($value);
            }

            // Let's render the table!
            $table->render();

            // The user needs to verify if the data being displayed looks right. If not, then verify if the CSV is right.
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion('Does this look good to you? [yes] ', '/^(y|j)/i');

            if(!$helper->ask($input, $output, $question))
            {
                return $output->writeln('Please check your CSV and make sure the fields are correct.');
            }

            $skipHeaders = true;

            // Going to for each again so we can individually invite everyone
            foreach($reader as $row)
            {
                $row = array_values($row);
                
                $name = $row[0];
                $emailAddressEmployee = $row[1];
                $emailAddressHr = $row[2];
                $contractStartDate = $row[3];
                $contractDuration = $row[4];

                $output->writeln('<bg=green>'.$name.'</>');

                // Start date + duration = end date
                $contractEndDate = date('d-m-Y', strtotime($contractStartDate.'+ '.$contractDuration.' months'));
                $output->writeln('The contract duration is till '.$contractEndDate);

                // Plan 5 weeks before, so it won't be too late. Also a week day
                $contractMeetingDate = $this->getWeekdayDate(date('d-m-Y', strtotime($contractEndDate.' - 5 weeks')));
                $output->writeln('Planning contract meeting on <options=bold>'.$contractMeetingDate.'</>');

                // Start date + 12 months
                $salaryMeeting = date('d-m-Y', strtotime($contractStartDate.'+ 12 months'));

                // Plan 12 months after contract date. Also a week day
                $salaryMeetingDate = $this->getWeekdayDate(date('d-m-Y', strtotime($salaryMeeting)));
                $output->writeln('Planning salary meeting on <options=bold>'.$salaryMeetingDate.'</>, going to send an invitation'."\n\n");

                // Set up an calendar event
                $contractAttachment = $this->generateIcsAttachment($emailAddressEmployee, $emailAddressHr, $contractMeetingDate, 'Contractbespreking');
                $salaryAttachment = $this->generateIcsAttachment($emailAddressEmployee, $emailAddressHr, $salaryMeetingDate, 'Salarisbespreking');

                // Send an e-mail to attendee (employee) and organizer (HR)
                $message = \Swift_Message::newInstance()
                    ->setSubject('HR bespreking')
                    ->setFrom($app['swiftmailer.options']['from'])
                    ->setTo($emailAddressEmployee)
                    ->setCc($emailAddressHr)
                    ->setBody(
                        $app['twig']->render(
                            'Emails/planned_meeting.html.twig',
                            [
                                'name' => $name,
                                'contractMeetingDate' => $contractMeetingDate,
                                'salaryMeetingDate' => $salaryMeetingDate
                            ]
                        ),
                        'text/html'
                    )
                    ->attach($contractAttachment)
                    ->attach($salaryAttachment)
                ;

                $app['mailer']->send($message);
            }

            // Rename the file so we won't have to process it again
            rename($fullPath, $dir.'processed_'.$file);
        }
    }

    private function generateIcsAttachment($emailAddressEmployee, $emailAddressHr, $date, $meetingTitle)
    {
        $eventOne = new CalendarEvent();
        $eventOne->setStart(new \DateTime($date, new \DateTimeZone('Europe/Amsterdam')))
            ->setSummary('')
            ->setUid('event-uid');

        $attendee = new Attendee(new Formatter());
        $attendee->setValue($emailAddressEmployee);
        $eventOne->addAttendee($attendee);

        $organizer = new Organizer(new Formatter());
        $organizer->setValue($emailAddressHr);
        $eventOne->setOrganizer($organizer);

        $calendar = new Calendar();
        $calendar->setProdId('-//Een bedrijf//NL')->addEvent($eventOne);

        $calendarExport = new CalendarExport(new CalendarStream, new Formatter());
        $calendarExport->addCalendar($calendar);

        $attachment = \Swift_Attachment::newInstance()
            ->setFilename($meetingTitle.'.ics')
            ->setContentType('text/calendar')
            ->setBody($calendarExport->getStream());

        return $attachment;
    }

    private function scanForFiles($files)
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

    private function getWeekdayDate($date)
    {
        $dates = [];
        $date = new \DateTime($date);

        while(count($dates)<5)
        {
            $date->add(new \DateInterval('P1D'));

            if($date->format('N')<6)
            {
                $dates[] = $date->format('d-m-Y '.rand(9,17).':00');
            }
        }

        return $dates[rand(0,4)];
    }
}
