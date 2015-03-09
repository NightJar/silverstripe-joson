<?php
class JSONAPI extends ViewableData {

	protected static $cache = array();

	protected $guid;
	protected $endpoint;
	protected $endpointConfig;
	protected $query;
	protected $data;
	
	protected $response;
	protected $result;
	
	protected $defaultConfig = array(
		'expects' => 'auto',
		'returns' => 'auto',
		'methods' => array('GET'),
		'params' => array(),
		'duplex' => array(),
		'pull' => array(),
		'push' => array(),
		'cache' => 0
	);
	
	public function __construct($endpointIdentifier, $id=null, $data=null) {
		$this->endpoint = $endpointIdentifier;
		$this->setIDs($id);
		$this->data = $data;
		$this->endpointConfig = $this->getEndpointConfig($endpointIdentifier);
		$base = $this->config()->base;
		$this->query = RestfulService::create(is_string($base)?$base:$base[Director::get_environment_type()], (int)$this->endpointConfig['cache']);
		
		//set debug data
		if(Director::isDev()) {
			if(isset($_GET['debug_api'])) {
				if($_GET['debug_api'] != '0')
					Session::set('debug_api', true);
				else
					Session::clear('debug_api');
			}
			if(isset($_GET['show_api_errors'])) {
				if($_GET['show_api_errors'] != '0') {
					if($_GET['show_api_errors'])
						Session::set('show_api_errors', $_GET['show_api_errors']);
					else
						Session::set('show_api_errors', true);
				}
				else
					Session::clear('show_api_errors');
			}
		}
	}
	
	/*
		Prepare for us this payload on Create/Update calls
	*/
	private function formatData() {
		$data = $this->data;
		if(is_object($data) && $data instanceof Form) {
			$form = $data;
			$data = array();
			foreach($form->Fields()->dataFields() as $field) {
				$name = $field->getName();
				if($name && $name != 'SecurityID') {
					if($field instanceof DateField) {
						if($field->Value())
							//oh you, old asp.net
							$data[$name] = '/Date('.((int)$field->setConfig('datavalueformat', 'U')->dataValue()*1000/**/).')/';
					}
					else
						$data[$name] = $field->dataValue();
				}
			}
		}
		if($data) {
			if(is_string($data))
				$this->data = $data;
			elseif(ArrayLib::is_associative($data) && $this->endpointConfig['expects'] == 'list') {
				$this->data = json_encode(array($data)); //array of one object
			}
			elseif(is_array($data))
				$this->data = json_encode($data); //array
			else
				$this->data = "$data"; //__toString()!
		}
	}
	
	/*
		The idea behind this is if a GET allows parameters to be passed in
		e.g. ?minPrice=300&maxPrice=400
	*/
	public function setParams($params) {
		if(is_array($params)) {
			if(!empty($params) && !ArrayLib::is_associative($params)) throw new Exception('JSONAPI::setParams expects associative array as it\'s parameter');
			$params = array_intersect_key($params, array_flip($this->endpointConfig['params']));
			if(!empty($params) && Director::isDev() && Session::get('debug_api')) var_dump($params);
			$this->query->setQueryString($params);
		}
		return $this;
	}
	
	public function setIDs($ids) {
		$this->guid = $ids && !is_array($ids) ? array($ids) : $ids;
		return $this;
	}
	
	/*
		if we're passing data, then we are wanting to create/update (put/post)
		Ensure first that this is allowed on this endpoint (don't just assume)
	*/
	private function okToSave() {
		if(in_array('POST', $this->endpointConfig['methods'])) {
			$this->formatData();
			return true;
		}
		throw new Exception('POSTing data not allowed on this end-point');
		return false;
	}
	
	/*
		Parses eg. User/$GUID into User/1234-56789-1234 (ie. to post to/update a record)
	
		This function makes the horrible assumption that the only parameter one would ever feed into
		an endpoint is a microsoft app style GUID. It should scan for and replace param definitions of
		any name, much the same as SilverStripe processes routes (as was the original intention)
	*/
	private function parseURL() {
		$FULLMATCHES = $MATCH = $REF = 0;
		$BACKREFS = $OFFSET = 1;
		$url = $this->endpointConfig['url'];
		$numVars = preg_match_all('#(/?\$GUID!?)/?#', $url, $matches, PREG_OFFSET_CAPTURE);
		if($numVars) {
			$last = $matches[$FULLMATCHES][$numVars-1];
			$lastReq = substr($matches[$BACKREFS][$numVars-1][$MATCH], -1) == '!';
			//is the last actually the segment in the endpoint? if not, then it's still required.
			if(!$lastReq) $lastReq = $last[$OFFSET] + strlen($last[$MATCH]) != strlen($url);
			$requiredVars = $lastReq ? $numVars : $numVars-1;
			if($requiredVars && !(count($this->guid) == $requiredVars || count($this->guid)-1 == $requiredVars)) {
				throw new Exception("Insufficient url vars to populate required sections of this end-point ({$this->endpoint})");
			}
			$url = preg_replace('#\$GUID!?#', '%s', $url);
			$suppliedVars = count($this->guid);
			if(!$lastReq && ($suppliedVars == $numVars-1 || ($this->guid[$suppliedVars-1] === null && $suppliedVars == $numVars))) $url = substr($url, 0, strrpos($url, '/%s'));
			$url = vsprintf($url, $this->guid);
		}
		return $url;
	}
	
