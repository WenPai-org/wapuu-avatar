<?php
/*
* Plugin Name: Wapuu Avatar
* Plugin URI: https://Wapuu.com/archive
* Description: Use Wapuu as WordPress user avatar, support display in article comments, bbpress forum, buddypress profile, etc.
* Author: Wapuu.com
* Author URI: https://Wapuu.com
* Text Domain: wapuu-avatar
* Version: 1.1
* License: GPL2
* License: GPLv2 or later
* License URI: http://www.gnu.org/licenses/gpl-2.0.html
*
* WP ICP License is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 2 of the License, or
* any later version.
*
* WP ICP License is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*/


if (!function_exists('get_current_screen')) {
    require_once(ABSPATH . 'wp-admin/includes/screen.php');
}

/**
 * Init. Setup translation for the plugin.
 */
function wapuu_avatar_init() {
	$locale = apply_filters( 'plugin_locale', get_locale(), 'wapuu-avatar' );
	load_textdomain( 'wapuu-avatar', WP_LANG_DIR . '/wapuu-avatar/wapuu-avatar-' . $locale . '.mo' );
	load_plugin_textdomain( 'wapuu-avatar', false, basename( dirname( __FILE__ ) ) . '/languages/' );
}
add_action( 'init', 'wapuu_avatar_init' );

/**
 * Register our avatar type so it can be chosen on the admin screens.
 *
 * @param  array $avatar_defaults  Array of avatar types.
 *
 * @return array                   Modified array of avatar types.
 */
function wapuu_avatar_defaults( $avatar_defaults ) {
	$avatar_defaults['gwapuu-avatar'] = __( 'Wapuu Avatar (Random Display)', 'wapuu-avatar' );
	$avatar_defaults['wapuu-avatar'] = __( 'Wapuu Local Avatar (Disable Gravatar)', 'wapuu-avatar' );
	return $avatar_defaults;
}
add_filter( 'avatar_defaults', 'wapuu_avatar_defaults' );


/**
 * Implements get_avatar().
 *
 * Generate a wapuu-avatar if requested.
 */
function wapuu_avatar_get_avatar( $avatar, $id_or_email, $size, $default, $alt, $args ) {
	if ( is_admin() ) {
		$screen = get_current_screen();
		if ( is_object($screen) && in_array( $screen->id, array( 'dashboard', 'edit-comments' ) ) && $default == 'mm') {
			$default = get_option( 'avatar_default', 'mystery' );
		}
	}
	if ( $default != 'wapuu-avatar' && $default != 'gwapuu-avatar' ) {
		return $avatar;
	}
	list ( $url, $url2x ) = wapuu_avatar_generate_avatar_url( $id_or_email, $size );
	$class = array( 'avatar', 'avatar-' . (int) $args['size'], 'photo' );
	if ( $default == 'wapuu-avatar' ) {
		return sprintf(
			"<img alt='%s' src='%s' srcset='%s' class='%s' height='%d' width='%d' %s/>",
			esc_attr( $args['alt'] ),
			esc_url( $url ),
			esc_attr( "$url2x 2x" ),
			esc_attr( join( ' ', $class ) ),
			(int) $args['height'],
			(int) $args['width'],
			$args['extra_attr']
		);
	}
	if ( 'gwapuu-avatar' == $default ) {
		return str_replace( 'gwapuu-avatar', urlencode( esc_url( $url ) ), $avatar );
	}
	return $avatar;
}
add_filter( 'get_avatar', 'wapuu_avatar_get_avatar', 10, 6 );

/**
 * Generate the wapuu-avatar URL for a specific ID or email.
 *
 * @param  mixed  $id_or_email  The ID / email / hash of the requested avatar.
 * @param  int    $size         The requested size.
 * @return array                Array of standard and 2x URLs.
 */
function wapuu_avatar_generate_avatar_url( $id_or_email, $requested_size ) {

	// Select a size.
	$sizes = array( 256, 128, 64 );
	$selected_size = max($sizes);
	foreach( $sizes as $choice ) {
		if ( $choice >= $requested_size ) {
			$selected_size = $choice;
		}
	}

	// Pick a wapuu.
	$hash        = wapuu_avatar_id_or_email_to_hash( $id_or_email );
	$wapuus      = wapuu_avatar_get_wapuus();
	$wapuu       = hexdec( substr( $hash, 0, 4) ) % count( $wapuus );
	$wapuu_base  = apply_filters( 'wapuu_avatar_chosen_wapuu', $wapuus[ $wapuu ], $id_or_email, $hash );
	$wapuu_img   = $wapuu_base . '-' . $selected_size . '.png';
	$wapuu_img2x = $wapuu_base . '-' . ( $selected_size * 2 ) . '.png';

	// Common base URL.
    $base_url = plugins_url() . '/wapuu-avatar/assets/images/';

	return array(
		$base_url . $wapuu_img,
		$base_url . $wapuu_img2x,
	);
}

