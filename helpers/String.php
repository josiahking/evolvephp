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
 * String Helper
 * This class helps handle String manipulation
 */

class String 
{
    /**
     * Hide last four digit of a phone number
     * @param string $phone
     * @return string
     */
    public function asterikPhone(string $phone): string
    {
        return substr($phone, 0, -4) . "****";
    }

    /**
     * Hide part of email address and return * of each character
     * @param string $email
     * @return string
     */
    public function asterikEmail(string $email): string
    {
        $mailSegments = explode("@", $email);
        $mailSegments[0] = str_repeat("*", strlen($mailSegments[0]));

        return implode("@", $mailSegments);
    }

    /**
     * Test for variable string data, if not emptyreturn $data else return $value
     * @param string $data
     * @param string $value
     * @return string
     */
    protected function testForStringData(string $data, string $value): string
    {
        if (isset($data) && !empty($data)) {
            return $data;
        } else {
            return $value;
        }
    }
}
