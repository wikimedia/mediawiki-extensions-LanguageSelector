<?php

if ( function_exists( 'wfLoadExtension' ) ) {
	wfLoadExtension( 'LanguageSelector' );

	$wgMessagesDirs['LanguageSelector'] = __DIR__ . '/i18n';

	wfWarn(
		'Deprecated PHP entry point used for the LanguageSelector extension. ' .
		'Please use wfLoadExtension() instead, ' .
		'see https://www.mediawiki.org/wiki/Special:MyLanguage/Manual:Extension_registration for more details.'
	);

	return;
} else {
	die( 'This version of the LanguageSelector extension requires MediaWiki 1.35+' );
}
