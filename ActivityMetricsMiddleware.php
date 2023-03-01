<?php

/*
 * (C) Copyright 2023 Storm Hub (https://github.com/3UR/laravel-activity-metrics-middleware)
 * This code is licensed under the Public Use License.
 * You may use, modify and distribute this code, but you may not sell it.
 */

namespace App\Http\Middleware;

use Cache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Prometheus\CollectorRegistry;
use GeoIp2\Database\Reader;
use Jenssegers\Agent\Agent;

class ActivityMetricsMiddleware
{
    private $dauCounter;
	public function __construct(CollectorRegistry $registry)
	{
		// Create a new counter to track DAU
		$this->dauCounter = $registry->getOrRegisterCounter(
            '',
            'daily_active_users',
            'Number of daily active users',
            ['device', 'country']
        );
	}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->ip();
        $cacheKey = "activity-metrics:$ip";
        $cachedData = Cache::get($cacheKey); // Get it from cache

        if ($cachedData) { // If its cached use it!
            [$device, $country] = $cachedData;
        } else { // Its not cached so cache it
            $userAgent = $request->header('User-Agent');
            $device = $this->getDeviceFromUserAgent($userAgent);
            $country = $this->getCountryFromIp($ip);
            Cache::put($cacheKey, [$device, $country], 60 * 5); // Cache for 5 hours
        }

        // Increment a counter for the DAU (Daily Active Users)
        $this->dauCounter->incBy(1, [$device, $country]);
        return $next($request);
    }

    /**
     * Get the device from the User-Agent header.
     */
    private function getDeviceFromUserAgent(string $userAgent): string
    {
        $agent = new Agent();
        $device = $agent->device($userAgent);
        $platform = $agent->platform($userAgent); // fallback 
        return $device ?: ($platform ?: 'unknown');
    }

    /**
     * Get the country from the IP address useing GeoLite2.
     */
    private function getCountryFromIp(string $ip): string
    {
        try {
            $reader = new Reader(storage_path('app/geoip/GeoLite2-Country.mmdb'));
            $record = $reader->country($ip);
            return $record->country->isoCode ?: 'unknown';
        } catch (\Exception) {
            return 'unknown/exception';
        }
    }
}
