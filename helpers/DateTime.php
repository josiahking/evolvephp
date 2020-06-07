<?php

/**
 * @see       https://github.com/josiahking/evolvephp for read me and documentation
 * @copyright https://github.com/josiahking/evolvephp/blob/master/COPYRIGHT.md
 * @license   https://github.com/josiahking/evolvephp/blob/master/LICENSE.md
 * @package EvolvePHP
 * @author 
 * @link Documentation on this file
 * @since Version 1.0
 * @filesource
 */

namespace EvolvePhpHelper;

/**
 * DateTime Helper
 * This class helps handle Date and Time manipulation
 */

class DateTime 
{
    
    /**
     * convert datetime(microtime) to date and time format
     * default format is jS F Y \@ g:i a
     * @param string $dateTime defaults to time()
     * @param bool $micro if $dateTime not timestamp set to false
     * @param string $format define your custom format, jS F Y \@ g:i a
     * @param string $timeZone define your timezone
     * @return string date time formatted
     */
    public function convertDateTimeToFormat(string $dateTime = "time()", bool $micro = true,string $format = "jS F Y \@ g:i a",string $timeZone = null)
    {
        if (!$micro) {
            $microtime = strtotime($dateTime);
        }
        else{
            $microtime = $dateTime;
        }
        if($timeZone != null){
            date_default_timezone_set($timeZone);
        }
        return date($format, $microtime);
    }

    /**
     * Convert datetime(timestamp) to time ago
     * this method calculate time since and now 
     * @param string $dateTime
     * @param bool $micro set to false if @dateTime is not timestamp
     * @return string 
     */
    public function convertDateTimeToAgo(string $dateTime, bool $micro = true) :string
    {
        if (!$micro) {
            $since = time() - strtotime($dateTime);
        } else {
            $since = time() - $dateTime;
        }
        if ($since < 1) {
            return "0 second";
        }
        $timeCalc = array(
            12 * 30 * 24 * 60 * 60 => 'year', 
            30 * 24 * 60 * 60 => 'month', 
            24 * 60 * 60 => 'day', 
            60 * 60 => 'hour', 
            60 => 'minute', 
            1 => 'second'
        );
        foreach ($timeCalc as $secs => $period) {
            $diff = $since / $secs;
            if ($diff >= 1) {
                $time = round($diff);
                return $time . ' ' . $period . ($time > 1 ? 's' : '') . ' ago';
            }
        }
    }
}
