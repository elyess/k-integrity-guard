<?php
/**
 * Tests for KIG_Settings::sanitize().
 *
 * @package K_Integrity_Guard
 */

class Test_KIG_Settings extends WP_UnitTestCase {

	private function saved() {
		return array(
			'daily_scan_enabled' => true,
			'targets'            => array(
				'core'                => true,
				'plugins'             => false,
				'themes'              => true,
				'third_party_plugins' => true,
				'third_party_themes'  => false,
			),
			'third_party'        => array( 'ignore' => array( 'foo/foo.php' => true ) ),
			'third_party_themes' => array( 'ignore' => array( 'bar' => true ) ),
		);
	}

	public function test_third_party_section_keeps_general() {
		update_option( KIG_Settings::OPTION, $this->saved() );

		$out = ( new KIG_Settings() )->sanitize(
			array(
				'_section'    => 'third_party',
				'third_party' => array( 'ignore' => array( 'baz/baz.php' => 'on' ) ),
			)
		);

		$this->assertTrue( $out['daily_scan_enabled'] );
		$this->assertSame( $this->saved()['targets'], $out['targets'] );
		$this->assertSame( array( 'baz/baz.php' => true ), $out['third_party']['ignore'] );
		$this->assertSame( array( 'bar' => true ), $out['third_party_themes']['ignore'] );
		$this->assertArrayNotHasKey( '_section', $out );
	}

	public function test_general_section_keeps_ignore_maps() {
		update_option( KIG_Settings::OPTION, $this->saved() );

		$out = ( new KIG_Settings() )->sanitize(
			array(
				'_section' => 'general',
				'targets'  => array( 'core' => 'on', 'third_party_themes' => 'on' ),
			)
		);

		$this->assertFalse( $out['daily_scan_enabled'] );
		$this->assertTrue( $out['targets']['core'] );
		$this->assertFalse( $out['targets']['third_party_plugins'] );
		$this->assertTrue( $out['targets']['third_party_themes'] );
		$this->assertSame( array( 'foo/foo.php' => true ), $out['third_party']['ignore'] );
		$this->assertSame( array( 'bar' => true ), $out['third_party_themes']['ignore'] );
		$this->assertArrayNotHasKey( '_section', $out );
	}
}
