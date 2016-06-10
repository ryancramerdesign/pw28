<?php 

/**
 * ImageSizer Engine ImageMagick
 * 
 * Copyright (C) 2016 by Horst Nogajski
 *
 * @property string $imageMagickPath Module configuration value
 *
 */
class ImageSizerEngineIMagickCLI extends ImageSizerEngine implements Module, ConfigurableModule {
	
	public static function getModuleInfo() {
		return array(
			'title' => 'ImageMagick CLI Image Sizer',
			'version' => 1,
			'summary' => "Upgrades image manipulations to use The CLI (exec) version of ImageMagick",
			'author' => 'Horst Nogajski',
			'autoload' => false,
			'singular' => false,
		);
	}

	/**
	 * @var bool|null
	 * 
	 */
	static protected $supported = null;

	/**
	 * @var string
	 * 
	 */
	protected $IMPATH;

	/**
	 * @var ?
	 * 
	 */
	protected $imageDepth;

	/**
	 * @var ?
	 * 
	 */
	protected $imageFormat;

	/**
	 *  Construct
	 * 
	 */
	public function __construct() {
		$this->set('imageMagickPath', '');
		parent::__construct();
	}

	/**
	 * Get shell path
	 * 
	 * @param string $path
	 * @return string mixed
	 * 
	 */
	protected static function shellpath($path) {
		if('/' != DIRECTORY_SEPARATOR) {
			$path = str_replace('/', DIRECTORY_SEPARATOR, $path);
		}
		$path = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
		return $path;
	}

	/**
	 * Get valid source image formats
	 * 
	 * @return array
	 * 
	 */
	protected function validSourceImageFormats() {
		return array('PNG', 'JPG', 'JPEG', 'GIF');
	}

	/**
	 * Is this module supported for resize of $this->filename?
	 * 
	 * @param string $action
	 * @return bool|null
	 * 
	 */
	public function supported($action = '') {
		
		if(self::$supported !== null) return self::$supported;
		
		$config = $this->wire('config');
		$debug = $config->debug || $action == 'install';
	
		// config.imageMagickPath overrides module configured imageMagickPath
		if($config->imageMagickPath) $this->imageMagickPath = $config->imageMagickPath;
		
		if(!$this->IMPATH) {
			if(!$this->imageMagickPath) {
				self::$supported = false;
				return false;
			}
			$this->IMPATH = self::shellpath($this->imageMagickPath);
		}
		
		$s = ini_get('disable_functions');
		
		if($s) {
			$a = explode(',', str_replace(' ', '', mb_strtolower($s)));
			if(in_array('exec', $a)) {
				if($debug) $this->warning(sprintf($this->_("%s - The PHP exec() function is not allowed!"), __CLASS__));
				self::$supported = false;
				return false;
			}
		}
		
		$executable = 'convert';
		if(!file_exists($this->IMPATH . $executable) && !file_exists($this->IMPATH . $executable . '.exe')) {
			if($debug) $this->warning(sprintf($this->_("%s - Missing executable: %s"), __CLASS__, $executable));
			self::$supported = false;
			return false;
		}
		
		self::$supported = true;
		
		return true;
	}

