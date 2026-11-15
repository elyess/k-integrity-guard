<?php
/**
 * Tests for KIG_Scan file comparison and core file filtering.
 *
 * @package K_Integrity_Guard
 */

class Test_KIG_Scan extends WP_UnitTestCase {

	/** @var KIG_Scan */
	private $scan;

	/** @var string */
	private $dir;

	public function set_up() {
		parent::set_up();
		$this->scan = new KIG_Scan( dirname( __DIR__ ) . '/k-integrity-guard.php', 'k_integrity_guard_scan', new KIG_Settings(), 'test', new KIG_DB() );
		$this->dir  = trailingslashit( get_temp_dir() ) . 'kig-test-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->dir );
		file_put_contents( $this->dir . '/hello.php', 'hello' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		file_put_contents( $this->dir . '/readme.txt', 'readme' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	public function tear_down() {
		array_map( 'unlink', glob( $this->dir . '/*' ) );
		rmdir( $this->dir );
		parent::tear_down();
	}

	private function call( $method, array $args ) {
		$m = new ReflectionMethod( KIG_Scan::class, $method );
		$m->setAccessible( true );
		return $m->invokeArgs( $this->scan, $args );
	}

	/** WordPress.org plugin checksums give {"md5": hash or [hashes], "sha256": ...} per file. */
	public function test_wporg_plugin_checksum_format_matches_clean_files() {
		$api = array(
			'hello.php'  => array( 'md5' => md5( 'hello' ), 'sha256' => hash( 'sha256', 'hello' ) ),
			'readme.txt' => array( 'md5' => array( md5( 'old readme' ), strtoupper( md5( 'readme' ) ) ), 'sha256' => array() ),
		);

		$map    = $this->call( 'normalize_checksum_map', array( $api, array( 'hello-dolly' ) ) );
		$result = $this->call( 'compare_extension_files', array( $this->dir, $map, 'md5', true ) );

		$this->assertSame( array(), $result['modified'] );
		$this->assertSame( array(), $result['missing'] );
		$this->assertSame( array(), $result['added'] );

		file_put_contents( $this->dir . '/hello.php', 'hacked' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$result = $this->call( 'compare_extension_files', array( $this->dir, $map, 'md5', true ) );

		$this->assertSame( array( 'hello.php' ), wp_list_pluck( $result['modified'], 'file' ) );
	}

	/** Other themes must never count as core files; a deleted bundled theme must not be "missing". */
	public function test_core_filter_ignores_other_themes_and_deleted_bundled_themes() {
		$checksums = array(
			'wp-login.php'                                => 'a',
			'wp-config.php'                               => 'b',
			'wp-content/plugins/akismet/akismet.php'      => 'c',
			'wp-content/themes/index.php'                 => 'd',
			'wp-content/themes/kig-deleted-theme/style.css' => 'e',
		);
		$allowed  = array();
		$filtered = $this->call( 'filter_core_checksums', array( $checksums, &$allowed ) );

		$this->assertSame( array( 'wp-login.php', 'wp-content/themes/index.php' ), array_keys( $filtered ) );
		$this->assertNotContains( 'wp-content/themes', $allowed );

		foreach ( $this->call( 'list_core_files', array( array() ) ) as $file ) {
			$this->assertStringStartsNotWith( 'wp-content/', $file );
			$this->assertNotSame( 'wp-config.php', $file );
		}
	}
}
