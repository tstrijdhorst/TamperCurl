<?php
/**
 * @author Tim Strijdhorst
 * @abstract Class that will take an XML file containing HTTP-headers exported by the Firefox plugin TamperData.
 * It will then set up a cURL session mimicking these HTTP-headers and give you control over the flow of execution as well as the 
 * possibility to customize the HTTP-headers further. After the configuration is done launch the request and get the data back. 
 * It is possible to reuse connections, but remember that the cURL settings you specified during an earlier launch will 
 * not be automaticly reverted (this can be specified though).
 * 
 * It is possible to load up multiple headers specified in one XML file, with the load*header() functions you can control what
 * header is initialized for execution.
 * 
 * Notice: It is ofcourse advisible to remove everything you don't want from the XML file, 'host' property is automaticly 
 * skipped as this is derived from the url in the header but especially the cookie property is error prone in combination 
 * with a custom set cookiejar.
 *
 * Dependencies:
 * + PHP cURL
 * o TamperData for Firefox (not a requirement for the execution of this script, but it's used for the generation of the XML files)
 * 
 * Bugs:
 * + A bug in TamperData causes the character ':' to be converted to %253A (instead of %3A) when it is found in POST-keys
 *   I'm not sure whether this is a problem with special characters in general or just ':', same for @
 * 
 * License:
 * This work is licensed under the Creative Commons Attribution-NonCommercial-ShareAlike 3.0 Unported License. 
 * To view a copy of this license, visit http://creativecommons.org/licenses/by-nc-sa/3.0/ or send a letter to 
 * Creative Commons, 444 Castro Street, Suite 900, Mountain View, California, 94041, USA.
 */

class TamperCurl
{
	private $xmlHeaders;
	private $customHeaders;
	private $curlSession;
	private $postHeadersArray;
	private $cookieJarLocation;
	private $stderrLocation;
	private $output;
	private $headerCounter;
	private $mimeTypeFilters;
	
	/**
	 * Initiates the class to be able to reproduce the header described in the XML file.
	 * 
	 * @param String $xmlFilePath path to the XML file, location should be readable.
	 * @param Array set multiple options at once, possibilities are the same as setOptions()
	 */
	public function __construct($xmlFilePath, $options=null)	
	{
		if($options != null) $this->setOptions($options);
		
		$this->headerCounter = 0;
		$this->setXMLHeader($xmlFilePath);
		$this->init();
	}
	
	/**
	 * Be a good boy (girl? PERSON!) and always release your resources.
	 */
	public function __destruct()
	{
		//Check if it isn't already closed
		if(gettype($this->curlSession) == 'resource') curl_close($this->curlSession);
	}
	
	/**
	 * Gather all the info from the previous steps and actually do the request
	 */
	public function doRequest()
	{
		/**
		 * @todo for some reason the STDERR output is not working
		 */
		if($this->stderrLocation != null)
		{			
			$handle = fopen($this->stderrLocation,'a+');
			curl_setopt($this->curlSession, CURLOPT_STDERR, $handle);
			curl_setopt($this->curlSession, CURLOPT_VERBOSE, 2); //Verbosity 2 to actually log something to STDERR...
		}
		
		if(count($this->postHeadersArray) > 0)
		{
			//Tell cURL we want to do some posting
			curl_setopt($this->curlSession, CURLOPT_POST, true);
			//Load all the postvariables
			curl_setopt($this->curlSession, CURLOPT_POSTFIELDS, $this->constructPOSTDataListFromArray());
		}
		else
		{
			curl_setopt($this->curlSession, CURLOPT_POST, false); //just to be sure
		}
		
		if(count($this->customHeaders) > 0)
		{
			//Load up all the custom set headers
			curl_setopt($this->curlSession, CURLOPT_HTTPHEADER, $this->customHeaders);
		}
		else
		{
			curl_setopt($this->curlSession, CURLOPT_HTTPHEADER, null);
		}
		
		if($this->cookieJarLocation != null)
		{
			curl_setopt($this->curlSession, CURLOPT_COOKIEFILE, $this->cookieJarLocation);
			curl_setopt($this->curlSession, CURLOPT_COOKIEJAR, $this->cookieJarLocation);
		}
		else
		{
			curl_setopt($this->curlSession, CURLOPT_COOKIEFILE, null);
			curl_setopt($this->curlSession, CURLOPT_COOKIEJAR, null);
		}
		
		$this->output[$this->headerCounter] = curl_exec($this->curlSession);
		
		if($this->stderrLocation != null) fclose($handle); //RELEASE THE KRAKEN \o/
		
		return $this->output[$this->headerCounter];
	}
	