	/*
		RESTful GET POST PUT DELETE?
		Nah, 'RESTless' GET or POST all the things.
		
		Fetch the data, create the appropriate record for the return type (or none in the case of eg string/number).
		If a custom class is provided then it must take the result data in it's constructor (and presumably set itself up).
		Or make an error. Whatever.
	*/
	public function execute() {
		if($this->data) $this->okToSave(); //throws exception, so don't actually have to do anything
		$cfg = $this->endpointConfig;
		$url = $this->parseURL();
		
		if(!$this->data && isset(self::$cache[$url]) && !self::$cache[$url]->isError()) {
			if(Director::isDev() && Session::get('debug_api'))
				//Debug::show('Found cached result for, returning that.');
				var_dump("Found cached result for '$url', returning that.");
			return self::$cache[$url];
		}
		
		if(Director::isDev() && Session::get('debug_api')) {
			$message = ($this->data ? 'POST data to ' : 'GET data from ')."'{$this->endpoint}' at: $url";
			//Debug::show($message);
			var_dump($message);
			if($this->data) var_dump($this->data);
		}
		
		$headers = $this->data ? array('Content-Type: application/json') : null;
		$auth = $this->config()->auth;
		if($auth) $headers[] = $auth;
		$this->response = $this->query->request($url, $this->data ? 'POST' : 'GET', $this->data, $headers);
		$body = $this->response->getBody();
		$type = $this->response->getHeader('Content-Type');
		$type = explode(';', is_array($type) ? $type[count($type)-1] : $type);
		$type = $type[0];
		if(!$this->response->isError() && $type == 'application/json') {
			switch($cfg['returns']) {
				case 'auto': $class = true; break; //default via config
				case 'list': $class = 'APIList'; break;
				case 'object': $class = 'APIData'; break;
				default: $class = $cfg['returns']; //fall back to specified class name
			}
			$this->result = json_decode($body);
			if($class) {
				if($class === true) {
					if(is_array($this->result))
						$class = 'APIList';
					elseif(is_object($this->result))
						$class = 'APIData';
					else
						$class = false; //leave result as a primitive/string
				}
				if($class) {
					if(is_subclass_of($class, 'Object'))
						$this->result = $class::create($this->result);
					else
						$this->result = new $class($this->result);
				}
			}
			self::$cache[$url] = $this;
		}
		else { //error!
			if($type != 'application/json') {
				$this->result = $body; //probably html
			}
			else { //error data structure
				$this->result = ArrayData::create(json_decode($body));
				if(Director::isDev() && Session::get('debug_api'))
					//Debug::show($this->result->Message);
					var_dump($this->result->Message);
			}
			if(Director::isDev() && $sae = Session::get('show_api_errors')) {
				if($sae === true || preg_match("#^$sae#", $this->endpoint))
					die($body); //this is serious business (spit out returned raw html).
			}
		}
		return $this;
	}
	
	public function errorMessage() {
		$error = false;
		if($this->isError()) {
			//Probably html
			if(is_string($this->result)) $error = $this->result;
			// JSON with Message property.
			else $error = is_object($this->result) && $this->result->Message ? 
					$this->result->Message : "A non-descript error has occurred";
		}
		return $error;
	}
	
	public function isError() {
		if(!$this->response) $this->execute();
		return $this->response->isError();
	}
	
	public function result() {
		if(!$this->result && $this->isError())
			$this->execute();
		return $this->result;
	}
	
