<?php
/**
 * Tests for KIG_DB.
 *
 * @package K_Integrity_Guard
 */

class Test_KIG_DB extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		KIG_DB::create_table();
		KIG_DB::delete_all_scans();
	}

	public function test_get_and_delete_scans() {
		$a = KIG_DB::insert_scan( array( 'summary' => 'first' ) );
		$b = KIG_DB::insert_scan( array( 'summary' => 'second', 'status' => 'warning' ) );
		$this->assertIsInt( $a );
		$this->assertIsInt( $b );

		$scans = KIG_DB::get_scans();
		$this->assertCount( 2, $scans['items'] );
		$this->assertSame( 2, $scans['total'] );

		$warning = KIG_DB::get_scans( array( 'status' => 'warning' ) );
		$this->assertCount( 1, $warning['items'] );
		$this->assertSame( 1, $warning['total'] );

		$this->assertSame( 2, KIG_DB::delete_scans( array( $a, $b ) ) );
		$this->assertSame( 0, KIG_DB::get_scans()['total'] );
	}

	public function test_delete_all_scans_returns_row_count() {
		KIG_DB::insert_scan( array( 'summary' => 'one' ) );
		KIG_DB::insert_scan( array( 'summary' => 'two' ) );
		KIG_DB::insert_scan( array( 'summary' => 'three' ) );

		$this->assertSame( 3, KIG_DB::delete_all_scans() );
		$this->assertSame( 0, KIG_DB::get_scans()['total'] );
	}
}
