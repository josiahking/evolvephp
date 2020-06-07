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
 * Curl Helper
 * This class helps handle simple curl request
 */

class Curl 
{
    /**
     * make curl http get request
     * @param string $url
     * @param array $options CURLOPT_RETURNTRANSFER = true, CURLOPT_FOLLOWLOCATION = true, CURLOPT_MAXREDIRS = 2
     * @return string
     */
    public function httpGet(string $url, array $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 2,
    ]) :string
    {
        $ch = curl_init();
        curl_setopt_array($ch, $options);
        curl_setopt($ch, CURLOPT_URL, $url);
        $data = curl_exec($ch);
        if ($data === false) {
            $data .= "Error Number:" . curl_errno($ch) . "<br>";
            $data .= "Error String:" . curl_error($ch);
        }
        curl_close($ch);
        return $data;
    }

    /**
     * make curl http post request
     * @param string $url
     * @param array $params post data
     * @param array $options CURLOPT_RETURNTRANSFER = true, CURLOPT_HEADER = false
     * @return string|bool
     */
    protected function httpPost(string $url, array $params = array(),array $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
    ])
    {
        $postData = "";
        //create name value pairs seperated by &
        foreach ($params as $k => $v) {
            $postData .= $k . '=' . $v . '&';
        }
        rtrim($postData, '&');

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt_array($ch, $options);
        curl_setopt($ch, CURLOPT_POST, count($postData));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

        $data = curl_exec($ch);
        curl_close($ch);
        return $data;
    }
}
