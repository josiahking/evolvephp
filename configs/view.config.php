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

/**
 * view config
 * define layout for view engine to use
 * files should be relative to the layout folder in the public folder
 * @todo Allow more than one placeholder (can be achieve by using the array index and search)
 */
return [
    'view_layout' => [
        'error' => [
            '%layout%/error/header.php',
            '%component%/error/views/%placeholder%',
            '%layout%/error/footer.php'
        ],
        'default' => [
            '%layout%/site/header.php',
            '%component%/site/views/%placeholder%',
            '%layout%/site/footer.php'
        ]
    ],
];

