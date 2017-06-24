# Palmtree TinyPNG API


##### WARNING: This library is no longer maintained. Use the [official library](https://tinypng.com/developers/reference/php) instead.

A [TinyPNG](https://tinypng.com/) API implementation for PHP.

Can be used via command line interface or within web applications.

### Requirements
* PHP >= 5.3

### Usage

Before using the class you'll need to grab an API key from the [TinyPNG
developers page](https://tinypng.com/developers).

#### Command line
```php
<?php
use Palmtree\TinyPng;

if ( TinyPng::isCli() ) {
	$options            = TinyPng::getCliOptions();
	$options['api_key'] = '<api key here>'; // Replace with your actual API key.

	$tinyPng = new TinyPng( $options );
	$tinyPng->shrink();
}
```

#### Web applications
```php
<?php
use Palmtree\TinyPng;

$tinyPng = new TinyPng( array(
	'path'          => '.',
	'api_key'       => '',
	'callback'      => null,
	'extensions'    => array( 'jpg', 'jpeg', 'png', 'gif' ),
	'files'         => array(),
	'backup_path'   => './TinyPng-backup',
	'log_path'      => './TinyPng.log',
	'fail_log_path' => './TinyPng-failed.log',
	'quiet'         => true,
	'max_failures'  => 2,
) );

$tinyPng->shrink();
```
