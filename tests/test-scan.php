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
		// Runs even when an assertion failed, so no test theme is left in the theme root.
		if ( is_dir( get_theme_root() . '/kig-bundled-test' ) ) {
			$this->remove_theme( 'kig-bundled-test' );
		}
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

	/** Writes a minimal theme into the theme root and returns its slug. */
	private function make_theme( $version ) {
		$slug = 'kig-bundled-test';
		$dir  = get_theme_root() . '/' . $slug;
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/style.css', "/*\nTheme Name: KIG Bundled Test\nVersion: {$version}\n*/\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		wp_clean_themes_cache();
		return $slug;
	}

	private function remove_theme( $slug ) {
		KIG_Utils::delete_theme_checksum_file( $slug );
		$dir = get_theme_root() . '/' . $slug;
		array_map( 'unlink', glob( $dir . '/*' ) );
		rmdir( $dir );
		wp_clean_themes_cache();
	}

	/** A bundled theme with a snapshot is checked against the snapshot: accepted edits pass, later edits are flagged. */
	public function test_bundled_theme_snapshot_replaces_core_check() {
		$slug = $this->make_theme( '1.0' );
		$dir  = 'wp-content/themes/' . $slug;
		file_put_contents( get_theme_root() . "/$slug/style.css", "\n/* my edit */", FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		KIG_Utils::generate_theme_checksum_json( $slug );

		$state    = array();
		$filtered = array( 'wp-login.php' => 'a', "$dir/style.css" => 'original' );
		$allowed  = array( 'wp-content/themes/index.php', $dir );
		$this->call( 'apply_theme_snapshots', array( &$state, &$filtered, &$allowed ) );

		$this->assertSame( array( 'wp-login.php' ), array_keys( $filtered ) );
		$this->assertSame( array( 'wp-content/themes/index.php' ), $allowed );
		$this->assertSame( array( $slug ), $state['snapshot_themes'] );

		$state += array( 'modified' => array(), 'missing' => array(), 'added' => array(), 'errors' => array() );
		$this->call( 'check_bundled_theme_snapshots', array( &$state ) );
		$this->assertSame( array(), $state['modified'] );

		file_put_contents( get_theme_root() . "/$slug/style.css", "\n/* later change */", FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->call( 'check_bundled_theme_snapshots', array( &$state ) );
		$this->assertSame( array( "$dir/style.css" ), wp_list_pluck( $state['modified'], 'file' ) );
	}

	/** Files the snapshot accepts but that differ from the WordPress original are always named in a note. */
	public function test_snapshot_accepted_edits_are_reported_in_a_note() {
		$slug = $this->make_theme( '1.0' );
		$dir  = 'wp-content/themes/' . $slug;
		$root = get_theme_root() . '/' . $slug;
		$original = md5_file( $root . '/style.css' );
		file_put_contents( $root . '/style.css', "\n/* my edit */", FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		file_put_contents( $root . '/extra.php', '<?php // my file' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		KIG_Utils::generate_theme_checksum_json( $slug );

		$state    = array();
		$filtered = array( "$dir/style.css" => $original );
		$allowed  = array( $dir );
		$this->call( 'apply_theme_snapshots', array( &$state, &$filtered, &$allowed ) );
		$state += array( 'modified' => array(), 'missing' => array(), 'added' => array(), 'errors' => array() );
		$this->call( 'check_bundled_theme_snapshots', array( &$state ) );

		$this->assertSame( array(), $state['modified'] );
		$this->assertSame( array(), $state['added'] );
		$this->assertCount( 1, $state['notes'] );
		$this->assertStringContainsString( '2 file(s) differ from the WordPress original', $state['notes'][0] );
	}

	/** The bundled theme list comes from the cached core checksums. */
	public function test_bundled_theme_slugs_from_core_checksums() {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		set_transient(
			'kig_core_checksums_' . md5( get_bloginfo( 'version' ) . '|' . $locale ),
			array(
				'checksums' => array(
					'wp-login.php'                               => 'a',
					'wp-content/themes/index.php'                => 'b',
					'wp-content/themes/twentytwentyfive/style.css' => 'c',
					'wp-content/themes/twentytwentyfive/theme.json' => 'd',
				),
				'locale'    => $locale,
			),
			DAY_IN_SECONDS
		);

		$this->assertSame( array( 'twentytwentyfive' ), $this->call( 'bundled_theme_slugs', array() ) );
	}

	/** After an update the snapshot is outdated: the theme goes back to the WordPress original, with a note. */
	public function test_outdated_bundled_theme_snapshot_falls_back_to_core() {
		$slug = $this->make_theme( '1.0' );
		KIG_Utils::generate_theme_checksum_json( $slug );
		$this->make_theme( '2.0' );

		$state    = array();
		$filtered = array( "wp-content/themes/$slug/style.css" => 'original' );
		$allowed  = array( "wp-content/themes/$slug" );
		$this->call( 'apply_theme_snapshots', array( &$state, &$filtered, &$allowed ) );

		$this->assertSame( array(), $state['snapshot_themes'] );
		$this->assertSame( array( "wp-content/themes/$slug/style.css" ), array_keys( $filtered ) );
		$this->assertCount( 1, $state['notes'] );
	}

	/** An edited bundled theme without a snapshot gets the child-theme hint once. */
	public function test_edited_bundled_theme_gets_child_theme_note() {
		$state = array(
			'bundled_themes' => array( 'wp-content/themes/twentytwentyfour', 'wp-content/themes/twentytwentyfive' ),
			'modified'       => array( array( 'file' => 'wp-content/themes/twentytwentyfour/style.css' ), array( 'file' => 'wp-content/themes/twentytwentyfour/theme.json' ) ),
			'added'          => array( 'wp-includes/evil.php' ),
			'notes'          => array(),
		);
		$this->call( 'note_edited_bundled_themes', array( &$state ) );

		$this->assertCount( 1, $state['notes'] );
		$this->assertStringContainsString( 'child theme', $state['notes'][0] );
	}
}

