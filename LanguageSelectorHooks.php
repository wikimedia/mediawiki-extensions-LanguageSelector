<?php

use MediaWiki\Context\IContextSource;
use MediaWiki\Context\RequestContext;
use MediaWiki\Html\Html;
use MediaWiki\Languages\LanguageNameUtils;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\Sanitizer;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

class LanguageSelectorHooks implements
	\MediaWiki\Auth\Hook\LocalUserCreatedHook,
	\MediaWiki\Hook\BeforePageDisplayHook,
	\MediaWiki\Hook\GetCacheVaryCookiesHook,
	\MediaWiki\Hook\ParserFirstCallInitHook,
	\MediaWiki\Hook\UserGetLanguageObjectHook
{
	public static function onRegistration() {
		global $wgLanguageSelectorDetectLanguage, $wgLanguageSelectorLocation;

		define( 'LANGUAGE_SELECTOR_USE_CONTENT_LANG', 0 ); # no detection
		define( 'LANGUAGE_SELECTOR_PREFER_CONTENT_LANG', 1 ); # use content language if accepted by the client
		define( 'LANGUAGE_SELECTOR_PREFER_CLIENT_LANG', 2 ); # use language most preferred by the client

		/**
		 * Language detection mode for anonymous visitors.
		 * Possible values:
		 * * LANGUAGE_SELECTOR_USE_CONTENT_LANG - use the $wgLanguageCode setting (default content language)
		 * * LANGUAGE_SELECTOR_PREFER_CONTENT_LANG - use the $wgLanguageCode setting, if accepted by the client
		 * * LANGUAGE_SELECTOR_PREFER_CLIENT_LANG - use the client's preferred language, if in $wgLanguageSelectorLanguages
		 */
		$wgLanguageSelectorDetectLanguage = LANGUAGE_SELECTOR_PREFER_CLIENT_LANG;

		define( 'LANGUAGE_SELECTOR_MANUAL', 0 ); # don't place anywhere
		define( 'LANGUAGE_SELECTOR_AT_TOP_OF_TEXT', 1 ); # put at the top of page content
		define( 'LANGUAGE_SELECTOR_IN_TOOLBOX', 2 ); # put into toolbox
		define( 'LANGUAGE_SELECTOR_AS_PORTLET', 3 ); # as portlet
		define( 'LANGUAGE_SELECTOR_INTO_SITENOTICE', 11 ); # put after sitenotice text
		define( 'LANGUAGE_SELECTOR_INTO_TITLE', 12 ); # put after title text
		define( 'LANGUAGE_SELECTOR_INTO_SUBTITLE', 13 ); # put after subtitle text
		define( 'LANGUAGE_SELECTOR_INTO_CATLINKS', 14 ); # put after catlinks text

		$wgLanguageSelectorLocation = LANGUAGE_SELECTOR_AT_TOP_OF_TEXT;
	}

	public static function extension() {
		global $wgLanguageSelectorLocation;

		// We'll probably be beaten to this by the call in onUserGetLanguageObject(),
		// but just in case, call this to make sure the global is properly initialised
		self::getLanguageSelectorLanguages();

		if ( $wgLanguageSelectorLocation != LANGUAGE_SELECTOR_MANUAL && $wgLanguageSelectorLocation != LANGUAGE_SELECTOR_AT_TOP_OF_TEXT ) {
			$hookContainer = MediaWikiServices::getInstance()->getHookContainer();
			switch ( $wgLanguageSelectorLocation ) {
				case LANGUAGE_SELECTOR_IN_TOOLBOX:
					$hookContainer->register(
						'SkinAfterPortlet',
						[ self::class, 'onSkinAfterPortlet' ]
					);
					break;
				default:
					$hookContainer->register(
						'SkinTemplateOutputPageBeforeExec',
						[ self::class, 'onSkinTemplateOutputPageBeforeExec' ]
					);
					break;
			}
		}
	}

	/**
	 * @param Parser $parser
	 */
	public function onParserFirstCallInit( $parser ) {
		$parser->setHook( 'languageselector', [ __CLASS__, 'languageSelectorTag' ] );
	}

	/**
	 * @return array
	 */
	public static function getLanguageSelectorLanguages() {
		global $wgLanguageSelectorLanguages, $wgLanguageSelectorShowAll;

		if ( $wgLanguageSelectorLanguages === null ) {
			$wgLanguageSelectorLanguages = array_keys(
				MediaWikiServices::getInstance()->getLanguageNameUtils()->getLanguageNames(
					LanguageNameUtils::AUTONYMS,
					$wgLanguageSelectorShowAll === true ? LanguageNameUtils::DEFINED : LanguageNameUtils::SUPPORTED
			) );
			sort( $wgLanguageSelectorLanguages );
		}

		return $wgLanguageSelectorLanguages;
	}

	/**
	 * @param User $user
	 * @param string &$code
	 * @param IContextSource $context
	 */
	public function onUserGetLanguageObject( $user, &$code, $context ) {
		global $wgLanguageSelectorDetectLanguage;

		$request = $context->getRequest();
		$setlang = $request->getRawVal( 'setlang' );
		if ( $setlang && !in_array( $setlang, self::getLanguageSelectorLanguages() ) ) {
			$setlang = null; // ignore invalid
		}

		if ( $setlang ) {
			$request->response()->setcookie( 'LanguageSelectorLanguage', $setlang );
			$requestedLanguage = $setlang;
		} else {
			$requestedLanguage = $request->getCookie( 'LanguageSelectorLanguage' );
		}

		if ( $setlang && !$user->isAnon() ) {
			$userOptionsManager = MediaWikiServices::getInstance()->getUserOptionsManager();
			if ( $setlang != $userOptionsManager->getOption( $user, 'language' ) ) {
				$userOptionsManager->setOption( $user, 'language', $requestedLanguage );
				$userOptionsManager->saveOptions( $user );
				$code = $requestedLanguage;
			}
		}

		if ( !$request->getRawVal( 'uselang' ) && $user->isAnon() ) {
			if ( $wgLanguageSelectorDetectLanguage != LANGUAGE_SELECTOR_USE_CONTENT_LANG ) {
				if ( $requestedLanguage ) {
					$code = $requestedLanguage;
				} else {
					$languages = $request->getAcceptLang();

					// see if the content language is accepted by the client.
					if ( $wgLanguageSelectorDetectLanguage != LANGUAGE_SELECTOR_PREFER_CONTENT_LANG
						|| !array_key_exists( MediaWikiServices::getInstance()->getContentLanguage()->getCode(), $languages ) ) {

						$supported = self::getLanguageSelectorLanguages();
						// look for a language that is acceptable to the client
						// and known to the wiki.
						foreach ( $languages as $reqCode => $q ) {
							if ( in_array( $reqCode, $supported ) ) {
								$code = $reqCode;
								break;
							}
						}

						// Apparently Safari sends stupid things like "de-de" only.
						// Try again with stripped codes.
						foreach ( $languages as $reqCode => $q ) {
							$stupidPHP = explode( '-', $reqCode, 2 );
							$bareCode = array_shift( $stupidPHP );
							if ( in_array( $bareCode, $supported ) ) {
								$code = $bareCode;
								break;
							}
						}
					}
				}
			}
		}
	}

	/**
	 * @param OutputPage $out
	 * @param Skin $skin
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		global $wgLanguageSelectorLocation;

		if ( $wgLanguageSelectorLocation == LANGUAGE_SELECTOR_MANUAL ) {
			return;
		}

		if ( $wgLanguageSelectorLocation == LANGUAGE_SELECTOR_AT_TOP_OF_TEXT ) {
			$html = self::languageSelectorHTML( $out->getTitle() );
			$out->setIndicators( [
				'languageselector' => $html,
			] );
		}

		$out->addModules( 'ext.languageSelector' );
	}

	/**
	 * @param OutputPage $out
	 * @param array &$cookies
	 */
	public function onGetCacheVaryCookies( $out, &$cookies ) {
		global $wgCookiePrefix;

		$cookies[] = $wgCookiePrefix . 'LanguageSelectorLanguage';
	}

	/**
	 * @param Skin $skin
	 * @param string $portlet
	 * @param string &$html
	 */
	public static function onSkinAfterPortlet( Skin $skin, $portlet, &$html ) {
		if ( $portlet === 'tb' ) {
			$html .= self::languageSelectorHTML( $skin->getTitle() );
		}
	}

	/**
	 * @param string $input
	 * @param array $args
	 * @param Parser $parser
	 * @return string
	 */
	public static function languageSelectorTag( $input, $args, $parser ) {
		$style = $args['style'] ?? '';
		$class = $args['class'] ?? '';
		$selectorstyle = $args['selectorstyle'] ?? '';
		$buttonstyle = $args['buttonstyle'] ?? '';
		$showcode = $args['showcode'] ?? null;

		if ( $style ) {
			$style = Sanitizer::checkCss( $style );
		}

		if ( $class ) {
			$class = htmlspecialchars( $class, ENT_QUOTES );
		}

		if ( $selectorstyle ) {
			$selectorstyle = htmlspecialchars( $selectorstyle, ENT_QUOTES );
		}

		if ( $buttonstyle ) {
			$buttonstyle = htmlspecialchars( $buttonstyle, ENT_QUOTES );
		}

		if ( $showcode ) {
			$showcode = strtolower( $showcode );

			if ( $showcode == 'true' || $showcode == 'yes' || $showcode == 'on' ) {
				$showcode = true;
			} elseif ( $showcode == 'false' || $showcode == 'no' || $showcode == 'off' ) {
				$showcode = false;
			} else {
				$showcode = null;
			}
		} else {
			$showcode = null;
		}

		# So that this also works with parser cache
		$parser->getOutput()->addModules( [ 'ext.languageSelector' ] );

		return self::languageSelectorHTML( $parser->getTitle(), $style, $class, $selectorstyle, $buttonstyle, $showcode );
	}

	/**
	 * @param SkinTemplate $skin
	 * @param QuickTemplate $tpl
	 */
	public static function onSkinTemplateOutputPageBeforeExec( $skin, $tpl ) {
		global $wgLanguageSelectorLocation;

		if ( $wgLanguageSelectorLocation == LANGUAGE_SELECTOR_AS_PORTLET ) {
			$code = $skin->getLanguage()->getCode();
			$lines = [];

			$languageNameUtils = MediaWikiServices::getInstance()->getLanguageNameUtils();
			foreach ( self::getLanguageSelectorLanguages() as $ln ) {
				$lines[] = [
					$href = $skin->getTitle()->getFullURL( 'setlang=' . $ln ),
					'text' => $languageNameUtils->getLanguageName( $ln ),
					'href' => $href,
					'id' => 'n-languageselector',
					'active' => ( $ln == $code ),
				];
			}

			$tpl->data['sidebar']['languageselector'] = $lines;

			return;
		}

		$key = null;

		switch ( $wgLanguageSelectorLocation ) {
			case LANGUAGE_SELECTOR_INTO_SITENOTICE:
				$key = 'sitenotice';
				break;
			case LANGUAGE_SELECTOR_INTO_TITLE:
				$key = 'title';
				break;
			case LANGUAGE_SELECTOR_INTO_SUBTITLE:
				$key = 'subtitle';
				break;
			case LANGUAGE_SELECTOR_INTO_CATLINKS:
				$key = 'catlinks';
				break;
		}

		if ( $key ) {
			$html = self::languageSelectorHTML( $skin->getTitle() );
			$tpl->set( $key, $tpl->data[$key] . $html );
		}
	}

	/**
	 * @param User $user
	 * @param bool $autocreated
	 */
	public function onLocalUserCreated( $user, $autocreated ) {
		$context = RequestContext::getMain();

		// inherit language;
		// if the context user is the created user this means remembering what the user selected
		// otherwise, it would mean inheriting the language from the user creating the account.
		if ( $context->getUser() === $user ) {
			$userOptionsManager = MediaWikiServices::getInstance()->getUserOptionsManager();
			$userOptionsManager->setOption( $user, 'language', $context->getLanguage()->getCode() );
		}
	}

	/**
	 * @param Title $title
	 * @param string $style
	 * @param string $class
	 * @param string $selectorstyle
	 * @param string $buttonstyle
	 * @param null|bool $showCode
	 * @return string
	 */
	public static function languageSelectorHTML(
		Title $title,
		string $style = '',
		string $class = '',
		string $selectorstyle = '',
		string $buttonstyle = '',
		$showCode = null
	) {
		global $wgLang, $wgScript, $wgLanguageSelectorShowCode;

		if ( $showCode === null ) {
			$showCode = $wgLanguageSelectorShowCode;
		}

		static $id = 0;
		$id += 1;

		$code = $wgLang->getCode();

		$html = '';
		$html .= Html::openElement( 'span', [
			'id' => 'languageselector-box-' . $id,
			'class' => 'languageselector ' . $class,
			'style' => $style
		] );

		$html .= Html::openElement( 'form', [
			'name' => 'languageselector-form-' . $id,
			'id' => 'languageselector-form-' . $id,
			'method' => 'get',
			'action' => $wgScript,
			'style' => 'display:inline;'
		] );

		$html .= Html::hidden( 'title', $title->getPrefixedDBkey() );
		$html .= Html::openElement( 'select', [
			'name' => 'setlang',
			'id' => 'languageselector-select-' . $id,
			'style' => $selectorstyle
		] );

		$languageNameUtils = MediaWikiServices::getInstance()->getLanguageNameUtils();
		foreach ( self::getLanguageSelectorLanguages() as $ln ) {
			$name = $languageNameUtils->getLanguageName( $ln );
			if ( $showCode ) {
				$name = LanguageCode::bcp47( $ln ) . ' - ' . $name;
			}

			$html .= Html::element( 'option', [ 'value' => $ln, 'selected' => $ln == $code ], $name );
		}

		$html .= Html::closeElement( 'select' );
		$html .= Html::submitButton( wfMessage( 'languageselector-setlang' )->text(),
			[ 'id' => 'languageselector-commit-' . $id, 'style' => $buttonstyle ] );
		$html .= Html::closeElement( 'form' );
		$html .= Html::closeElement( 'span' );

		return $html;
	}
}
