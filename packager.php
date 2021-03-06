<?php

require dirname(__FILE__) . "/helpers/yaml.php";
require dirname(__FILE__) . "/helpers/array.php";

class Packager {
	
	protected function failure($message){
		throw new Exception($message);
	}
	
	private $packages  = array();
	private $manifests = array();
	private $root      = null;
	private $overall   = null;
	private $postprocessor = null;
	private $files     = array();

	public function __construct($package_paths){
		foreach ((array)$package_paths as $package_path) $this->parse_manifest($package_path);
	}

	private function parse_manifest($path){
		$pathinfo = pathinfo($path);

		if (is_dir($path)){

			$package_path = $pathinfo['dirname'] . '/' . $pathinfo['basename'] . '/';

			if (file_exists($package_path . 'package.yml')){
				$manifest_path = $package_path . 'package.yml';
				$manifest_format = 'yaml';
			} else if (file_exists($package_path . 'package.yaml')){
				$manifest_path = $package_path . 'package.yaml';
				$manifest_format = 'yaml';
			} else if (file_exists($package_path . 'package.json')){
				$manifest_path = $package_path . 'package.json';
				$manifest_format = 'json';
			} else {
				self::warn('No package information file could be found in "' . $package_path . '".');
			}

		} else if (file_exists($path)){
			$package_path = $pathinfo['dirname'] . '/';
			$manifest_path = $package_path . $pathinfo['basename'];
			$manifest_format = $pathinfo['extension'];
		} else {
			$this->failure('Neither directory nor file "' . $path . '" exist.');
		}

		if ($manifest_format == 'json') $manifest = json_decode(file_get_contents($manifest_path), true);
		else if ($manifest_format == 'yaml' || $manifest_format == 'yml') $manifest = YAML::decode_file($manifest_path);

		if (empty($manifest)) {
			$this->failure("manifest not found in $package_path, or unable to parse manifest.");
		}

		$package_name = $manifest['name'];

		if ($this->root == null) $this->root = $package_name;

		if (array_has($this->manifests, $package_name)) {
			return;
		}

		$manifest['path'] = $package_path;
		$manifest['manifest'] = $manifest_path;

		$this->manifests[$package_name] = $manifest;
		
		if (!isset($manifest['sources']) || !is_array($manifest['sources'])) {
			$this->failure('No valuable sources defined in package "'  . $package_name . '"');
		}
		
		if (!is_array($manifest['sources'])){
			$manifest['sources'] = $this->bfglob($package_path, $manifest['sources'], 0, 5);
			$patternUsed = true;
 		}
		else {
			$patternUsed = false;
		}

		if (!empty($manifest['overall'])) $this->overall = $package_path . $manifest['overall'];

		foreach ($manifest['sources'] as $i => $path){

			if ($this->overall == $path) {
				unset($manifest['sources'][$i]);
				continue;
			}

			// thomasd: if the source-node contains a description we cache it, but we wait if there's also a description-header in the file as this one takes precedence
			if (is_array($path)){
				$source_desc = $path[1];
				$path = $path[0];
			}
			else {
				$source_desc = null;
			}
			if (!$patternUsed) $path = $package_path . $path;
			
			// this is where we "hook" for possible other replacers.
			if (!file_exists($path)) {
				$this->failure('Source file "'  . $path . '" for package "'  . $package_name . '" does not exist');
			}
			$source = file_get_contents($path);

			$descriptor = array();

			// get contents of first comment
			preg_match('/\/\*\s*^---(.*?)^(?:\.\.\.|---)\s*\*\//ms', $source, $matches);

			if (!empty($matches)){
				$descriptor = YAML::decode($matches[0]);
			}
			// thomasd: if the file doesn't contain a proper description-header but the manifest does, we take that description 
			else if (isset($source_desc) && is_array($source_desc)){
				$descriptor = $source_desc;
			}

			// populate / convert to array requires and provides
			$requires = (array)(!empty($descriptor['requires']) ? $descriptor['requires'] : array());
			$provides = (array)(!empty($descriptor['provides']) ? $descriptor['provides'] : array());
			$file_name = !empty($descriptor['name']) ? $descriptor['name'] : basename($path, '.js');

			// "normalization" for requires. Fills up the default package name from requires, if not present.
			foreach ($requires as $i => $require)
				$requires[$i] = implode('/', $this->parse_name($package_name, $require));

			$license = array_get($descriptor, 'license');

			$this->packages[$package_name][$file_name] = array_merge($descriptor, array(
				'name' => $file_name,
				'package' => $package_name,
				'requires' => $requires,
				'provides' => $provides,
				'source' => $source,
				'path' => $path,
				'package/name' => $package_name . '/' . $file_name,
				'license' => empty($license) ? array_get($manifest, 'license') : $license
			));

		}
	}

	public function add_package($package_path){
		$this->parse_manifest($package_path);
	}

	public function remove_package($package_name){
		unset($this->packages[$package_name]);
		unset($this->manifests[$package_name]);
	}

	public function set_postprocessor($processor_function){
		$this->postprocessor = $processor_function;
	}
	
	// # private UTILITIES

