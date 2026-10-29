<?php
/*
Plugin Name: Simple Export of Orders for Marketing Campaigns
Description: Exports customer email and phone from WooCommerce orders via WP-CLI or a WooCommerce admin panel.
Version: 1.3
Author: Eduard V. Doloc
Author URI: https://uprise.ro
*/

if ( ! defined( 'ABSPATH' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	exit;
}

define( 'SME_PLUGIN_FILE', __FILE__ );
define( 'SME_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SME_EXPORTS_DIR', SME_PLUGIN_DIR . 'exports/' );
define( 'SME_MENU_SLUG', 'simple-marketing-export' );
define( 'SME_CAPABILITY', 'manage_woocommerce' );

class Simple_Marketing_Export {

	/**
	 * Run the export.
	 *
	 * @param array         $args   status, date_from, date_to, all_periods
	 * @param callable|null $logger Optional callback receiving ($event, $payload)
	 *                              events: start(total), tick(count), log(msg), finish()
	 *
	 * @return array|WP_Error
	 */
	public static function run_export( array $args = [], $logger = null ) {
		global $wpdb;

		if ( ! class_exists( 'WooCommerce' ) ) {
			return new WP_Error( 'sme_no_woocommerce', 'WooCommerce is not active.' );
		}

		$args = wp_parse_args( $args, [
			'status'      => 'wc-completed',
			'date_from'   => '',
			'date_to'     => '',
			'all_periods' => false,
		] );

		if ( ! self::ensure_exports_dir() ) {
			return new WP_Error( 'sme_dir_failure', 'Could not create exports directory.' );
		}

		[ $where_sql, $params ] = self::build_where( $args );

		$count_sql = "SELECT COUNT(*)
			FROM {$wpdb->prefix}wc_order_addresses a
			INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = a.order_id
			WHERE $where_sql";
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );

		if ( $total === 0 ) {
			return new WP_Error( 'sme_no_orders', 'No orders found for the selected filters.' );
		}

		$filename = 'marketing-export-' . wp_generate_password( 16, false ) . '-' . date( 'Y-m-d-H-i-s' ) . '.csv';
		$filepath = SME_EXPORTS_DIR . $filename;
		$file     = fopen( $filepath, 'w' );
		if ( ! $file ) {
			return new WP_Error( 'sme_file_failure', 'Could not open export file for writing.' );
		}
		fputcsv( $file, [ 'email', 'phone' ] );

		if ( $logger ) {
			$logger( 'start', $total );
		}

		$batch_size = 5000;
		$last_id    = 0;

		// Keyset pagination on order id: one billing address per order (unique key),
		// so o.id is a stable, unique cursor and each batch is an index range scan.
		do {
			$select_sql = "SELECT o.id, a.email, a.phone
				FROM {$wpdb->prefix}wc_order_addresses a
				INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = a.order_id
				WHERE $where_sql AND o.id > %d
				ORDER BY o.id
				LIMIT %d";

			$batch_params = array_merge( $params, [ $last_id, $batch_size ] );
			$results      = $wpdb->get_results( $wpdb->prepare( $select_sql, $batch_params ) );

			if ( $wpdb->last_error ) {
				fclose( $file );
				unlink( $filepath );
				return new WP_Error( 'sme_db_failure', $wpdb->last_error );
			}

			if ( empty( $results ) ) {
				break;
			}

			foreach ( $results as $row ) {
				fputcsv( $file, [ $row->email, $row->phone ] );
			}

			$last_id = (int) $results[ count( $results ) - 1 ]->id;

			if ( $logger ) {
				$logger( 'tick', count( $results ) );
				$logger( 'log', "Processed up to order #$last_id" );
			}
		} while ( count( $results ) === $batch_size );

		fclose( $file );

		if ( $logger ) {
			$logger( 'finish' );
		}

		return [
			'filename' => $filename,
			'filepath' => $filepath,
			'url'      => plugins_url( 'exports/' . $filename, SME_PLUGIN_FILE ),
			'rows'     => $total,
		];
	}

	/**
	 * Export unique billing phone numbers from all completed orders.
	 *
	 * Paginates on order id (keyset) instead of OFFSET, so every batch is an
	 * index range scan no matter how deep into the table it is.
	 *
	 * @param callable|null $logger Optional callback receiving ($event, $payload)
	 *                              events: start(total), tick(count), finish()
	 *
	 * @return array|WP_Error
	 */
	public static function run_phone_export( $logger = null ) {
		global $wpdb;

		if ( ! class_exists( 'WooCommerce' ) ) {
			return new WP_Error( 'sme_no_woocommerce', 'WooCommerce is not active.' );
		}

		if ( ! self::ensure_exports_dir() ) {
			return new WP_Error( 'sme_dir_failure', 'Could not create exports directory.' );
		}

		$base_sql = "FROM {$wpdb->prefix}wc_orders o
			INNER JOIN {$wpdb->prefix}wc_order_addresses a ON a.order_id = o.id AND a.address_type = 'billing'
			WHERE o.type = 'shop_order' AND o.status = 'wc-completed'";

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) $base_sql" );

		if ( $total === 0 ) {
			return new WP_Error( 'sme_no_orders', 'No completed orders found.' );
		}

		$filename = 'marketing-phones-' . wp_generate_password( 16, false ) . '-' . date( 'Y-m-d-H-i-s' ) . '.csv';
		$filepath = SME_EXPORTS_DIR . $filename;
		$file     = fopen( $filepath, 'w' );
		if ( ! $file ) {
			return new WP_Error( 'sme_file_failure', 'Could not open export file for writing.' );
		}
		// Normalized phones are only digits and "+", so plain lines are valid CSV.
		fwrite( $file, "phone\n" );

		if ( $logger ) {
			$logger( 'start', $total );
		}

		$batch_size = 20000;
		$last_id    = 0;
		$seen       = [];

		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT o.id, a.phone $base_sql AND o.id > %d ORDER BY o.id LIMIT %d", $last_id, $batch_size ),
				ARRAY_N
			);

			if ( $wpdb->last_error ) {
				fclose( $file );
				unlink( $filepath );
				return new WP_Error( 'sme_db_failure', $wpdb->last_error );
			}

			if ( empty( $rows ) ) {
				break;
			}

			$lines = '';
			foreach ( $rows as $row ) {
				$phone = self::normalize_phone( (string) $row[1] );
				if ( $phone !== '' && ! isset( $seen[ $phone ] ) ) {
					$seen[ $phone ] = true;
					$lines         .= $phone . "\n";
				}
			}
			fwrite( $file, $lines );

			$last_id = (int) $rows[ count( $rows ) - 1 ][0];

			if ( $logger ) {
				$logger( 'tick', count( $rows ) );
			}
		} while ( count( $rows ) === $batch_size );

		fclose( $file );

		if ( $logger ) {
			$logger( 'finish' );
		}

		return [
			'filename' => $filename,
			'filepath' => $filepath,
			'url'      => plugins_url( 'exports/' . $filename, SME_PLUGIN_FILE ),
			'rows'     => count( $seen ),
			'orders'   => $total,
		];
	}

	/**
	 * Keep only digits and a leading "+", so "0722 123 456" and "0722-123-456" dedupe together.
	 */
	private static function normalize_phone( string $raw ): string {
		$digits = preg_replace( '/\D+/', '', $raw );
		if ( $digits === '' ) {
			return '';
		}
		return ltrim( $raw )[0] === '+' ? '+' . $digits : $digits;
	}

	private static function build_where( array $args ): array {
		$where  = [ "a.address_type = 'billing'" ];
		$params = [];

		if ( ! empty( $args['all_periods'] ) ) {
			return [ implode( ' AND ', $where ), $params ];
		}

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'o.status = %s';
			$params[] = $args['status'];
		}

		if ( ! empty( $args['date_from'] ) ) {
			$where[]  = 'o.date_created_gmt >= %s';
			$params[] = self::normalize_datetime( $args['date_from'] );
		}

		if ( ! empty( $args['date_to'] ) ) {
			$where[]  = 'o.date_created_gmt <= %s';
			$params[] = self::normalize_datetime( $args['date_to'] );
		}

		return [ implode( ' AND ', $where ), $params ];
	}

	private static function normalize_datetime( string $value ): string {
		$value = str_replace( 'T', ' ', trim( $value ) );
		if ( strlen( $value ) === 16 ) {
			$value .= ':00';
		}
		return $value;
	}

	public static function ensure_exports_dir(): bool {
		if ( file_exists( SME_EXPORTS_DIR ) ) {
			return true;
		}
		return mkdir( SME_EXPORTS_DIR, 0755, true );
	}

	public static function list_exports(): array {
		if ( ! is_dir( SME_EXPORTS_DIR ) ) {
			return [];
		}
		$files = [];
		foreach ( glob( SME_EXPORTS_DIR . '*.csv' ) ?: [] as $path ) {
			$files[] = [
				'name'  => basename( $path ),
				'size'  => filesize( $path ),
				'mtime' => filemtime( $path ),
				'url'   => plugins_url( 'exports/' . basename( $path ), SME_PLUGIN_FILE ),
			];
		}
		usort( $files, static fn( $a, $b ) => $b['mtime'] <=> $a['mtime'] );
		return $files;
	}

	public static function delete_export( string $name ): bool {
		$name = basename( $name );
		$path = SME_EXPORTS_DIR . $name;
		if ( pathinfo( $name, PATHINFO_EXTENSION ) !== 'csv' || ! is_file( $path ) ) {
			return false;
		}
		return unlink( $path );
	}

	public static function purge_exports(): int {
		if ( ! is_dir( SME_EXPORTS_DIR ) ) {
			return 0;
		}
		$count = 0;
		foreach ( glob( SME_EXPORTS_DIR . '*.csv' ) ?: [] as $path ) {
			if ( unlink( $path ) ) {
				$count++;
			}
		}
		return $count;
	}

	public static function get_statuses(): array {
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			return wc_get_order_statuses();
		}
		return [
			'wc-pending'    => 'Pending payment',
			'wc-processing' => 'Processing',
			'wc-on-hold'    => 'On hold',
			'wc-completed'  => 'Completed',
			'wc-cancelled'  => 'Cancelled',
			'wc-refunded'   => 'Refunded',
			'wc-failed'     => 'Failed',
		];
	}
}

