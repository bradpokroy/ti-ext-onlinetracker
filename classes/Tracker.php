<?php

namespace Igniter\OnlineTracker\Classes;

use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use Igniter\OnlineTracker\Models\Settings;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Jenssegers\Agent\Agent;
use MaxMind\Db\Reader\InvalidDatabaseException;
use Str;

class Tracker
{
    protected $config;

    protected $repositoryManager;

    protected $request;

    protected $session;

    protected $route;

    protected $agent;

    protected $reader;

    protected $booted;

    public function __construct(
        Settings $config,
        RepositoryManager $repositoryManager,
        Request $request,
        Session $session,
        Router $route,
        Agent $agent,
        Reader $reader
    )
    {
        $this->config = $config;
        $this->repositoryManager = $repositoryManager;
        $this->request = $request;
        $this->session = $session;
        $this->route = $route;
        $this->agent = $agent;
        $this->reader = $reader;

        $agent->setUserAgent($userAgent = $request->userAgent());
        $agent->setHttpHeaders($headers = $request->header());
    }

    public function boot()
    {
        if ($this->booted)
            return;

        if ($this->isTrackable())
            $this->track();

        $this->booted = TRUE;
    }

    public function track()
    {
        $this->repositoryManager->createLog($this->getLogData());
    }

    protected function isTrackable()
    {
        return $this->config->get('status')
            AND $this->isTrackableIp()
            AND $this->robotIsTrackable()
            AND $this->routeIsTrackable()
            AND $this->pathIsTrackable();
    }

    protected function isTrackableIp()
    {
        $ipAddress = $this->request->getClientIp();
        $excludeIps = $this->config->get('exclude_ips');

        return !$excludeIps
            OR $this->ipNotInRanges($ipAddress, $excludeIps);
    }

    protected function robotIsTrackable()
    {
        $trackRobots = $this->config->get('track_robots', FALSE);

        return !$this->agent->isRobot()
            OR !$trackRobots;
    }

    protected function routeIsTrackable()
    {
        if (!$this->route)
            return FALSE;

        $currentRouteName = $this->route->currentRouteName();
        $excludeRoutes = $this->explodeString($this->config->get('exclude_routes'));

        return !$excludeRoutes
            OR !$currentRouteName
            OR !$this->matchesPattern($currentRouteName, $excludeRoutes);
    }

    protected function pathIsTrackable()
    {
        $currentPath = $this->request->path();
        $excludePaths = $this->explodeString($this->config->get('exclude_paths'));

        return !$excludePaths
            OR empty($currentPath)
            OR !$this->matchesPattern($currentPath, $excludePaths);
    }

    protected function getLogData()
    {
        return [
            'session_id' => $this->session->getId(),
            'ip_address' => $this->request->getClientIp(),
            'access_type' => $this->request->method(),
            'geoip_id' => $this->getGeoIpId(),
            'request_uri' => $this->request->path(),
            'query' => $this->request->getQueryString(),
            'referrer_uri' => $this->getReferer(),
            'user_agent' => $this->request->userAgent(),
            'headers' => $this->request->headers->all(),
            'browser' => $this->agent->browser(),
        ];
    }

    //
    // Agent
    //

    protected function getReferer()
    {
        $referer = $this->request->header('referer', $this->request->header('utm_source', ''));

        if (starts_with($referer, root_url()))
            $referer = null;

        return $referer;
    }

    //
    // IP Range
    //

    protected function getGeoIpId()
    {
        try {
            $record = $this->reader->city($this->request->getClientIp());

            $geoIpId = $this->repositoryManager->createGeoIp(
                $this->getGeoIpData($record),
                ['latitude', 'longitude']
            );

            return $geoIpId;
        }
        catch (AddressNotFoundException $e) {
        }
        catch (InvalidDatabaseException $e) {
        }
    }

    protected function ipNotInRanges($ip, $excludeRange)
    {
        if (!is_array($excludeRange))
            $excludeRange = [$excludeRange];

        foreach ($excludeRange as $range) {
            if ($this->ipInRange($ip, $range))
                return FALSE;
        }

        return TRUE;
    }

    protected function ipInRange($ip, $range)
    {
        // Wildcarded range
        // 192.168.1.*
        $range = $this->ipRangeIsWildCard($range);

        // Dashed range
        //   192.168.1.1-192.168.1.100
        //   0.0.0.0-255.255.255.255
        if ($parsedRange = $this->ipRangeIsDashed($range)) {
            list($ip1, $ip2) = $parsedRange;

            return ip2long($ip) >= $ip1 AND ip2long($ip) <= $ip2;
        }

        // Masked range or fixed IP
        //   192.168.17.1/16 or
        //   127.0.0.1/255.255.255.255 or
        //   10.0.0.1
        return ipv4_match_mask($ip, $range);
    }

    protected function ipRangeIsWildCard($range)
    {
        if (!str_contains($range, '-') AND str_contains($range, '*'))
            return str_replace('*', '0', $range).'-'.str_replace('*', '255', $range);

        return null;
    }

    protected function ipRangeIsDashed($range)
    {
        if (count($twoIps = explode('-', $range)) == 2)
            return $twoIps;

        return null;
    }

    protected function explodeString($string)
    {
        return array_map(function ($str) {
            return trim($str);
        }, explode(',', str_replace("\n", ',', $string)));
    }

    protected function matchesPattern($what, $patterns)
    {
        foreach ($patterns as $pattern) {
            if (Str::is($pattern, $what))
                return TRUE;
        }

        return FALSE;
    }

    protected function getGeoIpData($record)
    {
        return [
            'latitude' => $record->location->latitude,
            'longitude' => $record->location->longitude,
            'region' => $record->mostSpecificSubdivision->isoCode,
            'city' => $record->city->name,
            'postal_code' => $record->postal->code,
            'country_iso_code_2' => $record->country->isoCode,
        ];
    }
}