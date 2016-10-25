<?php

namespace Palmtree;

/**
 * TinyPNG API Bridge for PHP
 *
 * @author  Andy Palmer
 * @version 0.9.9
 * @package Palmtree\TinyPng
 */
class TinyPng {

	/** Minimum HTTP OK response code. */
	const HTTP_OK_MIN = 200;

	/** Maximum HTTP OK response code. */
	const HTTP_OK_MAX = 299;

	/**
	 * @var array        $defaults     Array of default options to be merged into user options
	 *
	 * @type string      $api_key      API key obtained from https://tinypng.com/developers
	 * @type string|bool $backup_path  Path to store backups of original images. Set to false to disable backups.
	 * @type callable    $callback     Optional callback function to be called for every file iteration of the shrink() method.
	 * @type string      $date_format  Date format for log files.
	 * @type array       $extensions   Valid file extensions to search for in 'path'.
	 * @type string|bool $fail_log     File to write all failed compressions to, relative to 'path' option. Set to false to disable.
	 * @type array       $files        Pre-selected array of files to compress. Overrides the 'path' option.
	 * @type string|bool $log          File to write all log messages to, relative to 'path' option. Set to false to disable.
	 * @type int         $max_failures Maximum number of failed compressions to allow before giving up.
	 * @type string      $path         Path in which to search for files. Ignored if 'files' option is not empty.
	 * @type boolean     $quiet        Set to true to disable echo-ing of log messages. Defaults to false in a CLI environment.
	 */
	public static $defaults = array(
		'api_key'      => '',
		'backup_path'  => false,
		'callback'     => null,
		'date_format'  => 'Y-m-d H:i:s',
		'extensions'   => array( 'jpg', 'jpeg', 'png', 'gif' ),
		'fail_log'     => 'TinyPng-failed.%d.log',
		'files'        => array(),
		'log'          => 'TinyPng.%d.log',
		'max_failures' => 2,
		'path'         => '.',
		'quiet'        => true,
	);

	/**
	 * @var array $cliDefaults Array of defaults for CLI usage.
	 */
	public static $cliDefaults = array(
		'fail_log' => false,
		'log'      => false,
		'quiet'    => false,
	);

	/**
	 * @var string $endpoint API Endpoint.
	 */
	public $endpoint = 'https://api.tinypng.com/shrink';

	/**
	 * @var string $userAgent User agent used in cURL requests.
	 */
	public $userAgent = 'Mozilla/5.0 (compatible; MSIE 10.0; Windows NT 6.2; Trident/6.0)';

	/** @var resource cURL handle returned from curl_init. */
	protected $curlHandle;

	/** @var array Array of curlopts to be used in curl_setopt_array. */
	public $curlOpts = array(
		CURLOPT_SSL_VERIFYPEER => false,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_BINARYTRANSFER => true,
	);

	/** @var array Array of compression results. */
	public $results = array();

	/** @var array Array of logged messages. */
	public $messages = array();

	/** @var array Array of files that failed compression. */
	public $failed = array();

	/** @var array Array of settings to be used per object. */
	protected $settings = array();

	/** @var bool Whether compression is complete. */
	protected $complete = false;

	/** @var bool Whether the `callback` setting is callable. */
	protected $isCallbackCallable = false;

	/** @var string The current file being iterated within the shrink() method. */
	protected $currentFile;

	/**
	 * Constructs an instance of the class.
	 *
	 * @param array $options Array of options to be merged with self::$defaults.
	 */
	public function __construct( $options = array() ) {
		$defaults = self::$defaults;

		if ( self::isCli() ) {
			$defaults = array_replace( $defaults, self::$cliDefaults );
		}

		$this->settings = array_replace( $defaults, $options );

		$this->curlHandle = curl_init();

		$this->curlOpts[ CURLOPT_URL ]       = $this->endpoint;
		$this->curlOpts[ CURLOPT_USERPWD ]   = 'api:' . $this->settings['api_key'];
		$this->curlOpts[ CURLOPT_USERAGENT ] = $this->userAgent;

		$this->settings['path'] = $this->normalizePath( $this->settings['path'] );

		if ( $this->settings['backup_path'] !== false ) {
			$this->settings['backup_path'] = $this->addTrailingSlash( $this->normalizePath( dirname( $this->settings['backup_path'] ) ) . basename( $this->settings['backup_path'] ) );

			if ( ! is_dir( $this->settings['backup_path'] ) ) {
				mkdir( $this->settings['backup_path'] );
			}
		}

		$this->isCallbackCallable = is_callable( $this->settings['callback'] );
	}

