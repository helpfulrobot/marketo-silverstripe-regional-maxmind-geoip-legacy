<?php

/**
 * @author Kirk Mayo <kirk.mayo@solnet.co.nz>
 *
 * A cache of geo data for a IP address
 */

class MarketoRegionalDriver extends DataObject
{
    public $defaultPath = '/usr/share/GeoIP/GeoIP.dat';
    public $defaultPathISP = '/usr/share/GeoIP/GeoIPISP.dat';
    public $json;

    public static $statuses = array (
        'SUCCESS' => 'Success',
        'SUCCESS_CACHED' => 'Successfully found and cached response',
        'IP_ADDRESS_INVALID' => 'You have not supplied a valid IPv4 or IPv6 address',
        'IP_ADDRESS_RESERVED' => 'You have supplied an IP address which belongs to ' .
            'a reserved or private range',
        'IP_ADDRESS_NOT_FOUND' => 'The supplied IP address is not in the database',
        'DOMAIN_REGISTRATION_REQUIRED' => 'The domain of your site is not registered.',
        'DOMAIN_REGISTRATION_REQUIRED' => 'The domain of your site is not registered.',
        'GEOIP_EXCEPTION' => 'GEOIP_EXCEPTION [ERROR]',
        'GEOIP_MISSING' => 'GeoIP module does not exist'
    );

    public static $privateAddresses = array(
        '10.0.0.0|10.255.255.255',
        '172.16.0.0|172.31.255.255',
        '192.168.0.0|192.168.255.255',
        '169.254.0.0|169.254.255.255',
        '127.0.0.0|127.255.255.255'
    );

    public static function getStatuses($code = null) {
        if ($code && isset(self::$statuses[$code])) {
            return self::$statuses[$code];
        }
        return self::$statuses;
    }

    public function processIP($ip) {
        // setup the default marketo bject
        $request = Config::inst()->get('DefaultMarketoResponse', 'request');
        $statusArray = Config::inst()->get('DefaultMarketoResponse', 'status');
        $result = Config::inst()->get('DefaultMarketoResponse', 'result');

        $status = null;
        $path = Config::inst()->get('IPInfoCache', 'GeoPathCity');
        if (!$path) $path = $this->defaultPath;
        if (!file_exists($path)) {
            user_error('Error loading Geo database', E_USER_ERROR);
        }

        $request['ip'] = $ip;
        $request['type'] = MarketoRegionalDriver::ipVersion($ip);
        if ($request['type'] == 'IPv4') {
            $isPrivate = MarketoRegionalDriver::isPrivateIP($ip);
            if ($isPrivate) {
                $status = self::setStatus('IP_ADDRESS_RESERVED', null, $status);
            }
            $geo = geoip_open($path, GEOIP_STANDARD);
            $record = geoip_record_by_addr($geo, $ip);
        } else {
            /* Will add IPv6 checking later
            $path = '/usr/share/GeoIP/GeoLiteCityv6.dat';
            $geo = geoip_open($path, GEOIP_STANDARD);
            $record = geoip_record_by_addr_v6($geo, $ip);
            */
        }

        $countryCode = null;
        if ($record && is_object($record)) {
            try {
                $result['location']['continent_code'] = $record->continent_code;
                // fetch continent by continent_code
                //$result['location']['continent_names'] = $record->continent->names;

                $countryCode = $record->country_code;
                $result['location']['country_code'] = $countryCode;
                //$result['location']['country_names'] = $record->country->names;

                $result['location']['postal_code'] = $record->postal_code;
                $result['location']['city_name'] = $record->city;

                $result['location']['latitude'] = $record->latitude;
                $result['location']['longitude'] = $record->longitude;
                // get timezone from region code
                //$result['location']['time_zone'] = $record->location->timeZone;
            } catch (Exception $e) {
                $status = self::setStatus('GEOIP_EXCEPTION', $e, $status);
            }
        }

        $geoRegion = null;
        if ($countryCode) {
            $geoRegion = GeoRegion::get()
                ->filter('RegionCode', $countryCode)
                ->first();
            if ($geoRegion && $geoRegion->exists()) {
                $result['location']['marketo_region_name'] = $geoRegion->Name;
                $result['location']['marketo_region_code'] = $geoRegion->RegionCode;
                $result['location']['marketo_region_time_zone'] = $geoRegion->TimeZone;
            }
        }

        // fetch ISP details
        $pathISP = Config::inst()->get('IPInfoCache', 'GeoPathISP');
        if (!$pathISP) $path = $this->defaultPathISP;
        if (!file_exists($pathISP)) {
            user_error('Error loading Geo ISP database', E_USER_ERROR);
        }
        $isp = geoip_open($pathISP, GEOIP_STANDARD);
        if ($request['type'] == 'IPv4') {
            $record = geoip_name_by_addr($isp, $ip);
        } else {
            /* Will add IPv6 checking later
            $record = geoip_name_by_addr_v6($isp, $ip);
            */
        }
        if ($record) {
            $result['organization']['isp'] = $record;
        }
        

        if ($status) {
            $statusArray['code'] = self::setStatus(null, null, $status);
            $statusArray['message'] = self::getStatusMessage($status);
            // do not cache a failure
            $this->json = json_encode(array(
                'request' => $request,
                'status' => $statusArray,
                'result' => $result
            ));
            return null;
        } else {
            // return cached success message
            $statusArray['code'] = self::setStatus('SUCCESS_CACHED', null, $status);
            $statusArray['message'] = self::getStatusMessage($status);
            $this->json = json_encode(array(
                'request' => $request,
                'status' => $statusArray,
                'result' => $result
            ));
        }

        // we write a different json object with a cached status to the DB
        $statusArray['code'] = self::setStatus('SUCCESS', null);
        $statusArray['message'] = self::getStatusMessage($statusArray['code']);
        $dbJson = json_encode(array(
            'request' => $request,
            'status' => $statusArray,
            'result' => $result
        ));

        return $dbJson;
    }

    public static function setStatus($code, $e, $status = null) {
        if ($status) return $status;
        if ($code == 'GEOIP_EXCEPTION' && $e && $e instanceof Exception) {
            self::$statuses['GEOIP_EXCEPTION'] = str_replace(
                'ERROR',
                $e->getMessage(),
                self::$statuses['GEOIP_EXCEPTION']
            );
        }
        return $code;
    }

    public static function getStatusMessage($status) {
        if (!$status) $status = 'SUCCESS_CACHED';
        return self::$statuses[$status];
    }

    public function getDetails() {
        return $this->Info;
    }

    public function getJSON() {
        return $this->json;
    }

    public function clearIPCache() {
        $this->write(false, false, true);
    }

    public static function ipVersion($ip = null) {
        return (strpos($ip, ':') === false) ? 'IPv4' : 'IPv6';
    }

    public static function isPrivateIP($ip) {
        $longIP = ip2long($ip);
        if ($longIP != -1) {
            foreach (MarketoRegionalDriver::$privateAddresses as $privateAddress) {
                list($start, $end) = explode('|', $privateAddress);
                if ($longIP >= ip2long($start) && $longIP <= ip2long($end)) return (true);
            }
        }
        return false;
    }
}