	/**
	 * Process the image resize
	 *
	 * @param string $srcFilename Source file
	 * @param string $dstFilename Destination file
	 * @param int $fullWidth Current width
	 * @param int $fullHeight Current height
	 * @param int $finalWidth Requested final width
	 * @param int $finalHeight Requested final height
	 * @return bool
	 * @throws WireException
	 *
	 */
	protected function processResize($srcFilename, $dstFilename, $fullWidth, $fullHeight, $finalWidth, $finalHeight) {
		
		$srcFilename = self::shellpath($srcFilename);
		$dstFilename = self::shellpath($dstFilename);

		@unlink($dstFilename);
		@clearstatcache(dirname($dstFilename));

		$this->modified = false;
		$this->imageDepth = $this->info['bits'];
		$this->imageFormat = strtoupper(str_replace('image/', '', $this->info['mime']));
		if(!in_array($this->imageFormat, $this->validSourceImageFormats())) {
			throw new WireException(sprintf($this->_("loaded file '%s' is not in the list of valid images", basename($dstFilename))));
		}

		$tickets = array();
		$orientations = null;
		$needRotation = $this->autoRotation !== true ? false : ($this->checkOrientation($orientations) && (!empty($orientations[0]) || !empty($orientations[1])) ? true : false);
		if($this->rotate || $needRotation) {
			$degrees = $this->rotate ? $this->rotate : ((is_float($orientations[0]) || is_int($orientations[0])) && $orientations[0] > -361 && $orientations[0] < 361 ? $orientations[0] : false);
			if($degrees !== false && !in_array($degrees, array(-360, 0, 360))) {
				$tickets[0][] = '-background transparent -rotate ' . (float) $degrees;
				if(abs($degrees) == 90 || abs($degrees) == 270) {
					// we have to swap width & height now!
					$this->setImageInfo($fullHeight, $fullWidth);
				}
			}
		}
		if($this->flip || $needRotation) {
			$vertical = null;
			if($this->flip) {
				$vertical = $this->flip == 'v';
			} else if($orientations[1] > 0) {
				$vertical = $orientations[1] == 2;
			}
			if(!is_null($vertical)) $tickets[0][] = $vertical ? '-flip' : '-flop';
		}
		if(is_array($this->cropExtra) && 4 == count($this->cropExtra)) { // crop before resize
			list($cropX, $cropY, $cropWidth, $cropHeight) = $this->cropExtra;
			$tickets[1][] = sprintf('-crop %dx%d+%d+%d!', $cropWidth, $cropHeight, $cropX, $cropY);
			$this->setImageInfo($cropWidth, $cropHeight);
		}
		$bgX = $bgY = 0;
		$bgWidth = $fullWidth;
		$bgHeight = $fullHeight;
		$resizemethod = $this->getResizeMethod($bgWidth, $bgHeight, $finalWidth, $finalHeight, $bgX, $bgY);
		if(0 == $resizemethod) {
			$this->sharpening = 'none';  // no need for sharpening because we use original copy without scaling
			$this->setImageInfo($fullWidth, $fullHeight);
		}
		if(2 == $resizemethod) { // 2 = resize with aspect ratio
			$tickets[2][] = sprintf('-resize %dx%d!', $finalWidth, $finalHeight);
			$this->setImageInfo($finalWidth, $finalHeight);
		}
		if(4 == $resizemethod) { // 4 = resize and crop from center with aspect ratio
			$tickets[2][] = sprintf('-resize %dx%d!', $bgWidth, $bgHeight);
			$tickets[2][] = sprintf('-crop %dx%d+%d+%d!', $finalWidth, $finalHeight, $bgX, $bgY);
			$this->setImageInfo($finalWidth, $finalHeight);
		}
		if($this->sharpening && $this->sharpening != 'none') {
			$a = $this->imSharpen($this->sharpening);
			$tickets[2][] = sprintf('-unsharp %sx%s+%s+%s', $a[0], $a[1], $a[2], $a[3]);
		}

		// EXECUTE ALL AT ONCE
		$out = array();
		$ret = 0;
		if(!isset($tickets[0])) $tickets[0] = array();
		if(!isset($tickets[2])) $tickets[2] = array();
		if(isset($tickets[1]) && 0 < count($tickets[1])) {
			$ticket = implode(' ', $tickets[0]) . ' ' . implode(' ', $tickets[1]) . ' | "' . 
				$this->IMPATH . 'convert" ' . implode(' ', $tickets[2]);
		} else {
			$ticket = implode(' ', $tickets[0]) . ' ' . implode(' ', $tickets[2]);
		}
		// added 16bit workspace and optionally gamma correction
		$gammaLinearized = $gammaRestored = '';
		if($this->defaultGamma && $this->defaultGamma != -1) {
			$gammaLinearized = '-gamma 0.454545';
			$gammaRestored = '-gamma 2.2';
		}
		
		$cmd = null;
		
		if(\IMAGETYPE_JPEG == $this->imageType) {
			$cmd = 
				"\"{$this->IMPATH}convert\" \"$srcFilename\" -depth 16 $gammaLinearized -filter lanczos {$ticket} $gammaRestored " . 
				"-depth 8 -quality {$this->quality} -sampling-factor 1x1 -strip \"$dstFilename\"";
			
		} else if(\IMAGETYPE_PNG == $this->imageType) {
			$cmd = 
				"\"{$this->IMPATH}convert\" \"$srcFilename\" -depth 16 $gammaLinearized {$ticket} $gammaRestored " . 
				"-depth 8 -strip \"$dstFilename\"";
			
		} else if(\IMAGETYPE_GIF == $this->imageType) {
			$cmd = 
				"\"{$this->IMPATH}convert\" \"$srcFilename\" -depth 16 $gammaLinearized {$ticket} $gammaRestored " . 
				"-depth 8 -strip \"$dstFilename\"";
		}
	
		if(is_null($cmd)) {
			return false;
		} else {
			@exec($cmd, $out, $ret);
		}
		
		if(0 != $ret) return false; // uhm, something went wrong

		return true;
	}

	/**
	 * Return array of info for performing a sharpen
	 * 
	 * @param string $mode
	 * @return array
	 * 
	 */
	protected function imSharpen($mode) {
		switch($mode) {
			case 'strong':
				$m = array(0, 0.5, 5.0, 0.02);
				break;
			case 'medium':
				$m = array(0, 0.5, 3.2, 0.04);
				break;
			case 'soft':
			default:
				$m = array(0, 0.5, 2.4, 0.07);
				break;
		}
		return $m;
	}

	/**
	 * Configure module
	 * 
	 * @param InputfieldWrapper $inputfields
	 * 
	 */
	public function getModuleConfigInputfields(InputfieldWrapper $inputfields) {
		
		parent::getModuleConfigInputfields($inputfields);
	
		if($this->wire('config')->imageMagickPath) {
			$this->imageMagickPath = $this->wire('config')->imageMagickPath;
			$this->message('Image magick path is defined in /site/config.php: $config->imageMagickPath');
		} else {
			$f = $this->wire('modules')->get('InputfieldText');
			$f->attr('name', 'imageMagickPath');
			$f->label = $this->_('Server path to ImageMagick CLI executables');
			$f->attr('value', $this->imageMagickPath);
			$inputfields->add($f);
		}
		
		// report warnings if there are potential issues using ImageMagick CLI
		if($this->imageMagickPath) $this->supported('install');
	}

}
