<?php
	class DB {
		protected $db = null;
		
		public function __construct($dsn, $username = "", $password = "") {
			$this->db = new PDO($dsn, $username, $password);
			$this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		}
		
		public function query($query, $values = array()) {
			$s = $this->db->prepare($query);
			$s->execute($values);
			$s->setFetchMode(PDO::FETCH_ASSOC);
			return $s->fetchAll();
		}
	}
	
	class XML {
		protected $filters = array();
		protected $template = null;
		protected $parameters = array();
		protected $xml = null;

		public function __construct() {
			$this->xml = new DOMDocument();
		}
		
		public function setTemplate($template) {
			$this->template = $template;
			return $this;
		}
		
		public function setParameter($name, $value) {
			$this->parameters[$name] = $value;
			return $this;
		}
		
		public function setData($data) {
			$this->xml->appendChild($this->arrayToNode($data));
			return $this;
		}
		
		public function addFilter($filter) {
			$this->filters[] = $filter;
			return $this;
		}
		
		public function toString() {
			$xml = null;
			if ($this->template) {
				$xsl = new DOMDocument();
				$xsl->load($this->template, LIBXML_NOCDATA);
				$xslt = new XSLTProcessor();
				$xslt->importStylesheet($xsl);
				foreach ($this->parameters as $name=>$value) {
					$xslt->setParameter("", $name, $value);
				}
				return $xslt->transformToXML($this->xml); 
			} else {
				return $this->xml->saveXML();
			}
		}
		
		protected function arrayToNode($array, $nodeName = null) {
			$node = ($nodeName === null ? $this->xml->createDocumentFragment() : $this->xml->createElement($nodeName));

			foreach ($array as $name=>$value) {
				if (is_array($value)) {
					$test = each($value);
					if (is_numeric($test[0])) { /* numbered array - set of children */
						foreach ($value as $child) {
							$node->appendChild($this->arrayToNode($child, $name));
						}
					} else { /* associative array - one child */
						$node->appendChild($this->arrayToNode($value, $name));
					}
				} else {
					$value = $this->filter($value);
					if ($name === "") {
						$node->appendChild($this->xml->createCDATASection($value));
					} else {
						$node->setAttribute($name, $value);
					}
				}
			}

			return $node;
		}
		
		protected function filter($str) {
			for ($i=0;$i<count($this->filters);$i++) {
				$str = $this->filters[$i]->apply($str);
			}
			return $str;
		}
	}
	
	class APP {
		const GET = 1;
		const POST = 2;
		const COOKIE = 4;

		protected $BASE = "";
		protected $dispatch_table = array();

		public function __construct() {
			if (isset($_SERVER["DOCUMENT_ROOT"]) && isset($_SERVER["SCRIPT_FILENAME"])) { /* detect base path */
				$root = $_SERVER["DOCUMENT_ROOT"];
				$cwd = dirname($_SERVER["SCRIPT_FILENAME"]);

				if (strpos($cwd, $root) === 0) { /* found! */
					$this->BASE = substr($cwd, strlen($root));
				}
			}
		}
		
		protected function dispatch() {
			$method = strtolower($_SERVER["REQUEST_METHOD"]);
			$method = $this->requestValue("http_method", self::POST, $method);

			$handler = "";
			$resource = substr($_SERVER["REQUEST_URI"], strlen($this->BASE));
			
			do {
				foreach ($this->dispatch_table as $row) {
					$item = preg_split("/\\s+/", $row);
					if ($item[0] != $method) { continue; }
					preg_match("#".$item[1]."#", $resource, $matches);
					if (!$matches) { continue; }
					$handler = $item[2];
					break;
				}
				
				if (!$handler) { return $this->http404(); } /* does not exist in table */
				
				if (substr($handler, 0, 1) == "/") { /* alias to other resource */
					$resource = $handler;
					$handler = "";
				}
				
			} while (!$handler);
			
			return $this->$handler($method, $matches);
		}

		/**
		 * @param {string} name
		 * @param {int} where Mix of GET/POST/COOKIE constants
		 * @param {any} default Used when no value is specified; used to coerce return type
		 * @returns {typeof($default)}
		 */
		protected function requestValue($name, $where, $default = null) {
			$value = $default;
			if (($where & self::GET) && isset($_GET[$name])) {
				$value = $_GET[$name];
			} elseif (($where & self::POST) && isset($_POST[$name])) {
				$value = $_POST[$name];
			} elseif (($where & self::COOKIE) && isset($_COOKIE[$name])) {
				$value = $_COOKIE[$name];
			} else {
				return $value;
			}
			
			if (!is_null($default)) { settype($value, gettype($default)); }
			return $value;
		}
		
		protected function httpRedirect($location) {
			if (substr($location, 0, 1) == "/") {
				$location = $this->BASE . $location;
			}
			header("Location: " . $location);
		}
		
		protected function httpStatus($code) {
			header("HTTP/1.1 " . $code, true, $code);
		}
		
		protected function http404() {
			$this->httpStatus(404);
			echo "<h1>404 Not Found</h1>";
		}
		
		protected function http500() {
			$this->httpStatus(500);
			echo "<h1>500 Internal Server Error</h1>";
		}
	}
	
	class FILTER {
		public function __construct() {
		}

		public function apply($str) {
			return $str;
		}
	}
	
	class FILTER_TYPO extends FILTER {
		protected static $typo = array(
			"<->" => "↔",
			"->" => "→",
			"<-" => "←",
			"<=>" => "⇔",
			"=>" => "⇒",
			"<=" => "⇐",
			">>" => "»",
			"<<" => "«",
			"---" => "—",
			"--" => "–",
			"(c)" => "©",
			"(C)" => "©",
			"(tm)" => "™",
			"(TM)" => "™",
			"(r)" => "®",
			"(R)" => "®",
			"..." => "…"
		);
		
		public function apply($str) {
			$str = str_replace(array_keys(self::$typo), array_values(self::$typo), $str);
			return preg_replace("/(?<=\d)x(?=\d)/i", "×", $str);
		}
	}
	
	class FILTER_NBSP extends FILTER {
		public function apply($str) {
			return preg_replace("/(?<=\s)([A-Z]) (?=\S)/i", "$1".html_entity_decode("&nbsp;", ENT_QUOTES, "utf-8"), $str);
		}
	}

	class FILTER_FRACTIONS extends FILTER {
		protected $fractions = null;

		protected static $_fractions = array(
			"1/2" => "½",
			"1/4" => "¼",
			"3/4" => "¾",
			"1/3" => "⅓",
			"2/3" => "⅔",
			"1/5" => "⅕",
			"2/5" => "⅖",
			"3/5" => "⅗",
			"4/5" => "⅘",
			"1/6" => "⅙",
			"5/6" => "⅚",
			"1/8" => "⅛",
			"3/8" => "⅜",
			"5/8" => "⅝",
			"7/8" => "⅞"
		);
		
		public function __construct() {
			parent::__construct();
			$this->xml = new DOMDocument();
			foreach (self::$_fractions as $name=>$value) {
				$newname = "@(?<=[^\\d]|^)".$name."(?=[^\\d]|$)@";
				$this->fractions[$newname] = $value;
			}
		}

		public function apply($str) {
			return preg_replace(array_keys($this->fractions), array_values($this->fractions), $str);
		}
	}
?>
