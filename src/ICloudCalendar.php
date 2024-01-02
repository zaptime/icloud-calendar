<?php

declare(strict_types=1);

namespace Zaptime\ICloudCalendar;

use DateTime;
use Sabre\DAV\Client;
use Zaptime\ICloudCalendar\Exceptions\EventCreationFailed;
use Zaptime\ICloudCalendar\Exceptions\EventDeletionFailed;

class ICloudCalendar
{

    public function __construct(private readonly string $userName, private readonly string $appSpecificPassword) {}

    public function getClient(): Client
    {
        return new Client([
            'baseUri' => 'https://caldav.icloud.com/',
            'userName' => $this->userName,
            'password' => $this->appSpecificPassword,
        ]);
    }

    /**
     * @param array<int, Attendee> $attendees
     * @throws EventCreationFailed
     */
    public function createEvent(
        string $calendarUrl,
        string $summary,
        DateTime $start,
        DateTime $end,
        string $description = '',
        array $attendees = []
    ): string
    {
        $eventData = "BEGIN:VCALENDAR\r\n";
        $eventData .= "VERSION:2.0\r\n";
        $eventData .= "BEGIN:VEVENT\r\n";
        $eventData .= "UID:" . $this->prepareUuid() . "@zaptime.app\r\n";
        $eventData .= "SUMMARY:" . $summary . "\r\n";
        $eventData .= "DESCRIPTION:" . $description . "\r\n";

        foreach ($attendees as $attendee) {
            $eventData .= "ATTENDEE;CN=" . $attendee->name . ":mailto:" . $attendee->email . "\r\n";
        }

        $eventData .= "DTSTART:" . $start->format('Ymd\THis\Z') . "\r\n";
        $eventData .= "DTEND:" . $end->format('Ymd\THis\Z') . "\r\n";
        $eventData .= "END:VEVENT\r\n";
        $eventData .= "END:VCALENDAR\r\n";

        $eventUrl = $calendarUrl . $this->prepareUuid() . '.ics';

        $response = $this->getClient()->request('PUT', $eventUrl, $eventData, [
            'Content-Type' => 'text/calendar; charset=utf-8',
        ]);

        if ($response['statusCode'] >= 200 && $response['statusCode'] < 300) {
            return $eventUrl;
        }

        throw new EventCreationFailed('Event was not created');
    }

    public function prepareUuid(): string
    {
        return uniqid();
    }

    /**
     * @throws EventDeletionFailed
     */
    public function deleteEvent(string $eventUrl): void
    {
        $response = $this->getClient()->request('DELETE', $eventUrl);

        if ($response['statusCode'] !== 204) {
            throw new EventDeletionFailed();
        }
    }

    /**
     * @return array<int, Calendar>
     */
    public function getCalendars(): array
    {
        $client = $this->getClient();

        $response = $client->propFind('', [
            '{DAV:}current-user-principal',
        ], 0);

        $userPrincipalUrl = $response['{DAV:}current-user-principal'][0]['value'];

        $response = $client->propFind($userPrincipalUrl, [
            '{urn:ietf:params:xml:ns:caldav}calendar-home-set',
        ], 0);

        $calendarHomeSetUrl = $response['{urn:ietf:params:xml:ns:caldav}calendar-home-set'][0]['value'];

        $calDavCalendars = $client->propFind($calendarHomeSetUrl, [
            '{DAV:}resourcetype',
            '{DAV:}displayname',
        ], 1);

        $calendars = [];

        foreach ($calDavCalendars as $url => $calendarDetails) {
            /** @var \Sabre\DAV\Xml\Property\ResourceType $resourceType */
            $resourceType = $calendarDetails['{DAV:}resourcetype'];
            $types = $resourceType->getValue();

            if (!in_array('{urn:ietf:params:xml:ns:caldav}calendar', $types, true)) {
                continue;
            }

            $calendars[] = new Calendar($url, $calendarDetails['{DAV:}displayname']);
        }

        return $calendars;
    }

}