/**
 * Deal with mapping an id_or_email to a hash.
 *
 * Borrows from get_avatar_data() in link-template.php.
 *
 * @param  mixed  $id_or_email  ID / email / hash of the requested avatar.
 *
 * @return string               Hash to use to map the wapuu.
 */
function wapuu_avatar_id_or_email_to_hash( $id_or_email ) {

	$email_hash = '';
	$user = $email = false;

	// Process the user identifier.
	if ( is_numeric( $id_or_email ) ) {
		$user = get_user_by( 'id', absint( $id_or_email ) );
	} elseif ( is_string( $id_or_email ) ) {
		if ( strpos( $id_or_email, '@md5.gravatar.com' ) ) {
			// md5 hash
			list( $email_hash ) = explode( '@', $id_or_email );
		} else {
			// email address
			$email = $id_or_email;
		}
	} elseif ( $id_or_email instanceof WP_User ) {
		// User Object
		$user = $id_or_email;
	} elseif ( $id_or_email instanceof WP_Post ) {
		// Post Object
		$user = get_user_by( 'id', (int) $id_or_email->post_author );
	} elseif ( is_object( $id_or_email ) && isset( $id_or_email->comment_ID ) ) {
		// Comment Object
		if ( ! empty( $id_or_email->user_id ) ) {
			$user = get_user_by( 'id', (int) $id_or_email->user_id );
		}
		if ( ( ! $user || is_wp_error( $user ) ) && ! empty( $id_or_email->comment_author_email ) ) {
			$email = $id_or_email->comment_author_email;
		}
	}
	if ( ! $email_hash ) {
		if ( $user ) {
			$email = $user->user_email;
		}
		if ( $email ) {
			$email_hash = md5( strtolower( trim( $email ) ) );
		}
	}
	return $email_hash;
}

