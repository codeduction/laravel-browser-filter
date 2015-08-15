<?php

namespace Spinen\BrowserFilter;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Mobile_Detect;

/**
 * Class Filter
 *
 * @package Spinen\BrowserFilter
 */
class Filter
{
    /**
     * The cache repository instance.
     *
     * @var Cache
     */
    protected $cache;

    /**
     * The client instance.
     *
     * @var \UAParser\Result\Client
     */
    protected $client;

    /**
     * The config repository instance.
     *
     * @var Configs
     */
    protected $config;

    /**
     * Location of the config file.
     *
     * @var string
     */
    protected $config_path = 'browserfilter.';

    /**
     * The mobile detector instance.
     *
     * @var Mobile_Detect
     */
    protected $detector;

    /**
     * The redirector instance.
     *
     * @var Redirector
     */
    protected $redirector;

    /**
     * Create a new browser filter middleware instance.
     *
     * @param Cache         $cache      Cache
     * @param Config        $config     Config
     * @param Mobile_Detect $detector   Mobile_Detect
     * @param ParserCreator $parser     ParserCreator
     * @param Redirector    $redirector Redirector
     */
    public function __construct(
        Cache $cache,
        Config $config,
        Mobile_Detect $detector,
        ParserCreator $parser,
        Redirector $redirector
    ) {
        $this->cache = $cache;
        $this->config = $config;
        $this->detector = $detector;
        $this->client = $parser->parseAgent($this->detector->getUserAgent());
        $this->redirector = $redirector;
    }

    /**
     * Generate the key to use to cache the determination.
     *
     * @return string
     */
    private function generateCacheKey()
    {
        return $this->client->device->family . ':' . $this->client->ua->family . ':' . $this->client->ua->toVersion();
    }

    /**
     * Get the browsers being filtered.
     *
     * @return string|array
     */
    private function getBlockedBrowsers()
    {
        return $this->config->get($this->config_path . 'blocked.' . $this->client->device->family);
    }

    /**
     * Get the versions of the browsers being filtered.
     *
     * @return string|array
     */
    private function getBlockedBrowserVersions()
    {
        return $this->config->get($this->config_path .
                                  'blocked.' .
                                  $this->client->device->family .
                                  '.' .
                                  $this->client->ua->family);
    }

    /**
     * Get the timeout of the cached value.
     *
     * @return mixed
     */
    private function getCacheTimeout()
    {
        return $this->config->get($this->config_path . 'timeout');
    }

    /**
     * Get the route to the redirect path.
     *
     * @return string|null
     */
    private function getRedirectRoute()
    {
        return $this->config->get($this->config_path . 'route');
    }

    /**
     * Handle an incoming request.
     *
     * @param Request $request Request
     * @param Closure $next    Closure
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if ($this->onRedirectPath($request)) {
            return $next($request);
        }

        $cache_key = $this->generateCacheKey();

        $redirect = $this->cache->get($cache_key);

        if (is_null($redirect)) {
            $redirect = $this->determineRedirect($cache_key);
        }

        if ($redirect) {
            return $redirect;
        }

        return $next($request);
    }

    /**
     * Determines if the client needs to be redirected.
     *
     * Caches the determination, so that next time the process of making the determination does not have to be reran.
     *
     * @param string $cache_key string
     *
     * @return \Illuminate\Http\RedirectResponse|bool
     */
    private function determineRedirect($cache_key)
    {
        $redirect = false;

        if ($this->isBlocked()) {
            $redirect = $this->redirector->route($this->getRedirectRoute());
        }

        $this->cache->put($cache_key, $redirect, $this->getCacheTimeout());

        return $redirect;
    }

    /**
     * Checks to see if the browser/client is blocked.
     *
     * @return bool
     */
    private function isBlocked()
    {
        return $this->isBlockedDevice() || $this->isBlockedBrowser() || $this->isBlockedBrowserVersion();
    }

    /**
     * Checks to see if all versions of the browser is blocked.
     *
     * @return bool
     */
    private function isBlockedBrowser()
    {
        return $this->getBlockedBrowserVersions() === '*';
    }

    /**
     * Checks to see if the version of the browser is blocked.
     *
     * Uses the php version_compare function to decide if there is a match.
     *
     * @link http://php.net/manual/en/function.version-compare.php
     *
     * @return bool
     */
    private function isBlockedBrowserVersion()
    {
        $denied = false;

        // cache it, so that we don't have to keep asking for it
        $client_version = $this->client->ua->toVersion();

        foreach ((array)$this->getBlockedBrowserVersions() as $operator => $version) {
            $denied |= (bool)version_compare($client_version, $version, $operator);
        }

        return $denied;
    }

    /**
     * Checks to see if all browsers of the device family is blocked.
     *
     * @return bool
     */
    private function isBlockedDevice()
    {
        return $this->getBlockedBrowsers() === '*';
    }

    /**
     * Check to see if we are on the redirect page.
     *
     * If we did not test for this, then we would get into a redirect loop.
     *
     * @param Request $request Request
     *
     * @return bool
     */
    private function onRedirectPath(Request $request)
    {
        return $request->path() === $this->getRedirectRoute();
    }
}
