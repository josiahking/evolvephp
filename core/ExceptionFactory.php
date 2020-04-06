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

namespace EvolvePhpCore;
use EvolvePhpCore\LogFactory;
/**
 * ExceptionFactory Class
 *
 * This class factory provides access to all the exceptions class
 * It provides access to the base exception class
 */

class ExceptionFactory 
{
    
    /**
     * types
     * Exceptions by type 
     * default: Base Exception
     * runtime: Runtime Exception
     * invalid_argument: InvalidArgumentException
     * @var array|mixed
     */
    public $types = [
        'default' => 'EvolvePhpCore\exception\BaseException',
        'runtime' => 'EvolvePhpCore\exception\RuntimeException',
        'domain' => 'EvolvePhpCore\exception\DomainException',
        'invalid_argument' => 'EvolvePhpCore\exception\InvalidArgumentException',
        'error' => 'EvolvePhpCore\exception\ErrorException'
    ];
    
    
    public static function getInstance()
    {
        return new ExceptionFactory;
    }


    public function __construct() {
        set_error_handler([$this,'handleErrorAsException']);
        set_exception_handler([$this,'handleException']);
    }
    
    
    public function __invoke() {
        return $this;
    }
    
    /**
     * getTypes
     * Get all existing exceptions
     * @return mixed|array
     */
    public function getTypes() {
        return $this->types;
    }
    
    /**
     * setType
     * Set or add new custom exception type to the list 
     * @param string $key key to use when creating exception using create method
     * @param string $namespace full namespace to load exception
     */
    public function setType(string $key,string $namespace) {
        $this->types[$key] = $namespace;
    }

    /**
     * create 
     * Create exception using specified type or fall back to default
     * @param string $type
     * @return \Exception
     */
    public static function create(string $type) : \Throwable
    {
        foreach(self::getInstance()->types as $k_ex => $v_ex){
            
            if($k_ex == $type){
                return new $v_ex;
            }
        }
        $defaultException = $this->types['default'];
        return new $defaultException;
    }
    
    public function triggerException(string $message,string $type,int $code = 0)
    {
        $exHandler = ExceptionFactory::create($type);
        throw new $exHandler($message,$code);
    }
    
    public static function handleErrorAsException($severity,$message,$file,$line)
    {
        if(!error_reporting() & $severity){
            ExceptionFactory::getInstance()->triggerException("Failed to report error. Error report might be disable at the moment", "default");
            return false;
        }
        $errorEx = ExceptionFactory::create('error');
        throw new $errorEx($message,0,$severity,$file,$line);
    }
    
    public static function handleException(\Throwable $ex) {
        if(is_a($ex,"ErrorException")){
            $errorKind = self::getInstance()->getErrorKind($ex->getSeverity());
            $heading = "<h1>".$errorKind."</h1>";
            $message = "<h3>".$ex->getMessage()."</h3>";
            $desc = "A ". $errorKind." error was thrown on line ".$ex->getLine()." of file ".$ex->getFile()." that prevented further execution of this request.";
        }
        elseif(preg_match('/Error$/', get_class($ex))){
            $errorKind = get_class($ex);
            $heading = "<h1>".$errorKind."</h1>";
            $message = "<h3>".$ex->getMessage()."</h3>";
            $desc = "A ". $errorKind." was thrown on line ".$ex->getLine()." of file ".$ex->getFile()." that prevented further execution of this request.";
        }
        else{
            $exceptionKind = empty(class_parents($ex)) ? "Exception" : key(class_parents($ex));
            $heading = "<h1>".$exceptionKind."</h1>";
            $message = "<h3>".$ex->getMessage()."</h3>";
            $desc = $exceptionKind." was thrown on line ".$ex->getLine()." of file ".$ex->getFile()." that prevented further execution of this request.";
        }
        $html = $heading.$message.$desc;
        if ( is_array( $ex->getTrace() ) ){
            $html .= "<h2>Stack trace:</h2>";
            $html .= '<table class="trace">';
            $html .= '<thead>
                        <tr>
                            <td>File</td>
                            <td>Line</td>
                            <td>Class</td>
                            <td>Function</td>
                            <td>Arguments</td>
                        </tr>
                    </thead>';
            $html .= '<tbody>';
            foreach ( $ex->getTrace() as $i => $trace ) {
                $html .= "<tr>";
                $html .= "<td>".(isset($trace[ 'file' ]) ? basename($trace[ 'file' ]) : '')."</td>";
                $html .= "<td>".(isset($trace[ 'line' ]) ? $trace[ 'line' ] : '')."</td>";
                $html .= "<td>".(isset($trace[ 'class' ]) ? $trace[ 'class' ] : '')."</td>";
                $html .= "<td>".(isset($trace[ 'function' ]) ? $trace[ 'function' ] : '')."</td>";
                $html .= "<td>";
                    if( isset($trace[ 'args' ]) ){
                        foreach ( $trace[ 'args' ] as $i => $arg ) :
                            $html .= "<span>".gettype( $arg )."</span>";
                            $html .= $i < count( $trace['args'] ) -1 ? ',' : '';
                        endforeach;
                    }
                    else{}
                $html .= "</td>";
                $html .= "</tr>";
            }
            $html .= "</tbody>";
            $html .= "</table>";
        }
        else{
            $html .= "<pre>".$ex->getTraceAsString()."</pre>";
        }
        echo $html;
        //log exception
        $log = LogFactory::create('default_file');
        $log->defaultConfig()->debug($ex->getTraceAsString());
    }
    
    public function triggerError(string $message,int $code = E_USER_ERROR)
    {
        trigger_error($message, $code);
    }
    
    
    public function getErrorKind(int $severity) : string
    {
        $kind = "";
        switch ($severity):
            case E_PARSE:
            case E_ERROR:
            case E_CORE_ERROR:
            case E_COMPILE_ERROR:
            case E_USER_ERROR:
                $kind = 'Fatal Error';
                break;
            case E_WARNING:
            case E_USER_WARNING:
            case E_COMPILE_WARNING:
            case E_RECOVERABLE_ERROR:
                $kind = 'Warning';
                break;
            case E_NOTICE:
            case E_USER_NOTICE:
                $kind = 'Notice';
                break;
            case E_STRICT:
                $kind = 'Strict';
                break;
            case E_DEPRECATED:
            case E_USER_DEPRECATED:
                $kind = 'Deprecated';
                break;
            default :
                $kind = "Unknown";
                break;
        endswitch;
        return $kind;
    }
}