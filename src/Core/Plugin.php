<?php
namespace Peyvast\Auth\Core;

use Peyvast\Auth\Core\Config\Settings;
use Peyvast\Auth\Infrastructure\Logging\Logger;
use Peyvast\Auth\Infrastructure\Persistence\PhoneIdentityIndex;
use Peyvast\Auth\Infrastructure\Scheduling\Scheduler;
use Peyvast\Auth\Infrastructure\WordPress\GuestSession;
use Peyvast\Auth\Infrastructure\WordPress\Privacy;
use Peyvast\Auth\Infrastructure\WordPress\Redirects;
use Peyvast\Auth\Presentation\Admin\Logs;
use Peyvast\Auth\Presentation\Admin\SettingsPage;
use Peyvast\Auth\Presentation\Admin\UserProfile;
use Peyvast\Auth\Presentation\Rest\RestRouter;
use Peyvast\Auth\Presentation\Frontend\Assets;
use Peyvast\Auth\Integrations\Blocks\Integration as Blocks;
use Peyvast\Auth\Integrations\Bricks\Integration as Bricks;
use Peyvast\Auth\Integrations\WooCommerce\Integration as Woo;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		\Peyvast\Auth\Infrastructure\WordPress\Installer::register_notices();
		GuestSession::boot();
		PhoneIdentityIndex::boot();
		// The Scheduler layer owns all background work; no WP-Cron jobs are registered here.
		Scheduler::boot();
		Privacy::boot();
		Settings::boot();
		if ( function_exists( 'register_block_type' ) ) {
			Blocks::boot();
		}
		SettingsPage::boot();
		Logs::boot();
		UserProfile::boot();
		RestRouter::boot();
		Woo::boot();
		Bricks::boot();
		add_action( 'template_redirect', array( Redirects::class, 'logged_in_login_page' ), 1 );
		add_filter( 'logout_redirect', array( self::class, 'wordpress_logout_redirect' ), 20, 3 );
	}

	public static function wordpress_logout_redirect( string $redirect_to, string $requested_redirect_to, $user ): string {
		$destination = Settings::logout_destination( 'wordpress' );
		return $destination !== '' ? $destination : $redirect_to;
	}

}
