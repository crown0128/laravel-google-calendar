<?php

namespace Spatie\GoogleCalendar;

use Carbon\Carbon;
use DateTime;
use Google_Service_Calendar_Event;
use Google_Service_Calendar_EventDateTime;
use Illuminate\Support\Collection;

class Event
{
    /** @var Google_Service_Calendar_Event */
    public $googleEvent;

    /** @var int */
    protected $calendarId;

    public static function createFromGoogleCalendarEvent(Google_Service_Calendar_Event $googleEvent, $calendarId)
    {
        $event = new static();

        $event->googleEvent = $googleEvent;

        $event->calendarId = $calendarId;

        return $event;
    }

    public static function create(array $properties, $calendarId = null)
    {
        $event = new static;

        $event->calendarId = static::getGoogleCalendar($calendarId)->getCalendarId();

        foreach ($properties as $name => $value) {
            $event->$name = $value;
        }

        return $event->save();
    }

    public function __construct()
    {
        $this->googleEvent = new Google_Service_Calendar_Event();
    }

    /**
     * @param string $name
     * @return mixed
     */
    public function __get($name)
    {
        $name = $this->translateFieldName($name);

        if ($name === 'sortDate') {
            return $this->getSortDate();
        }

        $value = array_get($this->googleEvent, $name);

        if (in_array($name, ['startDate', 'end.date']) && $value) {
            $value = Carbon::createFromFormat('Y-m-d', $value);
        }

        if (in_array($name, ['start.dateTime', 'end.dateTime']) && $value) {
            $value = Carbon::createFromFormat(DateTime::RFC3339, $value);
        }

        return $value;
    }

    public function __set($name, $value)
    {
        $name = $this->translateFieldName($name);

        if (in_array($name, ['start.date', 'end.date', 'start.dateTime', 'end.dateTime'])) {
            $this->setDateProperty($name, $value);

            return;
        }

        array_set($this->googleEvent, $name, $value);
    }

    /**
     * @return bool
     */
    public function exists()
    {
        return $this->id != '';
    }

    /**
     * @return bool
     */
    public function isAllDayEvent()
    {
        return is_null($this->googleEvent['start']['dateTime']);
    }

    /**
     * @param \Carbon\Carbon|null $startDateTime
     * @param \Carbon\Carbon|null $endDateTime
     * @param array $queryParameters
     * @param string|null $calendarId
     *
     * @return \Illuminate\Support\Collection
     */
    public static function get(Carbon $startDateTime = null, Carbon $endDateTime = null, array $queryParameters = [], string $calendarId = null) : Collection
    {
        $googleCalendar = self::getGoogleCalendar($calendarId);

        $googleEvents = $googleCalendar->listEvents($startDateTime, $endDateTime, $queryParameters);

        return collect($googleEvents)
            ->map(function (Google_Service_Calendar_Event $event) use ($calendarId) {
                return Event::createFromGoogleCalendarEvent($event, $calendarId);
            });
    }

    /**
     * @param string $id
     * @param string $calendarId
     *
     * @return \Spatie\GoogleCalendar\Event
     */
    public static function find($id, $calendarId = null) : Event
    {
        $googleCalendar = self::getGoogleCalendar($calendarId);

        $googleEvent = $googleCalendar->getEvent($id);
        
        return Event::createFromGoogleCalendarEvent($googleEvent, $calendarId);
    }

    /**
     * @return mixed
     */
    public function save() : Event
    {
        $method = $this->exists() ? 'updateEvent' : 'insertEvent';

        $googleCalendar = $this->getGoogleCalendar();

        $googleEvent =  $googleCalendar->$method($this);
        
        return Event::createFromGoogleCalendarEvent($googleEvent, $googleCalendar->getCalendarId());
    }

    /**
     * @param string $id
     *
     * @return mixed
     */
    public function delete($id = null)
    {
        return $this->getGoogleCalendar($this->calendarId)->deleteEvent($id ?? $this->id);
    }

    /**
     * @param string $calendarId
     *
     * @return \Spatie\GoogleCalendar\GoogleCalendar
     */
    protected static function getGoogleCalendar($calendarId = null) : GoogleCalendar
    {
        $calendarId = $calendarId ?? config('laravel-google-calendar.calendar_id');

        return GoogleCalendarFactory::createForCalendarId($calendarId);
    }

    /**
     * @param string $name
     * @param \Carbon\Carbon $date
     */
    protected function setDateProperty($name, Carbon $date)
    {
        $eventDateTime = new Google_Service_Calendar_EventDateTime();

        if (in_array($name, ['start.date', 'end.date'])) {
            $eventDateTime->setDate($date->format('Y-m-d'));
        }

        if (in_array($name, ['start.dateTime', 'end.dateTime'])) {
            $eventDateTime->setDate($date->format(DateTime::RFC3339));
        }

        if (starts_with($name, 'start')) {
            $this->googleEvent->setStart($eventDateTime);
        }

        if (starts_with($name, 'end')) {
            $this->googleEvent->setEnd($eventDateTime);
        }
    }

    /**
     * @param $name
     *
     * @return string
     */
    protected function translateFieldName($name)
    {
        if ($name === 'name') {
            return 'summary';
        }

        if ($name === 'startDate') {
            return 'start.date';
        }

        if ($name === 'endDate') {
            return 'end.date';
        }

        if ($name === 'startDateTime') {
            return 'start.dateTime';
        }

        if ($name === 'endDateTime') {
            return 'end.dateTime';
        }

        return $name;
    }

    public function getSortDate() : Carbon
    {
        if ($this->startDate) {
            return $this->startDate;
        }

        return $this->startDateTime;
    }
}
