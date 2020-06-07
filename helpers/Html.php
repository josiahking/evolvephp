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
 * Html Helper
 * This class helps handle html
 */

class Html 
{
    
    /**
     * encode html entity
     * @param string $entity null
     * @param array $dataArr null
     * @param string $flag the name of entity flag as string e.g. ENT_SUBSTITUTE | ENT_QUOTES | ENT_HTML5 **Only one flag allowed**
     * @param string $encoding UTF-8
     * @param bool $doubleEncode false
     * @return string 
     */
    public function encodeHtml(string $entity = null, array $dataArr = null,string $flag = null,string $encoding = "UTF-8",bool $doubleEncode = false)
    {
        $entityArr = [];
        if (!empty($entity) && !empty($dataArr)) {
            foreach ($dataArr as $key => $val) {
                $entityArr[$key] = htmlentities($val, (!empty($flag) ? constant($flag) : ENT_SUBSTITUTE | ENT_QUOTES | ENT_HTML5), $encoding, $doubleEncode);
            }
            $dataStr = htmlentities($entity,(!empty($flag) ? constant($flag) : ENT_SUBSTITUTE | ENT_QUOTES | ENT_HTML5), $encoding, $doubleEncode);
            return [$entity,$entityArr];
        }
        if (!empty($dataArr)) {
            foreach ($dataArr as $key => $val) {
                $entityArr[$key] = htmlentities($val, (!empty($flag) ? constant($flag) : ENT_SUBSTITUTE | ENT_QUOTES | ENT_HTML5), $encoding, $doubleEncode);
            }
            return $entityArr;
        }
        if (!empty($entity)) {
            return htmlentities($entity, (!empty($flag) ? constant($flag) : ENT_SUBSTITUTE | ENT_QUOTES | ENT_HTML5), $encoding, $doubleEncode);
        }
    }

    /**
     * decode html entity
     * @param string $entity null
     * @param array $dataArr null
     * @param string $flag null the name of entity flag as string e.g. ENT_SUBSTITUTE | ENT_QUOTES | ENT_HTML5 **Only one flag allowed**
     * @param string $encoding UTF-8
     * @return string
     */
    public function decodeHtml(string $entity = null,array $dataArr = null,string $flag = null,string $encoding = "UTF-8") :string
    {
        $entityArr = [];
        if (!empty($entity) && !empty($dataArr)) {
            foreach ($dataArr as $key => $val) {
                $entityArr[$key] = html_entity_decode($val, (!empty($flag) ? constant($flag) : ENT_SUBSTITUTE | ENT_QUOTES | ENT_HTML5), $encoding);
            }
            $dataStr = html_entity_decode($entity, (!empty($flag) ? constant($flag) : ENT_SUBSTITUTE | ENT_QUOTES | ENT_HTML5), $encoding);
            return [$entity, $entityArr];
        }
        if (!empty($dataArr)) {
            foreach ($dataArr as $key => $val) {
                $entityArr[$key] = html_entity_decode($val, (!empty($flag) ? constant($flag) : ENT_SUBSTITUTE | ENT_QUOTES | ENT_HTML5), $encoding);
            }
            return $entityArr;
        }
        if (!empty($entity)) {
            return html_entity_decode($entity, (!empty($flag) ? constant($flag) : ENT_SUBSTITUTE | ENT_QUOTES | ENT_HTML5), $encoding);
        }
    }
}