function wapuu_avatar_get_wapuus() {
	return array(
		'wapuu-original',
		'wapuu-kani',
		'wapuu-manchester',
		'wapuu-mineiro',
		'wapuu-onsen',
		'wapuu-scott',
		'wapuu-shikasenbei',
		'wapuu-sydney',
		'wapuu-takoyaki',
		'wapuu-tampa-gasparilla',
		'wapuu-tonkotsu',
		'wapuu-wapuda-shingen',
		'wapuu-brainhurts',
		'wapuu-cheesehead',
		'wapuu-cosplay',
		'wapuu-der-ber',
		'wapuu-dev',
		'wapuu-hampton-roads',
		'wapuu-minion',
		'wapuu-pizza',
		'wapuu-poststatus',
		'wapuu-spy',
		'wapuu-struggle',
		'wapuu-torque',
		'wapuu-travel',
		'wapuu-tron',
		'wapuu-wptavern',
		'wapuu-wapuujlo',
		'wapuu-wapuunder',
		'wapuu-wapuushkin',
		'wapuu-wapuutah',
		'wapuu-wck',
		'wapuu-wct2012',
		'wapuu-wct2013',
		'wapuu-wapevil',
		'wapuu-sweden',
		'wapuu-eduwapuu',
		'wapuu-wapmas',
		'wapuu-bapuu',
		'wapuu-benpuu',
		'wapuu-heian',
		'wapuu-okita',
		'wapuu-r2',
		'wapuu-shikari',
		'wapuu-wapuupepa',
		'wapuu-wapuupepe',
		'wapuu-sunshinecoast',
		'wapuu-taekwon-blue',
		'wapuu-taekwon-red',
		'wapuu-wapumura-kenshin',
		'wapuu-alaaf',
		'wapuu-tiger',
		'wapuu-xiru',
		'wapuu-wptd3',
		'wapuu-wp20',
		'wapuu-world',
		'wapuu-wordsesh',
		'wapuu-wordpress-chs',
		'wapuu-wordcamp-tokyo',
		'wapuu-wordcamp-retreat-soltau',
		'wapuu-wonder',
		'wapuu-with-lines02',
		'wapuu-with-lines',
		'wapuu-winter',
		'wapuu-white-hat',
		'wapuu-wharf',
		'wapuu-welshwapuuv2',
		'wapuu-wcwue-superwapuu-2018',
		'wapuu-wcus2018-blue',
		'wapuu-wctokyo2017',
		'wapuu-wctokyo',
		'wapuu-wcphx-w-preview',
		'wapuu-wcphx-w-no-pants-preview',
		'wapuu-wcphx-plane-purple',
		'wapuu-wclvpa',
		'wapuu-wceu2016-volto-mask',
		'wapuu-wceu2016-leopold-mozart',
		'wapuu-wceu8-astropuu',
		'wapuu-wceu7-male',
		'wapuu-wceu7-female',
		'wapuu-wcdublin',
		'wapuu-wcdfw2017-flag-only',
		'wapuu-wcb19',
		'wapuu-wcatl-web',
		'wapuu-wc-ubud7-kelapa-muda',
		'wapuu-wc-jakarta9-wiro-sableng',
		'wapuu-wc-jakarta9-si-pitung',
		'wapuu-wc-jakarta9-ondel-ondel-female',
		'wapuu-wc-jakarta9-ojol',
		'wapuu-wc-jakarta7-ondel-ondel-male',
		'wapuu-wc-denpasar6-wayan',
		'wapuu-wc-denpasar6-garuda',
		'wapuu-wc-biratnagar-logo-n-mascot',
		'wapuu-war',
		'wapuu-wapuusurf',
		'wapuu-wapuusticker-die-curves',
		'wapuu-wapuunicorn',
		'wapuu-wapuunashville',
		'wapuu-wapuulovesdrupal',
		'wapuu-wapuugu',
		'wapuu-wapuufinal',
		'wapuu-wapuucr',
		'wapuu-wapuubeer',
		'wapuu-wapuubble',
		'wapuu-wapushanka',
		'wapuu-wapu60',
		'wapuu-wapu-sloth',
		'wapuu-wapu-bagel',
		'wapuu-wapsara',
		'wapuu-wappu-punt',
		'wapuu-wapoutine',
		'wapuu-wapanduu',
		'wapuu-wambhau',
		'wapuu-wabully',
		'wapuu-w3',
		'wapuu-vampuu-liberty-2017wcphilly',
		'wapuu-twins',
		'wapuu-turku-lippu',
		'wapuu-try-me',
		'wapuu-translation4',
		'wapuu-translation3',
		'wapuu-translation2',
		'wapuu-translation',
		'wapuu-tinguu-pagina',
		'wapuu-thessaloniki-alexander',
		'wapuu-the-troll',
		'wapuu-the-guruu',
		'wapuu-tangerine',
		'wapuu-swiss',
		'wapuu-swag-orlando',
		'wapuu-stateside-wappu',
		'wapuu-squirrel',
		'wapuu-speakermin',
		'wapuu-speaker0min',
		'wapuu-speaker-stop',
		'wapuu-space',
		'wapuu-space-cadet',
		'wapuu-sofia7-all-02',
		'wapuu-sofia7-all-01',
		'wapuu-snitch',
		'wapuu-snapshot',
		'wapuu-smush',
		'wapuu-skunk',
		'wapuu-sitelock-wcus18',
		'wapuu-sheepuu',
		'wapuu-shachihoko',
		'wapuu-sele',
		'wapuu-scope-creep',
		'wapuu-savvii',
		'wapuu-save-the-day-blue',
		'wapuu-sauna-wordcamp-finland',
		'wapuu-santa.orig',
		'wapuu-santa-02',
		'wapuu-salentinu',
		'wapuu-salakot',
		'wapuu-sailor',
		'wapuu-rubiks',
		'wapuu-rosie-the',
		'wapuu-rome',
		'wapuu-rocky',
		'wapuu-rochester',
		'wapuu-roboto-wc-singapore',
		'wapuu-robert',
		'wapuu-raleigh',
		'wapuu-rabelo-o',
		'wapuu-r2wapuu',
		'wapuu-pretzel',
		'wapuu-pixar',
		'wapuu-piratepuu-2017wcphilly',
		'wapuu-permalink-1024-with-wp',
		'wapuu-patheon-waving',
		'wapuu-pantheon',
		'wapuu-ottawa',
		'wapuu-ottawa-mountie',
		'wapuu-orange',
		'wapuu-opt',
		'wapuu-of-the-north',
		'wapuu-octapuu',
		'wapuu-noypi',
		'wapuu-nom-nom',
		'wapuu-ninja',
		'wapuu-newtlab',
		'wapuu-nepuu',
		'wapuu-nashville',
		'wapuu-napuu',
		'wapuu-mummypuu-2017wcphilly',
		'wapuu-mugen',
		'wapuu-mrs-nuremberg',
		'wapuu-mr-nuremberg',
		'wapuu-mountie',
		'wapuu-moto',
		'wapuu-monk',
		'wapuu-mom-and-cubbys',
		'wapuu-micro',
		'wapuu-mercenary',
		'wapuu-med',
		'wapuu-mecha-color',
		'wapuu-mcfly',
		'wapuu-matsuri',
		'wapuu-masuzushi',
		'wapuu-mascotte-final',
		'wapuu-marinwapuu',
		'wapuu-mango',
		'wapuu-manawapuu',
		'wapuu-manapuu',
		'wapuu-main-wabster',
		'wapuu-maiko',
		'wapuu-maido',
		'wapuu-magic',
		'wapuu-macedonia',
		'wapuu-lyon-lugdunum',
		'wapuu-lumberjack',
		'wapuu-london2016',
		'wapuu-logomeetupbari',
		'wapuu-logo-toque',
		'wapuu-large-lobster',
		'wapuu-kurashiki',
		'wapuu-krimpet',
		'wapuu-keep-austin-weird',
		'wapuu-kabuki-seal',
		'wapuu-jpop',
		'wapuu-ji-chaudhary',
		'wapuu-jeff',
		'wapuu-jeepney',
		'wapuu-jedi',
		'wapuu-jags',
		'wapuu-inpsyde',
		'wapuu-indie-with-text',
		'wapuu-hummingbird',
		'wapuu-hot-air-balloon',
		'wapuu-hipster',
		'wapuu-hip-hop',
		'wapuu-hex',
		'wapuu-heropress',
		'wapuu-heart',
		'wapuu-haobhau-in-jungle',
		'wapuu-hanuman-transparent',
		'wapuu-habu',
		'wapuu-gutenpuu',
		'wapuu-guitar',
		'wapuu-gravity-forms-franklin-san-jose',
		'wapuu-gravity-forms-astronaut-saturn-w-icon',
		'wapuu-gravity-forms-astronaut-moon-w-icon',
		'wapuu-gravity-forms-astronaut-mars-w-icon',
		'wapuu-gravity-forms-astronaut-jupiter-icon',
		'wapuu-gollum',
		'wapuu-gokart',
		'wapuu-gianduu',
		'wapuu-ghostbuster',
		'wapuu-ghost-costume',
		'wapuu-geekpuu-right',
		'wapuu-fujisan',
		'wapuu-frapuu',
		'wapuu-frankenpuu',
		'wapuu-france',
		'wapuu-football',
		'wapuu-fishingwapuu',
		'wapuu-fes',
		'wapuu-fayapuu',
		'wapuu-exercisering',
		'wapuu-eight-ball',
		'wapuu-efendi',
		'wapuu-edinburgh',
		'wapuu-duerer',
		'wapuu-dracuu',
		'wapuu-dokuganryu',
		'wapuu-devman',
		'wapuu-delhi',
		'wapuu-defender',
		'wapuu-de-la-wordcamp-santander',
		'wapuu-david-bowie',
		'wapuu-cubby',
		'wapuu-crab',
		'wapuu-cowpuu-jacksonvill-pin',
		'wapuu-cowboy-coder',
		'wapuu-cossackla',
		'wapuu-copernicuswapuu',
		'wapuu-colorado',
		'wapuu-cologne-hannes-final',
		'wapuu-cologne-baerbel-final',
		'wapuu-collector-pin-for-translation',
		'wapuu-collector-pin-for-training',
		'wapuu-collector-pin-for-support',
		'wapuu-collector-pin-for-development',
		'wapuu-collector-pin-for-design-ui',
		'wapuu-collector-pin-for-content',
		'wapuu-collector-pin-for-community',
		'wapuu-collector-pin-for-accessibility',
		'wapuu-cheesesteak',
		'wapuu-catering',
		'wapuu-carole-community',
		'wapuu-captian-w',
		'wapuu-canvas',
		'wapuu-cangaceiro',
		'wapuu-camera',
		'wapuu-building-block-orlando',
		'wapuu-brownie-shading',
		'wapuu-brighton-and-sid',
		'wapuu-boldie',
		'wapuu-blockpuu',
		'wapuu-black-hat',
		'wapuu-blab',
		'wapuu-birthday',
		'wapuu-bicycling',
		'wapuu-bg',
		'wapuu-woo',
		'wapuu-pt-woo',
		'wapuu-better-off-wordpress',
		'wapuu-batpuu',
		'wapuu-batchoy',
		'wapuu-basile',
		'wapuu-baap',
		'wapuu-auguste',
		'wapuu-ati',
		'wapuu-arno-and-ezio',
		'wapuu-ammuappu',
		'wapuu-ahmedabad',
		'wapuu-ahmedabad-wordcamp2017',
		'wapuu-achilles',
		'wapuu-80s',
		'wapuu-10uppu',
		'wapuu-08',
		'wapuu-8-bit',
		'wapuu-07',
		'wapuu-06',
		'wapuu-05',
		'wapuu-04',
		'wapuu-03',
		'wapuu-02',
		'wapuu-01',
		'wapuu-santa',
		'wapuu-logo-id',
		'wapuu-grad',
	);
}