	/**
	 * Does all the requests specified in the XML file in a row
	 * 
	 * @return array with all output
	 */
	public function doAllRequests()
	{
		try
		{
			while(true)
			{
				$this->doRequest();
				$output = $this->getOutput();
				$this->loadNextHeader();				
			}
		}
		catch(Exception $e)
		{
			//Last header has been reached
		}
		
		return $this->output;
	}
	
	/**
	 * Do the next N requests without any interference from the user or code.
	 * 
	 * NOTICE: Afterwards the N+1th header will be selected.
	 * 
	 * @param unknown_type $amount
	 * @throws Exception
	 */
	public function doNextAmountRequests($amount)
	{
		try
		{
			for($i=0;$i<$amount;$i++)
			{
				$this->doRequest();
				$output = $this->getOutput();
				$this->loadNextHeader();				
			}
		}
		catch(Exception $e)
		{
			throw new Exception("Last header has been reached.");
		}
		
		return $this->output;
	}
	
	/**
	 * Initializes the cURL session (a new one or just keep using the old one). Get the URL from the XML and set all the
	 * custom request headers.
	 * 
	 * @param boolean $reuseConnection reuse the connection of a previous header execution (cURL session cannot be NULL)
	 * @param boolean $resetSettings reset the settings of a previous cURL session (like specified request headers / POST headers)
	 */
	public function init($reuseConnection=false,$resetSettings=null)
	{
		if($reuseConnection && $this->curlSession == null)
		{
			throw new Exception("You've already closed the cURL session...");
		}
		else if(!$reuseConnection && $resetSettings)
		{
			throw new Exception("It doesn't make a whole lot of sense to reset the settings with a new curl session.");
		}
		else if($reuseConnection && $resetSettings == null || $reuseConnection && $resetSettings == true) //NULL is to circumvent exception on default values
		{
			//Reset all the variables set by possible previous initiations
			$this->customHeaders = null;
			$this->postHeadersArray = null;
		}
		else
		{
			if($this->curlSession != null)
			{
				curl_close($this->curlSession);
			}
			
			$this->curlSession = curl_init();
			curl_setopt($this->curlSession, CURLOPT_RETURNTRANSFER, true); //Output should be returned and not sent to STDOUT by default
		}
		
		//Try to do as much with the standard cURL options (as long as it's handy)
		$request = $this->xmlHeaders->tdRequest[$this->headerCounter];
		curl_setopt($this->curlSession, CURLOPT_URL, urldecode($request->attributes()->uri));
		
		foreach($request->tdRequestHeaders->tdRequestHeader as $header)
		{
			switch($header->attributes()->name)
			{
				case('User-Agent'):
					curl_setopt($this->curlSession, CURLOPT_USERAGENT, trim(urldecode($header)));
					break;
				case('Host'):
					//do nothing, this will be specified by cURL (recommended to remove it from the XML, but ok)
					break;
				case('Cookie'):
					if($this->cookieJarLocation == null)
						$this->customHeaders[] = $header->attributes()->name.': '.trim(urldecode($header));
					break;
				default:
					$this->customHeaders[] = $header->attributes()->name.': '.trim(urldecode($header));
			}
		}
		
		foreach($request->tdPostElements->tdPostElement as $postElement)
		{
			$name = urldecode($postElement->attributes()->name);
			$this->postHeadersArray[$name] = trim(urldecode($postElement)); //No need for URL decoding since it will be sent as POST anyway
		}
	}
	
	/**
	 * Import an XML file
	 * 
	 * @param String $xmlFilePath
	 */
	public function setXMLHeader($xmlFilePath)
	{
		$postHeadersArray = array();
		
		$data = file_get_contents($xmlFilePath);
		
		$this->xmlHeaders = new SimpleXMLElement($data);
	}
	
	/**
	 * Initialize the next header specified in the XML file
	 * If MIME type filters are defined only headers with a eligible MIME type will be selected.
	 */
	public function loadNextHeader($reuseConnection=true)
	{
		if(count($this->xmlHeaders->tdRequest) == ($this->headerCounter+1))
			throw new Exception('There is no next header.');
		
		if($this->mimeTypeFilters != null && !empty($this->mimeTypeFilters))
		{		
			for($i=$this->headerCounter+1;$i<count($this->xmlHeaders);$i++)
			{
				if(in_array($this->xmlHeaders->tdRequest[$i]->tdMimeType,$this->mimeTypeFilters))
				{	
					$this->headerCounter = $i;
					break;
				}
			}
			
			if($i == count($this->xmlHeaders))
				throw new Exception('There is no next header within the bounds of the MIME type filter');
		}
		else
			$this->headerCounter++;
			
		$this->init($reuseConnection);
	}
	