	private function parse_name($default, $name){
		$exploded = explode('/', $name, 2);
		$length = count($exploded);
		if ($length == 1) return array($default, $exploded[0]);
		if (empty($exploded[0])) return array($default, $exploded[1]);
		return array($exploded[0], $exploded[1]);
	}

	private	function bfglob($path, $pattern = '*', $flags = 0, $depth = 0) {
		$matches = array();
		$folders = array(rtrim($path, DIRECTORY_SEPARATOR));

		while($folder = array_shift($folders)) {
			$matches = array_merge($matches, glob($folder.DIRECTORY_SEPARATOR.$pattern, $flags));
			if ($depth != 0) {
				$moreFolders = glob($folder.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR);
				$depth   = ($depth < -1) ? -1: $depth + count($moreFolders) - 2;
				$folders = array_merge($folders, $moreFolders);
			}
		}
		return $matches;
	}
	
	// # private HASHES

	private function component_to_hash($name){
		$pair = $this->parse_name($this->root, $name);
		$package = array_get($this->packages, $pair[0]);

		if (!empty($package)){
			$component = $pair[1];

			foreach ($package as $file => $data){
				foreach ($data['provides'] as $c){
					if ($c == $component) return $data;
				}
			}
		}

		return null;
	}

	private function file_to_hash($name){
		$pair = $this->parse_name($this->root, $name);
		$package = array_get($this->packages, $pair[0]);

		if (!empty($package)){
			$file_name = $pair[1];

			foreach ($package as $file => $data){
				if ($file == $file_name) return $data;
			}
		}

		return null;
	}

	public function file_exists($name){
		return $this->file_to_hash($name) ? true : false;
	}

	public function component_exists($name){
		return $this->component_to_hash($name) ? true : false;
	}

	public function package_exists($name){
		return array_contains($this->get_packages(), $name);
	}

	public function wrap_all($code) {
		if (!$this->overall) return $code . "\n";
		
		return str_replace('/*** [Code] ***/', $code, file_get_contents($this->overall));
	}
	
	// perform the global postprocess AFTER all sources have been merged.
	public function global_postprocess($code) {
		if (empty($this->postprocessor)) {
			// do nothing to the content, simply keep it as is...
			return $code; 
		}
		$rv = call_user_func($this->postprocessor, $code);
		return ($rv === false ? $code : $rv);
	}
	
	// perform package-specific postprocess per file.
	public function individual_postprocess($file, $code) {
		return $code;
		
		// TODO:
		$filespec = $this->file_to_hash($file);
		if (array_has($filespec, 'package')) {
			$package = array_get($this->packages, $filespec['package']);
			$package['lazyload']['source'] = 'bla!';
			echo "<hr><pre>\n"; print_r(array(__FILE__, __LINE__, $file, $package));
		
			if (!empty($manifest['postprocessor'])) {
				$this->postprocessor = $manifest['postprocessor'];
				if (!is_callable($this->postprocessor)) {
					$this->failure('Package "'  . $package_name . '" requires execution of an undefined postprocessor function "' . strval($this->postprocessor) . '"');
				}
			}
		}
	}
	
	// return FALSE on success or return array of strings listing the validation failures
	public function validate($more_files = array(), $more_components = array(), $more_packages = array()){
		$rv = array();
		
		foreach ($this->packages as $name => $files){
			foreach ($files as $file){
				$file_requires = $file['requires'];
				foreach ($file_requires as $component){
					if (!$this->component_exists($component)){
						$rv[] = "WARNING: The component $component, required in the file " . $file['package/name'] . ", has not been provided.";
					}
				}
			}
		}

		foreach ($more_files as $file){
			if (!$this->file_exists($file)) $rv[] = "WARNING: The required file $file could not be found.";
		}

		foreach ($more_components as $component){
			if (!$this->component_exists($component)) $rv[] = "WARNING: The required component $component could not be found.";
		}

		foreach ($more_packages as $package){
			if (!$this->package_exists($package)) $rv[] = "WARNING: The required package $package could not be found.";
		}
		
		return (count($rv) ? $rv : false);
	}

	public function resolve_files($files = array(), $components = array(), $packages = array(), $excluded = array()){

		if (!empty($components)){
			$more = $this->components_to_files($components);
			foreach ($more as $file) array_include($files, $file);
		}

		foreach ($packages as $package){
			$more = $this->get_all_files($package);
			foreach ($more as $file) array_include($files, $file);
		}
		
		if (is_array($excluded)){
			if (isset($excluded['components'])){
				$less = array();
				foreach ($this->components_to_files($excluded['components']) as $file) array_include($less, $file);
				$exclude = $this->complete_files($less);
				$files = array_diff($files, $exclude);
			}
		}
		
		/*
		  As the components-to-remove may remove dependencies which are also required by files/components
		  which are still in the list, we first need to remove those components-to-remove and only then
		  'complete' the fileset with listed dependencies as they exist in the remaining set.
		  
		  Meanwhile the 'files' and 'files_and_deps' remove sets are meant as more of a brute-force
		  apparatus where those take precedence over the 'real' dependencies, i.e. those two allow
		  you to discard a dependency file which is listed in the $files[] set!
		*/
		
		$files = $this->complete_files($files);
		
		if (is_array($excluded)){
			if (isset($excluded['files'])){
				foreach ($excluded['files'] as $file) array_erase($files, $file);
			}
			
			if (isset($excluded['files_and_deps'])){
				$less = $this->complete_files($excluded['files_and_deps']);
				foreach ($less as $file) array_erase($files, $file);
			}
		}

		return $files;
	}