	/**
	 * Iterates over all matching files in $this->settings['path'] and shrinks them.
	 * @return bool False on error, true on success.
	 */
	public function shrink() {
		if ( ! $this->preShrink() ) {
			return false;
		}

		set_time_limit( 0 );

		foreach ( $this->getFiles() as $inputFile ) {
			$this->currentFile = $inputFile;

			if ( $this->hasExceededMaxFailures() ) {
				break;
			}

			$this->log( "Compressing $inputFile..." );

			// Post the input file to the TinyPNG API.
			$response = $this->callApi( $inputFile );

			// If the response is not a JSON object there was an error.
			if ( ! ( $response instanceof \stdClass ) ) {
				$this->addFailure( 'The API response was not an object.' );
				continue;
			}

			$outputFile = $this->getOutputFile( $response );

			if ( $outputFile === false ) {
				$this->addFailure( $response );
				continue;
			}

			// Move the original image to the backup directory if backups are on.
			if ( ! empty( $this->settings['backup_path'] ) && is_dir( $this->settings['backup_path'] ) ) {
				rename( $inputFile, $this->settings['backup_path'] . basename( $inputFile ) );
			}

			// Replace the original image with the compressed version.
			file_put_contents( $inputFile, $outputFile );

			$savings = $this->getSavings( $response );

			$this->results[ $inputFile ] = $savings;

			$this->log( 'SUCCESS: Reduced file size from %s to %s (%s saving)', array(
				$savings['was'],
				$savings['now'],
				$savings['saved_percent'],
			) );
			if ( $this->isCallbackCallable ) {
				call_user_func_array( $this->settings['callback'], array( $inputFile ) );
			}
		}

		$this->currentFile = null;

		curl_close( $this->curlHandle );

		$this->writeFailLogFile();
		$this->writeLogFile();

		$this->complete = true;

		return true;
	}

	/**
	 * Returns the array of compression results.
	 * @return array
	 */
	public function getResults() {
		return $this->results;
	}

	/**
	 * Return a result at the given index. Defaults to the last result.
	 *
	 * @param int $index The index.
	 *
	 * @return string The result.
	 */
	public function getResult( $index = -1 ) {
		if ( $index < 0 ) {
			return array_slice( $this->results, $index, 1 );
		}

		return $this->results[ $index ];
	}

	public function getTotalResults() {
		return count( $this->results );
	}

	/**
	 * Returns the array of messages logged during compression.
	 * @return array
	 */
	public function getMessages() {
		return $this->messages;
	}

	/**
	 * Return a message at the given index. Defaults to the last message.
	 *
	 * @param int $index The index.
	 *
	 * @return string The message.
	 */
	public function getMessage( $index = -1 ) {
		if ( $index < 0 ) {
			return array_slice( $this->messages, $index, 1 );
		}

		return $this->messages[ $index ];
	}

	/**
	 * Returns the array of failed compressions.
	 * @return array
	 */
	public function getFailures() {
		return $this->failed;
	}

	/**
	 * Returns the total number of failed compressions.
	 * @return int
	 */
	public function getTotalFailures() {
		return count( $this->failed );
	}

	protected function addFailure( $message = '' ) {
		$date    = date( $this->settings['date_format'] );
		$message = "[$date]: $message";

		$this->failed[ $this->currentFile ] = $message;

		if ( $this->isCallbackCallable ) {
			call_user_func_array( $this->settings['callback'], array( $this->currentFile ) );
		}
	}