	/**
	 * Initialize the previous header specified in the XML file
	 * If MIME type filters are defined only headers with a eligible MIME type will be selected.
	 */
	public function loadPreviousHeader($reuseConnection=true)
	{
		if($this->headerCounter-1 < 0)
			throw new Exception('There is no previous header.');
		
		if($this->mimeTypeFilters != null && !empty($this->mimeTypeFilters))
		{		
			for($i=$this->headerCounter-1;$i>=0;$i--)
			{
				if(in_array($this->xmlHeaders->tdRequest[$i]->tdMimeType,$this->mimeTypeFilters))
				{	
					$this->headerCounter = $i;
					break;
				}
			}
		}
		else
			$this->headerCounter--;
		
		$this->init($reuseConnection);
	}
	
	/**
	 * Initialize a specific header from the XML file (starts at 0)
	 * 
	 * @param int $headerNumber number of the header you want to initialize (>=0)
	 */
	public function loadSpecificHeader($headerNumber,$reuseConnection=true)
	{
		$headerCount = count($this->xmlHeaders->tdRequest);
		if($headerCount <= $headerNumber || $headerNumber < 0)
			throw new Exception('Illegal header number. Correct domain: 0 <= headernumber < '.($headerCount-1));
		if($this->mimeTypeFilters != null && !empty($this->mimeTypeFilters) && 
			!in_array($this->xmlHeaders->tdRequest[$headerNumber]->tdMimeType,$this->mimeTypeFilters))
				throw new Exception('That header does not have a MIME type specified in the filters.');
		
		$this->headerCounter = $headerNumber;
		$this->init($reuseConnection);
	}
	
	/**
	 * Kind of useless te reimplement all the curl setopt stuff, but it's not as pretty
	 * to just let you grab the cURL object. This is analogue to curl_setopt_array!
	 * 
	 * Try not to fiddle with anything that you are able to set with native class methods, this might
	 * cause sync problems. Use this for stuff like follow-location, header, header_out and stuff.
	 * For an extensive overview of all the options that can be set this way, see:
	 * http://www.php.net/manual/en/function.curl-setopt.php
	 * 
	 * @param array[int][mixed] $optionArray
	 */
	public function setCURLOption($optionArray)
	{
		curl_setopt_array($this->curlSession,$optionArray);
	}

	/**
	 * Return a string containing the last error for the current session
	 */
	public function getError()
	{
		return curl_error($this->curlSession);
	}
	
	/**
	 * Return the last error number
	 */
	public function getErrorNumber()
	{
		return curl_errno($this->curlSession);
	}
	
	public function setURL($url)
	{
		curl_setopt($this->curlSession, CURLOPT_URL, $url);
	}
	
	/**
	 * Change the data of a POST variable with a certain name.
	 * 
	 * @param String $name
	 * @param String $data
	 */
	public function setPostVariableData($name,$data)
	{
		$this->postHeadersArray[$name] = $data;
	}
	
	/**
	 * Change the name of a POSTVariable
	 * 
	 * @param String $oldName
	 * @param String $newName
	 */
	public function setPostVariableName($oldName,$newName)
	{
		$this->postHeadersArray[$newName] = $this->postHeadersArray[$oldName];
		unset($this->postHeadersArray[$oldName]);
	}
	
	public function PostVariableNameExists($name)
	{
		return array_key_exists($name,$this->postHeadersArray);
	}
	
	/**
	 * Construct an url encoded query-string listing all the POSTdata ((&)name=value)
	 * 
	 * Note: I am aware that there is a function http_build_query() that does exactly this, but it is only available since
	 * PHP 5.3 which would unneccesarily force that requirement.
	 * 
	 * @param array(String) $postArray
	 */
	public function constructPOSTDataListFromArray($postHeadersArray=null)
	{
		if($postHeadersArray == null) $postHeadersArray = $this->postHeadersArray;
		
		$postVars = '';
		
		foreach($postHeadersArray as $name => $headerData)
		{			
			$postVars .= urlencode($name).'='.urlencode($headerData).'&';
		}
		
		return substr($postVars,0,strlen($postVars)-1); //chop off the last &
	}
	
	/**
	 * Set multiple options at the same time, possibilities:
	 * +cookieJarLocation
	 * +stderr
	 * 
	 * @param array(String)(String) $options
	 * @require $options != null
	 */
	public function setOptions($options)
	{
			foreach($options as $option => $value)
			{
				switch(strtolower($option))
				{
					case('cookiejarlocation'):
						$this->setCookieJarLocation($value);
						break;
					case('stderr'):
						$this->setSTDERR($value);
						break;
				}
			}
	}
	
	/**
	 * Sets the path to which STDERR output will go, also sets cURL verbosity to 1 so there is actually something to output.
	 * 
	 * @param String $stderrPath
	 */
	public function setSTDERR($stderrPath)
	{
		$this->stderrLocation = $stderrPath;
	}
	
	/**
	 * Set the user agent that will be used when making the request.
	 * 
	 * @param String $userAgent
	 */
	public function setUserAgent($userAgent)
	{
		curl_setopt($this->curlSession, CURLOPT_USERAGENT, $userAgent);
	}
	
