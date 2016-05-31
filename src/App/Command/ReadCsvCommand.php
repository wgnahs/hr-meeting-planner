<?php

namespace App\Command;

use Knp\Command\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Question\ConfirmationQuestion;

use App\Service\CsvHandlerService;
use App\Service\IcsService;

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
        $handleCsv = new CsvHandlerService();

        $output->writeln('<info>Reading folder..</info>');

        // Scan for directory
        $dir = $this->getProjectDirectory().'files/csv/';
        if(!file_exists($dir))
        {
            throw new \Exception('Folder not found! Make sure you have a folder created at: '.$dir);
        }

        // Scan for files, built it in a private function to prevent a mess
        $files = $handleCsv->scanForFiles($dir);
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

            // We need a full path for the handler
            $fullPath = $dir.$file;

            $reader = $handleCsv->getRows($fullPath);

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

                $icsService = new IcsService();

                // Set up an calendar event
                $contractAttachment = $icsService->generateAttachment($emailAddressEmployee, $emailAddressHr, $contractMeetingDate, 'Contractbespreking');
                $salaryAttachment = $icsService->generateAttachment($emailAddressEmployee, $emailAddressHr, $salaryMeetingDate, 'Salarisbespreking');

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
