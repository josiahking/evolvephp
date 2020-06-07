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
use WhichBrowser\Parser;
/**
 * Session
 *
 * This interface extends the Throwable interface that Error and Exception implements
 * 
 */
class Session
{
    /**
     * const used with flash message
     */
    const SUCCESS = 1;
    const INFO = 2;
    const WARNING = 3;
    const DANGER = 0;
    /**
     * if session is valid
     * @var bool
     */
    protected $valid = false;
    
    /**
     * error
     * @var array
     */
    protected $error = array();
    
    /**
     * last error message
     * @var null|int
     */
    protected $lasterror = null;
    
    public function __construct() {
        $status = session_status();
        if ($status == PHP_SESSION_NONE) {
            //There is no active session
            session_name(SESSION_NAME);
            session_cache_expire(SESSION_EXPIRE);
            if(!empty(SESSION_SAVE_PATH)){
                session_save_path(SESSION_SAVE_PATH);
            }
            session_start();
            $this->init();
        }
    }
    
    /**
     * initialize the session
     */
    protected function init() {
        $ip = $_SERVER['REMOTE_ADDR'];
        $parserResult = new Parser(getallheaders());
        if(is_null($parserResult->browser->using) === false){
            $userAgent = md5($parserResult->browser->using->name.' '.$parserResult->browser->using->version->value);
        }
        else{
            $userAgent = md5($parserResult->browser->name.' '.$parserResult->browser->version->value);
        }
        $sessionHashed = md5($ip.$userAgent);
        if(!empty(gethostbyaddr($ip)) && !empty($userAgent)) {
            $ip = md5($ip);
            if ($this->issetSession("config")) {
                if ($ip == $this->getSession("config.ip") 
                        && $userAgent == $this->getSession("config.userAgent")
                        && $sessionHashed == $this->getSession('config.sessionHash')
                    ){
                    $this->setValid(true);
                }
                else {
                    $this->setError(1, "Hacking attempt !!!");
                    $this->setValid(false);
                }
            } 
            else {
                $this->setSession("config.ip", $ip);
                $this->setSession("config.userAgent", $userAgent);
                $this->setSession("config.sessionHash", $sessionHashed);
                $this->setValid(true);
            }
        } 
        else {
            //if empty, we can't verify IP adress and user agent
            $this->setValid(false);
            $this->unSetAll();
            $this->destroy();
            exit('Your identity could not be verified');
        }
    }
    
    /**
     * sessionTimeout
     * this method serves as a mini security implementation to ensure session variable gets destroy after specific time to prevent hijacking
     * prevents session hijacking
     */
    public function sessionTimeout()
    {
        $now = time();
        $timeOut = SESSION_EXPIRE * 60;// in seconds;
        if(!$this->issetSession('userTimeOut')){
            $this->setSession('userTimeOut', $now + $timeOut);
        }
        if($this->getSession('userTimeOut') < $now){
            $this->unSetAll();
            $this->destroy();
        }
        else{
            $this->setSession('userTimeOut', $now + $timeOut);
        }
    }
    
    /**
     * getSession
     * get session variable data
     * @param string $var
     * @return string|null
     */
    public function getSession(string $var)
    {
        if(preg_match('/[.]/', $var)){
            $varArray = explode('.', $var);
            if($this->issetSession($varArray[0]) && $this->issetSessionArr($var)){
                return $_SESSION[$varArray[0]][$varArray[1]];
            }
            else{
                $this->setError(5, "Session variable is not set or not an array");
                return null;
            }
        }
        else{
            if($this->issetSession($var)){
                return $_SESSION[$var];
            }
            else{
                $this->setError(6, "Session variable is not set");
                return null;
            }
        }
    }
    
    /**
     * setSession
     * set session variable|array
     * @param string $var variable name can be formatted with . which will be converted into an array
     * @param type $val
     */
    public function setSession(string $var,$val)
    {
        if(preg_match('/[.]/', $var)){
            $varArray = explode('.', $var);
            if(!isset($_SESSION[$varArray[0]])){
                $_SESSION[$varArray[0]] = array();
            }
            $_SESSION[$varArray[0]][$varArray[1]] = $val;
        }
        else{
            $_SESSION[$var] = $val;
        }
    }
    
    /**
     * issetSession
     * @param string $name name of session variable
     * @return bool
     */
    public function issetSession(string $name) :bool
    {
        $name = filter_var($name,FILTER_SANITIZE_STRING);
        if(isset($_SESSION[$name])){
            return true;
        }
        else{
            $this->setError(2, "Session variable is not set");
            return false;
        }
    }
    
    /**
     * issetSessionArr
     * @param string $name format with . which will be use to convert the string into an array
     * @return bool
     */
    public function issetSessionArr(string $name) :bool
    {
        $name = filter_var($name,FILTER_SANITIZE_STRING);
        if(preg_match('/[.]/', $name)){
            $varArray = explode('.', $name);
            if (isset($_SESSION[$varArray[0]][$varArray[1]])) {
                return true;
            } 
            else {
                $this->setError(3, "Session variable is not an array or set");
                return false;
            }
        }
        $this->setError(3, "Session variable is not an array or set");
        return false;
    }
    