if ( is_admin() ) {

	class Simple_Marketing_Export_Admin {

		public static function register(): void {
			add_action( 'admin_menu', [ self::class, 'add_menu' ] );
		}

		public static function add_menu(): void {
			add_submenu_page(
				'woocommerce',
				'Simple Marketing Export',
				'Simple Marketing Export',
				SME_CAPABILITY,
				SME_MENU_SLUG,
				[ self::class, 'render_page' ]
			);
		}

		public static function render_page(): void {
			if ( ! current_user_can( SME_CAPABILITY ) ) {
				wp_die( 'You do not have permission to access this page.' );
			}

			$notice    = '';
			$error     = '';
			$crash_msg = '';
			$result    = null;

			$action = isset( $_POST['sme_action'] ) ? sanitize_key( $_POST['sme_action'] ) : '';

			if ( $action === 'export' ) {
				check_admin_referer( 'sme_export' );

				$args = [
					'status'      => isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '',
					'date_from'   => isset( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : '',
					'date_to'     => isset( $_POST['date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) ) : '',
					'all_periods' => ! empty( $_POST['all_periods'] ),
				];

				@set_time_limit( 0 );
				ignore_user_abort( true );

				try {
					$result = Simple_Marketing_Export::run_export( $args );
					if ( is_wp_error( $result ) ) {
						$error = $result->get_error_message();
					} else {
						$notice = sprintf(
							'Exported %d rows to %s',
							(int) $result['rows'],
							esc_html( $result['filename'] )
						);
					}
				} catch ( \Throwable $e ) {
					$crash_msg = $e->getMessage();
				}
			} elseif ( $action === 'export_phones' ) {
				check_admin_referer( 'sme_export_phones' );

				@set_time_limit( 0 );
				ignore_user_abort( true );

				try {
					$result = Simple_Marketing_Export::run_phone_export();
					if ( is_wp_error( $result ) ) {
						$error = $result->get_error_message();
					} else {
						$notice = sprintf(
							'Exported %d unique phone numbers from %d completed orders to %s',
							(int) $result['rows'],
							(int) $result['orders'],
							$result['filename']
						);
					}
				} catch ( \Throwable $e ) {
					$crash_msg = $e->getMessage();
				}
			} elseif ( $action === 'purge' ) {
				check_admin_referer( 'sme_purge' );
				$purged = Simple_Marketing_Export::purge_exports();
				$notice = sprintf( '%d file(s) purged.', $purged );
			} elseif ( $action === 'delete' ) {
				check_admin_referer( 'sme_delete' );
				$file = isset( $_POST['file'] ) ? sanitize_file_name( wp_unslash( $_POST['file'] ) ) : '';
				if ( $file && Simple_Marketing_Export::delete_export( $file ) ) {
					$notice = 'File deleted.';
				} else {
					$error = 'Could not delete file.';
				}
			}

			$statuses     = Simple_Marketing_Export::get_statuses();
			$files        = Simple_Marketing_Export::list_exports();
			$form_status  = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : 'wc-completed';
			$form_from    = isset( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : '';
			$form_to      = isset( $_POST['date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) ) : '';
			$form_all     = ! empty( $_POST['all_periods'] );

			?>
			<div class="wrap">
				<h1>Simple Marketing Export</h1>

				<?php if ( $notice ) : ?>
					<div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
				<?php endif; ?>

				<?php if ( $error ) : ?>
					<div class="notice notice-warning"><p><?php echo esc_html( $error ); ?></p></div>
				<?php endif; ?>

				<?php if ( $crash_msg ) : ?>
					<div class="notice notice-error">
						<p><strong>The export crashed:</strong> <?php echo esc_html( $crash_msg ); ?></p>
						<p>Run it from the command line instead — it bypasses PHP web limits:</p>
						<pre style="background:#fff;border:1px solid #ccd0d4;padding:8px;"><code><?php echo $action === 'export_phones' ? 'wp simple-marketing phones' : 'wp simple-marketing export'; ?></code></pre>
					</div>
				<?php endif; ?>

				<?php if ( $result && ! is_wp_error( $result ) ) : ?>
					<div class="notice notice-info">
						<p>
							Download:
							<a href="<?php echo esc_url( $result['url'] ); ?>"><?php echo esc_html( $result['filename'] ); ?></a>
						</p>
					</div>
				<?php endif; ?>

				<div class="card" style="max-width:820px;padding:1em 1.5em;">
					<h2>About this export</h2>
					<p>
						This tool runs a single SQL query against the WooCommerce HPOS tables
						(<code>wc_orders</code> and <code>wc_order_addresses</code>) to extract the
						<strong>email</strong> and <strong>phone</strong> from billing addresses,
						and writes the result as a CSV into the plugin's <code>/exports</code> folder.
					</p>
					<p>
						Pure SQL, batched at 5,000 rows — usually fast even on large stores.
						If the web request crashes (timeout, memory, etc.), use the WP-CLI fallback shown below.
					</p>
					<p>
						<strong>WP-CLI fallback:</strong>
						<code>wp simple-marketing export</code>
					</p>
				</div>

				<h2 style="margin-top:1.5em;">Run an export</h2>
				<form method="post">
					<?php wp_nonce_field( 'sme_export' ); ?>
					<input type="hidden" name="sme_action" value="export" />
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="sme-status">Order status</label></th>
							<td>
								<select id="sme-status" name="status">
									<option value="">— All statuses —</option>
									<?php foreach ( $statuses as $key => $label ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $form_status, $key ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">Leave empty to include all statuses.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sme-from">Date from</label></th>
							<td>
								<input type="datetime-local" id="sme-from" name="date_from" value="<?php echo esc_attr( $form_from ); ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sme-to">Date to</label></th>
							<td>
								<input type="datetime-local" id="sme-to" name="date_to" value="<?php echo esc_attr( $form_to ); ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row">All periods / orders</th>
							<td>
								<label>
									<input type="checkbox" name="all_periods" value="1" <?php checked( $form_all ); ?> />
									Ignore status and date filters — export every order.
								</label>
							</td>
						</tr>
					</table>
					<p>
						<button type="submit" class="button button-primary">Run export</button>
					</p>
				</form>

				<h2 style="margin-top:1.5em;">Phones only</h2>
				<p>
					Unique billing phone numbers from all completed orders, cleaned to digits (plus a leading <code>+</code>).
					WP-CLI: <code>wp simple-marketing phones</code>
				</p>
				<form method="post">
					<?php wp_nonce_field( 'sme_export_phones' ); ?>
					<input type="hidden" name="sme_action" value="export_phones" />
					<p>
						<button type="submit" class="button button-primary">Export phones</button>
					</p>
				</form>

				<h2 style="margin-top:1.5em;">Existing export files</h2>
				<?php if ( empty( $files ) ) : ?>
					<p><em>No export files yet.</em></p>
				<?php else : ?>
					<table class="widefat striped" style="max-width:820px;">
						<thead>
						<tr>
							<th>File</th>
							<th>Size</th>
							<th>Created</th>
							<th></th>
						</tr>
						</thead>
						<tbody>
						<?php foreach ( $files as $f ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( $f['url'] ); ?>"><?php echo esc_html( $f['name'] ); ?></a></td>
								<td><?php echo esc_html( size_format( $f['size'] ) ); ?></td>
								<td><?php echo esc_html( date( 'Y-m-d H:i:s', $f['mtime'] ) ); ?></td>
								<td>
									<form method="post" style="display:inline;" onsubmit="return confirm('Delete this file?');">
										<?php wp_nonce_field( 'sme_delete' ); ?>
										<input type="hidden" name="sme_action" value="delete" />
										<input type="hidden" name="file" value="<?php echo esc_attr( $f['name'] ); ?>" />
										<button type="submit" class="button-link-delete">Delete</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>

					<form method="post" style="margin-top:1em;" onsubmit="return confirm('Purge ALL export files? This cannot be undone.');">
						<?php wp_nonce_field( 'sme_purge' ); ?>
						<input type="hidden" name="sme_action" value="purge" />
						<button type="submit" class="button button-secondary">Purge all export files</button>
					</form>
				<?php endif; ?>
			</div>
			<?php
		}
	}

	Simple_Marketing_Export_Admin::register();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	class Simple_Marketing_Export_Command {

		/**
		 * Export email and phone from WooCommerce orders.
		 *
		 * ## OPTIONS
		 *
		 * [--status=<status>]
		 * : Order status (e.g. wc-completed). Pass empty string for all statuses. Default: wc-completed.
		 *
		 * [--date-from=<datetime>]
		 * : Lower bound on date_created_gmt, e.g. "2025-01-01 00:00:00".
		 *
		 * [--date-to=<datetime>]
		 * : Upper bound on date_created_gmt, e.g. "2025-12-31 23:59:59".
		 *
		 * [--all]
		 * : Ignore status and date filters and export every order.
		 *
		 * ## EXAMPLES
		 *
		 *     wp simple-marketing export
		 *     wp simple-marketing export --status=wc-processing
		 *     wp simple-marketing export --date-from="2025-01-01 00:00:00" --date-to="2025-06-30 23:59:59"
		 *     wp simple-marketing export --all
		 *
		 * @when after_wp_load
		 */
		public function export( $args, $assoc_args ) {
			$run_args = [
				'status'      => array_key_exists( 'status', $assoc_args ) ? (string) $assoc_args['status'] : 'wc-completed',
				'date_from'   => $assoc_args['date-from'] ?? '',
				'date_to'     => $assoc_args['date-to'] ?? '',
				'all_periods' => isset( $assoc_args['all'] ),
			];

			$result = Simple_Marketing_Export::run_export( $run_args, $this->progress_logger( 'Exporting orders' ) );

			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}

			WP_CLI::success( "Exported {$result['rows']} rows to {$result['filepath']}" );
		}

		/**
		 * Export unique billing phone numbers from all completed orders.
		 *
		 * Phones are cleaned to digits (plus a leading "+") and deduplicated.
		 *
		 * ## EXAMPLES
		 *
		 *     wp simple-marketing phones
		 *
		 * @when after_wp_load
		 */
		public function phones( $args, $assoc_args ) {
			$result = Simple_Marketing_Export::run_phone_export( $this->progress_logger( 'Scanning completed orders' ) );

			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}

			WP_CLI::success( "Exported {$result['rows']} unique phone numbers from {$result['orders']} completed orders to {$result['filepath']}" );
		}

		private function progress_logger( string $label ): callable {
			$progress = null;
			return function ( $event, $payload = null ) use ( $label, &$progress ) {
				if ( $event === 'start' ) {
					$progress = \WP_CLI\Utils\make_progress_bar( $label, (int) $payload );
				} elseif ( $event === 'tick' && $progress ) {
					$progress->tick( $payload ? (int) $payload : 1 );
				} elseif ( $event === 'log' ) {
					WP_CLI::log( (string) $payload );
				} elseif ( $event === 'finish' && $progress ) {
					$progress->finish();
				}
			};
		}
	}

	WP_CLI::add_command( 'simple-marketing', 'Simple_Marketing_Export_Command' );
}