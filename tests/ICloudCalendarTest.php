<?php

declare(strict_types=1);

namespace Zaptime\ICloudCalendar\Tests;

use DateTime;
use Mockery;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\Client;
use Sabre\DAV\Xml\Property\ResourceType;
use Zaptime\ICloudCalendar\Attendee;
use Zaptime\ICloudCalendar\Exceptions\EventCreationFailed;
use Zaptime\ICloudCalendar\Exceptions\EventDeletionFailed;
use Zaptime\ICloudCalendar\ICloudCalendar;

class ICloudCalendarTest extends TestCase
{

    /** @test */
    public function can_create_event(): void
    {
        $davClient = Mockery::mock(Client::class);
        $davClient->shouldReceive('request')
            ->with(
                'PUT',
                'https://caldav.icloud.test/1234567890/1234567890.ics',
                "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:1234567890@zaptime.app\r\nSUMMARY:Test event\r\nDESCRIPTION:Test description\r\nATTENDEE;CN=John Doe:mailto:john@doe.test\r\nDTSTART:20210101T120000Z\r\nDTEND:20210101T130000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
                [
                    'Content-Type' => 'text/calendar; charset=utf-8'
                ]
            )->andReturn([
                'statusCode' => 200,
            ])
            ->once();

        $ICloudCalendar = Mockery::mock(ICloudCalendar::class)->makePartial();
        $ICloudCalendar->shouldReceive('getClient')
            ->with()
            ->andReturn($davClient)
            ->once();

        $ICloudCalendar->shouldReceive('prepareUuid')
            ->with()
            ->andReturn('1234567890')
            ->twice();

        $eventUrl = $ICloudCalendar->createEvent(
            'https://caldav.icloud.test/1234567890/',
            'Test event',
            new DateTime('2021-01-01 12:00:00'),
            new DateTime('2021-01-01 13:00:00'),
            'Test description',
            [
                new Attendee('John Doe', 'john@doe.test'),
            ],
        );

        $this->assertEquals('https://caldav.icloud.test/1234567890/1234567890.ics', $eventUrl);
    }

    /** @test */
    public function throws_exception_if_event_is_not_created(): void
    {
        $this->expectException(EventCreationFailed::class);

        $davClient = Mockery::mock(Client::class);
        $davClient->shouldReceive('request')
            ->with(
                'PUT',
                'https://caldav.icloud.test/1234567890/1234567890.ics',
                "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:1234567890@zaptime.app\r\nSUMMARY:Test event\r\nDESCRIPTION:Test description\r\nATTENDEE;CN=John Doe:mailto:john@doe.test\r\nDTSTART:20210101T120000Z\r\nDTEND:20210101T130000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
                [
                    'Content-Type' => 'text/calendar; charset=utf-8'
                ]
            )->andReturn([
                'statusCode' => 400,
            ])
            ->once();

        $ICloudCalendar = Mockery::mock(ICloudCalendar::class)->makePartial();
        $ICloudCalendar->shouldReceive('getClient')
            ->with()
            ->andReturn($davClient)
            ->once();

        $ICloudCalendar->shouldReceive('prepareUuid')
            ->with()
            ->andReturn('1234567890')
            ->twice();

        $ICloudCalendar->createEvent(
            'https://caldav.icloud.test/1234567890/',
            'Test event',
            new DateTime('2021-01-01 12:00:00'),
            new DateTime('2021-01-01 13:00:00'),
            'Test description',
            [
                new Attendee('John Doe', 'john@doe.test'),
            ],
        );
    }

    /** @test */
    public function can_delete_event(): void
    {
        $this->expectNotToPerformAssertions();

        $davClient = Mockery::mock(Client::class);
        $davClient->shouldReceive('request')
            ->with(
                'DELETE',
                'https://caldav.icloud.test/1234567890/1234567890.ics',
            )->andReturn([
                'statusCode' => 204,
            ])
            ->once();

        $ICloudCalendar = Mockery::mock(ICloudCalendar::class)->makePartial();
        $ICloudCalendar->shouldReceive('getClient')
            ->with()
            ->andReturn($davClient)
            ->once();

        $ICloudCalendar->deleteEvent('https://caldav.icloud.test/1234567890/1234567890.ics');
    }

    /** @test */
    public function throws_exception_if_event_is_not_deleted(): void
    {
        $this->expectException(EventDeletionFailed::class);

        $davClient = Mockery::mock(Client::class);
        $davClient->shouldReceive('request')
            ->with(
                'DELETE',
                'https://caldav.icloud.test/1234567890/1234567890.ics',
            )->andReturn([
                'statusCode' => 400,
            ])
            ->once();

        $ICloudCalendar = Mockery::mock(ICloudCalendar::class)->makePartial();
        $ICloudCalendar->shouldReceive('getClient')
            ->with()
            ->andReturn($davClient)
            ->once();

        $ICloudCalendar->deleteEvent('https://caldav.icloud.test/1234567890/1234567890.ics');
    }

    /** @test */
    public function can_prepare_uuid(): void
    {
        $davClient = Mockery::mock(Client::class);
        $davClient->shouldReceive('propFind')
            ->with('', ['{DAV:}current-user-principal'], 0)
            ->andReturn([
                '{DAV:}current-user-principal' => [
                    [
                        'value' => 'https://caldav.icloud.test/1234567890/',
                    ]
                ]
            ])
            ->once();

        $davClient->shouldReceive('propFind')
            ->with('https://caldav.icloud.test/1234567890/', ['{urn:ietf:params:xml:ns:caldav}calendar-home-set',], 0)->andReturn([
                '{urn:ietf:params:xml:ns:caldav}calendar-home-set' => [
                    [
                        'value' => 'https://caldav.icloud.test/1234567890/calendar-home-set',
                    ],
                ],
            ])
            ->once();

        $davClient->shouldReceive('propFind')
            ->with('https://caldav.icloud.test/1234567890/calendar-home-set', ['{DAV:}resourcetype', '{DAV:}displayname',], 1)->andReturn([
                'statusCode' => 400,
            ])
            ->andReturn([
                'https://caldav.icloud.test/1234567890/calendar-home-set/test-calendar' => [
                    '{DAV:}resourcetype' => new ResourceType(['{urn:ietf:params:xml:ns:caldav}calendar']),
                    '{DAV:}displayname' => 'Test calendar',
                ],
                'https://caldav.icloud.test/1234567890/calendar-home-set/test-2-calendar' => [
                    '{DAV:}resourcetype' => new ResourceType(['{urn:ietf:params:xml:ns:caldav}calendar']),
                    '{DAV:}displayname' => 'Test 2 calendar',
                ],
            ])
            ->once();

        $ICloudCalendar = Mockery::mock(ICloudCalendar::class)->makePartial();
        $ICloudCalendar->shouldReceive('getClient')
            ->with()
            ->andReturn($davClient)
            ->once();

        $calendars = $ICloudCalendar->getCalendars();

        $this->assertIsArray($calendars);
        $this->assertEquals(2, count($calendars));
        $this->assertEquals('Test calendar', $calendars[0]->name);
        $this->assertEquals('https://caldav.icloud.test/1234567890/calendar-home-set/test-calendar', $calendars[0]->url);
        $this->assertEquals('Test 2 calendar', $calendars[1]->name);
        $this->assertEquals('https://caldav.icloud.test/1234567890/calendar-home-set/test-2-calendar', $calendars[1]->url);
    }

}