    /**
     * unsetSession
     * free a session variable
     * @param string $name name of the session variable
     * @return bool
     */
    public function unsetSession(string $name) :bool
    {
        if($this->issetSession($name)){
            unset($_SESSION[$name]);
            return true;
        }
        else{
            return false;
        }
    }
    
    /**
     * unsetArraySession
     * unset session array
     * @param string $name name of session variable
     * @return bool
     */
    public function unsetArraySession(string $name) :bool
    {
        if($this->issetSessionArr($name)){
            $varArray = explode('.', $name);
            unset($_SESSION[$varArray[0]][$varArray[1]]);
            return true;
        }
        else{
            return false;
        }
    }
    
    /**
     * getValid
     * get session valid option
     * @return bool $valid
     */
    public function getValid() :bool
    {
        return $this->valid;
    }
    
    /**
     * setValid
     * set if session is valid
     * @param bool $valid
     */
    public function setValid(bool $valid)
    {
        $this->valid = $valid;
    }
    
    /**
     * getCsrfToken
     * get csrf token
     * @return bool|string
     */
    public function getCsrfToken()
    {
        return $this->getSession('csrfToken');
    }
    
    /**
     * setCsrfToken
     * set csrf token
     * @param int $timeOut time in seconds
     * @return bool
     */
    public function setCsrfToken(int $timeOut = 600) :bool
    {
        $now = time();
        if(!$this->issetSession('csrfTimeOut')){
            $this->setSession('csrfTimeOut', $now + $timeOut);
        }
        if(!$this->issetSession('csrfToken')){
            $this->setSession('csrfToken', md5(rand()));
            return true;
        }
        return false;
    }
    
    /**
     * validateCsrfToken
     * validates csrfToken
     * @param type $token
     * @return bool
     */
    public function validateCsrfToken($token) :bool
    {
        $now = time();
        if($this->getSession('csrfTimeOut') < $now){
            $this->unsetSession('csrfTimeOut');
            $this->unsetSession('csrfToken');
            return false;
        }
        if($this->getcsrfToken() === $token){
            $this->unsetSession('csrfTimeOut');
            $this->unsetSession('csrfToken');
            return true;
        }
        return false;
    }
    
    /**
     * setError
     * set error
     * @param type $errNum
     * @param type $errMsg
     */
    protected function setError($errNum, $errMsg) 
    {
        $this->error[$errNum] = $errMsg;
        $this->lasterror = $errNum;
    }
    
    /**
     * getError
     * get error using error number
     * @param int $errNum
     * @return null|string
     */
    public function getError(int $errNum) 
    {
        if (!array_key_exists($errNum, $this->error)){
            return null;
        }
        else{
            return 'Error Number: '.$errNum.'<br/>Error Message: '.$this->error[$errNum];
        }
    }
    
    /**
     * getLastError
     * Get last error
     * @return null|string
     */
    public function getLastError() 
    {
        if (is_numeric($this->lasterror)){
            return $this->getError($this->lasterror);
        }
        else{
            return null;
        }
    }
    
    /**
     * unsetAll
     * unset all session variables
     */
    public function unsetAll() 
    {
        session_unset();
    }
    
    /**
     * destroy
     * destroys all session 
     */
    public function destroy()
    {
        session_destroy();
    }
    
    /**
     * flashMessage
     * Displays the flash message
     * @return string flash message
     */
    public function flashMessage() :string
    {
        if($this->issetSession('flash_message')){
            return $this->getSession('flash_message');
        }
        return "";
    }
    
    /**
     * setFlashMessage
     * Set the flash message and type to be displayed
     * @param string $message
     * @param int $type
     */
    public function setFlashMessage(string $message,int $type) 
    {
        if($type == Session::SUCCESS){
            $flashMessage = '<div class="alert alert-success" role="alert">'.$message.'</div>';
        }
        elseif($type == Session::INFO){
            $flashMessage = '<div class="alert alert-info" role="alert">'.$message.'</div>';
        }
        elseif($type == Session::WARNING){
            $flashMessage = '<div class="alert alert-warning" role="alert">'.$message.'</div>';
        }
        elseif($type == Session::DANGER){
            $flashMessage = '<div class="alert alert-danger" role="alert">'.$message.'</div>';
        }
        else{
            ExceptionFactory::getInstance()->triggerError("Flash message type is not supported",E_USER_WARNING);
        }
        $this->setSession('flash_message', $flashMessage);
    }
    
    /**
     * unsetFlashMessage
     * Unset session variable that stores flash message
     */
    public function unsetFlashMessage() 
    {
        if($this->issetSession('flash_message')){
            $this->unsetSession('flash_message');
        }
    }
}
