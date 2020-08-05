<?php

namespace Spinen\BrowserFilter\Route;

use Illuminate\Http\Request;
use Spinen\BrowserFilter\Filter;

/**
 * Class RouteFilter
 *
 * @package Spinen\BrowserFilter\Route
 */
abstract class RouteFilter extends Filter
{
    /**
     * Loop through all of the parameters in the string and process them.
     *
     * @param string $filter The filter separated by '/'
     *
     * @return void
     */
    private function extractRule($filter)
    {
        list($device, $browser, $operator_versions) = array_pad(array_filter(explode('/', $filter, 3)), 3, '*');

        // Apply rule to all browsers of the device
        if ('*' === $browser) {
            $this->rules[$device] = '*';

            return;
        }

        // Apply rule to all versions of the browser
        if ('*' === $operator_versions) {
            $this->rules[$device][$browser] = '*';

            return;
        }

        $this->rules[$device][$browser] = $this->extractVersions($device, $browser, $operator_versions);
    }

    /**
     * Loop through all of the versions in the string and process them.
     *
     * @param string $device The device
     * @param string $browser The browser
     * @param string $operator_versions The versions separated by '|'
     *
     * @return array
     */
    private function extractVersions($device, $browser, $operator_versions)
    {
        // Were there existing rules for the browser?
        $versions = empty($this->getRules()[$device][$browser]) ? [] : $this->getRules()[$device][$browser];

        foreach (array_filter(explode('|', $operator_versions)) as $operator_version) {
            // Remove everything to the leading numbers
            $version = preg_replace("/^[^\\d]*/u", "", $operator_version);
            // Default no operator to equals
            $operator = str_replace($version, '', $operator_version) ?: '=';

            $versions[$operator] = $version;
        }

        return $versions;
    }

    /**
     * @inheritDoc
     */
    public function generateCacheKey(Request $request)
    {
        return parent::generateCacheKey($request) . ':' . $this->getFilterType() . ':' . md5($request->path());
    }

    /**
     * Generate the key to use to cache the processed filter string into an array.
     *
     * @param string $filter_string The filters separated by ';'
     *
     * @return string
     */
    private function generateFilterStringCacheKey($filter_string)
    {
        return 'filter_string:' . md5($filter_string);
    }

    /**
     * Loop through all of the filters in the string and process them.
     *
     * @param string $filter_string The filters separated by ';'
     *
     * @return void
     */
    public function parseFilterString($filter_string)
    {
        if (empty($filter_string)) {
            return;
        }

        $cache_key = $this->generateFilterStringCacheKey($filter_string);

        $this->rules = $this->cache->get($cache_key, []);

        if ($this->rules !== []) {
            return;
        }

        array_map([$this, 'extractRule'], array_filter(explode(';', $filter_string)));

        $this->cache->put($cache_key, $this->rules, $this->getCacheTimeout());
    }
}
