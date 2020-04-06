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
use apache\log4php;
use EvolvePhpCore\log\LogWriterInterface;
/**
 * Apachelog4php 
 * Utilizes apache/log4php file logger
 * Logs to file
 */
class Apachelog4phpFileLogger implements LogWriterInterface {
    
    /**
     * loggerName
     * @var string $loggerName name of logger to use is set during config
     */
    protected $loggerName = "";
    
    /**
     * config
     * @var array|null $config logger config
     */
    protected $config = null;


    public function defaultConfig()
    {
        $this->loggerName = "default";
        $config = [
            'appenders' => [
                'default' => [
                    'class' => 'LoggerAppenderFile',
                    'layout' => [
                        'class' => 'LoggerLayoutPattern',
                        'params' => [
                            'conversionPattern' => '%date{Y-m-d H:i:s} %level %message%newline'
                        ]
                    ],
                    'params' => [
                        'file' => BASE_DIR.'logs/error.log',
                        'append' => true
                    ],
                ],
            ],
            'rootLogger' => [
                'appenders' => ['default']
            ]
        ];
        $this->config = $config;
        return $this;
    }
    
    /**
     * configure
     * @return array|null $this->config
     */
    public function configure() 
    {
        return $this->config;
    }
    
    public function debug($message,$extras = []) 
    {
        \Logger::configure($this->configure());
        $logger = \Logger::getLogger($this->loggerName);
        $log = [
            'message' => $message,
            'extras' => $extras
        ];
        $logger->debug($log);
    }
    
    public function info($message,$extras = [])
    {
        \Logger::configure($this->configure());
        $logger = \Logger::getLogger($this->loggerName);
        $log = [
            'message' => $message,
            'extras' => $extras
        ];
        $logger->info($log);
    }
    
    public function notice($message,$extras = [])
    {
        \Logger::configure($this->configure());
        $logger = \Logger::getLogger($this->loggerName);
        $log = [
            'message' => $message,
            'extras' => $extras
        ];
        $logger->trace($log);
    }
    
    public function warning($message,$extras = [])
    {
        \Logger::configure($this->configure());
        $logger = \Logger::getLogger($this->loggerName);
        $log = [
            'message' => $message,
            'extras' => $extras
        ];
        $logger->warn($log);
    }
    
    public function error($message,$extras = [])
    {
        \Logger::configure($this->configure());
        $logger = \Logger::getLogger($this->loggerName);
        $log = [
            'message' => $message,
            'extras' => $extras
        ];
        $logger->error($log);
    }
    
    public function critical($message,$extras = [])
    {
        \Logger::configure($this->configure());
        $logger = \Logger::getLogger($this->loggerName);
        $log = [
            'message' => $message,
            'extras' => $extras
        ];
        $logger->error($log);
    }
    
    public function alert($message,$extras = [])
    {
        \Logger::configure($this->configure());
        $logger = \Logger::getLogger($this->loggerName);
        $log = [
            'message' => $message,
            'extras' => $extras
        ];
        $logger->fatal($log);
    }
    
    public function emergency($message,$extras = [])
    {
        \Logger::configure($this->configure());
        $logger = \Logger::getLogger($this->loggerName);
        $log = [
            'message' => $message,
            'extras' => $extras
        ];
        $logger->fatal($log);
    }
}
