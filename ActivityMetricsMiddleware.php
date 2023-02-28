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
        // btw we dont check if the ip is unique but if needed it isnt hard to implement

        // Cache the device and country for this IP address
        $ip = $request->ip();
        $cacheKey = "activity-metrics:$ip";
        $cachedData = Cache::get($cacheKey);

        if ($cachedData) {
            [$device, $country] = $cachedData;
        } else {
            $userAgent = $request->header('User-Agent');
            $device = $this->getDeviceFromUserAgent($userAgent);
            $country = $this->getCountryFromIp($ip);
            Cache::put($cacheKey, [$device, $country], 60 * 1); // Cache for 1 hour
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
        return $device ?: 'unknown';
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
