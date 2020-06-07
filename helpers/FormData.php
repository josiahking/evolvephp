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
 * FormData Helper
 * This class helps handle submitted form data
 */

class FormData 
{
    /**
     * form data
     * @var array|null  
     */
    protected $formData = null;
    
    /**
     * set form data
     * @param array $formData 
     * @param bool $clean true, clean the supplied form data
     * @throws \DomainException when argument is not supplied
     */
    public function setFormData(array $formData = [],bool $clean = true) 
    {
        if(empty($formData)){
            $formType = $this->getRequestMethod();
            if($formType == 'GET'){
                $formData = $_GET;
            }
            elseif($formType == 'POST'){
                $formData = $_POST;
            }
            else{
                throw new \DomainException('$formData is required. Request method is not supported.');
            }
        }
        if($clean){
            $this->formData = $this->clean($formData);
        }
        else{
            $this->formData = $formData;
        }
    }
    
    /**
     * clean form data without or before setting them to $formData with setFormData()
     * @param array $formData
     * @param int $sanitizeOption
     * @return array
     */
    public function clean(array $formData,int $sanitizeOption = null) :array
    {
        if($sanitizeOption != null){
            return filter_var_array($formData,$sanitizeOption);
        }
        return filter_var_array($formData,FILTER_SANITIZE_STRING);
    }
    
    /**
     * clean form data field before re-assigning them to $formData[field]
     * @param string $value
     * @param int $sanitizeOption
     * @return string
     */
    public function cleanField(string $value,int $sanitizeOption = null)
    {
        if($sanitizeOption != null){
            return filter_var($value,$sanitizeOption);
        }
        return filter_var($value,FILTER_SANITIZE_STRING);
    }
    
    /**
     * get $formData
     * @return array
     */
    public function getFormData() :array
    {
        return $this->formData;
    }
    
    /**
     * get request method of the form
     * @return string
     */
    public function getRequestMethod() : string
    {
        return $_SERVER['REQUEST_METHOD'];
    }
    
    /**
     * get field of $formData
     * @param string $name name of the form field
     * @return string|array field can be string or array
     * @throws \DomainException when field is not found
     */
    public function getField(string $name)
    {
        foreach ($this->getFormData() as $field => $value){
            if($field == $name){
                return $value;
            }
        }
        throw new \DomainException(var_export($name).'is not available in form data');
    }
    
    /**
     * set or re-assign field to the $formData
     * @param string $name
     * @param string $value
     * @param bool $clean
     */
    public function setField(string $name,string $value,bool $clean = true) 
    {
        if($clean){
            $this->formData[$name] = $this->cleanField($value);
        }
        else{
            $this->formData[$name] = $value;
        }
    }
    
    /**
     * check if form field is available in the form data
     * @param string $name name of the field
     * @return bool
     */
    public function issetField(string $name) :bool
    {
        if(isset($this->formData[$name])){
            return true;
        }
        return false;
    }
}
