<?php

namespace App\Service;

use Jsvrcek\ICS\Model\Calendar;
use Jsvrcek\ICS\Model\CalendarEvent;
use Jsvrcek\ICS\Model\Relationship\Attendee;
use Jsvrcek\ICS\Model\Relationship\Organizer;
use Jsvrcek\ICS\Utility\Formatter;
use Jsvrcek\ICS\CalendarStream;
use Jsvrcek\ICS\CalendarExport;

class IcsService
{
    public function generateAttachment($emailAddressEmployee, $emailAddressHr, $date, $meetingTitle)
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
}