	private function getEndpointConfig($identifier) {
		$config = $this->defaultConfig;
		$endpoint = null;
		$urls = array();
		while(!$endpoint) {
			$identifiers = explode('.', $identifier);
			$depth = count($identifiers);
			$parent = $this->config()->get('endpoints');
			if(!array_key_exists($identifiers[0], $parent))
				throw new Exception("API end-point '$identifiers[0]' not found!");
			$parent = $parent[$identifiers[0]];
			for($i = 0; $i < $depth; $i++) {
				//set up
				$segment = $identifiers[$i];
				$next = $i+1 != $depth ? $identifiers[$i+1] : null;
				$validChildren = array();
				if(is_string($parent))
					$parent = array('url' => $parent);
				if(!array_key_exists('url', $parent))
					throw new Exception("API end-point '$segment' has no url value set");
				$urls[] = $parent['url'];
				$parent = array_replace($config, $parent);
				//find sub segments
				if($depth > 1) {
					if($this->data)
						$validChildren = array_merge($parent['duplex'], $parent['push']);
					else
						$validChildren = array_merge($parent['duplex'], $parent['pull']);
				}
				//if there are no deeper segments...
				if(!$next) {
					//check for alias
					$url = substr($parent['url'], 0, 2);
					if($url == '->') {
						$urls = array();
						$identifier = substr($parent['url'], 2);
					}
					else //found!
						$endpoint = $config = $parent;
				}
				//else go deeper
				else if($next && array_key_exists($next, $validChildren)) {
					//if there is data the sub end-point MUST be in duplex or push (because that's all we've checked)
					if($this->data && !in_array('POST', $config['methods']))
						$config['methods'][] = 'POST';
					$parent = $validChildren[$next];
				}
				else //otherwise explode
					throw new Exception("API end-point '$segment' has no child '$next'");
			}
		}
		foreach($urls as $urlsegment) {
			if(substr($urlsegment, 0, 1) == '/')
				$url = $urlsegment;
			else
				$url = substr($url, -1) == '/' ? $url.$urlsegment : "$url/$urlsegment";
		}
		$config['url'] = $url;
		return $config;
	}
	
	public static function get($endpoint, $id=null, $params=array()) {
		$calledClass = get_called_class();
		if(!Config::inst()->get($calledClass, 'base') || !Config::inst()->get($calledClass, 'endpoints')) 	throw new InvalidArguementException("Incomplete API config found for $calledClass");
		$result = $calledClass::create($endpoint, $id)->setParams($params)->execute();
		$error = $result->errorMessage();
		return $error ? $error : $result->result();
	}
	
	public static function set($endpoint, $id=null, $data=array()) {
		$calledClass = get_called_class();
		if(!Config::inst()->get($calledClass, 'base') || !Config::inst()->get($calledClass, 'endpoints')) 	throw new InvalidArguementException("Incomplete API config found for $calledClass");
		$result = $calledClass::create($endpoint, $id, $data)->execute();
		$error = $result->errorMessage();
		return $error ? $error : $result->result();
	}
	
}

/*
	JSON returns primitives. Convert to a SilverStripe consistent & Template compatible method of access.
	A class for objects, and a class for arrays that automatically ensures perpetuation of this conversion.
*/

class APIList extends ArrayList {
	public function __construct(array $items = array()) {
		foreach($items as $i => $item) {
			$items[$i] = $this->convertItem($item);
		}
		$this->items = array_values($items);
		ViewableData::__construct();
	}
	private function convertItem($item) {
		//not a perfect test, but ViewableData is the common parent of both DataList and DataObject
		if((is_object($item) && !$item instanceof ViewableData) || ArrayLib::is_associative($item))
			$item = APIData::create($item);
		else if(is_array($item))
			$item = APIList::create($item);
		return $item;
	}
	public function getIterator() {
		return new ArrayIterator($this->items);
	}
	public function push($item) {
		$this->items[] = $this->convertItem($item);
	}
	public function unshift($item) {
		array_unshift($this->items, $this->convertItem($item));
	}
	public function merge($with) {
		parent::merge($with);
		return $this;
	}
}

class APIData extends ArrayData {
	public function getField($f) {
		$value = $this->array[$f];
		if((is_object($value) && !$value instanceof ViewableData) || ArrayLib::is_associative($value))
			$value = APIData::create($value);
		elseif(is_array($value))
			$value = APIList::create($value);
		//Test for old style .NET JSON Date 'objects'
		elseif(is_string($value) && preg_match('#^\\/Date\((\d+)\)\\/$#', $value, $matches)) {
			//Non UTC dates.
			$value = DBField::create_field('SS_Datetime', date('Y-m-d\TH:i:s', intval((float)$matches[1]/1000)));
			//$value = DBField::create_field('SS_Datetime', DateTime::createFromFormat('U', intval((float)$matches[1]/1000))->format('Y-m-d\TH:i:s'));
			
			//dates returned in UTC
			//$value = DBField::create_field('SS_Datetime', DateTime::createFromFormat('U', intval((float)$matches[1]/1000))->setTimeZone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d\TH:i:s'));
		}
		return $value;
	}
	public function exists() {
		return !empty($this->array);
	}
}
