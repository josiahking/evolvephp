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

namespace EvolvePhpCore\log;

/**
 * LogWriterInterface Interface
 *
 * This interface defines base rules for writing logs
 */
interface LogWriterInterface extends LoggerInterface{
    
    /**
     * configure logger
     * @return LogWriterInterface
     */
    public function configure();
    
    /**
     * debug logger
     * @param mixed $message log message
     * @param array $extras extra log information
     * @return LogWriterInterface
     */
    public function debug($message,array $extras = []);
    
    /**
     * info logger
     * @param mixed $message log message
     * @param array $extras extra log information
     * @return LogWriterInterface
     */
    public function info($message,array $extras = []);
    
    /**
     * notice logger
     * @param mixed $message log message
     * @param array $extras extra log information
     * @return LogWriterInterface
     */
    public function notice($message,array $extras = []);
    
    /**
     * warning logger
     * @param mixed $message log message
     * @param array $extras extra log information
     * @return LogWriterInterface
     */
    public function warning($message,array $extras = []);
    
    /**
     * error logger
     * @param mixed $message log message
     * @param array $extras extra log information
     * @return LogWriterInterface
     */
    public function error($message,array $extras = []);
    
    /**
     * critical logger
     * @param mixed $message log message
     * @param array $extras extra log information
     * @return LogWriterInterface
     */
    public function critical($message,array $extras = []);
    
    /**
     * alert logger
     * @param mixed $message log message
     * @param array $extras extra log information
     * @return LogWriterInterface
     */
    public function alert($message,array $extras = []);
    
    /**
     * emergency logger
     * @param mixed $message log message
     * @param array $extras extra log information
     * @return LogWriterInterface
     */
    public function emergency($message,array $extras = []);
}