	/**
	 * Get the raw CURL session data.
	 * 
	 * Note: this is a reference to the actual curl session used by this instance.
	 * (You should use a syntax like $var = &$obj->getCurlSession();)
	 * 
	 */
	public function &getCURLSession()
	{
		return $this->curlSession;
	}
	
	/**
	 * Set the raw cURL session data
	 * 
	 * WARNING: This could potentially mess everything up if this class and the curl session are out of sync.
	 * It's not especially recommended to use this for any data that this class handles. You could ofcourse use it
	 * for specific cURL settings that are beyond the scope of this class.
	 * 
	 * @param $curlSession
	 */
	public function setCURLSession($curlSession)
	{
		$this->curlSession = $curlSession;
	}
	
	/**
	 * @return String contents of the cookiejar
	 */
	public function getCookieJar()
	{
		if($this->cookieJarLocation != null)
			return file_get_contents($this->cookieJarLocation);
	}
	
	/**
	 * @return String cookiejar location
	 */
	public function getCookieJarLocation()
	{
		return $this->cookieJarLocation;
	}
	
	/**
	 * @param String $cookieJarLocation
	 */
	public function setCookieJarLocation($cookieJarLocation)
	{
		$this->cookieJarLocation = $cookieJarLocation;
	}
	
	/**
	 * @return SimpleXMLElement[] xmlFile or null (if doesn't exist yet)
	 */
	public function getXMLHeaders()
	{
		return $this->xmlHeaders;
	}
	
	/**
	 * Saves the XML headers to a string and if set to a file at given location
	 * 
	 * Uses the mime-type filters
	 * 
	 * @param String $path
	 * @todo add proper rootElement :r
	 */
	public function saveXMLHeaders($path=null)
	{		
		$dom = new DOMDocument('1.0');
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput = true;
		$rootElement = $dom->createElement('tdRequests');
		
		
		if($this->mimeTypeFilters != null && !count($this->mimeTypeFilters) == 0)
		{
			foreach($this->mimeTypeFilters as $mimeTypeFilter)
			{				
				$xmlHeadersFiltered = $this->xmlHeaders->xpath("//tdRequest/tdMimeType[text()='".$mimeTypeFilter."']/parent::*");
				
				if($xmlHeadersFiltered != null)
				{
					foreach($xmlHeadersFiltered as $xmlHeader)
					{
						$dom_sxe = dom_import_simplexml($xmlHeader);
						$dom_sxe = $dom->importNode($dom_sxe, true);
						$dom->appendChild($dom_sxe);
					}
				}
			}
		}		
		
		
		$dom->insertBefore($rootElement,$dom_sxe);
		$xmlFile = $dom->saveXML();
		
		if($path != null)
		{
			file_put_contents($path,$xmlFile);
		}
		
		return $xmlFile;
	}
	
	/**
	 * @return array(String) $customHeaders
	 */
	public function getCustomHeaders()
	{
		return $this->customHeaders;
	}

	/**
	 * @param array(String) $customHeaders
	 */
	public function setCustomHeaders($customHeaders)
	{
		$this->customHeaders = $customHeaders;
	}
	
	/**
	 * @return array(String)(String) $postHeadersArray
	 */
	public function getPostHeadersArray()
	{
		return $this->postHeadersArray;
	}

	/**
	 * @param array(String)(String) $postHeadersArray
	 */
	public function setPostHeadersArray($postHeadersArray)
	{
		$this->postHeadersArray = $postHeadersArray;
	}
	
	/**
	 * Set an array of MIME Type Filters. With this you can load up a very general XML-Header file specifying many headers
	 * but only the ones with the set MIME Types will be executed.
	 * 
	 * This could be handy for very quick and dirty testing but for proper usage I would not recommend using it in this
	 * way and instead it would be better to just make an XML file containing the minimum set of information you need.
	 * 
	 * @param array(String) $filters
	 */
	public function setMIMETypeFilters($filters)
	{
		$this->mimeTypeFilters = $filters;
	}
	
	public function getMIMETypeFilters()
	{
		return $this->mimeTypeFilters;
	}
	
	/**
	 * Returns the output of the header where the current header counter is pointing at (normally the last execution
	 * if you do not explicitly change it in between)
	 * 
	 * @param integer $headerNumber headernumber of your choice, must exist
	 */
	public function getOutput($headerNumber=null)
	{
		if($headerNumber == null) $headerNumber = $this->headerCounter;
		
		return $this->output[$headerNumber];
	}
	
	/**
	 * @return Get the whole array of all the output produced by runs so far. Keys are the headernumbers in the XML file.
	 */
	public function getAllOutput()
	{
		return $this->output;
	}
}
