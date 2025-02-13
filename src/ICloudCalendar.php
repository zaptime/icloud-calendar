<?php

declare(strict_types=1);

namespace Zaptime\ICloudCalendar;

use Carbon\Carbon;
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

    public function getEvents(string $calendarUrl, Carbon $startDate, Carbon $endDate): array
    {
        $client = $this->getClient();
        $xmlRequest = '<?xml version="1.0" encoding="UTF-8"?>
        <c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
            <d:prop>
                <d:getetag/>
                <c:calendar-data/>
            </d:prop>
            <c:filter>
                <c:comp-filter name="VCALENDAR">
                    <c:comp-filter name="VEVENT">
                        <c:time-range start="' . $startDate->format('Ymd\THis\Z') . '" end="' . $endDate->format('Ymd\THis\Z') . '"/>
                    </c:comp-filter>
                </c:comp-filter>
            </c:filter>
        </c:calendar-query>';

        $response = $client->request('REPORT', $calendarUrl, $xmlRequest, [
            'Depth' => '1',
            'Content-Type' => 'application/xml; charset=utf-8',
        ]);

        if ($response['statusCode'] < 200 || $response['statusCode'] >= 300) {
            throw new \RuntimeException('Failed to fetch events');
        }

        return $this->parseICloudEvents($response);
    }

    private function parseICloudEvents(array $response): array
    {
        $events = [];

        // Load XML response body
        $xml = new \SimpleXMLElement($response['body']);

        // Register the namespace for DAV and CalDAV
        $xml->registerXPathNamespace('d', 'DAV:');
        $xml->registerXPathNamespace('c', 'urn:ietf:params:xml:ns:caldav');

        // Extract all calendar-data elements
        foreach ($xml->xpath('//c:calendar-data') as $calendarData) {
            $icalData = (string) $calendarData;

            // Parse the iCalendar data
            $parsedEvents = $this->parseICalData($icalData);
            $events = array_merge($events, $parsedEvents);
        }

        return $events;
    }

    private function parseICalData(string $icalData): array
    {
        $events = [];
        $lines = explode("\n", $icalData);
        $event = [];
        $insideEvent = false;
        $timezone = null;

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === 'BEGIN:VEVENT') {
                $insideEvent = true;
                $event = [];
            } elseif ($line === 'END:VEVENT') {
                $insideEvent = false;
                $events[] = $event;
            } elseif ($insideEvent) {
                if (strpos($line, ':') !== false) {
                    [$key, $value] = explode(':', $line, 2);

                    // Handle timezones (TZID in DTSTART/DTEND)
                    if (strpos($key, ';TZID=') !== false) {
                        preg_match('/TZID=([^:]+):(.+)/', $line, $matches);
                        if (!empty($matches[1]) && !empty($matches[2])) {
                            $key = str_replace(';TZID='.$matches[1], '', $key);
                            $event['timezone'] = $matches[1];
                            $value = $matches[2];
                        }
                    }

                    // Convert date formats
                    if ($key === 'DTSTART' || $key === 'DTEND') {
                        $event[$key] = Carbon::createFromFormat('Ymd\THis', $value, $event['timezone'] ?? 'UTC');
                    } elseif ($key === 'DTEND;VALUE=DATE' || $key === 'DTSTART;VALUE=DATE') {
                        $event[str_replace(';VALUE=DATE', '', $key)] = Carbon::createFromFormat('Ymd', $value, $event['timezone'] ?? 'UTC')->startOfDay();
                    } else {
                        $event[strtolower($key)] = $value;
                    }
                }
            }
        }

        return $events;
    }


}