	// # public BUILD

	public function build($files = array(), $components = array(), $packages = array(), $blocks = array(), $excluded = null){

		$files = $this->resolve_files($files, $components, $packages, $excluded);
		
		if (empty($files)) return '';
		
		$included_sources = array();
		foreach ($files as $file) {
			$filespec = $this->file_to_hash($file);
			$included_sources[] = $this->individual_postprocess($file, $this->get_file_source($file));
		}
		
		$source = implode($included_sources, "\n\n");
		
		return $this->global_postprocess($this->wrap_all($this->remove_blocks($source, $blocks)));
	}

	public function remove_blocks($source, $blocks){
		foreach ($blocks as $block){
			$source = preg_replace_callback("%(/[/*])\s*<$block>(.*?)</$block>(?:\s*\*/)?%s", array($this, "block_replacement"), $source);
		}
		
		return $source;
	}

	private function block_replacement($matches){
		return (strpos($matches[2], ($matches[1] == "//") ? "\n" : "*/") === false) ? $matches[2] : "";
	}
	
	public function build_from_files($files, $components = array(), $packages = array(), $blocks = array()){
		return $this->build($files, $components, $packages, $blocks);
	}
	
	public function build_from_components($components, $excluded = null){
		return $this->build(array(), $components, array(), array(), $excluded);
	}

	public function write_from_files($file_name, $files = null){
		$full = $this->build_from_files($files);
		file_put_contents($file_name, $full);
	}

	public function write_from_components($file_name, $components = null, $excluded = null){
		$full = $this->build_from_components($components, $excluded);
		file_put_contents($file_name, $full);
	}

	// # public FILES

	public function get_all_files($of_package = null){
		$files = array();
		foreach ($this->packages as $name => $package){
			if ($of_package == null || $of_package == $name) foreach ($package as $file){
				$files[] = $file['package/name'];
			}
		}
		return $files;
	}

	public function get_file_dependancies($file){
		$this->files = array();
		$deps = $this->parse_file_dependancies($file);
		return $deps;
	}

	private function parse_file_dependancies($file){
		$deps = array();
		$hash = $this->file_to_hash($file);

		if (empty($hash)) return array();
		if (!in_array($file, $this->files)) {
			$this->files[] = $file;
			$files = $this->components_to_files($hash['requires']);
			$files = array_diff($files, $this->files);
			$deps = $this->complete_files($files);
		}
		return $deps;
	}

	public function complete_file($file){
		$files = $this->parse_file_dependancies($file);
		$hash = $this->file_to_hash($file);
		if (empty($hash)) return array();
		array_include($files, $hash['package/name']);
		return $files;
	}

	public function complete_files($files){
		$ordered_files = array();
		foreach ($files as $file){
			$all_files = $this->complete_file($file);
			foreach ($all_files as $one_file) array_include($ordered_files, $one_file);
		}
		return $ordered_files;
	}

	// # public COMPONENTS

	public function component_to_file($component){
		return array_get($this->component_to_hash($component), 'package/name');
	}

	public function components_to_files($components){
		$files = array();
		foreach ($components as $component){
			$file_name = $this->component_to_file($component);
			if (!empty($file_name) && !in_array($file_name, $files)) $files[] = $file_name;
		}
		return $files;
	}

	// # dynamic getter for PACKAGE properties and FILE properties

	public function __call($method, $arguments){
		if (strpos($method, 'get_file_') === 0){
			$file = array_get($arguments, 0);
			if (empty($file)) return null;
			$key = substr($method, 9);
			$hash = $this->file_to_hash($file);
			return array_get($hash, $key);
		}

		if (strpos($method, 'get_package_') === 0){
			$key = substr($method, 12);
			$package = array_get($arguments, 0);
			$package = array_get($this->manifests, (empty($package)) ? $this->root : $package);
			return array_get($package, $key);
		}

		return null;
	}

	public function get_packages(){
		return array_keys($this->packages);
	}

	// authors normalization

	public function get_package_authors($package = null){
		if (empty($package)) $package = $this->root;
		$package = array_get($this->manifests, $package);
		if (empty($package)) return array();
		return $this->normalize_authors(array_get($package, 'authors'), array_get($package, 'author'));
	}

	public function get_file_authors($file){
		$hash = $this->file_to_hash($file);
		if (empty($hash)) return array();
		return $this->normalize_authors(array_get($hash, 'authors'), array_get($hash, 'author'), $this->get_package_authors());
	}

	private function normalize_authors($authors = null, $author = null, $default = null){
		$use = empty($authors) ? $author : $authors;
		if (empty($use) && !empty($default)) return $default;
		if (is_array($use)) return $use;
		if (empty($use)) return array();
		return array($use);
	}
}