	/**
	 * Return a failure at the given index. Defaults to the last failure.
	 *
	 * @param int $index The index.
	 *
	 * @return string The failure.
	 */
	public function getFailure( $index = -1 ) {
		if ( $index < 0 ) {
			return array_slice( $this->failed, $index, 1 );
		}

		return $this->failed[ $index ];
	}

	/**
	 * Performs checks and returns whether the shrink method can begin compressing.
	 * @return bool
	 */
	protected function preShrink() {
		if ( $this->complete ) {
			$this->log( 'Compression already completed. Create a new instance of the class to compress a different directory.' );

			return false;
		}

		if ( empty( $this->settings['api_key'] ) ) {
			$this->log( 'Unable to begin compression. API key must not be empty' );

			return false;
		}

		// Set the curl options late so they can be overridden.
		curl_setopt_array( $this->curlHandle, $this->curlOpts );

		return true;
	}

	/**
	 * Returns whether the amount of failed compressions has exceeded the limit.
	 * @return bool
	 */
	protected function hasExceededMaxFailures() {
		if ( count( $this->failed ) >= $this->settings['max_failures'] ) {
			$this->log( 'Maximum number of failures reached. Giving up compressing.' );
			if ( $this->isCallbackCallable ) {
				call_user_func_array( $this->settings['callback'], array( $this->currentFile ) );
			}

			return true;
		}

		return false;
	}

	/**
	 * Returns an array of files to compress.
	 * @return array Array of image file paths.
	 */
	protected function getFiles() {
		if ( ! empty( $this->settings['files'] ) ) {
			return $this->settings['files'];
		}

		$extensions = array_merge( $this->settings['extensions'], array_map( 'strtoupper', $this->settings['extensions'] ) );

		$files = glob( $this->settings['path'] . '*.{' . implode( ',', $extensions ) . '}', GLOB_BRACE );

		if ( ! $files ) {
			$this->log( 'No files found matching the pattern: ' . $this->settings['path'] . '*.{' . implode( ',', $extensions ) . '}' );
			$files = array();
		}

		return $files;
	}

	/**
	 * Posts the contents of a file to the TinyPNG shrink API and returns the response.
	 *
	 * @param string $file Path to the file whose contents should be posted.
	 *
	 * @return bool|\stdClass False on failure. stdClass object on success.
	 */
	protected function callApi( $file ) {
		curl_setopt( $this->curlHandle, CURLOPT_POSTFIELDS, file_get_contents( $file ) );

		$result = curl_exec( $this->curlHandle );

		if ( ! is_string( $result ) ) {
			$this->log( 'ERROR: Unable to compress file: Response could not be decoded.' );

			return false;
		}

		$response = json_decode( $result );

		if ( ! $response ) {
			$this->log( 'ERROR: Unable to compress file: Response could not be decoded.' );

			return false;
		}

		if ( isset( $response->error ) ) {
			$error = $response->error;

			if ( ! empty( $response->message ) ) {
				$error .= ' - ' . $response->message;
			}

			$this->log( "ERROR: Unable to compress file: $error" );

			return false;
		}

		$httpCode = curl_getinfo( $this->curlHandle, CURLINFO_HTTP_CODE );

		// If the status code is not in the 2xx range there was an error.
		if ( $httpCode < self::HTTP_OK_MIN || $httpCode > self::HTTP_OK_MAX ) {
			$this->log( "ERROR: Unable to compress file: HTTP Error: $httpCode" );

			return false;
		}

		return $response;
	}

	/**
	 * Returns the contents of a compressed output file or false on error.
	 *
	 * @param object $response TinyPNG JSON response object
	 *
	 * @return mixed Contents of image on success. False on error.
	 */
	protected function getOutputFile( $response ) {
		if ( isset( $response->error ) ) {
			$error = isset( $response->message ) ? $response->message : $response->error;
			$this->log( "ERROR: Unable to compress file: $error" );

			return false;
		}

		$result = $this->curlGetContents( $response->output->url );

		if ( $result === false ) {
			$this->log( 'ERROR: Unable to retrieve compressed file' );
		}

		return $result;
	}

