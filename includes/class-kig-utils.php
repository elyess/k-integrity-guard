<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KIG_Utils {

	/**
	 * Detect installed plugins and whether they are from WordPress.org.
	 *
	 * @param bool $force_refresh Optional. Whether to refresh update transient.
	 * @return array Array keyed by plugin file. Each item: name, version, plugin_uri, update_uri, is_wporg (bool), slug.
	 */
	public static function detect_plugin_sources( $force_refresh = false ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( $force_refresh && function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}

		$plugins   = get_plugins();
		$transient = get_site_transient( 'update_plugins' );
		$wporg     = array();
		$slugs     = array();

		if ( is_object( $transient ) ) {
			foreach ( array( 'response', 'no_update' ) as $bucket ) {
				if ( empty( $transient->{$bucket} ) || ! is_array( $transient->{$bucket} ) ) {
					continue;
				}
				foreach ( $transient->{$bucket} as $file => $obj ) {
					$id  = isset( $obj->id ) ? (string) $obj->id : '';
					$url = isset( $obj->url ) ? (string) $obj->url : '';
					$slug = isset( $obj->slug ) ? (string) $obj->slug : '';

					if ( 0 === strpos( $id, 'w.org/plugins/' ) || false !== strpos( $url, 'wordpress.org/plugins/' ) ) {
						$wporg[ $file ] = true;
					}

					if ( '' === $slug && '' !== $id && 0 === strpos( $id, 'w.org/plugins/' ) ) {
						$slug = substr( $id, strlen( 'w.org/plugins/' ) );
					}

					if ( '' !== $slug ) {
						$slugs[ $file ] = sanitize_key( $slug );
					}
				}
			}
		}

		$out = array();

		foreach ( $plugins as $file => $data ) {
			$update_uri = isset( $data['UpdateURI'] ) ? trim( (string) $data['UpdateURI'] ) : '';
			$is_wporg   = isset( $wporg[ $file ] );
			$slug       = isset( $slugs[ $file ] ) ? $slugs[ $file ] : '';

			if ( ! $is_wporg && $update_uri ) {
				$u = strtolower( $update_uri );
				if ( false !== strpos( $u, 'wordpress.org/plugins' ) || 'wordpress.org' === $u || 'w.org' === $u ) {
					$is_wporg = true;
					if ( '' === $slug ) {
						$slug = self::maybe_extract_slug_from_url( $update_uri );
					}
				}
			}

			if ( '' === $slug ) {
				$slug = self::guess_plugin_slug( $file, $data );
			}

			$out[ $file ] = array(
				'name'       => isset( $data['Name'] ) ? $data['Name'] : $file,
				'version'    => isset( $data['Version'] ) ? $data['Version'] : '',
				'plugin_uri' => isset( $data['PluginURI'] ) ? $data['PluginURI'] : '',
				'update_uri' => $update_uri,
				'is_wporg'   => $is_wporg,
				'slug'       => $slug,
			);
		}

		return $out;
	}

	/**
	 * Attempt to extract a plugin slug from an Update URI.
	 *
	 * @param string $update_uri Update URI string.
	 * @return string
	 */
	private static function maybe_extract_slug_from_url( $update_uri ) {
		$uri = trim( strtolower( (string) $update_uri ) );
		if ( '' === $uri ) {
			return '';
		}

		$parts = wp_parse_url( $uri );
		if ( empty( $parts['host'] ) || false === strpos( $parts['host'], 'wordpress.org' ) ) {
			return '';
		}

		$path = isset( $parts['path'] ) ? trim( $parts['path'], '/' ) : '';
		if ( '' === $path ) {
			return '';
		}

		$segments = explode( '/', $path );
		if ( count( $segments ) < 2 ) {
			return '';
		}

		return sanitize_key( $segments[1] );
	}

	/**
	 * Guess a plugin slug when not provided by the update API.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @param array  $data        Plugin header data.
	 * @return string
	 */
	private static function guess_plugin_slug( $plugin_file, $data ) {
		$directory = dirname( $plugin_file );
		if ( '.' !== $directory && '' !== $directory ) {
			return sanitize_key( $directory );
		}

		if ( ! empty( $data['TextDomain'] ) ) {
			return sanitize_key( $data['TextDomain'] );
		}

		if ( ! empty( $data['Name'] ) ) {
			return sanitize_title( $data['Name'] );
		}

		$basename = basename( $plugin_file, '.php' );
		return sanitize_key( $basename );
	}

	/**
	 * Checksums directory inside uploads.
	 *
	 * @return string Absolute path.
	 */
	public static function checksums_dir() {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'k-integrity-guard/checksums';
		wp_mkdir_p( $dir );
		return $dir;
	}

	/**
	 * Path to a plugin checksum JSON file.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @return string Absolute path to JSON file.
	 */
	public static function checksum_path( $plugin_file ) {
		$san = sanitize_file_name( $plugin_file );
		return trailingslashit( self::checksums_dir() ) . $san . '.json';
	}

	/**
	 * Delete checksum file for a plugin.
	 *
	 * @param string $plugin_file Plugin basename.
	 */
	public static function delete_checksum_file( $plugin_file ) {
		$path = self::checksum_path( $plugin_file );
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Read checksum JSON for a plugin.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @return array|null Decoded JSON array or null if missing/invalid.
	 */
	public static function read_plugin_checksum_json( $plugin_file ) {
		$path = self::checksum_path( $plugin_file );
		if ( ! file_exists( $path ) ) {
			return null;
		}
		$raw = file_get_contents( $path );
		$dec = json_decode( $raw, true );
		return is_array( $dec ) ? $dec : null;
	}

	/**
	 * Determine if a plugin's checksum JSON is stale (version mismatch or missing).
	 *
	 * @param string $plugin_file Plugin basename.
	 * @return bool True if missing or stale.
	 */
	public static function checksum_is_stale( $plugin_file ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		if ( ! isset( $plugins[ $plugin_file ] ) ) {
			return false; // Not installed anymore.
		}
		$json = self::read_plugin_checksum_json( $plugin_file );
		if ( ! $json ) {
			return true;
		}
		$current = isset( $plugins[ $plugin_file ]['Version'] ) ? (string) $plugins[ $plugin_file ]['Version'] : '';
		return (string) ( $json['version'] ?? '' ) !== $current;
	}

	/**
	 * Generate/update checksum JSON for a plugin.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @return string|WP_Error Path to JSON or error.
	 */
	public static function generate_plugin_checksum_json( $plugin_file ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		if ( ! isset( $plugins[ $plugin_file ] ) ) {
			return new WP_Error( 'kig_not_found', __( 'Plugin not found.', 'k-integrity-guard' ) );
		}

		$root = WP_PLUGIN_DIR . '/' . dirname( $plugin_file );
		$algo = 'sha256';
		$map  = array();

		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $it as $f ) {
			/** @var SplFileInfo $f */
			if ( $f->isFile() ) {
				$rel         = ltrim( str_replace( $root, '', $f->getPathname() ), '/\\' );
				$map[ $rel ] = hash_file( $algo, $f->getPathname() );
			}
		}

		$payload = array(
			'plugin_file'  => $plugin_file,
			'version'      => isset( $plugins[ $plugin_file ]['Version'] ) ? $plugins[ $plugin_file ]['Version'] : '',
			'algorithm'    => $algo,
			'generated_at' => time(),
			'files'        => $map,
		);

		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		$path = self::checksum_path( $plugin_file );

		if ( false === file_put_contents( $path, $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'kig_write_failed', __( 'Failed to write checksum file.', 'k-integrity-guard' ) );
		}

		return $path;
	}

	/**
	 * Detect installed themes and whether they are from WordPress.org.
	 *
	 * @param bool $force_refresh Optional. Whether to refresh update transient.
	 * @return array Array keyed by stylesheet. Each item: name, version, theme_uri, update_uri, is_wporg (bool).
	 */
	public static function detect_theme_sources( $force_refresh = false ) {
		if ( $force_refresh && function_exists( 'wp_update_themes' ) ) {
			wp_update_themes();
		}

		$themes    = wp_get_themes();
		$transient = get_site_transient( 'update_themes' );
		$wporg     = array();

		if ( is_object( $transient ) ) {
			foreach ( array( 'response', 'no_update' ) as $bucket ) {
				if ( empty( $transient->{$bucket} ) || ! is_array( $transient->{$bucket} ) ) {
					continue;
				}
				foreach ( $transient->{$bucket} as $stylesheet => $obj ) {
					$url = isset( $obj['url'] ) ? (string) $obj['url'] : '';
					$id  = isset( $obj['id'] ) ? (string) $obj['id'] : '';
					if ( 0 === strpos( $id, 'w.org/themes/' ) || false !== strpos( $url, 'wordpress.org/themes/' ) ) {
						$wporg[ $stylesheet ] = true;
					}
				}
			}
		}

		$out = array();

		foreach ( $themes as $stylesheet => $theme ) {
			$update_uri = trim( (string) $theme->get( 'UpdateURI' ) );
			$is_wporg   = ! empty( $wporg[ $stylesheet ] );

			if ( ! $is_wporg && '' !== $update_uri ) {
				$u = strtolower( $update_uri );
				if ( false !== strpos( $u, 'wordpress.org/themes' ) || 'wordpress.org' === $u || 'w.org' === $u ) {
					$is_wporg = true;
				}
			}

			$out[ $stylesheet ] = array(
				'name'       => $theme->get( 'Name' ),
				'version'    => $theme->get( 'Version' ),
				'theme_uri'  => $theme->get( 'ThemeURI' ),
				'update_uri' => $update_uri,
				'is_wporg'   => $is_wporg,
			);
		}

		return $out;
	}

	/**
	 * Directory for theme checksum JSON files.
	 *
	 * @return string
	 */
	public static function theme_checksums_dir() {
		$dir = trailingslashit( self::checksums_dir() ) . 'themes';
		wp_mkdir_p( $dir );
		return $dir;
	}

	/**
	 * Path to a theme checksum JSON file.
	 *
	 * @param string $stylesheet Theme stylesheet (directory name).
	 * @return string
	 */
	public static function theme_checksum_path( $stylesheet ) {
		return trailingslashit( self::theme_checksums_dir() ) . sanitize_file_name( $stylesheet ) . '.json';
	}

	/**
	 * Delete checksum file for a theme.
	 *
	 * @param string $stylesheet Theme stylesheet.
	 */
	public static function delete_theme_checksum_file( $stylesheet ) {
		$path = self::theme_checksum_path( $stylesheet );
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Determine if a theme checksum is stale.
	 *
	 * @param string $stylesheet Theme stylesheet.
	 * @return bool
	 */
	public static function theme_checksum_is_stale( $stylesheet ) {
		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return false;
		}

		$path = self::theme_checksum_path( $stylesheet );
		if ( ! file_exists( $path ) ) {
			return true;
		}

		$raw = file_get_contents( $path );
		$dec = json_decode( $raw, true );
		if ( ! is_array( $dec ) ) {
			return true;
		}

		return (string) ( $dec['version'] ?? '' ) !== (string) $theme->get( 'Version' );
	}

	/**
	 * Generate/update checksum JSON for a theme.
	 *
	 * @param string $stylesheet Theme stylesheet.
	 * @return string|WP_Error
	 */
	public static function generate_theme_checksum_json( $stylesheet ) {
		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return new WP_Error( 'kig_theme_not_found', __( 'Theme not found.', 'k-integrity-guard' ) );
		}

		$root = $theme->get_stylesheet_directory();
		$algo = 'sha256';
		$map  = array();

		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $it as $f ) {
			/** @var SplFileInfo $f */
			if ( $f->isFile() ) {
				$rel         = ltrim( str_replace( $root, '', $f->getPathname() ), '/\\' );
				$map[ $rel ] = hash_file( $algo, $f->getPathname() );
			}
		}

		$payload = array(
			'stylesheet'  => $stylesheet,
			'version'     => $theme->get( 'Version' ),
			'algorithm'   => $algo,
			'generated_at' => time(),
			'files'       => $map,
		);

		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		$path = self::theme_checksum_path( $stylesheet );

		if ( false === file_put_contents( $path, $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'kig_write_failed', __( 'Failed to write checksum file.', 'k-integrity-guard' ) );
		}

		return $path;
	}
}