	/**
	 * Returns an array of information based on savings of a compression.
	 *
	 * @param object $response
	 *
	 * @return array
	 */
	protected function getSavings( $response ) {
		$savedTotal   = $response->input->size - $response->output->size;
		$savedPercent = $savedTotal / $response->input->size * 100;

		return array(
			'was'           => $this->sizeFormat( $response->input->size ),
			'now'           => $this->sizeFormat( $response->output->size ),
			'saved_total'   => $this->sizeFormat( $savedTotal ),
			'saved_percent' => floor( $savedPercent ) . '%',
		);
	}

	/**
	 * Passes $path through realpath() and adds a trailing slash.
	 *
	 * @param string $path Path to normalize.
	 *
	 * @return string Normalized path.
	 */
	protected function normalizePath( $path ) {
		return $this->addTrailingSlash( realpath( $path ) );
	}

	/**
	 * Returns the input string with a trailing slash appended.
	 *
	 * @param string $string String to add trailing slash to.
	 *
	 * @return string String with trailing slash added.
	 */
	protected function addTrailingSlash( $string ) {
		return rtrim( $string, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
	}

	/**
	 * Logs a message to stdout and $this->messages array.
	 *
	 * @param string $message Message to log
	 * @param array  $args    Array of args to be used in vsprintf for $message.
	 */
	protected function log( $message, $args = array() ) {
		if ( ! empty( $args ) ) {
			$message = vsprintf( $message, $args );
		}

		$date    = date( $this->settings['date_format'] );
		$message = "[$date]: $message";

		if ( ! $this->settings['quiet'] ) {
			echo $message . PHP_EOL;
		}

		$this->messages[] = $message;
	}

	/**
	 * Writes $data to $file and appends an EOL, if the parent directory is writable.
	 *
	 * @param string $file
	 * @param string $data
	 *
	 * @return bool Whether the data was written to the file.
	 */
	protected function writeFile( $file, $data ) {
		if ( $file === false ) {
			return false;
		}

		if ( ! is_writable( dirname( $file ) ) ) {
			$this->log( "ERROR: Unable to write to $file" );

			return false;
		}

		return (bool) file_put_contents( $file, $data . PHP_EOL );
	}

	/**
	 * Writes the contents of $this->messages array to the log_path setting.
	 * @return boolean Whether the file was written.
	 */
	protected function writeLogFile() {
		if ( $this->settings['log'] === false ) {
			return false;
		}

		$globPattern = str_replace( '%d', '[0-9]*', $this->settings['log'] );

		$files      = glob( $this->settings['path'] . "/$globPattern" );
		$totalFiles = ( $files ) ? count( $files ) : 0;

		$file = sprintf( $this->settings['log'], $totalFiles );

		return $this->writeFile( $this->settings['path'] . "/$file", implode( PHP_EOL, $this->messages ) );
	}

	/**
	 * Writes the contents of $this->failed array to the fail_log_path setting.
	 * @return boolean Whether the file was written.
	 */
	protected function writeFailLogFile() {
		if ( empty( $this->failed ) ) {
			return false;
		}

		$this->log( count( $this->failed ) . ' images were not compressed' );

		$data = '';

		foreach ( $this->failed as $key => $value ) {
			$data .= basename( $key ) . ': ' . $value . PHP_EOL;
		}

		return $this->writeFile( $this->settings['fail_log_path'], $data );
	}

	/**
	 * Convert a given number of bytes into a human readable format,
	 * using the largest unit the bytes will fit into.
	 * Credit: WordPress core.
	 *
	 * @param mixed $bytes Number of bytes. Accepts int or string.
	 *
	 * @return boolean|string False on failure. Number string on success
	 */
	protected function sizeFormat( $bytes ) {
		$quant = array(
			// ========================= Origin ====
			'TB' => 1099511627776, // pow( 1024, 4)
			'GB' => 1073741824, // pow( 1024, 3)
			'MB' => 1048576, // pow( 1024, 2)
			'kB' => 1024, // pow( 1024, 1)
			'B'  => 1, // pow( 1024, 0)
		);

		foreach ( $quant as $unit => $mag ) {
			if ( doubleval( $bytes ) >= $mag ) {
				return number_format( $bytes / $mag, 2, '.', ',' ) . $unit;
			}
		}

		return false;
	}

	/**
	 * cURL version of file_get_contents. Returns the contents of a remote resource.
	 *
	 * @param string $url The URL of the resource.
	 *
	 * @return boolean|string False on failure, contents of the resource as a string on success.
	 */
	protected function curlGetContents( $url = '' ) {
		$handle = curl_init();

		curl_setopt_array( $handle, array(
			CURLOPT_URL            => $url,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYHOST => false,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_HEADER         => false,
			CURLOPT_AUTOREFERER    => true,
			CURLOPT_USERAGENT      => $this->userAgent,
		) );

		$result   = curl_exec( $handle );
		$httpCode = curl_getinfo( $handle, CURLINFO_HTTP_CODE );

		if ( $httpCode < self::HTTP_OK_MIN || $httpCode > self::HTTP_OK_MAX ) {
			return false;
		}

		curl_close( $handle );

		return $result;
	}

	/**
	 * Returns whether the script was executed from the command line.
	 * @return boolean
	 */
	public static function isCli() {
		return ( ! isset( $_SERVER['SERVER_SOFTWARE'] ) &&
		         ( php_sapi_name() === 'cli' || ( is_numeric( $_SERVER['argc'] ) && $_SERVER['argc'] > 0 ) )
		);
	}

	public static function getCliUsage() {
		$usage = <<< USAGE
USAGE:
 -a, --api_key          API key obtained from https://tinypng.com/developers
 -b, --backup_path      Path to store backups of original images. Defaults to false (no backups).
 -c, --callback         Optional callback function to be called for every file iteration of the shrink() method.
 -d, --date_format      Date format for log files.
 -e, --extensions       Valid file extensions to search for in 'path'.
 -f, --fail_log         File to write all failed compressions to, relative to 'path' option. Set to false to disable.
 -l, --log              File to write all log messages to, relative to 'path' option. Set to false to disable.
 -m, --max_failures     Maximum number of failed compressions to allow before giving up.
 -p, --path             Path in which to search for files.
 -q, --quiet            Set to true to disable echo-ing of log messages. Defaults to false in a CLI environment.


USAGE;

		return $usage;

	}

	/**
	 * Parses command line options into an array of options used for the class
	 * constructor.
	 * @return array
	 */
	public static function getCliOptions() {
		$optionMap = array();

		foreach ( self::$defaults as $key => $value ) {
			$chr         = substr( $key, 0, 1 );
			$longoptKey  = $key . ':';
			$shortoptKey = $chr . ':';

			// api_key is the only required option.
			if ( $key !== 'api_key' ) {
				$longoptKey .= ':';
				$shortoptKey .= ':';
			}

			$optionMap[ $shortoptKey ] = $longoptKey;
		}

		$opts = getopt( implode( '', array_keys( $optionMap ) ), $optionMap );

		$options = array();

		foreach ( $opts as $key => $value ) {
			$mapKey = current( preg_grep( '/^' . $key . '\:{1,2}/', array_keys( $optionMap ) ) );

			if ( isset( $optionMap[ $mapKey ] ) ) {
				$key = $optionMap[ $mapKey ];
			}

			$optionKey = rtrim( $key, ':' );

			$toArray = isset( self::$defaults[ $optionKey ] ) && is_array( self::$defaults[ $optionKey ] );

			$value = self::normalizeValue( $value, $toArray );

			$options[ $optionKey ] = $value;
		}

		return $options;
	}

	/**
	 * Returns truthy/falsey strings as a boolean and numeric strings as an integer.
	 *
	 * @param string $value
	 *
	 * @return bool|int
	 */
	private static function normalizeValue( $value, $toArray = false ) {
		if ( $toArray ) {
			return explode( ',', $value );
		}

		if ( $value === '1' || $value === 'true' ) {
			return true;
		}

		if ( $value === '0' || $value === 'false' ) {
			return false;
		}

		if ( ctype_digit( $value ) ) {
			return (int) $value;
		}

		return $value;
	}
}
